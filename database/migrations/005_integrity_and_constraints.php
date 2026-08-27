<?php
// ============================================================================
// Migration 005 — schema integrity
//
// Closes the storage-layer half of the tenancy model. The PHP layer already
// scoped every query correctly; the database did not enforce it.
//
// Addresses baseline audit T-1 (Critical), T-2, T-3, T-4, T-5, plus the audit
// log and login-throttle tables Phase 1 needs.
//
// NON-DESTRUCTIVE. No row is deleted or rewritten. Every constraint added
// here was verified against live data first; if a constraint cannot be
// created because real data violates it, the migration fails loudly rather
// than "fixing" the data by discarding it.
//
// Written in PHP against schema_helpers.php so it runs on MySQL 8 as well as
// MariaDB (baseline audit D-7).
// ============================================================================

return function (PDO $conn): array {
    $log = [];

    // -----------------------------------------------------------------------
    // Pre-flight. Refuse to proceed if data would be silently mangled.
    // -----------------------------------------------------------------------
    $dupTags = (int) $conn->query(
        "SELECT COUNT(*) FROM (SELECT farm_id, unique_id FROM cows
          GROUP BY farm_id, unique_id HAVING COUNT(*) > 1) d"
    )->fetchColumn();
    if ($dupTags > 0) {
        throw new RuntimeException(
            "Cannot add per-farm unique animal tags: $dupTags duplicate (farm_id, unique_id) pair(s) exist. "
            . "Resolve the duplicates before re-running — this migration will not delete data."
        );
    }

    $dupMilk = (int) $conn->query(
        "SELECT COUNT(*) FROM (SELECT farm_id, production_date FROM milk_production
          GROUP BY farm_id, production_date HAVING COUNT(*) > 1) d"
    )->fetchColumn();
    if ($dupMilk > 0) {
        throw new RuntimeException(
            "Cannot add unique (farm_id, production_date) on milk_production: $dupMilk duplicate(s) exist. "
            . "Merge them before re-running."
        );
    }

    // Orphaned tenant rows would make the foreign keys below fail with an
    // opaque engine error; check first so the message is actionable.
    $tenantTables = [
        'alerts', 'audit_log', 'feeding_schedules', 'feed_consumption', 'health_events',
        'inventory_items', 'inventory_levels', 'milk_production', 'temperature_readings',
    ];
    foreach ($tenantTables as $table) {
        if (!schemaTableExists($conn, $table) || !schemaColumnExists($conn, $table, 'farm_id')) {
            continue;
        }
        $orphans = (int) $conn->query(
            "SELECT COUNT(*) FROM `$table` t
             LEFT JOIN farms f ON f.farm_id = t.farm_id
             WHERE f.farm_id IS NULL"
        )->fetchColumn();
        if ($orphans > 0) {
            throw new RuntimeException(
                "Cannot add farm_id foreign key on `$table`: $orphans row(s) reference a farm that does not exist. "
                . "Reassign or archive them before re-running."
            );
        }
    }
    $log[] = '  pre-flight: no duplicate tags, no duplicate milk dates, no orphaned tenant rows';

    // -----------------------------------------------------------------------
    // T-1 (CRITICAL) — animal tags are unique PER FARM, not globally.
    //
    // A global unique index meant one tenant's INSERT could fail in a way that
    // confirmed another tenant's record existed, and made ear-tag numbering
    // unworkable across real farms.
    // -----------------------------------------------------------------------
    if (schemaIndexExists($conn, 'cows', 'unique_id')) {
        // Drop the old single-column unique. Any FK depending on it would
        // block this; none does (fk_cows_farm and fk_cows_location use other
        // columns).
        $log[] = schemaDropIndex($conn, 'cows', 'unique_id');
    }
    $log[] = schemaAddUnique($conn, 'cows', 'uq_cow_tag_per_farm', '`farm_id`, `unique_id`');

    // -----------------------------------------------------------------------
    // T-5 — one production record per farm per day, enforced by the database
    // rather than by a check-then-insert race in PHP.
    // -----------------------------------------------------------------------
    $log[] = schemaAddUnique($conn, 'milk_production', 'uq_milk_farm_date', '`farm_id`, `production_date`');

    // -----------------------------------------------------------------------
    // T-2 — remove `DEFAULT 1` from every farm_id.
    //
    // The default turned "forgot the tenant key" from a loud error into a
    // silent write into farm 1. Foreign keys are added AFTER this so a
    // defaulted insert now fails on NOT NULL instead of landing in farm 1.
    // -----------------------------------------------------------------------
    $defaultTables = array_merge($tenantTables, ['cows', 'admin_users']);
    foreach ($defaultTables as $table) {
        if (schemaColumnExists($conn, $table, 'farm_id')) {
            $log[] = schemaDropDefault($conn, $table, 'farm_id');
        }
    }

    // -----------------------------------------------------------------------
    // T-3 — foreign keys on farm_id.
    //
    // ON DELETE RESTRICT, deliberately not CASCADE: deleting a farm should be
    // blocked while it still owns data, not silently erase a customer's
    // records. Farm removal becomes an explicit, audited archival operation.
    // -----------------------------------------------------------------------
    foreach ($tenantTables as $table) {
        if (!schemaColumnExists($conn, $table, 'farm_id')) {
            continue;
        }
        $log[] = schemaAddForeignKey($conn, $table, 'fk_' . $table . '_farm', 'farm_id', 'farms', 'farm_id', 'RESTRICT');
    }

    // Child-to-parent integrity that was also missing.
    if (schemaTableExists($conn, 'audit_log')) {
        $log[] = schemaAddColumn($conn, 'audit_log', 'organization_id', 'INT NULL AFTER farm_id');
        $log[] = schemaAddColumn($conn, 'audit_log', 'ip_address', 'VARCHAR(45) NULL AFTER new_value');
        $log[] = schemaAddColumn($conn, 'audit_log', 'user_agent', 'VARCHAR(255) NULL AFTER ip_address');
        $log[] = schemaAddIndex($conn, 'audit_log', 'idx_audit_farm_created', '`farm_id`, `created_at`');
        $log[] = schemaAddIndex($conn, 'audit_log', 'idx_audit_actor', '`admin_id`, `created_at`');
        $log[] = schemaAddIndex($conn, 'audit_log', 'idx_audit_action', '`action`, `created_at`');
    }

    // -----------------------------------------------------------------------
    // T-4 — every tenant index leads with farm_id, because every read filters
    // on it first. Five tables previously had no farm_id index at all.
    // -----------------------------------------------------------------------
    $log[] = schemaAddIndex($conn, 'cows', 'idx_cows_farm_deleted', '`farm_id`, `deleted_at`');
    $log[] = schemaAddIndex($conn, 'health_events', 'idx_health_farm_cow_date', '`farm_id`, `cow_id`, `event_date`');
    $log[] = schemaAddIndex($conn, 'health_events', 'idx_health_farm_severity', '`farm_id`, `severity`, `event_date`');
    $log[] = schemaAddIndex($conn, 'feeding_schedules', 'idx_feeding_farm_time', '`farm_id`, `feed_time`');
    $log[] = schemaAddIndex($conn, 'feed_consumption', 'idx_feedcons_farm_date', '`farm_id`, `consumption_date`');
    $log[] = schemaAddIndex($conn, 'inventory_items', 'idx_items_farm_name', '`farm_id`, `item_name`');
    $log[] = schemaAddIndex($conn, 'inventory_levels', 'idx_levels_farm_item', '`farm_id`, `item_id`');
    $log[] = schemaAddIndex($conn, 'temperature_readings', 'idx_temp_farm_time', '`farm_id`, `reading_time`');
    $log[] = schemaAddIndex($conn, 'alerts', 'idx_alerts_farm_read_time', '`farm_id`, `is_read`, `alert_time`');

    // -----------------------------------------------------------------------
    // Alerts: acknowledgement columns.
    //
    // Baseline audit C-2: is_read was read in two places and written by none,
    // so the badge could only ever climb. These columns record WHO cleared an
    // alert and when, which the mark-read endpoint now populates.
    // -----------------------------------------------------------------------
    $log[] = schemaAddColumn($conn, 'alerts', 'read_at', 'TIMESTAMP NULL DEFAULT NULL AFTER is_read');
    $log[] = schemaAddColumn($conn, 'alerts', 'read_by', 'INT NULL AFTER read_at');

    // -----------------------------------------------------------------------
    // Inventory: per-item reorder level, replacing the threshold that was
    // hardcoded as `10` in three separate places (baseline audit C-9) and was
    // unit-blind — 10 bottles and 10 kg are not comparable quantities.
    // Existing rows keep the old behaviour via the 10 default.
    // -----------------------------------------------------------------------
    $log[] = schemaAddColumn($conn, 'inventory_items', 'reorder_level', 'DECIMAL(10,2) NOT NULL DEFAULT 10.00 AFTER unit');

    // -----------------------------------------------------------------------
    // Login throttling (no table existed; brute force was unbounded).
    // -----------------------------------------------------------------------
    $log[] = schemaCreateTable($conn, 'login_attempts',
        "attempt_id   BIGINT AUTO_INCREMENT PRIMARY KEY,
         username     VARCHAR(50) NULL,
         ip_address   VARCHAR(45) NULL,
         succeeded    TINYINT(1) NOT NULL DEFAULT 0,
         user_agent   VARCHAR(255) NULL,
         attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         INDEX idx_login_username_time (username, attempted_at),
         INDEX idx_login_ip_time (ip_address, attempted_at)"
    );

    // -----------------------------------------------------------------------
    // Uploaded files. storage.php has always handled bytes safely but nothing
    // recorded WHICH tenant owned a file, so access control on retrieval was
    // impossible (baseline audit Step 8).
    // -----------------------------------------------------------------------
    $log[] = schemaCreateTable($conn, 'stored_files',
        "file_id       INT AUTO_INCREMENT PRIMARY KEY,
         farm_id       INT NOT NULL,
         category      VARCHAR(32) NOT NULL,
         stored_path   VARCHAR(255) NOT NULL,
         original_name VARCHAR(255) NULL,
         mime_type     VARCHAR(64) NOT NULL,
         byte_size     INT NOT NULL,
         entity_type   VARCHAR(32) NULL,
         entity_id     INT NULL,
         uploaded_by   INT NULL,
         created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         deleted_at    TIMESTAMP NULL DEFAULT NULL,
         UNIQUE KEY uq_stored_path (stored_path),
         INDEX idx_files_farm (farm_id, category),
         INDEX idx_files_entity (entity_type, entity_id)"
    );
    $log[] = schemaAddForeignKey($conn, 'stored_files', 'fk_files_farm', 'farm_id', 'farms', 'farm_id', 'RESTRICT');

    return $log;
};
