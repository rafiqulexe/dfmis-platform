<?php
// ============================================================================
// LIVESTOCK DOMAIN — the Animal Digital Twin
//
// Reference data and domain operations for animals: classification,
// identifiers, lifecycle, parentage, location, transfers, ownership and the
// timeline.
//
// ARCHITECTURE
// The animal master record is the existing `cows` table (see migration 007 for
// why it was evolved rather than replaced). This file is the ONLY place that
// knows the rules of the animal domain — species lists, valid transitions,
// parentage constraints — so those rules cannot drift across module pages.
//
// SECURITY — reuses Phase 1, duplicates nothing
// Every read goes through tenantQuery()/tenantFind(); every write through
// tenantInsert()/tenantExec()/tenantTransaction(). Authorization stays with the
// caller via requireAuth(). No function here performs its own permission check,
// and no function accepts a farm id from the caller.
// ============================================================================

require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/repository.php';
require_once __DIR__ . '/settings.php';

// ---------------------------------------------------------------------------
// CLASSIFICATION
//
// Species is a closed registry rather than an ENUM so adding one is a code
// change, not a migration. Each entry declares the vocabulary that species
// actually uses — a goat has kids, not calves — so labels stop being
// hardcoded per page.
//
// `dairy_capable` records whether milk recording is meaningful. It is NOT a
// permission and NOT a farm capability (see includes/farm_types.php for those);
// it exists so a future poultry or beef record does not show a lactation field.
// ---------------------------------------------------------------------------
const DFMS_SPECIES = [
    'cattle' => [
        'label'         => 'Cattle',
        'young_label'   => 'Calf',
        'female_label'  => 'Cow',
        'male_label'    => 'Bull',
        'dairy_capable' => true,
        'gestation_days' => 283,
    ],
    'buffalo' => [
        'label'         => 'Buffalo',
        'young_label'   => 'Calf',
        'female_label'  => 'Buffalo cow',
        'male_label'    => 'Bull',
        'dairy_capable' => true,
        'gestation_days' => 310,
    ],
    'goat' => [
        'label'         => 'Goat',
        'young_label'   => 'Kid',
        'female_label'  => 'Doe',
        'male_label'    => 'Buck',
        'dairy_capable' => true,
        'gestation_days' => 150,
    ],
    'sheep' => [
        'label'         => 'Sheep',
        'young_label'   => 'Lamb',
        'female_label'  => 'Ewe',
        'male_label'    => 'Ram',
        'dairy_capable' => true,
        'gestation_days' => 147,
    ],
];

/**
 * Common breeds per species, offered as SUGGESTIONS only.
 *
 * `cows.breed` remains free text deliberately. Local and regional breeds are
 * endless, and a farm in a country this list does not cover must still be able
 * to record what it actually keeps. A closed list would either reject real
 * breeds or force users to mislabel their animals.
 */
const DFMS_BREED_SUGGESTIONS = [
    'cattle'  => ['Holstein Friesian', 'Jersey', 'Sahiwal', 'Red Sindhi', 'Gir', 'Pabna',
                  'Red Chittagong', 'North Bengal Grey', 'Brown Swiss', 'Angus', 'Hereford', 'Local'],
    'buffalo' => ['Murrah', 'Nili-Ravi', 'Surti', 'Jaffarabadi', 'Mediterranean', 'Local'],
    'goat'    => ['Black Bengal', 'Jamunapari', 'Boer', 'Saanen', 'Alpine', 'Beetal', 'Local'],
    'sheep'   => ['Garole', 'Dorper', 'Merino', 'Suffolk', 'Awassi', 'Local'],
];

function dfms_species(): array {
    return DFMS_SPECIES;
}

function dfms_is_valid_species(?string $species): bool {
    return $species !== null && isset(DFMS_SPECIES[$species]);
}

function dfms_species_label(?string $species): string {
    return DFMS_SPECIES[$species]['label'] ?? ucfirst((string) $species);
}

/** Species-correct word for an animal of this sex — "Cow", "Doe", "Ram". */
function dfms_animal_sex_label(?string $species, ?string $sex): string {
    if (!dfms_is_valid_species($species)) {
        return ucfirst((string) $sex);
    }
    return $sex === 'male'
        ? DFMS_SPECIES[$species]['male_label']
        : DFMS_SPECIES[$species]['female_label'];
}

function dfms_breed_suggestions(?string $species): array {
    return DFMS_BREED_SUGGESTIONS[$species] ?? [];
}

// ---------------------------------------------------------------------------
// IDENTIFIERS
//
// Countries and schemes differ, so the TYPE is data. `scoped` records whether a
// value is meaningful outside this farm — used only for labelling, never to
// widen a uniqueness constraint (see migration 007 on why uniqueness is
// per-farm).
// ---------------------------------------------------------------------------
const DFMS_IDENTIFIER_TYPES = [
    'ear_tag'       => ['label' => 'Ear tag',              'scoped' => 'farm'],
    'rfid'          => ['label' => 'RFID / EID',           'scoped' => 'national'],
    'microchip'     => ['label' => 'Microchip',            'scoped' => 'national'],
    'national_id'   => ['label' => 'National registration', 'scoped' => 'national'],
    'breed_registry' => ['label' => 'Breed registry / herd book', 'scoped' => 'registry'],
    'management'    => ['label' => 'Management number',    'scoped' => 'farm'],
    'other'         => ['label' => 'Other',                'scoped' => 'farm'],
];

