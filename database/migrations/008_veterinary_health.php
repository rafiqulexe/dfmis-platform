<?php
// ============================================================================
// Migration 008 — Veterinary & Animal Health foundation
//
// Builds the structured clinical record that Phase 3's animal digital twin was
// missing. It EXTENDS the existing health surface; it replaces nothing.
//
// WHAT ALREADY EXISTED (audited before this file was written)
//   health_events        farm_id, cow_id, event_date, event_type, severity,
//                        description, recorded_by. Four real rows. A flat log
//                        with no episode, no treatment, no medication and no
//                        follow-up. It KEEPS working exactly as it does today.
//   cows.health_status   ENUM('healthy','at_risk','sick','treatment'), already
//                        read by insights.php and the dashboard.
//   stored_files         tenant-scoped, entity-linked, access-controlled, with
//                        an unused 'medical_images' category already allowed.
//   locations            Phase 3. Reused for isolation facilities.
//   alerts               generic (entity_type, entity_id) targeting.
//   inventory_items      free-text item_type; no category, no withdrawal data.
//
// WHY health_events IS NOT TURNED INTO THE CASE TABLE
// A clinical EPISODE and a clinical EVENT are different things: an episode
// spans days and owns symptoms, diagnoses and treatments; an event is a single
// dated note. Overloading one table with both would force every historical row
// to be assigned a case status nobody knows. Instead `health_cases` is new and
// `health_events` gains ONE nullable column, `case_id`. Existing rows keep
// case_id NULL, which is factually true — no case existed when they were
// written — and the existing add/list pages are unaffected.
//
// WHY NOT ONE BIG `animal_health` TABLE
// Symptoms, diagnoses, treatments and medications are all one-to-many against
// an episode and all need to be queried independently ("every animal treated
// with this product", "every unresolved suspected diagnosis"). Collapsing them
// into columns or a JSON blob would make exactly the queries that matter
// impossible, and future AI feature extraction needs them row-shaped.
//
// NON-DESTRUCTIVE. No table is dropped or renamed, no column is removed, no
// historical value is reinterpreted, and no medical history is invented for
// animals already in the system. Unknown history stays unknown.
// ============================================================================

