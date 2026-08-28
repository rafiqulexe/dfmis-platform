<?php
// ============================================================================
// AI SERVICE — single entry point for every AI feature in the product.
//
// The important architectural property here is not the model call itself but
// that NO feature may reach a model except through this file, which:
//   1. resolves tenant context (organization, farm, user)
//   2. checks the organization's plan allowance BEFORE spending anything
//   3. delegates the actual call to a PROVIDER (includes/ai_provider.php)
//   4. records usage against the tenant afterwards
//
// That ordering is what makes plan limits and per-customer cost reporting work
// without revisiting a single call site. Billing itself is deliberately not
// built yet — only the seam it will attach to.
//
// ---------------------------------------------------------------------------
// PHASE 5D CHANGE — what moved, and why
//
// This file previously curled api.anthropic.com directly from the middle of
// the accounting logic, and could only send ONE user turn. Two things changed:
//
//   - The vendor call moved behind DfmsAiProvider. This file no longer names
//     a vendor, holds a key, or knows what an HTTP status is.
//   - aiChat() was added for multi-turn conversation. aiComplete() is retained
//     with its original single-prompt signature and is now a thin wrapper, so
//     any future caller written against the old shape still works.
//
// The tenant accounting, the plan gate, the ai_usage row and the
// ['ok','text','reason'] contract are UNCHANGED. Callers must still render a
// degraded state on failure rather than inventing content — an unavailable
// model must never look like a confident answer.
// ---------------------------------------------------------------------------
// ============================================================================

// ---------------------------------------------------------------------------
// PHASE 5E CHANGE — the gate order, completed
//
// aiChat() was already the one path to a model. Phase 5E fills in the three
// steps that were missing from it rather than building a second path:
//
//   authentication -> feature gate -> plan gate   (already here / at the caller)
//   -> RATE LIMIT        new: abuse protection the monthly plan cannot provide
//   -> prompt registry   new: the prompt is identified and versioned
//   -> provider          now called under a bounded retry and timeout policy
//   -> output validation new: aiStructuredComplete() for non-prose answers
//   -> usage metadata    now records provider, prompt key and prompt version
//
// Every one of those is ADDITIVE. aiChat()'s signature, its return contract
// and the ['ok','text','reason'] shape every existing caller depends on are
// unchanged, and a caller that passes no new option gets the same behaviour it
// got in Phase 5D with a retry it did not have to ask for.
// ---------------------------------------------------------------------------

require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/ai_provider.php';
require_once __DIR__ . '/ai_config.php';
require_once __DIR__ . '/ai_prompts.php';
require_once __DIR__ . '/ai_ratelimit.php';
require_once __DIR__ . '/ai_schema.php';

// Retained for backward compatibility with anything that referenced it. The
// authoritative model now comes from the provider, which reads AI_MODEL from
// the environment so a deployment can pin one without a code change.
const DFMS_AI_MODEL = 'claude-sonnet-5';

// Monthly AI call allowance per plan. Enforced today; priced later.
const DFMS_PLAN_AI_LIMITS = [
    'starter'      => 100,
    'professional' => 1000,
    'enterprise'   => -1, // unlimited
];

/**
 * Is AI usable on this installation?
 *
 * Delegates to the provider, so this answers "can we actually call a model"
 * rather than "does one particular environment variable exist". Returns a
 * boolean and nothing else — no caller ever learns anything about the
 * credential itself.
 */
function aiIsConfigured() {
    return dfms_ai_provider()->isConfigured();
}

function organizationPlan(PDO $conn, $organizationId) {
    $stmt = $conn->prepare("SELECT plan FROM organizations WHERE organization_id = ?");
    $stmt->execute([$organizationId]);
    return $stmt->fetchColumn() ?: 'starter';
}

function aiUsageThisMonth(PDO $conn, $organizationId) {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM ai_usage
         WHERE organization_id = ? AND status = 'success'
           AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
    );
    $stmt->execute([$organizationId]);
    return (int) $stmt->fetchColumn();
}

