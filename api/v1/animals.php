<?php
// ============================================================================
// GET /api/v1/animals        list, with search and filters
// GET /api/v1/animals/{id}   one animal
//
// Requires cows.view. READ ONLY — no write endpoints in this phase.
//
// This handler contains NO business logic. It calls animalSearch() and
// animalProfile() from includes/livestock.php, the same functions the HTML
// pages use, so the API cannot drift from the UI or acquire its own
// interpretation of tenant scope.
// ============================================================================

if (!defined('DFMS_API_DISPATCHED')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../includes/livestock.php';

$conn = getDBConnection();
$input = http_input();

// A trailing path segment selects one animal: /v1/animals/12
$animalId = $GLOBALS['dfms_route_param'] ?? null;

if ($animalId !== null) {
    // ---- single animal ----------------------------------------------------
    $v = new DfmsValidator(['id' => $animalId]);
    $id = $v->id('id');
    if ($v->fails()) {
        // Malformed and foreign ids are indistinguishable, as everywhere else.
        http_error('NOT_FOUND', 'Animal not found.', 404);
    }

    // Returns null for missing OR another tenant's animal.
    $animal = animalFind($conn, $id, true);
    if ($animal === null) {
        http_error('NOT_FOUND', 'Animal not found.', 404);
    }

    $profile = animalProfile($conn, (int) $animal['cow_id']);

    http_success([
        'animal' => dfms_api_animal($profile['animal']),
        'identifiers' => array_map(static function (array $identifier): array {
            return [
                'type'      => $identifier['identifier_type'],
                'value'     => $identifier['identifier_value'],
                'authority' => $identifier['issuing_authority'],
                'status'    => $identifier['status'],
            ];
        }, $profile['identifiers']),
        'parentage' => [
            // Parent ids are exposed only for parents inside this tenant; an
            // external parent is a name, never a reference to another farm.
            'dam'  => $profile['parents']['dam'] === null ? null : dfms_api_animal_ref($profile['parents']['dam']),
            'sire' => $profile['parents']['sire'] === null ? null : dfms_api_animal_ref($profile['parents']['sire']),
            'dam_external'  => $profile['parents']['dam_external'],
            'sire_external' => $profile['parents']['sire_external'],
        ],
        'offspring_count' => count($profile['offspring']),
        'location' => $profile['location'] === null ? null : [
            'id'   => (int) $profile['location']['location_id'],
            'name' => $profile['location']['name'],
            'type' => $profile['location']['location_type'],
        ],
        'ownership' => $profile['ownership'] === null ? null : [
            'type'           => $profile['ownership']['ownership_type'],
            'owner_name'     => $profile['ownership']['owner_name'],
            'effective_from' => $profile['ownership']['effective_from'],
        ],
        // Counts, not contents: file bytes and stored paths are never exposed.
        'photo_count'    => count($profile['photos']),
        'document_count' => count($profile['documents']),
    ]);
}

// ---- list -----------------------------------------------------------------
$v = new DfmsValidator($input);
$filters = [
    'search'           => $v->string('search', 0, 100, ['nullable' => true]) ?? '',
    'species'          => $v->enum('species', array_keys(DFMS_SPECIES), ['nullable' => true]),
    'sex'              => $v->enum('sex', ['female', 'male'], ['nullable' => true]),
    'lifecycle_status' => $v->enum('status', array_keys(DFMS_LIFECYCLE_STATUSES), ['nullable' => true]),
];
$page = $v->integer('page', ['nullable' => true, 'min' => 1, 'max' => 100000]) ?? 1;
$perPage = $v->integer('per_page', ['nullable' => true, 'min' => 1, 'max' => 100]) ?? 25;

if ($v->fails()) {
    http_validation_failed($v->errors());
}

// Tenant-scoped by construction — animalSearch() has no cross-farm code path.
$result = animalSearch($conn, $filters, $page, $perPage);

http_success(
    array_map('dfms_api_animal', $result['items']),
    200,
    [
        'page'     => $result['page'],
        'per_page' => $perPage,
        'total'    => $result['total'],
        'pages'    => $result['pages'],
    ]
);

// ---------------------------------------------------------------------------
// Serialisers.
//
// An explicit allow-list of fields. Returning the raw row would leak whatever a
// future migration happens to add, which is how internal columns end up in a
// public payload by accident.
// ---------------------------------------------------------------------------
function dfms_api_animal(array $animal): array
{
    return [
        'id'               => (int) $animal['cow_id'],
        'tag'              => $animal['unique_id'],
        'name'             => $animal['name'],
        'species'          => $animal['species'],
        'breed'            => $animal['breed'],
        'sex'              => $animal['sex'],
        'date_of_birth'    => $animal['date_of_birth'],
        'age_months'       => animalAgeInMonths($animal['date_of_birth'] ?? null),
        'lifecycle_status' => $animal['lifecycle_status'],
        'health_status'    => $animal['health_status'],
        'acquisition_type' => $animal['acquisition_type'] ?? null,
        // farm_id is deliberately ABSENT: the caller's farm is implied by their
        // session, and echoing a tenant key invites clients to send one back.
    ];
}

function dfms_api_animal_ref(array $animal): array
{
    return [
        'id'   => (int) $animal['cow_id'],
        'tag'  => $animal['unique_id'],
        'name' => $animal['name'],
    ];
}