return function (PDO $conn): array {
    $log = [];

    // -----------------------------------------------------------------------
    // Pre-flight. Phase 3 must be in place: every health record hangs off an
    // animal, and several tables reference locations.
    // -----------------------------------------------------------------------
    foreach (['cows', 'health_events', 'locations', 'stored_files', 'inventory_items'] as $required) {
        if (!schemaTableExists($conn, $required)) {
            throw new RuntimeException("$required is missing — run earlier migrations first.");
        }
    }

    // =======================================================================
    // 1. HEALTH STATUS — widen the existing column, do not replace it.
    //
    // The four current values stay exactly as they are, with the same ordinals
    // and the same meaning, so insights.php and every existing row are
    // untouched. Two states are genuinely absent from the current model and are
    // appended:
    //
    //   quarantined   an animal under isolation is not merely "sick"; the
    //                 distinction drives movement and product decisions.
    //   recovered     a resolved episode is not the same as never having been
    //                 ill, and collapsing it to 'healthy' loses that.
    //
    // 'deceased' is DELIBERATELY NOT ADDED. Death is a LIFECYCLE fact and
    // cows.lifecycle_status already owns it. Adding it here would create two
    // columns that can disagree about whether an animal is alive, which is
    // exactly the drift Phase 3 avoided. includes/health.php enforces the
    // one-way rule instead: lifecycle is authoritative, and a terminal
    // lifecycle state refuses any health-status change.
    // =======================================================================
    $log[] = schemaEnsureEnumValues(
        $conn, 'cows', 'health_status',
        ['quarantined', 'recovered'],
        "NOT NULL DEFAULT 'healthy'"
    );

    // History behind the column. cows.health_status holds the CURRENT state;
    // this holds how it got there, mirroring the lifecycle design from 007.
    $log[] = schemaCreateTable($conn, 'health_status_events',
        "status_event_id INT AUTO_INCREMENT PRIMARY KEY,
         farm_id         INT NOT NULL,
         cow_id          INT NOT NULL,
         case_id         INT NULL COMMENT 'the episode that caused the change, when there was one',
         previous_status VARCHAR(20) NULL COMMENT 'NULL for the first recorded change',
         new_status      VARCHAR(20) NOT NULL COMMENT 'validated against DFMS_HEALTH_STATUSES',
         reason          VARCHAR(150) NULL,
         notes           TEXT NULL,
         effective_at    DATETIME NOT NULL,
         recorded_by     INT NULL,
         created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         INDEX idx_hstatus_animal (cow_id, effective_at),
         INDEX idx_hstatus_farm (farm_id, effective_at)"
    );
    $log[] = schemaAddForeignKey($conn, 'health_status_events', 'fk_hstatus_farm', 'farm_id', 'farms', 'farm_id', 'RESTRICT');
    $log[] = schemaAddForeignKey($conn, 'health_status_events', 'fk_hstatus_animal', 'cow_id', 'cows', 'cow_id', 'CASCADE');

    // =======================================================================
    // 2. HEALTH CASES — the clinical episode.
    //
    // Everything clinical hangs off a case, EXCEPT the things that legitimately
    // happen without one: a routine vaccination, a weekly weight, a precautionary
    // quarantine on a new arrival. Those carry case_id NULL rather than being
    // forced to invent an illness episode, which would pollute disease
    // surveillance with cases that were never cases.
    //
    // `outcome` is NULLABLE and includes 'unknown' precisely because a real
    // episode often closes without a definitive answer. Forcing a resolution is
    // how a management system starts fabricating clinical conclusions.
    // =======================================================================
    $log[] = schemaCreateTable($conn, 'health_cases',
        "case_id        INT AUTO_INCREMENT PRIMARY KEY,
         farm_id        INT NOT NULL,
         cow_id         INT NOT NULL,
         case_reference VARCHAR(32) NOT NULL COMMENT 'human reference, unique per farm',
         title          VARCHAR(150) NOT NULL,
         case_type      VARCHAR(32) NOT NULL DEFAULT 'illness' COMMENT 'validated against DFMS_CASE_TYPES',
         severity       ENUM('routine','moderate','urgent') NOT NULL DEFAULT 'routine' COMMENT 'same vocabulary as health_events.severity',
         status         ENUM('open','monitoring','treating','resolved','closed') NOT NULL DEFAULT 'open',
         outcome        VARCHAR(24) NULL COMMENT 'validated against DFMS_CASE_OUTCOMES; NULL while open',
         opened_on      DATE NOT NULL,
         closed_on      DATE NULL,
         follow_up_on   DATE NULL COMMENT 'drives the follow-up alert',
         is_contagious  TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'operator judgement, not a system diagnosis',
         notes          TEXT NULL,
         opened_by      INT NULL,
         closed_by      INT NULL,
         created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         updated_at     TIMESTAMP NULL DEFAULT NULL,
         UNIQUE KEY uq_case_reference_per_farm (farm_id, case_reference),
         INDEX idx_case_animal (cow_id, opened_on),
         INDEX idx_case_farm_status (farm_id, status, opened_on),
         INDEX idx_case_followup (farm_id, follow_up_on, status)"
    );
    $log[] = schemaAddForeignKey($conn, 'health_cases', 'fk_case_farm', 'farm_id', 'farms', 'farm_id', 'RESTRICT');
    $log[] = schemaAddForeignKey($conn, 'health_cases', 'fk_case_animal', 'cow_id', 'cows', 'cow_id', 'CASCADE');

    // The one column health_events gains. Nullable, no backfill: the four
    // existing rows predate cases and pretending otherwise would be fiction.
    $log[] = schemaAddColumn($conn, 'health_events', 'case_id',
        "INT NULL COMMENT 'NULL for standalone events and for every row written before migration 008' AFTER cow_id");
    // health_events has never had a creation timestamp — only a date. New rows
    // get one so the timeline can order same-day events correctly; old rows
    // stay NULL rather than being given a manufactured time.
    $log[] = schemaAddColumn($conn, 'health_events', 'created_at',
        "TIMESTAMP NULL DEFAULT NULL COMMENT 'NULL for records that predate this column' AFTER recorded_by");
    $log[] = schemaAddIndex($conn, 'health_events', 'idx_health_events_case', '`case_id`');
    $log[] = schemaAddIndex($conn, 'health_events', 'idx_health_events_farm_animal', '`farm_id`, `cow_id`, `event_date`');

    // =======================================================================
    // 3. CLINICAL OBSERVATIONS.
    //
    // The balance the brief asks for: the observation TYPE is data (so a new
    // measurement does not need a migration), but the VALUE is split into a
    // numeric column and a coded column rather than a free-text blob — because
    // "was this animal's temperature trending up" must be answerable in SQL.
    //
    // UNITS: value_numeric is stored in the Phase 2 CANONICAL unit for its
    // quantity kind (°C for temperature, kg for mass) and `unit` records which
    // canonical unit that is. Nothing is stored in a display unit, so no row
    // depends on who entered it. includes/health.php refuses an unsupported
    // unit outright rather than converting it wrongly.
    //
    // value_code carries qualitative results ('poor', 'normal', 'lame') against
    // a scale declared by the observation type, so "every animal with poor
    // appetite" is a query, not a text search.
    // =======================================================================
    $log[] = schemaCreateTable($conn, 'health_observations',
        "observation_id   INT AUTO_INCREMENT PRIMARY KEY,
         farm_id          INT NOT NULL,
         cow_id           INT NOT NULL,
         case_id          INT NULL COMMENT 'NULL for routine monitoring outside any episode',
         observation_type VARCHAR(32) NOT NULL COMMENT 'validated against DFMS_OBSERVATION_TYPES',
         value_numeric    DECIMAL(12,3) NULL COMMENT 'stored in the CANONICAL unit named by `unit`',
         value_code       VARCHAR(32) NULL COMMENT 'qualitative result from the type scale',
         unit             VARCHAR(16) NULL COMMENT 'canonical unit key; NULL for qualitative observations',
         observed_at      DATETIME NOT NULL,
         observed_by      INT NULL,
         notes            VARCHAR(500) NULL,
         created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         INDEX idx_obs_animal_type (cow_id, observation_type, observed_at),
         INDEX idx_obs_farm (farm_id, observed_at),
         INDEX idx_obs_case (case_id)"
    );
    $log[] = schemaAddForeignKey($conn, 'health_observations', 'fk_obs_farm', 'farm_id', 'farms', 'farm_id', 'RESTRICT');
    $log[] = schemaAddForeignKey($conn, 'health_observations', 'fk_obs_animal', 'cow_id', 'cows', 'cow_id', 'CASCADE');
    $log[] = schemaAddForeignKey($conn, 'health_observations', 'fk_obs_case', 'case_id', 'health_cases', 'case_id', 'SET NULL');

    // =======================================================================
    // 4. SYMPTOMS — many per case.
    //
    // symptom_code comes from a registry so patterns are analysable; the escape
    // hatch is the single code 'other', which REQUIRES symptom_label. That is
    // controlled extensibility: an unrecognised sign can still be recorded
    // truthfully, and it is visibly distinguishable from a coded one rather
    // than silently degrading the vocabulary.
    // =======================================================================
    $log[] = schemaCreateTable($conn, 'health_symptoms',
        "symptom_id    INT AUTO_INCREMENT PRIMARY KEY,
         farm_id       INT NOT NULL,
         case_id       INT NOT NULL,
         cow_id        INT NOT NULL COMMENT 'denormalised from the case so per-animal symptom history is one index away',
         symptom_code  VARCHAR(32) NOT NULL COMMENT 'validated against DFMS_SYMPTOMS; ''other'' requires symptom_label',
         symptom_label VARCHAR(120) NULL COMMENT 'required when symptom_code = other',
         severity      ENUM('mild','moderate','severe') NOT NULL DEFAULT 'mild',
         onset_at      DATETIME NULL COMMENT 'NULL when the onset is genuinely unknown',
         resolved_at   DATETIME NULL,
         notes         VARCHAR(500) NULL,
         recorded_by   INT NULL,
         created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         INDEX idx_symptom_case (case_id),
         INDEX idx_symptom_animal (cow_id, created_at),
         INDEX idx_symptom_pattern (farm_id, symptom_code, created_at)"
    );
    $log[] = schemaAddForeignKey($conn, 'health_symptoms', 'fk_symptom_farm', 'farm_id', 'farms', 'farm_id', 'RESTRICT');
    $log[] = schemaAddForeignKey($conn, 'health_symptoms', 'fk_symptom_case', 'case_id', 'health_cases', 'case_id', 'CASCADE');
    $log[] = schemaAddForeignKey($conn, 'health_symptoms', 'fk_symptom_animal', 'cow_id', 'cows', 'cow_id', 'CASCADE');

    // =======================================================================
    // 5. DIAGNOSES — many per case, each with an explicit epistemic status.
    //
    // The four statuses exist so the system never has to pretend it knows more
    // than it does. 'suspected' and 'ruled_out' are first-class: a differential
    // that was considered and excluded is clinically valuable information, and
    // discarding it would make the record less honest, not tidier.
    //
    // `confidence` is the RECORDING CLINICIAN'S stated confidence. It is not
    // computed, not scored, and this software never generates it.
    // =======================================================================
    $log[] = schemaCreateTable($conn, 'health_diagnoses',
        "diagnosis_id    INT AUTO_INCREMENT PRIMARY KEY,
         farm_id         INT NOT NULL,
         case_id         INT NOT NULL,
         cow_id          INT NOT NULL,
         condition_code  VARCHAR(40) NOT NULL COMMENT 'validated against DFMS_CONDITIONS; ''other'' requires condition_label',
         condition_label VARCHAR(150) NULL COMMENT 'required when condition_code = other',
         status          ENUM('suspected','confirmed','ruled_out','resolved') NOT NULL DEFAULT 'suspected',
         confidence      ENUM('low','moderate','high') NULL COMMENT 'the clinician''s stated confidence; never computed',
         diagnosed_on    DATE NOT NULL,
         diagnosed_by    INT NULL COMMENT 'internal user; an external vet is recorded through consultation_id',
         consultation_id INT NULL,
         notes           TEXT NULL,
         created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         INDEX idx_diag_case (case_id),
         INDEX idx_diag_animal (cow_id, diagnosed_on),
         INDEX idx_diag_surveillance (farm_id, condition_code, status, diagnosed_on)"
    );
    $log[] = schemaAddForeignKey($conn, 'health_diagnoses', 'fk_diag_farm', 'farm_id', 'farms', 'farm_id', 'RESTRICT');
    $log[] = schemaAddForeignKey($conn, 'health_diagnoses', 'fk_diag_case', 'case_id', 'health_cases', 'case_id', 'CASCADE');
    $log[] = schemaAddForeignKey($conn, 'health_diagnoses', 'fk_diag_animal', 'cow_id', 'cows', 'cow_id', 'CASCADE');

    // =======================================================================
    // 6. VETERINARY CONSULTATIONS.
    //
    // NO USER ACCOUNT IS REQUIRED for the veterinarian. A farm calls whichever
    // practice is available; demanding that each one first be provisioned as a
    // DFMIS login would make the record unusable and would drag an entire
    // external-identity system into a health phase.
    //
    // The vet is therefore descriptive text plus an optional registration
    // number. `vet_user_id` links the record to an internal account only when
    // the attending vet genuinely IS a member of this farm — it is resolved
    // from membership, never from the form.
    // =======================================================================
    $log[] = schemaCreateTable($conn, 'veterinary_consultations',
        "consultation_id   INT AUTO_INCREMENT PRIMARY KEY,
         farm_id           INT NOT NULL,
         cow_id            INT NOT NULL,
         case_id           INT NULL,
         vet_name          VARCHAR(150) NOT NULL,
         vet_practice      VARCHAR(150) NULL,
         vet_registration  VARCHAR(60) NULL COMMENT 'practitioner licence / registration number as supplied',
         vet_contact       VARCHAR(120) NULL,
         vet_user_id       INT NULL COMMENT 'set ONLY when the vet is a member of this farm; never taken from the form',
         consultation_type VARCHAR(24) NOT NULL DEFAULT 'on_farm' COMMENT 'validated against DFMS_CONSULTATION_TYPES',
         consulted_at      DATETIME NOT NULL,
         reason            TEXT NULL,
         findings          TEXT NULL,
         recommendations   TEXT NULL,
         follow_up_on      DATE NULL,
         cost_amount       DECIMAL(12,2) NULL COMMENT 'optional; finance is Phase 9 and nothing aggregates this yet',
         notes             TEXT NULL,
         recorded_by       INT NULL,
         created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         INDEX idx_consult_animal (cow_id, consulted_at),
         INDEX idx_consult_farm (farm_id, consulted_at),
         INDEX idx_consult_case (case_id),
         INDEX idx_consult_followup (farm_id, follow_up_on)"
    );
    $log[] = schemaAddForeignKey($conn, 'veterinary_consultations', 'fk_consult_farm', 'farm_id', 'farms', 'farm_id', 'RESTRICT');
    $log[] = schemaAddForeignKey($conn, 'veterinary_consultations', 'fk_consult_animal', 'cow_id', 'cows', 'cow_id', 'CASCADE');
    $log[] = schemaAddForeignKey($conn, 'veterinary_consultations', 'fk_consult_case', 'case_id', 'health_cases', 'case_id', 'SET NULL');
    // Deferred until the table exists, because it points back at consultations.
    $log[] = schemaAddForeignKey($conn, 'health_diagnoses', 'fk_diag_consult', 'consultation_id', 'veterinary_consultations', 'consultation_id', 'SET NULL');

    // =======================================================================
    // 7. TREATMENTS — separate from diagnoses, many per case.
    //
    // A treatment is a PLAN with a lifecycle; a medication administration is a
    // single act. Merging them would make "three injections over five days"
    // either three treatments or one treatment with no record of the doses.
    //
    // HISTORY IS NEVER OVERWRITTEN. 'completed' and 'cancelled' are terminal
    // states that remain visible; nothing deletes a treatment row.
    // =======================================================================
    $log[] = schemaCreateTable($conn, 'health_treatments',
        "treatment_id     INT AUTO_INCREMENT PRIMARY KEY,
         farm_id          INT NOT NULL,
         cow_id           INT NOT NULL,
         case_id          INT NULL,
         diagnosis_id     INT NULL,
         consultation_id  INT NULL,
         treatment_type   VARCHAR(24) NOT NULL DEFAULT 'medication' COMMENT 'validated against DFMS_TREATMENT_TYPES',
         name             VARCHAR(150) NOT NULL,
         protocol         TEXT NULL COMMENT 'the plan as written by the prescriber',
         status           ENUM('planned','active','completed','cancelled') NOT NULL DEFAULT 'active',
         outcome          VARCHAR(24) NULL COMMENT 'validated against DFMS_TREATMENT_OUTCOMES; NULL until completed',
         started_on       DATE NOT NULL,
         ended_on         DATE NULL,
         responsible_name VARCHAR(150) NULL COMMENT 'free text so an external vet needs no account',
         responsible_user_id INT NULL,
         notes            TEXT NULL,
         recorded_by      INT NULL,
         created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         updated_at       TIMESTAMP NULL DEFAULT NULL,
         INDEX idx_treat_animal (cow_id, started_on),
         INDEX idx_treat_farm_status (farm_id, status, started_on),
         INDEX idx_treat_case (case_id)"
    );
    $log[] = schemaAddForeignKey($conn, 'health_treatments', 'fk_treat_farm', 'farm_id', 'farms', 'farm_id', 'RESTRICT');
    $log[] = schemaAddForeignKey($conn, 'health_treatments', 'fk_treat_animal', 'cow_id', 'cows', 'cow_id', 'CASCADE');
    $log[] = schemaAddForeignKey($conn, 'health_treatments', 'fk_treat_case', 'case_id', 'health_cases', 'case_id', 'SET NULL');
    $log[] = schemaAddForeignKey($conn, 'health_treatments', 'fk_treat_diagnosis', 'diagnosis_id', 'health_diagnoses', 'diagnosis_id', 'SET NULL');
    $log[] = schemaAddForeignKey($conn, 'health_treatments', 'fk_treat_consult', 'consultation_id', 'veterinary_consultations', 'consultation_id', 'SET NULL');

    // =======================================================================
    // 8. INVENTORY — the MINIMUM extension medication traceability needs.
    //
    // AUDITED FIRST. inventory_items has item_name, free-text item_type
    // ('Medicine', 'Feed', 'Supplement'), a free-text unit and a reorder level.
    // inventory_levels holds ONE quantity row per item. There is no stock
    // ledger, no batch table and no expiry anywhere.
    //
    // WHAT IS ADDED, AND WHY EACH IS THE MINIMUM
    //   category                      a NORMALISED key beside the free-text
    //                                 item_type, so "is this a medicine" stops
    //                                 depending on someone's spelling. The old
    //                                 column is untouched and still displayed.
    //   default_withdrawal_*_days     a product's withdrawal period is a
    //                                 property OF THE PRODUCT. Without it every
    //                                 administration would need it retyped, and
    //                                 a mistyped withdrawal is a food-safety
    //                                 failure, not a data-entry annoyance.
    //
    // WHAT IS DELIBERATELY NOT ADDED
    //   Batch-level STOCK. inventory_levels can hold exactly one quantity per
    //   item, so a batch table would need a parallel stock model — that is a
    //   pharmacy ERP and it is out of scope. Batch and expiry are instead
    //   captured ON THE ADMINISTRATION and ON THE VACCINATION, which is where
    //   they are clinically needed: the chain product -> batch -> animal ->
    //   treatment -> withdrawal is complete for the animal record even though
    //   the stock ledger cannot yet reconcile it. This is stated plainly in
    //   docs/DFMIS_VETERINARY_HEALTH_ARCHITECTURE.md rather than papered over.
    //
    // Backfill is NULL everywhere. Which existing items are medicines, and what
    // their withdrawal periods are, is genuinely unknown — and a wrong default
    // withdrawal period is the single most dangerous value this migration could
    // invent.
    // =======================================================================
    $log[] = schemaAddColumn($conn, 'inventory_items', 'category',
        "VARCHAR(32) NULL COMMENT 'validated against DFMS_INVENTORY_CATEGORIES; NULL = uncategorised legacy item' AFTER item_type");
    $log[] = schemaAddColumn($conn, 'inventory_items', 'default_withdrawal_milk_days',
        "INT NULL COMMENT 'product withdrawal period for milk, in days; NULL = not recorded, NEVER assumed to be zero' AFTER category");
    $log[] = schemaAddColumn($conn, 'inventory_items', 'default_withdrawal_meat_days',
        "INT NULL COMMENT 'product withdrawal period for meat, in days; NULL = not recorded, NEVER assumed to be zero' AFTER default_withdrawal_milk_days");
    $log[] = schemaAddIndex($conn, 'inventory_items', 'idx_items_farm_category', '`farm_id`, `category`');

    // =======================================================================
    // 9. MEDICATION ADMINISTRATIONS.
    //
    // medication_name is NOT NULL even when item_id is set. A clinical record
    // must stay readable after the inventory item it referenced is renamed or
    // removed — the name at the time of administration is part of the record,
    // not a lookup.
    //
    // inventory_deducted records whether stock was actually consumed, so an
    // administration of a product the farm does not stock is representable
    // without silently corrupting inventory.
    // =======================================================================
    $log[] = schemaCreateTable($conn, 'medication_administrations',
        "administration_id INT AUTO_INCREMENT PRIMARY KEY,
         farm_id           INT NOT NULL,
         cow_id            INT NOT NULL,
         treatment_id      INT NULL,
         case_id           INT NULL,
         item_id           INT NULL COMMENT 'inventory product, when the medication is stocked',
         medication_name   VARCHAR(150) NOT NULL COMMENT 'captured at administration time; survives item rename or removal',
         batch_lot         VARCHAR(60) NULL COMMENT 'as read off the container; inventory does not track batch-level stock',
         expiry_date       DATE NULL COMMENT 'as read off the container',
         dose              DECIMAL(12,3) NOT NULL,
         dose_unit         VARCHAR(16) NOT NULL COMMENT 'validated against DFMS_DOSE_UNITS',
         route             VARCHAR(24) NOT NULL COMMENT 'validated against DFMS_ADMIN_ROUTES',
         frequency         VARCHAR(60) NULL COMMENT 'free text as prescribed, e.g. ''once daily for 3 days''',
         administered_at   DATETIME NOT NULL,
         administered_by   INT NULL COMMENT 'internal user',
         administered_by_name VARCHAR(150) NULL COMMENT 'external administrator, needs no account',
         inventory_deducted TINYINT(1) NOT NULL DEFAULT 0,
         quantity_deducted DECIMAL(10,2) NULL COMMENT 'in the inventory item''s own unit, not the dose unit',
         notes             VARCHAR(500) NULL,
         created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         INDEX idx_admin_animal (cow_id, administered_at),
         INDEX idx_admin_farm (farm_id, administered_at),
         INDEX idx_admin_treatment (treatment_id),
         INDEX idx_admin_item (item_id, administered_at),
         INDEX idx_admin_batch (farm_id, batch_lot)"
    );
    $log[] = schemaAddForeignKey($conn, 'medication_administrations', 'fk_medadmin_farm', 'farm_id', 'farms', 'farm_id', 'RESTRICT');
    $log[] = schemaAddForeignKey($conn, 'medication_administrations', 'fk_medadmin_animal', 'cow_id', 'cows', 'cow_id', 'CASCADE');
    $log[] = schemaAddForeignKey($conn, 'medication_administrations', 'fk_medadmin_treatment', 'treatment_id', 'health_treatments', 'treatment_id', 'SET NULL');
    $log[] = schemaAddForeignKey($conn, 'medication_administrations', 'fk_medadmin_case', 'case_id', 'health_cases', 'case_id', 'SET NULL');
    // SET NULL, not CASCADE: deleting an inventory product must never erase the
    // record that an animal received a medicine.
    $log[] = schemaAddForeignKey($conn, 'medication_administrations', 'fk_medadmin_item', 'item_id', 'inventory_items', 'item_id', 'SET NULL');

    // =======================================================================
    // 10. VACCINATIONS.
    //
    // ONE table for both the schedule and the record, because a scheduled dose
    // and the dose that was given are the same clinical intention at two points
    // in time — splitting them guarantees they drift.
    //
    // A 'scheduled' row is NEVER promoted to 'administered' by the passage of
    // time or by any background process; only an explicit human action does
    // that, and administered_on stays NULL until then. 'missed' exists so a
    // dose that did not happen is recorded as such rather than deleted.
    //
    // CORRECTIONS: supersedes_id points a corrected row at the one it replaces.
    // The superseded row is retained and marked, so a mistake is visible in the
    // history rather than edited out of it.
    // =======================================================================
    $log[] = schemaCreateTable($conn, 'vaccination_records',
        "vaccination_id   INT AUTO_INCREMENT PRIMARY KEY,
         farm_id          INT NOT NULL,
         cow_id           INT NOT NULL,
         vaccine_name     VARCHAR(150) NOT NULL,
         disease_target   VARCHAR(150) NULL COMMENT 'what the vaccine protects against, as recorded',
         item_id          INT NULL COMMENT 'inventory product, when the vaccine is stocked',
         status           ENUM('scheduled','administered','missed','cancelled','superseded') NOT NULL DEFAULT 'scheduled',
         scheduled_on     DATE NULL,
         administered_on  DATE NULL COMMENT 'NULL until a human records the dose as given',
         dose             DECIMAL(12,3) NULL,
         dose_unit        VARCHAR(16) NULL COMMENT 'validated against DFMS_DOSE_UNITS',
         route            VARCHAR(24) NULL COMMENT 'validated against DFMS_ADMIN_ROUTES',
         batch_lot        VARCHAR(60) NULL,
         expiry_date      DATE NULL,
         administrator_name VARCHAR(150) NULL,
         administered_by  INT NULL,
         next_due_on      DATE NULL,
         supersedes_id    INT NULL COMMENT 'correction chain; the superseded row is kept, never edited away',
         inventory_deducted TINYINT(1) NOT NULL DEFAULT 0,
         notes            VARCHAR(500) NULL,
         recorded_by      INT NULL,
         created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         INDEX idx_vacc_animal (cow_id, administered_on),
         INDEX idx_vacc_farm_status (farm_id, status, scheduled_on),
         INDEX idx_vacc_due (farm_id, next_due_on),
         INDEX idx_vacc_schedule (farm_id, scheduled_on, status)"
    );
    $log[] = schemaAddForeignKey($conn, 'vaccination_records', 'fk_vacc_farm', 'farm_id', 'farms', 'farm_id', 'RESTRICT');
    $log[] = schemaAddForeignKey($conn, 'vaccination_records', 'fk_vacc_animal', 'cow_id', 'cows', 'cow_id', 'CASCADE');
    $log[] = schemaAddForeignKey($conn, 'vaccination_records', 'fk_vacc_item', 'item_id', 'inventory_items', 'item_id', 'SET NULL');
    $log[] = schemaAddForeignKey($conn, 'vaccination_records', 'fk_vacc_supersedes', 'supersedes_id', 'vaccination_records', 'vaccination_id', 'SET NULL');

    // =======================================================================
    // 11. QUARANTINE / ISOLATION.
    //
    // location_id REUSES the Phase 3 locations table. A second location model
    // for "isolation pens" would immediately disagree with the first about
    // where an animal is.
    // =======================================================================
    $log[] = schemaCreateTable($conn, 'health_quarantines',
        "quarantine_id   INT AUTO_INCREMENT PRIMARY KEY,
         farm_id         INT NOT NULL,
         cow_id          INT NOT NULL,
         case_id         INT NULL,
         quarantine_type VARCHAR(24) NOT NULL DEFAULT 'precautionary' COMMENT 'validated against DFMS_QUARANTINE_TYPES',
         reason          VARCHAR(200) NOT NULL,
         location_id     INT NULL COMMENT 'the Phase 3 locations table; no second location model exists',
         started_at      DATETIME NOT NULL,
         expected_end_on DATE NULL,
         ended_at        DATETIME NULL,
         status          ENUM('active','released','cancelled') NOT NULL DEFAULT 'active',
         released_by     INT NULL,
         release_notes   VARCHAR(500) NULL,
         notes           TEXT NULL,
         recorded_by     INT NULL,
         created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         INDEX idx_quar_animal (cow_id, started_at),
         INDEX idx_quar_farm_status (farm_id, status, started_at),
         INDEX idx_quar_review (farm_id, expected_end_on, status)"
    );
    $log[] = schemaAddForeignKey($conn, 'health_quarantines', 'fk_quar_farm', 'farm_id', 'farms', 'farm_id', 'RESTRICT');
    $log[] = schemaAddForeignKey($conn, 'health_quarantines', 'fk_quar_animal', 'cow_id', 'cows', 'cow_id', 'CASCADE');
    $log[] = schemaAddForeignKey($conn, 'health_quarantines', 'fk_quar_case', 'case_id', 'health_cases', 'case_id', 'SET NULL');
    $log[] = schemaAddForeignKey($conn, 'health_quarantines', 'fk_quar_location', 'location_id', 'locations', 'location_id', 'SET NULL');

    // =======================================================================
    // 12. WITHDRAWAL PERIODS — food-safety data, per animal.
    //
    // A withdrawal is its own row rather than two columns on the administration
    // because ONE medication commonly creates SEVERAL restrictions with
    // DIFFERENT end dates (milk 4 days, meat 28 days). Columns cannot express
    // that, and getting it wrong means unsafe product reaching the food chain.
    //
    // starts_on / ends_on are STORED, not computed on read: the withdrawal that
    // applied is a fact about the day it was set, and it must not silently
    // change if a product's default period is later corrected.
    //
    // ends_on is INCLUSIVE — the restriction is in force through that date and
    // lifts the following day. Stated here because an off-by-one on a
    // withdrawal end is a food-safety error, not a display bug.
    //
    // SCOPE LIMIT, STATED HONESTLY: milk_production stores a FARM-LEVEL daily
    // total with no cow_id, so this system can flag and surface an individual
    // animal's restriction but CANNOT verify that its milk was excluded from
    // the farm total. Nothing here claims otherwise. See the architecture doc.
    // =======================================================================
    $log[] = schemaCreateTable($conn, 'withdrawal_periods',
        "withdrawal_id     INT AUTO_INCREMENT PRIMARY KEY,
         farm_id           INT NOT NULL,
         cow_id            INT NOT NULL,
         administration_id INT NULL,
         vaccination_id    INT NULL,
         treatment_id      INT NULL,
         case_id           INT NULL,
         restriction_type  VARCHAR(16) NOT NULL COMMENT 'validated against DFMS_WITHDRAWAL_TYPES',
         source_label      VARCHAR(150) NOT NULL COMMENT 'the product that caused it, captured at the time',
         starts_on         DATE NOT NULL,
         ends_on           DATE NOT NULL COMMENT 'INCLUSIVE — the restriction lifts the following day',
         status            ENUM('active','completed','cancelled') NOT NULL DEFAULT 'active',
         cancelled_reason  VARCHAR(200) NULL,
         notes             VARCHAR(500) NULL,
         recorded_by       INT NULL,
         created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         INDEX idx_wd_animal (cow_id, ends_on),
         INDEX idx_wd_farm_active (farm_id, status, ends_on),
         INDEX idx_wd_type (farm_id, restriction_type, status, ends_on),
         INDEX idx_wd_admin (administration_id)"
    );
    $log[] = schemaAddForeignKey($conn, 'withdrawal_periods', 'fk_wd_farm', 'farm_id', 'farms', 'farm_id', 'RESTRICT');
    $log[] = schemaAddForeignKey($conn, 'withdrawal_periods', 'fk_wd_animal', 'cow_id', 'cows', 'cow_id', 'CASCADE');
    $log[] = schemaAddForeignKey($conn, 'withdrawal_periods', 'fk_wd_admin', 'administration_id', 'medication_administrations', 'administration_id', 'CASCADE');
    $log[] = schemaAddForeignKey($conn, 'withdrawal_periods', 'fk_wd_vacc', 'vaccination_id', 'vaccination_records', 'vaccination_id', 'CASCADE');
    $log[] = schemaAddForeignKey($conn, 'withdrawal_periods', 'fk_wd_treatment', 'treatment_id', 'health_treatments', 'treatment_id', 'SET NULL');

    // =======================================================================
    // 13. LABORATORY TESTS.
    //
    // An INTEGRATION POINT, not a laboratory information system. It records
    // what was sent, what came back and where the report lives — it does not
    // model specimens, analysers, panels or accreditation.
    //
    // Results are split the same way observations are: a numeric value with a
    // unit for quantitative assays, a coded value for qualitative ones. The
    // report PDF itself goes to stored_files, never to a filesystem path stored
    // in this table.
    // =======================================================================
    $log[] = schemaCreateTable($conn, 'laboratory_tests',
        "lab_test_id       INT AUTO_INCREMENT PRIMARY KEY,
         farm_id           INT NOT NULL,
         cow_id            INT NOT NULL,
         case_id           INT NULL,
         test_type         VARCHAR(32) NOT NULL COMMENT 'validated against DFMS_LAB_TEST_TYPES',
         test_name         VARCHAR(150) NOT NULL,
         laboratory_name   VARCHAR(150) NULL,
         sample_type       VARCHAR(60) NULL COMMENT 'milk, blood, faeces, tissue, swab…',
         sample_taken_on   DATE NOT NULL,
         result_received_on DATE NULL,
         result_status     ENUM('pending','received','inconclusive','cancelled') NOT NULL DEFAULT 'pending',
         result_numeric    DECIMAL(14,4) NULL,
         result_unit       VARCHAR(24) NULL COMMENT 'free text: laboratory units are not a DFMIS measurement kind',
         result_code       VARCHAR(24) NULL COMMENT 'validated against DFMS_LAB_RESULT_CODES',
         reference_range   VARCHAR(80) NULL COMMENT 'as printed on the report',
         interpretation    TEXT NULL,
         notes             TEXT NULL,
         recorded_by       INT NULL,
         created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         updated_at        TIMESTAMP NULL DEFAULT NULL,
         INDEX idx_lab_animal (cow_id, sample_taken_on),
         INDEX idx_lab_farm_status (farm_id, result_status, sample_taken_on),
         INDEX idx_lab_case (case_id),
         INDEX idx_lab_surveillance (farm_id, test_type, result_code, sample_taken_on)"
    );
    $log[] = schemaAddForeignKey($conn, 'laboratory_tests', 'fk_lab_farm', 'farm_id', 'farms', 'farm_id', 'RESTRICT');
    $log[] = schemaAddForeignKey($conn, 'laboratory_tests', 'fk_lab_animal', 'cow_id', 'cows', 'cow_id', 'CASCADE');
    $log[] = schemaAddForeignKey($conn, 'laboratory_tests', 'fk_lab_case', 'case_id', 'health_cases', 'case_id', 'SET NULL');

    // Back-references that had to wait for their target tables to exist.
    $log[] = schemaAddForeignKey($conn, 'health_status_events', 'fk_hstatus_case', 'case_id', 'health_cases', 'case_id', 'SET NULL');
    $log[] = schemaAddForeignKey($conn, 'health_events', 'fk_health_event_case', 'case_id', 'health_cases', 'case_id', 'SET NULL');
    $log[] = schemaAddForeignKey($conn, 'withdrawal_periods', 'fk_wd_case', 'case_id', 'health_cases', 'case_id', 'SET NULL');

    // =======================================================================
    // 14. stored_files — veterinary document support.
    //
    // No new table and no new storage location. The existing tenant-scoped,
    // access-controlled file system gains nothing but three additional
    // document_type values (validated in PHP, not by the schema), so a
    // laboratory report is reachable only through modules/files/serve.php like
    // every other file. The 'medical_images' storage category already existed
    // and was unused; Phase 4 is its first consumer.
    // =======================================================================
    $log[] = "  note  stored_files needs no schema change — document_type, entity_type and";
    $log[] = "        entity_id already carry veterinary documents; new type keys are";
    $log[] = "        validated in includes/livestock.php (DFMS_DOCUMENT_TYPES).";

    // =======================================================================
    // 15. EXISTING ANIMALS.
    //
    // No health history is manufactured. Every animal already in the system
    // keeps the health_status it has, gains no cases, no diagnoses, no
    // treatments and no vaccinations, and acquires no withdrawal restrictions.
    // An animal with no vaccination record is an animal whose vaccination
    // history is UNKNOWN — not one that is unvaccinated, and the UI says so.
    // =======================================================================
    $log[] = "  note  no medical history invented for existing animals; unknown stays unknown.";

    return $log;
};