/**
 * The organization's remaining allowance this month.
 *
 * @return array{plan: string, limit: int, used: int, remaining: ?int}
 *         `limit` of -1 and `remaining` of null both mean unlimited.
 */
function aiPlanStatus(PDO $conn, $organizationId): array {
    $plan = (string) organizationPlan($conn, $organizationId);
    $limit = DFMS_PLAN_AI_LIMITS[$plan] ?? 0;
    $used = aiUsageThisMonth($conn, $organizationId);
    return [
        'plan'      => $plan,
        'limit'     => $limit,
        'used'      => $used,
        'remaining' => $limit < 0 ? null : max(0, $limit - $used),
    ];
}

/**
 * Record one AI call against the tenant.
 *
 * PHASE 5E added `provider`, `prompt_key` and `prompt_version` (migration 010).
 * They are the answer to the question asked after a bad answer is found months
 * later: which vendor, which model and which version of which prompt produced
 * it. Without them the row says a call happened and nothing about what was
 * asked, and the prompt has been edited since.
 *
 * NOTHING SECRET IS WRITTEN HERE. Not the prompt body, not the question, not
 * the answer, and by construction nothing derived from the credential — the
 * columns are a feature name, machine tokens, integers and a status.
 */
function recordAiUsage(PDO $conn, array $ctx, $status, $inputTokens = 0, $outputTokens = 0) {
    $stmt = $conn->prepare(
        "INSERT INTO ai_usage (organization_id, farm_id, admin_id, feature, model, provider,
                               prompt_key, prompt_version, entity_type, entity_id,
                               input_tokens, output_tokens, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $ctx['organization_id'], $ctx['farm_id'], $ctx['admin_id'],
        $ctx['feature'], $ctx['model'] ?? DFMS_AI_MODEL,
        $ctx['provider'] ?? null,
        $ctx['prompt_key'] ?? null, $ctx['prompt_version'] ?? null,
        $ctx['entity_type'] ?? null, $ctx['entity_id'] ?? null,
        $inputTokens, $outputTokens, $status,
    ]);
}

/**
 * Run a MULTI-TURN AI completion, fully attributed to the current tenant.
 *
 * This is the real entry point. Every gate runs before the provider is
 * touched, so an unconfigured installation, a tenant-less request or an
 * over-limit organization all cost nothing.
 *
 * $options may carry entity_type/entity_id ('cow', 'flock', 'pond', 'field'…)
 * so usage stays attributable without the core AI layer knowing any vertical.
 *
 * @param list<array{role: string, content: string|list<array<string, mixed>>}> $messages
 * @return array{ok: bool, text: ?string, reason: ?string, model: ?string,
 *               provider: ?string, input_tokens: int, output_tokens: int,
 *               stop_reason?: string, tool_requests?: list<array<string, mixed>>,
 *               blocks?: list<array<string, mixed>>}
 */