const DFMS_IDENTIFIER_STATUSES = ['active', 'inactive', 'lost', 'replaced'];

function dfms_identifier_types(): array {
    return DFMS_IDENTIFIER_TYPES;
}

function dfms_is_valid_identifier_type(?string $type): bool {
    return $type !== null && isset(DFMS_IDENTIFIER_TYPES[$type]);
}

// ---------------------------------------------------------------------------
// LIFECYCLE
//
// `active` is the only state an animal can leave. Every other state is
// TERMINAL for day-to-day management: an animal that has been sold, has died or
// has been transferred away is no longer part of this farm's working herd.
//
// Terminal does not mean immutable — a mistake is corrected by recording a
// transition back to `active`, which leaves the error visible in the history
// rather than erasing it. That is why `archived` and the rest can return to
// `active` but not move sideways to each other: an animal cannot go from
// `sold` directly to `deceased` without first being un-sold, because that
// sequence would describe something that did not happen.
// ---------------------------------------------------------------------------
const DFMS_LIFECYCLE_STATUSES = [
    'active'      => ['label' => 'Active',      'terminal' => false, 'tone' => 'success'],
    'sold'        => ['label' => 'Sold',        'terminal' => true,  'tone' => 'info'],
    'transferred' => ['label' => 'Transferred', 'terminal' => true,  'tone' => 'info'],
    'deceased'    => ['label' => 'Deceased',    'terminal' => true,  'tone' => 'danger'],
    'retired'     => ['label' => 'Retired',     'terminal' => true,  'tone' => 'warning'],
    'archived'    => ['label' => 'Archived',    'terminal' => true,  'tone' => 'neutral'],
];

function dfms_lifecycle_statuses(): array {
    return DFMS_LIFECYCLE_STATUSES;
}

function dfms_is_valid_lifecycle_status(?string $status): bool {
    return $status !== null && isset(DFMS_LIFECYCLE_STATUSES[$status]);
}

/**
 * May an animal move from $from to $to?
 *
 * Rules, in order:
 *   - the target must exist
 *   - a no-op transition is refused (it would create a meaningless history row)
 *   - from `active`, any state is reachable
 *   - from a terminal state, ONLY `active` is reachable (correcting a mistake)
 */
function dfms_lifecycle_transition_allowed(?string $from, string $to): bool {
    if (!dfms_is_valid_lifecycle_status($to)) {
        return false;
    }
    if ($from === null) {
        return true;   // initial record
    }
    if (!dfms_is_valid_lifecycle_status($from) || $from === $to) {
        return false;
    }
    if ($from === 'active') {
        return true;
    }
    return $to === 'active';
}

/** Statuses reachable from $from, for building a <select> that cannot lie. */
function dfms_allowed_transitions(?string $from): array {
    $allowed = [];
    foreach (array_keys(DFMS_LIFECYCLE_STATUSES) as $status) {
        if (dfms_lifecycle_transition_allowed($from, $status)) {
            $allowed[$status] = DFMS_LIFECYCLE_STATUSES[$status]['label'];
        }
    }
    return $allowed;
}

// ---------------------------------------------------------------------------
// ACQUISITION, OWNERSHIP, DOCUMENTS
// ---------------------------------------------------------------------------
const DFMS_ACQUISITION_TYPES = [
    'born_on_farm'    => 'Born on this farm',
    'purchased'       => 'Purchased',
    'gifted'          => 'Gifted',
    'leased_in'       => 'Leased in',
    'transferred_in'  => 'Transferred from another farm',
    'other'           => 'Other',
];

const DFMS_BIRTH_TYPES = [
    'single'   => 'Single',
    'twin'     => 'Twin',
    'triplet'  => 'Triplet',
    'other'    => 'Other',
];

const DFMS_OWNERSHIP_TYPES = [
    'owned'       => 'Owned by this farm',
    'leased_in'   => 'Leased in',
    'leased_out'  => 'Leased out',
    'managed'     => 'Managed for a third party',
    'partnership' => 'Partnership',
    'boarding'    => 'Boarding / agistment',
];

const DFMS_DOCUMENT_TYPES = [
    'registration'  => 'Registration certificate',
    'vaccination'   => 'Vaccination certificate',
    'purchase'      => 'Purchase record',
    'health'        => 'Health document',
    'pedigree'      => 'Pedigree document',
    'insurance'     => 'Insurance document',
    'other'         => 'Other',
];

const DFMS_PHOTO_CATEGORIES = [
    'profile'        => 'Profile photo',
    'identification' => 'Identification',
    'observation'    => 'Health observation',
];

function dfms_is_valid_acquisition_type(?string $type): bool {
    return $type !== null && isset(DFMS_ACQUISITION_TYPES[$type]);
}

function dfms_is_valid_ownership_type(?string $type): bool {
    return $type !== null && isset(DFMS_OWNERSHIP_TYPES[$type]);
}

function dfms_is_valid_document_type(?string $type): bool {
    return $type !== null && isset(DFMS_DOCUMENT_TYPES[$type]);
}

// ===========================================================================
// ANIMAL RECORD
// ===========================================================================

/**
 * Load one animal, scoped to the active farm.
 *
 * Returns null for missing OR foreign — indistinguishable, as everywhere else,
 * so this cannot be used to probe another tenant's herd.
 */
function animalFind(PDO $conn, $animalId, bool $includeArchived = false): ?array {
    $extra = $includeArchived ? '' : 'deleted_at IS NULL';
    return tenantFind($conn, 'cows', 'cow_id', $animalId, $extra);
}

