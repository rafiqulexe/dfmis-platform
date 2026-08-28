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
require_once __DIR__ . '/../../includes/ai_tools.php';

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

// ---------------------------------------------------------------------------
// PHASE 5E — the `platform` block.
//
// Added to THIS endpoint rather than as a new route. Phase 5E deliberately
// creates no new public surface: a second endpoint would be a second thing to
// authorize, rate-limit and keep in step, to answer a question this one is
// already about. In particular there is NO generic tool-execution endpoint —
// a route that runs a tool by name from a request body would hand a remote
// caller the registry, and the allow-list would be the only thing between it
// and every read in the system.
//
// WHAT IT CONTAINS, and why each is safe to state:
//   provider/model     already recorded on every ai_messages row and shown in
//                      the conversation UI, so this reveals nothing new
//   prompt key/version the identity of the safety contract, never its text
//   rate limits        a client that knows the pace can respect it; hiding
//                      them only means clients discover them by tripping them
//   tools              filtered to what THIS caller may run, and names only
//
// WHAT IT NEVER CONTAINS: any credential, key prefix, key length, environment
// variable name or provider endpoint. `configured` stays a boolean and remains
// the entire extent of what a caller learns about credentials.
// ---------------------------------------------------------------------------
$config = aiConfigSummary();
$rateLimits = aiRateLimits();

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
    'platform' => [
        'provider' => $config['provider'],
        'model'    => $config['model'],
        'prompt'   => [
            'key'     => DFMS_AI_VET_PROMPT_KEY,
            'version' => aiPromptVersion(DFMS_AI_VET_PROMPT_KEY),
        ],
        'rate_limit' => [
            'user_per_minute' => $rateLimits['user_per_minute'],
            'user_per_hour'   => $rateLimits['user_per_hour'],
            'org_per_minute'  => $rateLimits['org_per_minute'],
        ],
        // Names only, filtered to this caller's permissions. Descriptions and
        // input schemas are for a model, not for an HTTP client — and there is
        // no endpoint that would run one of these on request.
        'tools' => array_column(aiToolsAvailable(), 'name'),
    ],
], 200, [
    // Restated on every response because a client acting on this endpoint is
    // about to put an answer in front of someone making a clinical decision.
    'assistant_role' => 'decision_support_only',
    'assistant_note' => 'The assistant does not diagnose, prescribe, or replace a licensed veterinarian.',
]);