function aiChat($feature, $systemPrompt, array $messages, array $options = []) {
    $provider = dfms_ai_provider();

    // Server-controlled request configuration. A caller override that is out
    // of bounds THROWS (it is our own code passing it, so it is a bug); a bad
    // environment value was already discarded and logged by aiRequestConfig().
    $config = aiRequestConfig($options);

    // The provider still receives an options array, so its contract is
    // unchanged — but the values in it are now validated rather than whatever
    // the call site happened to write.
    $providerOptions = $options;
    $providerOptions['model'] = $config['model'];
    $providerOptions['max_tokens'] = $config['max_tokens'];
    if ($config['temperature'] !== null) {
        $providerOptions['temperature'] = $config['temperature'];
    } else {
        unset($providerOptions['temperature']);
    }

    $model = $config['model'];

    // The prompt's identity, when the caller named one from the registry.
    // aiPromptVersion() throws on an unknown key, which is correct: a prompt
    // key is written by us, and the alternative to failing is recording a
    // version number that is a guess.
    $promptKey = isset($options['prompt_key']) ? (string) $options['prompt_key'] : null;
    $promptVersion = $promptKey !== null ? aiPromptVersion($promptKey) : null;

    $fail = static function (string $reason) use ($provider, $model): array {
        return [
            'ok' => false, 'text' => null, 'reason' => $reason,
            'model' => $model, 'provider' => $provider->name(),
            'input_tokens' => 0, 'output_tokens' => 0,
        ];
    };

    $ctx = [
        'organization_id' => currentOrganizationId(),
        'farm_id'         => currentFarmId(),
        'admin_id'        => currentUserId(),
        'feature'         => $feature,
        'model'           => $model,
        'provider'        => $provider->name(),
        'prompt_key'      => $promptKey,
        'prompt_version'  => $promptVersion,
        'entity_type'     => $options['entity_type'] ?? null,
        'entity_id'       => $options['entity_id'] ?? null,
    ];

    if ($ctx['organization_id'] === null) {
        return $fail('no_tenant');
    }
    if (!$provider->isConfigured()) {
        // NOT recorded as usage: nothing was spent and no request was made.
        return $fail('not_configured');
    }

    $conn = getDBConnection();

    // ---- plan gate --------------------------------------------------------
    // Checked before the call so an over-limit tenant costs nothing.
    $status = aiPlanStatus($conn, $ctx['organization_id']);
    if ($status['remaining'] !== null && $status['remaining'] <= 0) {
        recordAiUsage($conn, $ctx, 'blocked');
        return $fail('plan_limit_reached');
    }

    // ---- rate limit -------------------------------------------------------
    // A SEPARATE question from the plan gate: that one asks whether the month's
    // allowance is spent, this one asks whether this is a plausible rate for a
    // person. An enterprise plan is unlimited and would otherwise have no
    // burst protection at all. Identity comes from the session, never from
    // input, so there is nothing here a client can manipulate.
    $rate = aiRateLimitCheck($conn, (int) $ctx['organization_id'], $ctx['admin_id'] === null ? null : (int) $ctx['admin_id']);
    if (!$rate['allowed']) {
        // Recorded as 'blocked' — the same status the plan gate uses, and the
        // status aiRateLimitCheck() excludes from its own window, so being
        // refused does not extend the refusal.
        recordAiUsage($conn, $ctx, 'blocked');
        error_log('[' . dfms_ai_request_ref() . '] ai rate limit: '
            . (string) $rate['scope'] . ' reached for organization ' . (int) $ctx['organization_id']);
        return $fail('ai_rate_limited');
    }

    // ---- provider, under the configured timeout and retry policy ----------
    // One usage row per LOGICAL call, not per attempt: a retried outage is one
    // failed request from the tenant's point of view, and billing a farm twice
    // for our own retry would be indefensible.
    $result = aiCallWithPolicy($provider, $systemPrompt, $messages, $providerOptions, $config);

    if (!$result['ok']) {
        recordAiUsage($conn, $ctx, 'error');
        return [
            'ok' => false, 'text' => null, 'reason' => $result['reason'],
            'model' => $result['model'], 'provider' => $provider->name(),
            'input_tokens' => 0, 'output_tokens' => 0,
        ];
    }

    recordAiUsage($conn, $ctx, 'success', $result['input_tokens'], $result['output_tokens']);

    return [
        'ok'            => true,
        'text'          => $result['text'],
        'reason'        => null,
        'model'         => $result['model'],
        'provider'      => $provider->name(),
        'input_tokens'  => $result['input_tokens'],
        'output_tokens' => $result['output_tokens'],
        // Phase 5F. Passed straight through in the INTERNAL vocabulary so the
        // orchestration in includes/ai_agent.php can read them. A provider
        // with no tool support omits all three and the defaults below make it
        // indistinguishable from "the model gave a final answer" — which is
        // exactly what it is.
        'stop_reason'   => $result['stop_reason'] ?? 'end_turn',
        'tool_requests' => $result['tool_requests'] ?? [],
        'blocks'        => $result['blocks'] ?? [],
    ];
}