function animalFindOr404(PDO $conn, $animalId, bool $includeArchived = false): array {
    $extra = $includeArchived ? '' : 'deleted_at IS NULL';
    return tenantFindOr404($conn, 'cows', 'cow_id', $animalId, $extra);
}

/**
 * The animal's display name: its given name if it has one, otherwise its tag.
 * One definition, so a list, a profile and the timeline never disagree.
 */
function animalDisplayName(array $animal): string {
    $name = trim((string) ($animal['name'] ?? ''));
    return $name !== '' ? $name : (string) ($animal['unique_id'] ?? '');
}

/** Age in whole months, or null when the birth date is unknown. */
function animalAgeInMonths(?string $dateOfBirth): ?int {
    if ($dateOfBirth === null || $dateOfBirth === '' || $dateOfBirth === '0000-00-00') {
        return null;
    }
    try {
        $birth = new DateTimeImmutable($dateOfBirth);
        $now = new DateTimeImmutable(farmToday());
        if ($birth > $now) {
            return null;   // a future birth date is invalid, not a negative age
        }
        $diff = $birth->diff($now);
        return ($diff->y * 12) + $diff->m;
    } catch (Exception $e) {
        return null;
    }
}

/** Human age: "3 y 4 m", "7 months", or "Unknown". */
function animalAgeLabel(?string $dateOfBirth): string {
    $months = animalAgeInMonths($dateOfBirth);
    if ($months === null) {
        return 'Unknown';
    }
    if ($months < 24) {
        return $months . ' month' . ($months === 1 ? '' : 's');
    }
    $years = intdiv($months, 12);
    $remainder = $months % 12;
    return $remainder === 0 ? $years . ' years' : "$years y $remainder m";
}

// ===========================================================================
// IDENTIFIERS
// ===========================================================================

function animalIdentifiers(PDO $conn, int $animalId): array {
    return tenantQuery(
        $conn,
        "SELECT * FROM animal_identifiers WHERE {scope} AND cow_id = ?
          ORDER BY FIELD(status,'active','replaced','inactive','lost'), identifier_type",
        [$animalId]
    );
}

/**
 * Attach an identifier to an animal.
 *
 * The animal is re-resolved through animalFind() so a crafted cow_id cannot
 * attach an identifier to another tenant's animal. farm_id comes from
 * tenantInsert(), never the caller.
 *
 * @throws InvalidArgumentException on bad input
 * @throws RuntimeException         when the animal is not in this tenant
 */
function animalAddIdentifier(PDO $conn, int $animalId, array $data): int {
    if (!dfms_is_valid_identifier_type($data['identifier_type'] ?? null)) {
        throw new InvalidArgumentException('Unknown identifier type.');
    }
    $value = trim((string) ($data['identifier_value'] ?? ''));
    if ($value === '') {
        throw new InvalidArgumentException('Identifier value is required.');
    }
    $status = $data['status'] ?? 'active';
    if (!in_array($status, DFMS_IDENTIFIER_STATUSES, true)) {
        throw new InvalidArgumentException('Unknown identifier status.');
    }

    $animal = animalFind($conn, $animalId, true);
    if ($animal === null) {
        throw new RuntimeException('Animal not found.');
    }

    $id = tenantInsert($conn, 'animal_identifiers', [
        'cow_id'            => (int) $animal['cow_id'],
        'identifier_type'   => $data['identifier_type'],
        'identifier_value'  => $value,
        'issuing_authority' => $data['issuing_authority'] ?? null,
        'issued_on'         => $data['issued_on'] ?? null,
        'status'            => $status,
        'notes'             => $data['notes'] ?? null,
    ]);

    if (function_exists('auditLog')) {
        auditLog('animal.identifier_add', 'animal', (int) $animal['cow_id'], null, [
            'identifier_type'  => $data['identifier_type'],
            'identifier_value' => $value,
            'status'           => $status,
        ]);
    }
    return $id;
}

function animalRemoveIdentifier(PDO $conn, int $identifierId): bool {
    $identifier = tenantFind($conn, 'animal_identifiers', 'identifier_id', $identifierId);
    if ($identifier === null) {
        return false;
    }
    if (function_exists('auditLog')) {
        auditLog('animal.identifier_remove', 'animal', (int) $identifier['cow_id'], [
            'identifier_type'  => $identifier['identifier_type'],
            'identifier_value' => $identifier['identifier_value'],
        ], null);
    }
    tenantExec($conn, "DELETE FROM animal_identifiers WHERE identifier_id = ? AND {scope}", [$identifierId]);
    return true;
}

// ===========================================================================
// LIFECYCLE
// ===========================================================================

function animalLifecycleHistory(PDO $conn, int $animalId): array {
    return tenantQuery(
        $conn,
        "SELECT e.*, u.username
           FROM animal_lifecycle_events e
           LEFT JOIN admin_users u ON u.admin_id = e.recorded_by
          WHERE {scope} AND e.cow_id = ?
          ORDER BY e.event_date DESC, e.event_id DESC",
        [$animalId],
        'e'
    );
}

/**
 * Record a lifecycle transition and update the animal's current status.
 *
 * Both writes happen in ONE transaction: a status column that disagrees with
 * its own history is worse than either being missing.
 *
 * A terminal status also sets deleted_at, so the animal leaves the working herd
 * — that is what every existing list page already filters on, so no module had
 * to change. Returning to `active` clears it.
 *
 * @throws InvalidArgumentException on an invalid transition
 */
