<?php
// ============================================================================
// Migration 007 — Animal Digital Twin foundation
//
// EVOLVES the existing `cows` table into the animal master record. It does NOT
// replace it.
//
// WHY NOT A NEW `animals` TABLE
// `cows` already carries farm_id, location_id, sex, breed, date_of_birth,
// deleted_at and foreign keys to farms and locations — it IS the animal master
// record, just under a legacy name. Renaming it would break 12 source files and
// two foreign keys (alerts.cow_id, health_events.cow_id) for no functional
// gain, and the brief is explicit: prefer evolution over replacement. The name
// is legacy; the structure is sound.
//
// WHAT THIS ADDS
//   cows                       classification, lifecycle, acquisition, parentage
//   animal_identifiers         RFID / registry / microchip / national ID
//   animal_lifecycle_events    state transition history
//   animal_transfers           movement, internal and inter-farm
//   animal_ownership           ownership history
//
// WHAT THIS DELIBERATELY DOES NOT ADD
//   - a documents or photos table: stored_files (migration 005) already does
//     this, tenant-scoped, with entity_type/entity_id and an access-controlled
//     read path. A second file system would be a security regression.
//   - a timeline table: the timeline is a QUERY across existing tables.
//     Duplicating every event would create two sources of truth that drift.
//   - a locations table: migration 004 already created one.
//
// NON-DESTRUCTIVE. No column is dropped or renamed, no row is deleted, and no
// historical value is reinterpreted. Every new column is NULL or carries a
// default that is factually true of the existing rows.
// ============================================================================

