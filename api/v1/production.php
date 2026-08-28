<?php
// ============================================================================
// GET /api/v1/production            herd production summary for a window
// GET /api/v1/production/{animal}   one animal's recorded yields
//
// READ-ONLY, like every /api/v1 endpoint since Phase 2B. Recording a milking
// is a POST to the HTML module; this phase creates no write surface, and no
// endpoint here can move stock or change a record.
//
// WHAT THIS ENDPOINT IS CAREFUL ABOUT
// It returns the per-animal recorded figures and the farm-level daily
// aggregate as TWO SEPARATE BLOCKS that it never reconciles. They measure
// different things — a bulk tank reading and a sum of individual readings —
// and a client that received one merged "production" number would have no way
// to know which it was holding, or that the two can legitimately differ.
// ============================================================================

if (!defined('DFMS_API_DISPATCHED')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../includes/livestock.php';
require_once __DIR__ . '/../../includes/production.php';

$conn = getDBConnection();
$param = $GLOBALS['dfms_route_param'] ?? null;

if ($param !== null) {
    // ---- one animal -------------------------------------------------------
    // The id is validated as a positive integer and THEN resolved through the
    // tenant repository. A malformed id is a 404, and so is a well-formed id
    // belonging to another farm — indistinguishably, per the convention every
    // other endpoint here follows.
    $v = new DfmsValidator(['id' => $param]);
    $animalId = $v->id('id');
    if ($v->fails() || $animalId === null) {
        http_error('NOT_FOUND', 'No such animal.', 404);
    }

    $animal = animalFind($conn, $animalId, true);
    if ($animal === null) {
        http_error('NOT_FOUND', 'No such animal.', 404);
    }

    $trend = productionAnimalTrend($conn, (int) $animal['cow_id']);

    http_success([
        'animal' => [
            'animal_id' => (int) $animal['cow_id'],
            'tag'       => (string) $animal['unique_id'],
            'name'      => $animal['name'],
            // farm_id is NOT returned. The caller already knows which farm
            // they are in, and echoing the tenant key serves no consumer.
        ],
        'unit' => dfms_canonical_unit('volume'),
        'yields' => array_map(static function (array $row): array {
            return [
                'date'      => (string) $row['production_date'],
                'session'   => (string) $row['session'],
                'quantity'  => (float) $row['quantity'],
                'discarded' => (float) $row['discarded'],
                'abnormal'  => (bool) $row['abnormal'],
                // Computed from withdrawal_periods at read time, never stored
                // — so a corrected withdrawal corrects this immediately.
                'withdrawal_restricted' => (bool) $row['restricted'],
            ];
        }, productionAnimalYields($conn, (int) $animal['cow_id'], 60)),
        'trend' => [
            'baseline_per_day' => $trend['baseline'],
            'latest' => $trend['latest'],
            'baseline_days' => DFMS_PRODUCTION_BASELINE_DAYS,
        ],
    ], 200, [
        'note' => 'Per-animal recorded yields only. Farm daily totals are a separate measurement '
            . 'and are never divided among animals.',
    ]);
}

// ---- herd summary ---------------------------------------------------------
$input = http_input();
$v = new DfmsValidator($input);
$days = $v->integer('days', ['nullable' => true, 'min' => 1, 'max' => 365]) ?? 30;
if ($v->fails()) {
    http_error('VALIDATION_ERROR', 'days must be a whole number between 1 and 365.', 422);
}

$to = farmToday();
$from = farmDateOffset(-($days - 1));

$summary = productionSummary($conn, $from, $to);

http_success([
    'from' => $summary['from'],
    'to'   => $summary['to'],
    'days' => $summary['days'],
    'unit' => dfms_canonical_unit('volume'),
    // The two sources, side by side and labelled. Never merged.
    'recorded' => $summary['recorded'],
    'farm_aggregate' => $summary['farm_aggregate'],
    'daily' => array_values(productionDailyTotals($conn, $from, $to)),
], 200, [
    'note' => '`recorded` sums per-animal readings; `farm_aggregate` is the separately recorded '
        . 'farm daily total. They measure different things and are not expected to match.',
]);