function animalChangeLifecycleStatus(PDO $conn, int $animalId, string $newStatus, array $options = []): bool {
    $animal = animalFindOr404($conn, $animalId, true);
    $current = $animal['lifecycle_status'] ?? 'active';

    if (!dfms_lifecycle_transition_allowed($current, $newStatus)) {
        throw new InvalidArgumentException(
            'An animal cannot move from ' . (DFMS_LIFECYCLE_STATUSES[$current]['label'] ?? $current)
            . ' to ' . (DFMS_LIFECYCLE_STATUSES[$newStatus]['label'] ?? $newStatus) . '.'
        );
    }

    $eventDate = $options['event_date'] ?? farmToday();
    $terminal = DFMS_LIFECYCLE_STATUSES[$newStatus]['terminal'];

    tenantTransaction($conn, function (PDO $conn) use ($animal, $current, $newStatus, $eventDate, $options, $terminal) {
        tenantInsert($conn, 'animal_lifecycle_events', [
            'cow_id'          => (int) $animal['cow_id'],
            'previous_status' => $current,
            'new_status'      => $newStatus,
            'event_date'      => $eventDate,
            'reason'          => $options['reason'] ?? null,
            'notes'           => $options['notes'] ?? null,
            'recorded_by'     => currentUserId(),
        ]);

        // deleted_at is the existing "not in the working herd" flag. Keeping it
        // in step with lifecycle_status is what lets every pre-Phase-3 query
        // keep working untouched.
        tenantExec(
            $conn,
            "UPDATE cows
                SET lifecycle_status = ?, status_changed_at = NOW(),
                    deleted_at = " . ($terminal ? 'COALESCE(deleted_at, NOW())' : 'NULL') . "
              WHERE cow_id = ? AND {scope}",
            [$newStatus, (int) $animal['cow_id']]
        );
    });

    if (function_exists('auditLog')) {
        auditLog('animal.lifecycle_change', 'animal', (int) $animal['cow_id'],
            ['lifecycle_status' => $current],
            ['lifecycle_status' => $newStatus, 'event_date' => $eventDate, 'reason' => $options['reason'] ?? null]
        );
    }
    return true;
}

// ===========================================================================
// PARENTAGE
// ===========================================================================

/**
 * Validate a proposed parent.
 *
 * @return array{ok: bool, error: ?string, parent: ?array}
 *
 * Checks, in order: the parent resolves inside this tenant; it is not the
 * animal itself; its sex matches the role; and it is not a descendant of the
 * animal (which would make the pedigree a cycle).
 *
 * Sex is enforced because a pedigree with a bull as dam is not a preference,
 * it is a data error that will corrupt every breeding calculation built on it.
 */
function animalValidateParent(PDO $conn, ?int $animalId, $parentId, string $role): array {
    if ($parentId === null || $parentId === '') {
        return ['ok' => true, 'error' => null, 'parent' => null];
    }
    if (!is_numeric($parentId)) {
        return ['ok' => false, 'error' => 'Invalid parent selection.', 'parent' => null];
    }
    $parentId = (int) $parentId;

    if ($animalId !== null && $parentId === $animalId) {
        return ['ok' => false, 'error' => 'An animal cannot be its own parent.', 'parent' => null];
    }

    // tenantFind: a parent id from a form can only ever resolve to an animal
    // this user can already see.
    $parent = animalFind($conn, $parentId, true);
    if ($parent === null) {
        return ['ok' => false, 'error' => 'That parent could not be found on this farm.', 'parent' => null];
    }

    $expectedSex = $role === 'dam' ? 'female' : 'male';
    if (($parent['sex'] ?? null) !== $expectedSex) {
        $word = $role === 'dam' ? 'mother' : 'father';
        return ['ok' => false, 'error' => "The selected animal cannot be the $word — its sex does not match.", 'parent' => null];
    }

    if ($animalId !== null && animalIsDescendantOf($conn, $parentId, $animalId)) {
        return ['ok' => false, 'error' => 'That animal is a descendant of this one, so it cannot also be its parent.', 'parent' => null];
    }

    return ['ok' => true, 'error' => null, 'parent' => $parent];
}

/**
 * Is $candidateId a descendant of $ancestorId?
 *
 * Walks up the candidate's ancestry. Depth-limited to 20 generations: that is
 * far beyond any real pedigree, and it guarantees termination even if existing
 * data already contains a cycle.
 */
function animalIsDescendantOf(PDO $conn, int $candidateId, int $ancestorId, int $depth = 0): bool {
    if ($depth > 20) {
        return false;
    }
    $candidate = animalFind($conn, $candidateId, true);
    if ($candidate === null) {
        return false;
    }
    foreach (['dam_id', 'sire_id'] as $parentField) {
        $parentId = $candidate[$parentField] ?? null;
        if ($parentId === null) {
            continue;
        }
        if ((int) $parentId === $ancestorId) {
            return true;
        }
        if (animalIsDescendantOf($conn, (int) $parentId, $ancestorId, $depth + 1)) {
            return true;
        }
    }
    return false;
}

/** Parents, resolved. Internal parents become records; external ones stay text. */
function animalParents(PDO $conn, array $animal): array {
    $parents = ['dam' => null, 'sire' => null, 'dam_external' => $animal['dam_external'] ?? null, 'sire_external' => $animal['sire_external'] ?? null];

    if (!empty($animal['dam_id'])) {
        $parents['dam'] = animalFind($conn, (int) $animal['dam_id'], true);
    }
    if (!empty($animal['sire_id'])) {
        $parents['sire'] = animalFind($conn, (int) $animal['sire_id'], true);
    }
    return $parents;
}

