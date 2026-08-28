<?php
// ============================================================================
// Migration 012 — Production & Feeding operations (Phase 6)
//
// FOUR NEW TABLES. NO EXISTING TABLE IS ALTERED, RENAMED OR DROPPED, AND NOT
// ONE HISTORICAL ROW IS REWRITTEN OR REINTERPRETED.
//
// ---------------------------------------------------------------------------
// WHAT THE AUDIT FOUND, AND WHY THAT DECIDED THE SHAPE
//
//   milk_production      EXISTS. farm_id + production_date + total_milk_yield,
//                        with UNIQUE(farm_id, production_date). A FARM-LEVEL
//                        DAILY AGGREGATE, with real historical rows.
//   feed_consumption     EXISTS. farm_id + consumption_date +
//                        total_feed_consumed. Also a farm-level daily
//                        aggregate — and it has NO item_id at all, so it is
//                        entirely disconnected from inventory.
//   feeding_schedules    EXISTS. A time + free-text feed_type + amount. Not a
//                        ration, and not linked to inventory either.
//   inventory_items      EXISTS, with a Phase 4 `category` that already
//                        includes 'feed' and 'supplement'.
//   per-animal yield     DOES NOT EXIST anywhere.
//
// THE CENTRAL DECISION: SATELLITES, NOT ALTERS.
//
// It would be shorter to ALTER milk_production and feed_consumption to add a
// cow_id / item_id. That was rejected, and the reason is data integrity rather
// than taste. Those tables hold rows that MEAN "the whole farm produced this
// much on this day". Adding a nullable cow_id would give one table two
// meanings — NULL for a farm total, set for one animal — and every future
// SUM() over it would double-count the day it was first used both ways. The
// existing rows cannot be migrated into the new meaning either, because a farm
// total genuinely does not decompose into per-animal figures. Inventing that
// attribution is precisely the fabrication this phase is forbidden to commit.
//
// So the aggregates stay exactly as they are and mean exactly what they always
// meant, and the new per-animal / per-item detail lives beside them.
//
// ---------------------------------------------------------------------------
// WHAT IS DELIBERATELY NOT HERE
//
//   inventory_transactions   The audit log is ALREADY the stock movement
//                            ledger (see includes/inventory.php). A second one
//                            would be a second source of stock truth.
//   an animal-group table    Group feeding targets `locations`, which already
//                            exists. Inventing a grouping system nobody asked
//                            for would be speculative architecture.
//   a milk quality/lab table laboratory_tests already exists from Phase 4.
//                            Only the two lightweight OPERATIONAL fields a
//                            milking actually produces are added inline.
//   a withdrawal flag column Restriction is COMPUTED from withdrawal_periods
//                            at read time. Storing a copy would be a second
//                            answer to a question the health domain already
//                            owns, and it would go stale the moment a
//                            withdrawal was corrected or cancelled.
// ============================================================================