return function (PDO $conn): array {
    $log = [];

    // -----------------------------------------------------------------------
    // Pre-flight.
    // -----------------------------------------------------------------------
    if (!schemaTableExists($conn, 'cows')) {
        throw new RuntimeException('cows table is missing — run earlier migrations first.');
    }

    // -----------------------------------------------------------------------
    // CLASSIFICATION — multi-species readiness.
    //
    // `species` defaults to 'cattle'. That is a statement of FACT about the
    // existing rows, not an invention: the table is `cows`, the application is
    // a dairy system, and every seeded breed (Pabna, Sahiwal, Red Chittagong,
    // Holstein Friesian, North Bengal Grey) is a cattle breed. Backfilling
    // anything else would be fabrication; leaving it NULL would make every
    // existing animal unclassified for no reason.
    //
    // Species is a VARCHAR validated against a PHP registry rather than an
    // ENUM, so adding a species later is a code change, not a schema lock.
    // `breed` stays free text: legacy values are real breeds, and an ENUM or a
    // strict FK would either reject them or require inventing a mapping.
    // -----------------------------------------------------------------------
    $log[] = schemaAddColumn($conn, 'cows', 'species', "VARCHAR(32) NOT NULL DEFAULT 'cattle' COMMENT 'validated against DFMS_SPECIES in includes/livestock.php' AFTER farm_id");
    $log[] = schemaAddColumn($conn, 'cows', 'breed_registry_id', "VARCHAR(100) NULL COMMENT 'breed society / herd book identifier' AFTER breed");

    // -----------------------------------------------------------------------
    // LIFECYCLE.
    //
    // Until now an animal was either present or soft-deleted (deleted_at). That
    // conflates five different outcomes: sold, transferred away, died, retired,
    // or simply archived by mistake.
    //
    // Backfill is honest: a row with deleted_at set WAS archived, so it becomes
    // 'archived'. Nothing is guessed about WHY it was archived — inventing
    // 'sold' or 'deceased' would put fiction into a permanent record.
    //
    // deleted_at is KEPT and still drives every existing query. lifecycle_status
    // adds meaning alongside it rather than replacing it, so no module breaks.
    // -----------------------------------------------------------------------
    $log[] = schemaAddColumn($conn, 'cows', 'lifecycle_status',
        "ENUM('active','sold','transferred','deceased','retired','archived') NOT NULL DEFAULT 'active' AFTER lactation_status");
    $log[] = schemaAddColumn($conn, 'cows', 'status_changed_at', 'TIMESTAMP NULL DEFAULT NULL AFTER lifecycle_status');

    if (schemaColumnExists($conn, 'cows', 'deleted_at') && schemaColumnExists($conn, 'cows', 'lifecycle_status')) {
        $backfilled = $conn->exec(
            "UPDATE cows SET lifecycle_status = 'archived'
              WHERE deleted_at IS NOT NULL AND lifecycle_status = 'active'"
        );
        $log[] = "  DATA  lifecycle_status: {$backfilled} archived row(s) marked 'archived' (reason NOT invented)";
    }

    // -----------------------------------------------------------------------
    // BIRTH & ACQUISITION.
    //
    // All NULL for existing rows. How an animal already in the system was
    // acquired is genuinely unknown, and 'purchased' or 'born_on_farm' would be
    // a guess recorded as a fact.
    // -----------------------------------------------------------------------
    $log[] = schemaAddColumn($conn, 'cows', 'birth_type', "ENUM('single','twin','triplet','other') NULL AFTER date_of_birth");
    $log[] = schemaAddColumn($conn, 'cows', 'acquisition_type',
        "ENUM('born_on_farm','purchased','gifted','leased_in','transferred_in','other') NULL COMMENT 'NULL = unknown for historical records' AFTER birth_type");
    $log[] = schemaAddColumn($conn, 'cows', 'acquisition_date', 'DATE NULL AFTER acquisition_type');
    $log[] = schemaAddColumn($conn, 'cows', 'acquisition_source', "VARCHAR(150) NULL COMMENT 'seller, market or originating farm' AFTER acquisition_date");

    // -----------------------------------------------------------------------
    // PARENTAGE.
    //
    // A parent may be INSIDE DFMIS (dam_id / sire_id) or outside it
    // (dam_external / sire_external). An external sire must never be forced to
    // exist as a fake internal animal — that would pollute the herd count, the
    // dashboard and every list page with animals the farm does not own.
    //
    // ON DELETE SET NULL: removing a parent must not cascade away its
    // offspring. Losing a pedigree link is recoverable; losing the calf is not.
    //
    // Cross-farm parentage is NOT constrained at the database level, because a
    // purchased animal legitimately has parents on another farm. Tenant safety
    // is enforced in the application: includes/livestock.php resolves a parent
    // through tenantFind(), so a parent id from a form can only ever reference
    // an animal the user can already see.
    // -----------------------------------------------------------------------
    $log[] = schemaAddColumn($conn, 'cows', 'dam_id', "INT NULL COMMENT 'mother, when managed inside DFMIS' AFTER notes");
    $log[] = schemaAddColumn($conn, 'cows', 'sire_id', "INT NULL COMMENT 'father, when managed inside DFMIS' AFTER dam_id");
    $log[] = schemaAddColumn($conn, 'cows', 'dam_external', "VARCHAR(150) NULL COMMENT 'mother not managed in DFMIS' AFTER sire_id");
    $log[] = schemaAddColumn($conn, 'cows', 'sire_external', "VARCHAR(150) NULL COMMENT 'father not managed in DFMIS' AFTER dam_external");
    $log[] = schemaAddColumn($conn, 'cows', 'genetic_notes', "TEXT NULL COMMENT 'free-form pedigree reference; full genetics is a later phase' AFTER sire_external");

    $log[] = schemaAddForeignKey($conn, 'cows', 'fk_cows_dam', 'dam_id', 'cows', 'cow_id', 'SET NULL');
    $log[] = schemaAddForeignKey($conn, 'cows', 'fk_cows_sire', 'sire_id', 'cows', 'cow_id', 'SET NULL');

    // Record when an animal entered the system. NULL for existing rows — the
    // creation date was never captured and cannot be recovered.
    $log[] = schemaAddColumn($conn, 'cows', 'created_at', "TIMESTAMP NULL DEFAULT NULL COMMENT 'NULL for records that predate this column' AFTER genetic_notes");

    // -----------------------------------------------------------------------
    // INDEXES — driven by the queries the animal module actually runs.
    //
    // Every one leads with farm_id because every tenant read filters on it
    // first (the T-4 lesson from the baseline audit).
    // -----------------------------------------------------------------------
    $log[] = schemaAddIndex($conn, 'cows', 'idx_cows_farm_lifecycle', '`farm_id`, `lifecycle_status`, `deleted_at`');
    $log[] = schemaAddIndex($conn, 'cows', 'idx_cows_farm_species', '`farm_id`, `species`, `deleted_at`');
    // Supports the animal list's default ordering and the search-by-name path.
    $log[] = schemaAddIndex($conn, 'cows', 'idx_cows_farm_name', '`farm_id`, `name`');
    // Pedigree lookups: "show me this animal's offspring".
    $log[] = schemaAddIndex($conn, 'cows', 'idx_cows_dam', '`dam_id`');
    $log[] = schemaAddIndex($conn, 'cows', 'idx_cows_sire', '`sire_id`');

    // -----------------------------------------------------------------------
    // ANIMAL IDENTIFIERS.
    //
    // One animal, many identifiers: an ear tag, an RFID transponder, a national
    // registration number, a microchip, a breed society number. Countries and
    // schemes differ, so the TYPE is data rather than a column name.
    //
    // UNIQUENESS IS PER FARM, deliberately. A global unique index on
    // (identifier_type, identifier_value) would let one tenant discover another
    // tenant's animal by watching an insert fail — exactly the enumeration leak
    // the baseline audit found as T-1 (Critical) and Phase 1 fixed. The same
    // mistake must not be reintroduced through a new table.
    //
    // The trade-off is real and accepted: a genuinely national identifier could
    // be duplicated across two farms in this database. Detecting that requires
    // a cross-tenant authority the platform does not have, and inventing one
    // would breach tenant isolation.
    // -----------------------------------------------------------------------
    $log[] = schemaCreateTable($conn, 'animal_identifiers',
        "identifier_id     INT AUTO_INCREMENT PRIMARY KEY,
         farm_id           INT NOT NULL,
         cow_id            INT NOT NULL,
         identifier_type   VARCHAR(32) NOT NULL COMMENT 'validated against DFMS_IDENTIFIER_TYPES',
         identifier_value  VARCHAR(100) NOT NULL,
         issuing_authority VARCHAR(150) NULL,
         issued_on         DATE NULL,
         status            ENUM('active','inactive','lost','replaced') NOT NULL DEFAULT 'active',
         notes             VARCHAR(255) NULL,
         created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         UNIQUE KEY uq_identifier_per_farm (farm_id, identifier_type, identifier_value),
         INDEX idx_identifier_animal (cow_id, status),
         INDEX idx_identifier_search (farm_id, identifier_value)"
    );
    $log[] = schemaAddForeignKey($conn, 'animal_identifiers', 'fk_identifier_farm', 'farm_id', 'farms', 'farm_id', 'RESTRICT');
    $log[] = schemaAddForeignKey($conn, 'animal_identifiers', 'fk_identifier_animal', 'cow_id', 'cows', 'cow_id', 'CASCADE');

    // -----------------------------------------------------------------------
    // LIFECYCLE EVENTS — the history behind cows.lifecycle_status.
    //
    // The column holds the CURRENT state; this table holds how it got there.
    // Rows are append-only by convention: a transition that turns out to be
    // wrong is corrected by recording another transition, never by editing
    // history.
    // -----------------------------------------------------------------------
    $log[] = schemaCreateTable($conn, 'animal_lifecycle_events',
        "event_id        INT AUTO_INCREMENT PRIMARY KEY,
         farm_id         INT NOT NULL,
         cow_id          INT NOT NULL,
         previous_status VARCHAR(20) NULL COMMENT 'NULL for the initial record',
         new_status      VARCHAR(20) NOT NULL,
         event_date      DATE NOT NULL,
         reason          VARCHAR(150) NULL,
         notes           TEXT NULL,
         recorded_by     INT NULL,
         created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         INDEX idx_lifecycle_animal (cow_id, event_date),
         INDEX idx_lifecycle_farm (farm_id, event_date)"
    );
    $log[] = schemaAddForeignKey($conn, 'animal_lifecycle_events', 'fk_lifecycle_farm', 'farm_id', 'farms', 'farm_id', 'RESTRICT');
    $log[] = schemaAddForeignKey($conn, 'animal_lifecycle_events', 'fk_lifecycle_animal', 'cow_id', 'cows', 'cow_id', 'CASCADE');

    // -----------------------------------------------------------------------
    // TRANSFERS — internal movement and inter-farm transfer.
    //
    // TENANT KEY: farm_id is the DESTINATION farm, so the history follows the
    // animal. After a transfer the animal belongs to the receiving farm, and
    // the receiving farm can see how it arrived. The originating farm retains
    // visibility through from_farm_id, which is recorded but is not the tenant
    // key — a single row cannot be scoped to two tenants, and scoping it to the
    // destination keeps it consistent with where the animal now is.
    //
    // Both farms must belong to the SAME organization; that is enforced in
    // includes/livestock.php, not here, because it is an authorization rule
    // rather than a referential one.
    // -----------------------------------------------------------------------
    $log[] = schemaCreateTable($conn, 'animal_transfers',
        "transfer_id      INT AUTO_INCREMENT PRIMARY KEY,
         farm_id          INT NOT NULL COMMENT 'tenant key = destination farm; history follows the animal',
         cow_id           INT NOT NULL,
         transfer_type    ENUM('internal','inter_farm') NOT NULL,
         from_farm_id     INT NULL,
         to_farm_id       INT NULL,
         from_location_id INT NULL,
         to_location_id   INT NULL,
         transferred_on   DATE NOT NULL,
         reason           VARCHAR(150) NULL,
         notes            TEXT NULL,
         recorded_by      INT NULL,
         created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         INDEX idx_transfer_animal (cow_id, transferred_on),
         INDEX idx_transfer_farm (farm_id, transferred_on)"
    );
    $log[] = schemaAddForeignKey($conn, 'animal_transfers', 'fk_transfer_farm', 'farm_id', 'farms', 'farm_id', 'RESTRICT');
    $log[] = schemaAddForeignKey($conn, 'animal_transfers', 'fk_transfer_animal', 'cow_id', 'cows', 'cow_id', 'CASCADE');

    // -----------------------------------------------------------------------
    // OWNERSHIP.
    //
    // An animal on a farm is not necessarily OWNED by that farm — leasing,
    // contract rearing and boarding are ordinary in livestock operations.
    //
    // Deliberately minimal: who holds the animal and on what basis, with
    // effective dates. No legal-entity model, no valuation, no share
    // percentages. Those are a finance concern (Phase 9) and would be
    // speculative here.
    //
    // effective_to NULL means "current". Exactly one open row per animal is an
    // application invariant, not a database constraint — MySQL cannot express
    // "at most one NULL per group" without a trigger or a generated column,
    // and a trigger would hide the rule from anyone reading the code.
    // -----------------------------------------------------------------------
    $log[] = schemaCreateTable($conn, 'animal_ownership',
        "ownership_id   INT AUTO_INCREMENT PRIMARY KEY,
         farm_id        INT NOT NULL,
         cow_id         INT NOT NULL,
         ownership_type ENUM('owned','leased_in','leased_out','managed','partnership','boarding') NOT NULL DEFAULT 'owned',
         owner_name     VARCHAR(150) NULL COMMENT 'NULL means the holding farm itself',
         effective_from DATE NOT NULL,
         effective_to   DATE NULL COMMENT 'NULL = current',
         notes          TEXT NULL,
         recorded_by    INT NULL,
         created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         INDEX idx_ownership_animal (cow_id, effective_from),
         INDEX idx_ownership_current (cow_id, effective_to)"
    );
    $log[] = schemaAddForeignKey($conn, 'animal_ownership', 'fk_ownership_farm', 'farm_id', 'farms', 'farm_id', 'RESTRICT');
    $log[] = schemaAddForeignKey($conn, 'animal_ownership', 'fk_ownership_animal', 'cow_id', 'cows', 'cow_id', 'CASCADE');

    // -----------------------------------------------------------------------
    // stored_files: document typing.
    //
    // Reusing the Phase 1 file system rather than building a second one. A
    // document's KIND (registration certificate, vaccination certificate…) is
    // metadata on the existing row, not a reason for a new table.
    // -----------------------------------------------------------------------
    $log[] = schemaAddColumn($conn, 'stored_files', 'document_type',
        "VARCHAR(40) NULL COMMENT 'validated against DFMS_DOCUMENT_TYPES; NULL for photos' AFTER category");
    $log[] = schemaAddColumn($conn, 'stored_files', 'caption', 'VARCHAR(255) NULL AFTER document_type');
    $log[] = schemaAddColumn($conn, 'stored_files', 'captured_on', "DATE NULL COMMENT 'when the photo was taken or the document issued' AFTER caption");
    $log[] = schemaAddIndex($conn, 'stored_files', 'idx_files_entity_type', '`farm_id`, `entity_type`, `entity_id`, `deleted_at`');

    return $log;
};