/** Offspring of an animal, within this tenant. */
function animalOffspring(PDO $conn, int $animalId): array {
    return tenantQuery(
        $conn,
        "SELECT * FROM cows WHERE {scope} AND (dam_id = ? OR sire_id = ?) ORDER BY date_of_birth DESC, unique_id",
        [$animalId, $animalId]
    );
}

// ===========================================================================
// TRANSFERS
// ===========================================================================

function animalTransferHistory(PDO $conn, int $animalId): array {
    return tenantQuery(
        $conn,
        "SELECT t.*, ff.name AS from_farm_name, tf.name AS to_farm_name,
                fl.name AS from_location_name, tl.name AS to_location_name, u.username
           FROM animal_transfers t
           LEFT JOIN farms ff ON ff.farm_id = t.from_farm_id
           LEFT JOIN farms tf ON tf.farm_id = t.to_farm_id
           LEFT JOIN locations fl ON fl.location_id = t.from_location_id
           LEFT JOIN locations tl ON tl.location_id = t.to_location_id
           LEFT JOIN admin_users u ON u.admin_id = t.recorded_by
          WHERE {scope} AND t.cow_id = ?
          ORDER BY t.transferred_on DESC, t.transfer_id DESC",
        [$animalId],
        't'
    );
}

/** Locations available on the active farm. */
function farmLocations(PDO $conn): array {
    return tenantQuery(
        $conn,
        "SELECT * FROM locations WHERE {scope} AND deleted_at IS NULL ORDER BY location_type, name"
    );
}

/**
 * Move an animal to another location on the SAME farm.
 *
 * The destination is resolved through tenantFind(), so a crafted location_id
 * cannot place an animal in another farm's barn.
 */
function animalMoveWithinFarm(PDO $conn, int $animalId, ?int $locationId, array $options = []): bool {
    $animal = animalFindOr404($conn, $animalId);

    $destination = null;
    if ($locationId !== null) {
        $destination = tenantFind($conn, 'locations', 'location_id', $locationId, 'deleted_at IS NULL');
        if ($destination === null) {
            throw new InvalidArgumentException('That location is not available on this farm.');
        }
    }

    $from = $animal['location_id'] === null ? null : (int) $animal['location_id'];
    if ($from === $locationId) {
        return false;   // no movement, no history row
    }

    tenantTransaction($conn, function (PDO $conn) use ($animal, $from, $locationId, $options) {
        tenantInsert($conn, 'animal_transfers', [
            'cow_id'           => (int) $animal['cow_id'],
            'transfer_type'    => 'internal',
            'from_farm_id'     => (int) $animal['farm_id'],
            'to_farm_id'       => (int) $animal['farm_id'],
            'from_location_id' => $from,
            'to_location_id'   => $locationId,
            'transferred_on'   => $options['transferred_on'] ?? farmToday(),
            'reason'           => $options['reason'] ?? null,
            'notes'            => $options['notes'] ?? null,
            'recorded_by'      => currentUserId(),
        ]);

        tenantExec($conn, "UPDATE cows SET location_id = ? WHERE cow_id = ? AND {scope}",
            [$locationId, (int) $animal['cow_id']]);
    });

    if (function_exists('auditLog')) {
        auditLog('animal.move', 'animal', (int) $animal['cow_id'],
            ['location_id' => $from], ['location_id' => $locationId]);
    }
    return true;
}

/**
 * Transfer an animal to ANOTHER FARM.
 *
 * This is the single most security-sensitive operation in the module: it
 * changes an animal's farm_id, which is its tenant key.
 *
 * Guards, all required:
 *   1. the animal must resolve inside the CURRENT tenant (animalFindOr404)
 *   2. the destination farm must be one the user is a member of
 *      (allowedFarmIds — the same live membership check requireAuth uses)
 *   3. the destination farm must be in the SAME organization, so an animal
 *      cannot be moved between customers even by someone who belongs to both
 *   4. everything happens in one transaction — a half-transferred animal would
 *      be visible to neither farm or to both
 *
 * The destination farm id is validated, never trusted.
 */