return function (PDO $conn): array {
    $log = [];

    foreach (['cows', 'farms', 'admin_users', 'inventory_items', 'inventory_levels', 'locations'] as $required) {
        if (!schemaTableExists($conn, $required)) {
            throw new RuntimeException("$required is missing — run earlier migrations first.");
        }
    }

    // =======================================================================
    // 1. MILK YIELDS — one animal, one date, one milking session.
    //
    // The RAW OPERATIONAL RECORD. milk_production remains the farm-level daily
    // aggregate; this is what a stockperson writes down at the parlour.
    //
    // UNIQUENESS: (farm_id, cow_id, production_date, session).
    //
    // Safe to enforce because the table is NEW — there is no historical
    // workflow it could invalidate. It is also the correct domain rule: an
    // animal is milked once per session, and a second row for the same session
    // is a double entry, which is the single most common recording error this
    // workflow will see. A correction is an EDIT of the existing row, which
    // keeps the audit trail showing what changed rather than leaving two
    // contradictory rows and no way to tell which is current.
    //
    // QUANTITY IS CANONICAL LITRES, per the Phase 2 units strategy
    // (DFMS_CANONICAL_UNITS['volume'] = 'l'). Nothing is stored in the user's
    // display unit; conversion happens at the edges only.
    // =======================================================================
    $log[] = schemaCreateTable($conn, 'milk_yields',
        "yield_id        INT AUTO_INCREMENT PRIMARY KEY,
         farm_id         INT NOT NULL,
         cow_id          INT NOT NULL,
         production_date DATE NOT NULL,
         session         VARCHAR(16) NOT NULL COMMENT 'validated against DFMS_MILKING_SESSIONS',
         quantity        DECIMAL(10,3) NOT NULL COMMENT 'CANONICAL litres — never a display unit',
         -- Operational quality only. Deliberately NOT laboratory chemistry:
         -- laboratory_tests (migration 008) already owns that, and duplicating
         -- it here would create a second place to look for a lab result.
         discarded       DECIMAL(10,3) NOT NULL DEFAULT 0.000 COMMENT 'canonical litres withheld at the parlour',
         abnormal        TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'stockperson flagged the milk as not normal',
         notes           VARCHAR(500) NULL,
         recorded_by     INT NULL,
         recorded_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         updated_at      TIMESTAMP NULL DEFAULT NULL,
         UNIQUE KEY uq_yield_animal_session (farm_id, cow_id, production_date, session),
         KEY idx_yield_farm_date (farm_id, production_date),
         KEY idx_yield_cow_date (cow_id, production_date)"
    );

    $log[] = schemaAddForeignKey($conn, 'milk_yields', 'fk_milk_yields_farm', 'farm_id', 'farms', 'farm_id', 'CASCADE');
    $log[] = schemaAddForeignKey($conn, 'milk_yields', 'fk_milk_yields_cow', 'cow_id', 'cows', 'cow_id', 'CASCADE');
    // recorded_by is SET NULL, not CASCADE: deleting a user must never delete
    // the production records they wrote. The farm produced that milk.
    $log[] = schemaAddForeignKey($conn, 'milk_yields', 'fk_milk_yields_user', 'recorded_by', 'admin_users', 'admin_id', 'SET NULL');

    // =======================================================================
    // 2. FEED CONSUMPTION EVENTS — what was actually fed, from which stock.
    //
    // The counterpart to milk_yields: legacy feed_consumption stays a
    // farm-level daily total, and this records the individual events that draw
    // down inventory.
    //
    // NO UNIQUE CONSTRAINT, and that is a considered decision rather than an
    // omission. Feeding the same item to the same target twice in one day is
    // ordinary practice (morning and evening rations are two real events), so
    // a uniqueness rule here would reject correct data. Milk is different:
    // an animal is milked once per SESSION, and the session is part of the key.
    //
    // SCOPE — farm | location | animal — is the smallest useful level the data
    // model already supports. There is no animal-group table in this codebase
    // and Phase 6 does not invent one; `locations` (migration 004) is the
    // existing grouping, so location IS the group.
    // =======================================================================
    $log[] = schemaCreateTable($conn, 'feed_consumption_events',
        "event_id         INT AUTO_INCREMENT PRIMARY KEY,
         farm_id          INT NOT NULL,
         consumed_on      DATE NOT NULL,
         item_id          INT NOT NULL COMMENT 'inventory_items — the single source of stock truth',
         quantity         DECIMAL(12,3) NOT NULL COMMENT 'CANONICAL kilograms',
         scope_type       VARCHAR(16) NOT NULL DEFAULT 'farm' COMMENT 'validated against DFMS_FEED_SCOPES',
         -- Exactly one of these is set, and only when scope_type names it.
         -- Enforced in the domain layer (feedRecordConsumption) rather than by
         -- a CHECK, because this schema targets MySQL and MariaDB versions
         -- whose CHECK support differs, and a constraint that silently does
         -- nothing on one engine is worse than one stated in code.
         location_id      INT NULL,
         cow_id           INT NULL,
         ration_id        INT NULL COMMENT 'the plan this event was fed from, when there was one',
         notes            VARCHAR(500) NULL,
         recorded_by      INT NULL,
         recorded_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         KEY idx_feedevent_farm_date (farm_id, consumed_on),
         KEY idx_feedevent_farm_item (farm_id, item_id)"
    );

    $log[] = schemaAddForeignKey($conn, 'feed_consumption_events', 'fk_feedevent_farm', 'farm_id', 'farms', 'farm_id', 'CASCADE');
    // RESTRICT on the item: an inventory item that has been consumed is part
    // of the farm's record and must not vanish out from under it.
    $log[] = schemaAddForeignKey($conn, 'feed_consumption_events', 'fk_feedevent_item', 'item_id', 'inventory_items', 'item_id', 'RESTRICT');
    $log[] = schemaAddForeignKey($conn, 'feed_consumption_events', 'fk_feedevent_location', 'location_id', 'locations', 'location_id', 'SET NULL');
    $log[] = schemaAddForeignKey($conn, 'feed_consumption_events', 'fk_feedevent_cow', 'cow_id', 'cows', 'cow_id', 'SET NULL');
    $log[] = schemaAddForeignKey($conn, 'feed_consumption_events', 'fk_feedevent_user', 'recorded_by', 'admin_users', 'admin_id', 'SET NULL');

    // =======================================================================
    // 3 & 4. RATIONS — a reusable PLAN. Never a consumption event.
    //
    // A ration says "this is what a lactating cow should get". Recording that
    // it was actually fed is a separate act that draws down stock. Keeping the
    // two apart is the whole point: a plan that silently consumed inventory
    // would make the stock level a projection rather than a count, and nobody
    // could tell which.
    //
    // Two tables rather than one JSON column because a ration LINE references
    // an inventory item by id, and a foreign key is how this codebase keeps a
    // reference honest.
    // =======================================================================
    $log[] = schemaCreateTable($conn, 'feed_rations',
        "ration_id  INT AUTO_INCREMENT PRIMARY KEY,
         farm_id    INT NOT NULL,
         name       VARCHAR(120) NOT NULL,
         purpose    VARCHAR(300) NULL COMMENT 'who this ration is for, in the farm\\'s own words',
         is_active  TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'retired rations are kept: past events reference them',
         created_by INT NULL,
         created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         updated_at TIMESTAMP NULL DEFAULT NULL,
         UNIQUE KEY uq_ration_name_per_farm (farm_id, name),
         KEY idx_ration_farm (farm_id)"
    );
    $log[] = schemaAddForeignKey($conn, 'feed_rations', 'fk_ration_farm', 'farm_id', 'farms', 'farm_id', 'CASCADE');
    $log[] = schemaAddForeignKey($conn, 'feed_rations', 'fk_ration_user', 'created_by', 'admin_users', 'admin_id', 'SET NULL');

    $log[] = schemaCreateTable($conn, 'feed_ration_items',
        "ration_item_id INT AUTO_INCREMENT PRIMARY KEY,
         farm_id        INT NOT NULL,
         ration_id      INT NOT NULL,
         item_id        INT NOT NULL,
         quantity       DECIMAL(12,3) NOT NULL COMMENT 'CANONICAL kilograms per animal per day',
         created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         UNIQUE KEY uq_ration_item (ration_id, item_id),
         KEY idx_ration_item_farm (farm_id)"
    );
    $log[] = schemaAddForeignKey($conn, 'feed_ration_items', 'fk_ration_item_farm', 'farm_id', 'farms', 'farm_id', 'CASCADE');
    $log[] = schemaAddForeignKey($conn, 'feed_ration_items', 'fk_ration_item_ration', 'ration_id', 'feed_rations', 'ration_id', 'CASCADE');
    $log[] = schemaAddForeignKey($conn, 'feed_ration_items', 'fk_ration_item_item', 'item_id', 'inventory_items', 'item_id', 'RESTRICT');

    // ration_id on an event is SET NULL rather than RESTRICT: a ration may be
    // deleted while the events fed from it remain true. Added last, because
    // feed_rations must exist before it can be referenced.
    $log[] = schemaAddForeignKey($conn, 'feed_consumption_events', 'fk_feedevent_ration', 'ration_id', 'feed_rations', 'ration_id', 'SET NULL');

    return $log;
};
