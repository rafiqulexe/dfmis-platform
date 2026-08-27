<?php
// ============================================================================
// GET /api/v1/animal-health/{id}
//
// The clinical summary and health timeline for ONE animal.
//
// Requires health.view. READ ONLY — Phase 4 adds no write endpoints, exactly as
// Phase 2B's /v1/animals did not.
//
// NOTE ON THE ROUTE NAME
// /v1/health is ALREADY TAKEN by the public, unauthenticated liveness probe.
// Overloading it would either expose clinical data on a public endpoint or
// break every load balancer pointing at it. This is therefore a separate route
// with its own name and its own authorization.
//
// This handler contains NO business logic. It calls healthAnimalSummary() and
// healthTimelineEvents() — the same functions the HTML pages use — so the API
// cannot drift from the UI or acquire its own idea of tenant scope.
// ============================================================================

if (!defined('DFMS_API_DISPATCHED')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../includes/livestock.php';
require_once __DIR__ . '/../../includes/health.php';

$conn = getDBConnection();

$animalId = $GLOBALS['dfms_route_param'] ?? null;
if ($animalId === null) {
    http_error('NOT_FOUND', 'Specify an animal, e.g. /v1/animal-health/12.', 404);
}

$v = new DfmsValidator(['id' => $animalId]);
$id = $v->id('id');
if ($v->fails()) {
    // Malformed and foreign ids are indistinguishable, as everywhere else.
    http_error('NOT_FOUND', 'Animal not found.', 404);
}

// Returns null for missing OR another tenant's animal.
$animal = animalFind($conn, (int) $id, true);
if ($animal === null) {
    http_error('NOT_FOUND', 'Animal not found.', 404);
}

$summary = healthAnimalSummary($conn, (int) $animal['cow_id']);

// ---------------------------------------------------------------------------
// Serialisers.
//
// An explicit allow-list, following the Phase 2B precedent. Returning raw rows
// would leak whatever a future migration happens to add — which is how internal
// columns end up in a public payload by accident.
//
// farm_id is NEVER echoed: the caller's farm is implied by their session, and
// returning a tenant key invites clients to send one back.
// ---------------------------------------------------------------------------
$serialiseCase = static function (array $case): array {
    return [
        'id'         => (int) $case['case_id'],
        'reference'  => $case['case_reference'],
        'title'      => $case['title'],
        'type'       => $case['case_type'],
        'severity'   => $case['severity'],
        'status'     => $case['status'],
        'outcome'    => $case['outcome'],
        'opened_on'  => $case['opened_on'],
        'closed_on'  => $case['closed_on'],
        'follow_up_on' => $case['follow_up_on'],
    ];
};

$serialiseTreatment = static function (array $treatment): array {
    return [
        'id'         => (int) $treatment['treatment_id'],
        'name'       => $treatment['name'],
        'type'       => $treatment['treatment_type'],
        'status'     => $treatment['status'],
        'outcome'    => $treatment['outcome'],
        'started_on' => $treatment['started_on'],
        'ended_on'   => $treatment['ended_on'],
    ];
};

$serialiseWithdrawal = static function (array $withdrawal): array {
    return [
        'id'             => (int) $withdrawal['withdrawal_id'],
        'restriction'    => $withdrawal['restriction_type'],
        'source'         => $withdrawal['source_label'],
        'starts_on'      => $withdrawal['starts_on'],
        // Repeated in the payload because an off-by-one on a withdrawal end is
        // a food-safety error, not a display detail. A client must not have to
        // guess the convention.
        'ends_on'        => $withdrawal['ends_on'],
        'ends_inclusive' => true,
        'days_remaining' => healthWithdrawalDaysRemaining($withdrawal),
    ];
};

$serialiseObservation = static function (array $observation): array {
    return [
        'type'          => $observation['observation_type'],
        'value_numeric' => $observation['value_numeric'] !== null ? (float) $observation['value_numeric'] : null,
        'value_code'    => $observation['value_code'],
        // The CANONICAL unit the value is stored in, never a display unit, so a
        // client is never left guessing which system a number is in.
        'unit'          => $observation['unit'],
        'observed_at'   => $observation['observed_at'],
    ];
};

$serialiseVaccination = static function (array $vaccination): array {
    return [
        'id'              => (int) $vaccination['vaccination_id'],
        'vaccine'         => $vaccination['vaccine_name'],
        'disease_target'  => $vaccination['disease_target'],
        'status'          => $vaccination['status'],
        'scheduled_on'    => $vaccination['scheduled_on'],
        'administered_on' => $vaccination['administered_on'],
        'next_due_on'     => $vaccination['next_due_on'],
    ];
};

http_success([
    'animal' => [
        'id'               => (int) $animal['cow_id'],
        'tag'              => $animal['unique_id'],
        'name'             => $animal['name'],
        'species'          => $animal['species'],
        'lifecycle_status' => $animal['lifecycle_status'],
    ],
    'health_status' => [
        'value' => $summary['health_status'],
        'label' => dfms_health_status_label($summary['health_status']),
        // Lifecycle is authoritative over health; a client building a write UI
        // needs to know the server will refuse before it offers the action.
        'records_locked_by_lifecycle' => $summary['lifecycle_blocks'],
    ],
    'active_cases'       => array_map($serialiseCase, $summary['active_cases']),
    'active_treatments'  => array_map($serialiseTreatment, $summary['active_treatments']),
    'active_withdrawals' => array_map($serialiseWithdrawal, $summary['active_withdrawals']),
    'quarantine' => $summary['active_quarantine'] === null ? null : [
        'id'              => (int) $summary['active_quarantine']['quarantine_id'],
        'type'            => $summary['active_quarantine']['quarantine_type'],
        'reason'          => $summary['active_quarantine']['reason'],
        'started_at'      => $summary['active_quarantine']['started_at'],
        'expected_end_on' => $summary['active_quarantine']['expected_end_on'],
        'location'        => $summary['active_quarantine']['location_name'],
    ],
    'upcoming_vaccinations' => array_map($serialiseVaccination, $summary['upcoming_vaccinations']),
    // Counts, not an assertion about the animal. Zero recorded doses means the
    // history is UNKNOWN to DFMIS, not that the animal is unvaccinated, and the
    // field name says exactly that.
    'recorded_vaccination_count' => $summary['vaccination_count'],
    'recent_observations'        => array_map($serialiseObservation, $summary['recent_observations']),

    // The health slice of the animal timeline, from the same union-over-source-
    // records function the profile page renders.
    'timeline' => array_map(static function (array $event): array {
        return [
            'occurred_at' => $event['occurred_at'],
            'type'        => $event['type'],
            'title'       => $event['title'],
            'detail'      => $event['detail'],
        ];
    }, healthTimelineEvents($conn, (int) $animal['cow_id'])),
], 200, [
    // Stated in the payload, not only in the docs, because a client that
    // enforces withdrawal on the strength of this API must know what it is and
    // is not being told.
    'withdrawal_enforcement' => 'advisory_only',
    'withdrawal_note' => 'Withdrawal periods are tracked per animal. Milk production is recorded as a '
        . 'farm-level daily total with no per-animal attribution, so DFMIS cannot verify that a restricted '
        . 'animal\'s product was withheld.',
]);