function animalTransferToFarm(PDO $conn, int $animalId, int $destinationFarmId, array $options = []): bool {
    $animal = animalFindOr404($conn, $animalId);
    $sourceFarmId = (int) $animal['farm_id'];

    if ($destinationFarmId === $sourceFarmId) {
        throw new InvalidArgumentException('That animal is already on this farm.');
    }

    // Guard 2 — membership, resolved live from farm_users.
    if (!in_array($destinationFarmId, allowedFarmIds(), true)) {
        if (function_exists('auditSecurityEvent')) {
            auditSecurityEvent('animal.transfer_denied', [
                'cow_id' => $animalId, 'destination_farm_id' => $destinationFarmId, 'reason' => 'not_a_member',
            ]);
        }
        throw new RuntimeException('You do not have access to that farm.');
    }

    // Guard 3 — same organization.
    $stmt = $conn->prepare("SELECT organization_id, name FROM farms WHERE farm_id = ?");
    $stmt->execute([$destinationFarmId]);
    $destination = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($destination === false) {
        throw new RuntimeException('That farm could not be found.');
    }
    if ((int) $destination['organization_id'] !== (int) currentOrganizationId()) {
        if (function_exists('auditSecurityEvent')) {
            auditSecurityEvent('animal.transfer_denied', [
                'cow_id' => $animalId, 'destination_farm_id' => $destinationFarmId, 'reason' => 'different_organization',
            ]);
        }
        throw new RuntimeException('An animal cannot be transferred to a different organization.');
    }

    $transferredOn = $options['transferred_on'] ?? farmToday();

    // Guard 4 — one transaction.
    //
    // The transfer row is written with farm_id = DESTINATION so the history
    // follows the animal (see migration 007). It is therefore inserted with an
    // explicit farm id rather than through tenantInsert(), which would scope it
    // to the source farm and lose the record the moment the animal moves.
    tenantTransaction($conn, function (PDO $conn) use ($animal, $sourceFarmId, $destinationFarmId, $transferredOn, $options) {
        $stmt = $conn->prepare(
            "INSERT INTO animal_transfers
                (farm_id, cow_id, transfer_type, from_farm_id, to_farm_id,
                 from_location_id, to_location_id, transferred_on, reason, notes, recorded_by)
             VALUES (?, ?, 'inter_farm', ?, ?, ?, NULL, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $destinationFarmId,
            (int) $animal['cow_id'],
            $sourceFarmId,
            $destinationFarmId,
            $animal['location_id'],
            $transferredOn,
            $options['reason'] ?? null,
            $options['notes'] ?? null,
            currentUserId(),
        ]);

        // The animal moves. location_id is cleared because a location belongs to
        // a farm — keeping it would point at a barn on the farm it just left.
        tenantExec(
            $conn,
            "UPDATE cows SET farm_id = ?, location_id = NULL WHERE cow_id = ? AND {scope}",
            [$destinationFarmId, (int) $animal['cow_id']]
        );
    });

    if (function_exists('auditLog')) {
        auditLog('animal.transfer', 'animal', (int) $animal['cow_id'],
            ['farm_id' => $sourceFarmId],
            ['farm_id' => $destinationFarmId, 'transferred_on' => $transferredOn, 'reason' => $options['reason'] ?? null]
        );
    }
    return true;
}

// ===========================================================================
// OWNERSHIP
// ===========================================================================

function animalOwnershipHistory(PDO $conn, int $animalId): array {
    return tenantQuery(
        $conn,
        "SELECT o.*, u.username FROM animal_ownership o
           LEFT JOIN admin_users u ON u.admin_id = o.recorded_by
          WHERE {scope} AND o.cow_id = ?
          ORDER BY o.effective_from DESC, o.ownership_id DESC",
        [$animalId],
        'o'
    );
}

function animalCurrentOwnership(PDO $conn, int $animalId): ?array {
    $rows = tenantQuery(
        $conn,
        "SELECT * FROM animal_ownership WHERE {scope} AND cow_id = ? AND effective_to IS NULL
          ORDER BY effective_from DESC LIMIT 1",
        [$animalId]
    );
    return $rows ? $rows[0] : null;
}

/**
 * Record an ownership change.
 *
 * Closes the open period before opening a new one, in one transaction, so an
 * animal never has two concurrent "current" owners.
 */
function animalRecordOwnership(PDO $conn, int $animalId, array $data): int {
    if (!dfms_is_valid_ownership_type($data['ownership_type'] ?? null)) {
        throw new InvalidArgumentException('Unknown ownership type.');
    }
    $animal = animalFindOr404($conn, $animalId, true);
    $effectiveFrom = $data['effective_from'] ?? farmToday();
    $previous = animalCurrentOwnership($conn, $animalId);

    $newId = tenantTransaction($conn, function (PDO $conn) use ($animal, $data, $effectiveFrom, $previous) {
        if ($previous !== null) {
            tenantExec(
                $conn,
                "UPDATE animal_ownership SET effective_to = ? WHERE ownership_id = ? AND {scope}",
                [$effectiveFrom, (int) $previous['ownership_id']]
            );
        }
        return tenantInsert($conn, 'animal_ownership', [
            'cow_id'         => (int) $animal['cow_id'],
            'ownership_type' => $data['ownership_type'],
            'owner_name'     => $data['owner_name'] ?? null,
            'effective_from' => $effectiveFrom,
            'effective_to'   => null,
            'notes'          => $data['notes'] ?? null,
            'recorded_by'    => currentUserId(),
        ]);
    });

    if (function_exists('auditLog')) {
        auditLog('animal.ownership_change', 'animal', (int) $animal['cow_id'],
            $previous === null ? null : ['ownership_type' => $previous['ownership_type'], 'owner_name' => $previous['owner_name']],
            ['ownership_type' => $data['ownership_type'], 'owner_name' => $data['owner_name'] ?? null, 'effective_from' => $effectiveFrom]
        );
    }
    return $newId;
}

// ===========================================================================
// FILES — documents and photos
//
// These are THIN wrappers over the Phase 1 storage layer. They add the
// animal link and the document type; they do not re-implement validation,
// MIME sniffing, path containment or access control.
// ===========================================================================

function animalFiles(PDO $conn, int $animalId, ?string $category = null): array {
    $sql = "SELECT f.*, u.username FROM stored_files f
              LEFT JOIN admin_users u ON u.admin_id = f.uploaded_by
             WHERE {scope} AND f.entity_type = 'animal' AND f.entity_id = ? AND f.deleted_at IS NULL";
    $params = [$animalId];
    if ($category !== null) {
        $sql .= " AND f.category = ?";
        $params[] = $category;
    }
    $sql .= " ORDER BY f.created_at DESC";
    return tenantQuery($conn, $sql, $params, 'f');
}

