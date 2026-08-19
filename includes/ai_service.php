<?php
// ============================================================================
// AI SERVICE — single entry point for every AI feature in the product.
//
// The important architectural property here is not the model call itself but
// that NO feature may reach a model except through aiComplete(), which:
//   1. resolves tenant context (organization, farm, user)
//   2. checks the organization's plan allowance BEFORE spending anything
//   3. records usage against the tenant afterwards
//
// That ordering is what makes plan limits and per-customer cost reporting
// addable later without revisiting a single call site. Billing itself is
// deliberately not built yet — only the seam it will attach to.
// ============================================================================

require_once __DIR__ . '/tenant.php';

const DFMS_AI_MODEL = 'claude-sonnet-5';

// Monthly AI call allowance per plan. Enforced today; priced later.
const DFMS_PLAN_AI_LIMITS = [
    'starter'      => 100,
    'professional' => 1000,
    'enterprise'   => -1, // unlimited
];

function aiIsConfigured() {
    return ANTHROPIC_API_KEY !== '';
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

function recordAiUsage(PDO $conn, array $ctx, $status, $inputTokens = 0, $outputTokens = 0) {
    $stmt = $conn->prepare(
        "INSERT INTO ai_usage (organization_id, farm_id, admin_id, feature, model, entity_type, entity_id, input_tokens, output_tokens, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $ctx['organization_id'], $ctx['farm_id'], $ctx['admin_id'],
        $ctx['feature'], DFMS_AI_MODEL,
        $ctx['entity_type'] ?? null, $ctx['entity_id'] ?? null,
        $inputTokens, $outputTokens, $status,
    ]);
}

/**
 * Run an AI completion for a feature, fully attributed to the current tenant.
 *
 * $options may carry entity_type/entity_id ('cow', 'flock', 'pond', 'field'…)
 * so usage stays attributable without the core AI layer knowing any vertical.
 *
 * Returns ['ok' => bool, 'text' => string|null, 'reason' => string|null].
 * Callers must render a degraded state on failure rather than inventing
 * content — an unavailable model must never look like a confident answer.
 */
function aiComplete($feature, $systemPrompt, $userPrompt, array $options = []) {
    $conn = getDBConnection();
    $ctx = [
        'organization_id' => currentOrganizationId(),
        'farm_id' => currentFarmId(),
        'admin_id' => currentUserId(),
        'feature' => $feature,
        'entity_type' => $options['entity_type'] ?? null,
        'entity_id' => $options['entity_id'] ?? null,
    ];

    if ($ctx['organization_id'] === null) {
        return ['ok' => false, 'text' => null, 'reason' => 'no_tenant'];
    }
    if (!aiIsConfigured()) {
        return ['ok' => false, 'text' => null, 'reason' => 'not_configured'];
    }

    // Plan gate — checked before the call so an over-limit tenant costs nothing.
    $plan = organizationPlan($conn, $ctx['organization_id']);
    $limit = DFMS_PLAN_AI_LIMITS[$plan] ?? 0;
    if ($limit >= 0 && aiUsageThisMonth($conn, $ctx['organization_id']) >= $limit) {
        recordAiUsage($conn, $ctx, 'blocked');
        return ['ok' => false, 'text' => null, 'reason' => 'plan_limit_reached'];
    }

    $payload = [
        'model' => DFMS_AI_MODEL,
        'max_tokens' => $options['max_tokens'] ?? 1024,
        'system' => $systemPrompt,
        'messages' => [['role' => 'user', 'content' => $userPrompt]],
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'content-type: application/json',
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $httpCode !== 200) {
        error_log("AI request failed for feature '$feature': HTTP $httpCode");
        recordAiUsage($conn, $ctx, 'error');
        return ['ok' => false, 'text' => null, 'reason' => 'request_failed'];
    }

    $decoded = json_decode($raw, true);
    $text = $decoded['content'][0]['text'] ?? null;
    if ($text === null) {
        recordAiUsage($conn, $ctx, 'error');
        return ['ok' => false, 'text' => null, 'reason' => 'empty_response'];
    }

    recordAiUsage(
        $conn, $ctx, 'success',
        $decoded['usage']['input_tokens'] ?? 0,
        $decoded['usage']['output_tokens'] ?? 0
    );

    return ['ok' => true, 'text' => $text, 'reason' => null];
}
