<?php
// ============================================================================
// GET /api/v1/health-cases        the farm's health case list, filtered
// GET /api/v1/health-cases/{id}   one case with its clinical detail
//
// Requires health.view. READ ONLY.
//
// Calls healthSearchCases() and healthCaseDetail() — the same functions the
// HTML module uses — so there is no second definition of tenant scope and no
// way for the API to return a case the UI would hide.
// ============================================================================

if (!defined('DFMS_API_DISPATCHED')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../includes/livestock.php';
require_once __DIR__ . '/../../includes/health.php';

$conn = getDBConnection();
$input = http_input();

$caseIdParam = $GLOBALS['dfms_route_param'] ?? null;

if ($caseIdParam !== null) {
    // ---- single case ------------------------------------------------------
    $v = new DfmsValidator(['id' => $caseIdParam]);
    $id = $v->id('id');
    if ($v->fails()) {
        http_error('NOT_FOUND', 'Case not found.', 404);
    }

    // Returns null for missing OR another tenant's case — indistinguishable.
    $case = healthFindCase($conn, (int) $id);
    if ($case === null) {
        http_error('NOT_FOUND', 'Case not found.', 404);
    }

    $detail = healthCaseDetail($conn, (int) $case['case_id']);

    http_success([
        'case' => [
            'id'           => (int) $detail['case']['case_id'],
            'reference'    => $detail['case']['case_reference'],
            'title'        => $detail['case']['title'],
            'type'         => $detail['case']['case_type'],
            'severity'     => $detail['case']['severity'],
            'status'       => $detail['case']['status'],
            'outcome'      => $detail['case']['outcome'],
            'opened_on'    => $detail['case']['opened_on'],
            'closed_on'    => $detail['case']['closed_on'],
            'follow_up_on' => $detail['case']['follow_up_on'],
            'is_contagious' => (bool) $detail['case']['is_contagious'],
        ],
        'animal' => [
            'id'      => (int) $detail['animal']['cow_id'],
            'tag'     => $detail['animal']['unique_id'],
            'name'    => $detail['animal']['name'],
            'species' => $detail['animal']['species'],
        ],
        'symptoms' => array_map(static function (array $symptom): array {
            return [
                'code'     => $symptom['symptom_code'],
                'label'    => dfms_symptom_label($symptom),
                'severity' => $symptom['severity'],
                'onset_at' => $symptom['onset_at'],
            ];
        }, $detail['symptoms']),
        'diagnoses' => array_map(static function (array $diagnosis): array {
            return [
                'code'   => $diagnosis['condition_code'],
                'label'  => dfms_condition_label($diagnosis),
                'status' => $diagnosis['status'],
                // The RECORDING CLINICIAN'S stated confidence. Never computed by
                // DFMIS, and a client must not present it as a system score.
                'stated_confidence' => $diagnosis['confidence'],
                'diagnosed_on'      => $diagnosis['diagnosed_on'],
            ];
        }, $detail['diagnoses']),
        'treatments' => array_map(static function (array $treatment): array {
            return [
                'id'         => (int) $treatment['treatment_id'],
                'name'       => $treatment['name'],
                'type'       => $treatment['treatment_type'],
                'status'     => $treatment['status'],
                'outcome'    => $treatment['outcome'],
                'started_on' => $treatment['started_on'],
                'ended_on'   => $treatment['ended_on'],
            ];
        }, $detail['treatments']),
        'medications' => array_map(static function (array $medication): array {
            return [
                'name'            => $medication['medication_name'],
                'dose'            => (float) $medication['dose'],
                'dose_unit'       => $medication['dose_unit'],
                'route'           => $medication['route'],
                'batch_lot'       => $medication['batch_lot'],
                'administered_at' => $medication['administered_at'],
            ];
        }, $detail['medications']),
        'consultations' => array_map(static function (array $consultation): array {
            return [
                'veterinarian' => $consultation['vet_name'],
                'practice'     => $consultation['vet_practice'],
                'type'         => $consultation['consultation_type'],
                'consulted_at' => $consultation['consulted_at'],
                'follow_up_on' => $consultation['follow_up_on'],
                // vet_contact and vet_registration are DELIBERATELY ABSENT.
                // They are a third party's personal contact details and no API
                // consumer has a demonstrated need for them; the HTML record
                // shows them to the farm that entered them.
            ];
        }, $detail['consultations']),
        'lab_tests' => array_map(static function (array $test): array {
            return [
                'type'            => $test['test_type'],
                'name'            => $test['test_name'],
                'laboratory'      => $test['laboratory_name'],
                'sample_taken_on' => $test['sample_taken_on'],
                'result_status'   => $test['result_status'],
                'result_code'     => $test['result_code'],
                'result_numeric'  => $test['result_numeric'] !== null ? (float) $test['result_numeric'] : null,
                'result_unit'     => $test['result_unit'],
                // Report FILES are never exposed here — bytes and stored paths
                // are reachable only through modules/files/serve.php, which
                // re-checks tenancy and the files.view permission.
            ];
        }, $detail['lab_tests']),
    ]);
}

// ---- list -----------------------------------------------------------------
$v = new DfmsValidator($input);
$filters = [
    'search'    => $v->string('search', 0, 100, ['nullable' => true]) ?? '',
    'status'    => $v->string('status', 0, 20, ['nullable' => true]) ?? 'active',
    'case_type' => $v->enum('case_type', array_keys(DFMS_CASE_TYPES), ['nullable' => true]),
    'severity'  => $v->enum('severity', DFMS_CASE_SEVERITIES, ['nullable' => true]),
    'cow_id'    => $v->id('cow_id', ['nullable' => true]),
];
$page = $v->integer('page', ['nullable' => true, 'min' => 1, 'max' => 100000]) ?? 1;
$perPage = $v->integer('per_page', ['nullable' => true, 'min' => 1, 'max' => 100]) ?? 25;

if ($v->fails()) {
    http_validation_failed($v->errors());
}

// Tenant-scoped by construction — healthSearchCases() has no cross-farm path.
$result = healthSearchCases($conn, $filters, $page, $perPage);

http_success(
    array_map(static function (array $case): array {
        return [
            'id'        => (int) $case['case_id'],
            'reference' => $case['case_reference'],
            'title'     => $case['title'],
            'type'      => $case['case_type'],
            'severity'  => $case['severity'],
            'status'    => $case['status'],
            'opened_on' => $case['opened_on'],
            'animal'    => [
                'id'      => (int) $case['cow_id'],
                'tag'     => $case['unique_id'],
                'name'    => $case['name'],
                'species' => $case['species'],
            ],
        ];
    }, $result['items']),
    200,
    [
        'page'     => $result['page'],
        'per_page' => $perPage,
        'total'    => $result['total'],
        'pages'    => $result['pages'],
    ]
);