function animalPhotos(PDO $conn, int $animalId): array {
    return animalFiles($conn, $animalId, 'animal_photos');
}

function animalDocuments(PDO $conn, int $animalId): array {
    return animalFiles($conn, $animalId, 'documents');
}

/**
 * Apply animal-specific metadata to a file already stored by storeUploadedImage().
 *
 * The file row is re-resolved through tenantFind() first, so this cannot label
 * another tenant's file.
 */
function animalTagFile(PDO $conn, int $fileId, int $animalId, array $meta = []): bool {
    $file = tenantFind($conn, 'stored_files', 'file_id', $fileId, 'deleted_at IS NULL');
    if ($file === null) {
        return false;
    }
    tenantExec(
        $conn,
        "UPDATE stored_files SET entity_type = 'animal', entity_id = ?, document_type = ?, caption = ?, captured_on = ?
          WHERE file_id = ? AND {scope}",
        [$animalId, $meta['document_type'] ?? null, $meta['caption'] ?? null, $meta['captured_on'] ?? null, $fileId]
    );
    return true;
}

// ===========================================================================
// TIMELINE
//
// A UNION over the tables that already hold the events — NOT a duplicate
// event table. Two copies of the same fact drift, and the copy is always the
// one that is wrong.
//
// This is a BUSINESS timeline, distinct from the audit log: the audit log
// records who touched what for accountability and includes security events;
// the timeline records what happened to the animal, for the person looking
// after it.
// ===========================================================================

/**
 * Chronological events for one animal.
 *
 * @return array{items: array, total: int}
 *
 * Every branch is tenant-scoped through tenantQuery(). Paginated in PHP after
 * merging, because the sources have different shapes and a SQL UNION across
 * five tables with different columns would be less readable and no faster at
 * the row counts involved.
 */
function animalTimeline(PDO $conn, int $animalId, int $page = 1, int $perPage = 25): array {
    $events = [];

    // Creation. Only when known — created_at is NULL for records that predate
    // migration 007, and inventing a date would be fiction.
    $animal = animalFind($conn, $animalId, true);
    if ($animal !== null && !empty($animal['created_at'])) {
        $events[] = [
            'occurred_at' => $animal['created_at'],
            'date'        => substr((string) $animal['created_at'], 0, 10),
            'type'        => 'created',
            'title'       => 'Animal registered',
            'detail'      => 'Added to the herd as ' . $animal['unique_id'],
            'tone'        => 'success',
            'link'        => null,
        ];
    }

    foreach (animalLifecycleHistory($conn, $animalId) as $event) {
        $label = DFMS_LIFECYCLE_STATUSES[$event['new_status']]['label'] ?? $event['new_status'];
        $events[] = [
            'occurred_at' => $event['event_date'] . ' 00:00:00',
            'date'        => $event['event_date'],
            'type'        => 'lifecycle',
            'title'       => 'Status changed to ' . $label,
            'detail'      => trim(($event['reason'] ?? '') . ' ' . ($event['notes'] ?? '')) ?: null,
            'tone'        => DFMS_LIFECYCLE_STATUSES[$event['new_status']]['tone'] ?? 'neutral',
            'actor'       => $event['username'] ?? null,
            'link'        => null,
        ];
    }

    foreach (animalTransferHistory($conn, $animalId) as $transfer) {
        $isInternal = $transfer['transfer_type'] === 'internal';
        $events[] = [
            'occurred_at' => $transfer['transferred_on'] . ' 00:00:00',
            'date'        => $transfer['transferred_on'],
            'type'        => 'transfer',
            'title'       => $isInternal ? 'Moved within the farm' : 'Transferred between farms',
            'detail'      => $isInternal
                ? trim(($transfer['from_location_name'] ?? 'Unassigned') . ' → ' . ($transfer['to_location_name'] ?? 'Unassigned'))
                : trim(($transfer['from_farm_name'] ?? '?') . ' → ' . ($transfer['to_farm_name'] ?? '?')),
            'tone'        => 'info',
            'actor'       => $transfer['username'] ?? null,
            'link'        => null,
        ];
    }

    foreach (animalOwnershipHistory($conn, $animalId) as $ownership) {
        $events[] = [
            'occurred_at' => $ownership['effective_from'] . ' 00:00:00',
            'date'        => $ownership['effective_from'],
            'type'        => 'ownership',
            'title'       => 'Ownership: ' . (DFMS_OWNERSHIP_TYPES[$ownership['ownership_type']] ?? $ownership['ownership_type']),
            'detail'      => $ownership['owner_name'] ?: null,
            'tone'        => 'neutral',
            'actor'       => $ownership['username'] ?? null,
            'link'        => null,
        ];
    }

    // Health events — the existing module, referenced not duplicated.
    foreach (tenantQuery(
        $conn,
        "SELECT h.* FROM health_events h WHERE {scope} AND h.cow_id = ? ORDER BY h.event_date DESC",
        [$animalId],
        'h'
    ) as $health) {
        $events[] = [
            'occurred_at' => $health['event_date'] . ' 00:00:00',
            'date'        => $health['event_date'],
            'type'        => 'health',
            'title'       => $health['event_type'],
            'detail'      => $health['description'] ?: null,
            'tone'        => $health['severity'] === 'urgent' ? 'danger'
                            : ($health['severity'] === 'moderate' ? 'warning' : 'neutral'),
            'link'        => 'modules/health/',
        ];
    }

    // Files.
    foreach (animalFiles($conn, $animalId) as $file) {
        $isPhoto = $file['category'] === 'animal_photos';
        $events[] = [
            'occurred_at' => $file['created_at'],
            'date'        => substr((string) $file['created_at'], 0, 10),
            'type'        => $isPhoto ? 'photo' : 'document',
            'title'       => $isPhoto ? 'Photo added' : 'Document added',
            'detail'      => $file['caption'] ?: ($file['original_name'] ?: null),
            'tone'        => 'neutral',
            'actor'       => $file['username'] ?? null,
            'link'        => 'modules/files/serve.php?id=' . (int) $file['file_id'],
        ];
    }

    // NOTE: milk production is deliberately absent. milk_production records a
    // FARM-level daily total and carries no cow_id, so there is no per-animal
    // production event to show. Per-animal yield is Phase 8; claiming it here
    // would mean attributing the whole herd's output to every animal.

    usort($events, function ($a, $b) {
        return strcmp((string) $b['occurred_at'], (string) $a['occurred_at']);
    });

    $total = count($events);
    $perPage = max(1, min($perPage, 100));
    $page = max(1, $page);
    $offset = ($page - 1) * $perPage;

    return ['items' => array_slice($events, $offset, $perPage), 'total' => $total];
}

