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

require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/ai_provider.php';

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

function recordAiUsage(PDO $conn, array $ctx, $status, $inputTokens = 0, $outputTokens = 0) {
    $stmt = $conn->prepare(
        "INSERT INTO ai_usage (organization_id, farm_id, admin_id, feature, model, entity_type, entity_id, input_tokens, output_tokens, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $ctx['organization_id'], $ctx['farm_id'], $ctx['admin_id'],
        $ctx['feature'], $ctx['model'] ?? DFMS_AI_MODEL,
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
 * @param list<array{role: string, content: string}> $messages
 * @return array{ok: bool, text: ?string, reason: ?string, model: ?string,
 *               provider: ?string, input_tokens: int, output_tokens: int}
 */
function aiChat($feature, $systemPrompt, array $messages, array $options = []) {
    $provider = dfms_ai_provider();
    $model = (string) ($options['model'] ?? $provider->defaultModel());

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

    // Plan gate — checked before the call so an over-limit tenant costs nothing.
    $status = aiPlanStatus($conn, $ctx['organization_id']);
    if ($status['remaining'] !== null && $status['remaining'] <= 0) {
        recordAiUsage($conn, $ctx, 'blocked');
        return $fail('plan_limit_reached');
    }

    $result = $provider->complete($systemPrompt, $messages, $options);

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