/**
 * Single-prompt completion.
 *
 * The ORIGINAL Phase 2 signature, retained so nothing written against it
 * breaks. It is now a one-turn call through aiChat().
 *
 * @return array{ok: bool, text: string|null, reason: string|null, ...}
 */
function aiComplete($feature, $systemPrompt, $userPrompt, array $options = []) {
    return aiChat($feature, $systemPrompt, [
        ['role' => 'user', 'content' => (string) $userPrompt],
    ], $options);
}

/**
 * Ask for a STRUCTURED answer and validate it before returning.
 *
 * The whole point of routing this through the same gateway is that a
 * structured request is not a different kind of AI call — it is the same call
 * with a stricter contract on the way back. Authentication, the feature's own
 * gate at the call site, the plan allowance, the rate limit, the retry policy
 * and the usage row are all exactly the ones prose answers get.
 *
 * WHAT IS DIFFERENT is what happens to the reply: it is parsed and validated
 * against $schema (includes/ai_schema.php), and a reply that does not match is
 * a FAILURE. There is no partial result, no repaired value and no default
 * silently substituted — a model that could not follow the contract has not
 * answered, and the caller is told so.
 *
 * NOTHING IS WRITTEN TO THE DATABASE from the validated structure. This
 * function returns data; deciding what a validated AI structure MEANS belongs
 * to the phase that wants it, in that phase's code, under review.
 *
 * @param string $promptKey  a key of the registry in includes/ai_prompts.php
 * @param list<array{role: string, content: string}> $messages
 * @param array<string, mixed> $schema  an ai_schema.php object schema
 *
 * @return array{ok: bool, data: mixed, reason: ?string, message: ?string,
 *               errors: list<string>, model: ?string, provider: ?string,
 *               prompt_key: string, prompt_version: int,
 *               input_tokens: int, output_tokens: int}
 * @throws AiPromptNotFound when $promptKey is not registered
 */
function aiStructured($feature, string $promptKey, array $messages, array $schema, array $options = []): array {
    $prompt = aiPrompt($promptKey);

    // The JSON discipline is appended from the registry rather than written
    // here, so every structured caller asks for the same thing in the same
    // words and a change to that wording is one edit with a version on it.
    $systemPrompt = $prompt['system'] . "\n\n"
        . aiPromptSystem('structured_output_contract') . "\n\n"
        . "SCHEMA\n" . aiSchemaDescribe($schema);

    $options['prompt_key'] = $promptKey;

    $result = aiChat($feature, $systemPrompt, $messages, $options);

    $envelope = [
        'model'          => $result['model'] ?? null,
        'provider'       => $result['provider'] ?? null,
        'prompt_key'     => $promptKey,
        'prompt_version' => $prompt['version'],
        'input_tokens'   => (int) $result['input_tokens'],
        'output_tokens'  => (int) $result['output_tokens'],
    ];

    if (!$result['ok']) {
        return array_merge($envelope, [
            'ok' => false, 'data' => null,
            'reason'  => $result['reason'],
            'message' => dfms_ai_failure_message($result['reason']),
            'errors'  => [],
        ]);
    }

    $parsed = aiStructuredParse((string) $result['text'], $schema);
    if (!$parsed['ok']) {
        // The call SUCCEEDED and was billed — tokens really were spent — but
        // the answer is unusable. Both facts are reported: the usage row
        // already says 'success', and the caller is told plainly that there is
        // no data. Reporting this as a provider failure would misattribute a
        // model's disobedience to an outage.
        error_log('[' . dfms_ai_request_ref() . "] ai structured output rejected for prompt "
            . "'$promptKey' v" . $prompt['version'] . ': ' . (string) $parsed['reason']);
        return array_merge($envelope, [
            'ok' => false, 'data' => null,
            'reason'  => $parsed['reason'],
            'message' => aiSchemaFailureMessage($parsed['reason']),
            'errors'  => $parsed['errors'],
        ]);
    }

    return array_merge($envelope, [
        'ok' => true, 'data' => $parsed['data'],
        'reason' => null, 'message' => null, 'errors' => [],
    ]);
}
