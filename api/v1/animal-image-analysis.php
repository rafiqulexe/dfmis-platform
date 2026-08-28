<?php
// ============================================================================
// GET /api/v1/animal-image-analysis/{animal id}
//
// The AI image analysis history for ONE animal.
//
// READ ONLY. This API has been read-only since Phase 2B and Phase 7 does not
// change that. REQUESTING an analysis is a POST to the HTML module, because it
// spends a metered allowance and a write surface for that is a separate
// security decision, not a convenience.
//
// Requires ai.consult — the SAME permission the HTML tab checks and the same
// one /v1/ai-conversations uses to read assistant history. cows.view is
// deliberately NOT enough: seeing an animal is not the same decision as seeing
// what an AI said about it.
//
// This handler contains NO business logic. It calls the same functions the
// profile page renders from, so the API cannot drift from the UI or acquire its
// own idea of tenant scope.
//
// WHAT IS NEVER IN THIS PAYLOAD
//   farm_id                a tenant key is never echoed; the caller farm is
//                          implied by their session
//   failure_reason         a machine token, mapped to safe text instead
//   stored_path, file bytes, any base64
//   provider credentials, upstream bodies, request headers
// ============================================================================

if (!defined('DFMS_API_DISPATCHED')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../includes/livestock.php';
require_once __DIR__ . '/../../includes/ai_vision.php';

$conn = getDBConnection();

$animalId = $GLOBALS['dfms_route_param'] ?? null;
if ($animalId === null) {
    http_error('NOT_FOUND', 'Specify an animal, e.g. /v1/animal-image-analysis/12.', 404);
}

$v = new DfmsValidator(['id' => $animalId]);
$id = $v->id('id');
if ($v->fails()) {
    // Malformed and foreign ids are indistinguishable, as everywhere else.
    http_error('NOT_FOUND', 'Animal not found.', 404);
}

// Returns null for missing OR another tenant animal.
$animal = animalFind($conn, (int) $id, true);
if ($animal === null) {
    http_error('NOT_FOUND', 'Animal not found.', 404);
}

// ---------------------------------------------------------------------------
// Serialiser — an explicit allow-list, following the Phase 2B precedent.
// Returning raw rows would leak whatever a future migration happens to add.
// ---------------------------------------------------------------------------
$serialise = static function (array $analysis): array {
    $status = (string) $analysis['status'];
    // Re-validated on the way out, exactly as the page does. A stored result
    // that no longer satisfies the schema is reported as absent rather than
    // emitted unchecked.
    $result = aiImageDecodeResult($analysis['result_json'] ?? null);

    return [
        'id'        => (int) $analysis['analysis_id'],
        'animal_id' => (int) $analysis['cow_id'],
        'file_id'   => (int) $analysis['file_id'],
        'status'    => $status,
        // Safe text for any non-completed state. The stored token never leaves
        // the server.
        'message'   => $status === 'completed'
            ? null
            : aiImageFailureMessage($analysis['failure_reason'] ?? null),
        // The RESOLVED urgency — already raised to the deterministic floor
        // before it was stored, so a client cannot see a lower value than the
        // page shows.
        'urgency'   => $analysis['urgency'],
        // Stated separately so a client knows the escalation came from the
        // server-side scan of the person own words, not from the model.
        'urgency_flagged_by_reported_signs' => (bool) $analysis['urgency_flagged'],
        'requested_at' => $analysis['created_at'],
        'completed_at' => $analysis['completed_at'],
        'provider'     => $analysis['provider'],
        'model'        => $analysis['model'],
        'prompt_key'     => $analysis['prompt_key'],
        'prompt_version' => $analysis['prompt_version'] !== null
            ? (int) $analysis['prompt_version'] : null,
        'summary' => aiImageSummaryLine($result),
        'result'  => $result,
    ];
};

$analyses = aiImageAnalysesForAnimal($conn, (int) $animal['cow_id'], 50);

http_success([
    'animal' => [
        'id'      => (int) $animal['cow_id'],
        'tag'     => $animal['unique_id'],
        'name'    => $animal['name'],
        'species' => $animal['species'],
    ],
    'analyses' => array_map($serialise, $analyses),
], 200, [
    // Stated in the PAYLOAD, not only in the docs, because a client rendering
    // this data must know what it is and is not being told. A client that
    // presented these observations as a diagnosis would be misusing them, and
    // this meta block is the machine-readable statement of that boundary.
    'interpretation'  => 'ai_assisted_observation',
    'is_diagnosis'    => false,
    'disclaimer'      => aiImageDisclaimer(),
    'clinical_record' => 'none',
    'clinical_record_note' => 'An AI image analysis is never automatically a health record. '
        . 'It does not open a case, record a diagnosis, create a treatment, administer '
        . 'medication or change the animal status.',
]);
