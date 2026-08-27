<?php
// ============================================================================
// GET /api/v1/ai-status
//
// Whether the AI assistant is usable for the CALLER'S tenant, and if not, why.
//
// Requires ai.consult — the same permission the HTML page enforces. This is
// NOT a public probe: /v1/health already fills that role, and whether an
// organization has AI configured and how much of its allowance is left is
// tenant business information.
//
// WHY THIS ENDPOINT EXISTS AT ALL
// The degraded state is a contract, not a nicety: a client must be able to
// render "the assistant is unavailable, here is why" without guessing. Without
// this, a client's only way to discover an unconfigured installation is to
// attempt a consultation and burn a request.
//
// WHAT IT NEVER RETURNS
// No API key, no key prefix, no key length, no provider endpoint, no
// environment variable name. `configured` is a BOOLEAN — that is the entire
// extent of what any caller learns about credentials.
// ============================================================================

if (!defined('DFMS_API_DISPATCHED')) {
    http_response_code(404);
    exit;
}

// The API front controller loads the Phase 1 gates, not every domain file, so
// each handler requires what it actually uses.
require_once __DIR__ . '/../../includes/features.php';
require_once __DIR__ . '/../../includes/ai_vet.php';

$conn = getDBConnection();
$availability = aiVetAvailability($conn);

// The feature flag is folded into `available` rather than reported alongside
// it. A client should not have to combine two booleans to answer one question,
// and a farm that has switched the assistant off has no usable assistant
// whatever the provider configuration says.
//
// This endpoint REPORTS the disabled state instead of 404ing, because "is the
// assistant available" is precisely the question it exists to answer — and a
// 404 would tell a client nothing about why.
$featureEnabled = dfms_feature_enabled('ai_vet_assistant');
if (!$featureEnabled) {
    $availability['available'] = false;
    $availability['reason'] = $availability['reason'] ?? 'feature_disabled';
    $availability['message'] = 'The AI assistant is switched off for this farm.';
}

// The plan block is usage accounting, which the organization owns and is
// already visible to it. It carries no provider information.
$plan = $availability['plan'];

http_success([
    'available'  => (bool) $availability['available'],
    // A boolean. Never a key, a fragment of one, or its length.
    'configured' => aiIsConfigured(),
    'reason'     => $availability['reason'],
    'message'    => $availability['message'],
    'feature_enabled' => $featureEnabled,
    'usage' => $plan === null ? null : [
        'plan'      => $plan['plan'],
        'used'      => $plan['used'],
        // -1 and null both mean unlimited; both are stated so a client does
        // not have to infer it.
        'limit'     => $plan['limit'],
        'remaining' => $plan['remaining'],
        'unlimited' => $plan['remaining'] === null,
    ],
], 200, [
    // Restated on every response because a client acting on this endpoint is
    // about to put an answer in front of someone making a clinical decision.
    'assistant_role' => 'decision_support_only',
    'assistant_note' => 'The assistant does not diagnose, prescribe, or replace a licensed veterinarian.',
]);