// ===========================================================================
// SEARCH & LISTING
// ===========================================================================

/**
 * Search and filter animals on the ACTIVE farm.
 *
 * Every query is tenant-scoped through tenantQuery(); there is no code path
 * that reads animals across farms.
 *
 * @param array $filters search, species, sex, lifecycle_status, location_id, breed
 * @return array{items: array, total: int, pages: int, page: int}
 */
function animalSearch(PDO $conn, array $filters = [], int $page = 1, int $perPage = 25): array {
    $where = '';
    $params = [];

    // Archived animals are hidden unless explicitly requested.
    if (empty($filters['include_archived'])) {
        $where .= ' AND c.deleted_at IS NULL';
    }

    $search = trim((string) ($filters['search'] ?? ''));
    if ($search !== '') {
        // Matches tag, name, breed, or any identifier value. The identifier
        // subquery is scoped to the same farm, so a search can never confirm
        // that an RFID exists on another tenant's animal.
        $where .= " AND (c.unique_id LIKE ? OR c.name LIKE ? OR c.breed LIKE ?
                    OR EXISTS (SELECT 1 FROM animal_identifiers i
                                WHERE i.cow_id = c.cow_id AND i.farm_id = c.farm_id
                                  AND i.identifier_value LIKE ?))";
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
    }

    foreach ([
        'species'          => 'c.species',
        'sex'              => 'c.sex',
        'lifecycle_status' => 'c.lifecycle_status',
        'breed'            => 'c.breed',
    ] as $filter => $column) {
        if (!empty($filters[$filter])) {
            $where .= " AND $column = ?";
            $params[] = $filters[$filter];
        }
    }

    if (!empty($filters['location_id'])) {
        $where .= ' AND c.location_id = ?';
        $params[] = (int) $filters['location_id'];
    }

    $total = (int) tenantScalar(
        $conn,
        "SELECT COUNT(*) FROM cows c WHERE {scope}" . $where,
        $params,
        0,
        'c'
    );

    $perPage = max(1, min($perPage, 100));
    $pages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($page, $pages));
    $offset = ($page - 1) * $perPage;

    $items = tenantQuery(
        $conn,
        "SELECT c.*, l.name AS location_name
           FROM cows c
           LEFT JOIN locations l ON l.location_id = c.location_id AND l.farm_id = c.farm_id
          WHERE {scope}" . $where . "
          ORDER BY c.unique_id
          LIMIT " . (int) $perPage . " OFFSET " . (int) $offset,
        $params,
        'c'
    );

    return ['items' => $items, 'total' => $total, 'pages' => $pages, 'page' => $page];
}

/** Distinct breeds actually present on this farm, for the filter dropdown. */
function farmBreeds(PDO $conn): array {
    $rows = tenantQuery(
        $conn,
        "SELECT DISTINCT breed FROM cows WHERE {scope} AND breed IS NOT NULL AND breed <> '' ORDER BY breed"
    );
    return array_column($rows, 'breed');
}

/**
 * Everything the profile page needs, in one call.
 *
 * Summaries and references — NOT a copy of every related record. The profile
 * links to the modules that own the detail.
 */
function animalProfile(PDO $conn, int $animalId): array {
    $animal = animalFindOr404($conn, $animalId, true);

    $recentHealth = tenantQuery(
        $conn,
        "SELECT h.* FROM health_events h WHERE {scope} AND h.cow_id = ?
          ORDER BY h.event_date DESC LIMIT 5",
        [$animalId],
        'h'
    );

    $location = null;
    if (!empty($animal['location_id'])) {
        $location = tenantFind($conn, 'locations', 'location_id', (int) $animal['location_id']);
    }

    return [
        'animal'       => $animal,
        'identifiers'  => animalIdentifiers($conn, $animalId),
        'parents'      => animalParents($conn, $animal),
        'offspring'    => animalOffspring($conn, $animalId),
        'location'     => $location,
        'ownership'    => animalCurrentOwnership($conn, $animalId),
        'recentHealth' => $recentHealth,
        'photos'       => animalPhotos($conn, $animalId),
        'documents'    => animalDocuments($conn, $animalId),
        'lifecycle'    => animalLifecycleHistory($conn, $animalId),
    ];
}
