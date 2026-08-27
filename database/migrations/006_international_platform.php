<?php
// ============================================================================
// Migration 006 — international platform foundation
//
// Adds the settings surface DFMIS needs to serve organizations in more than
// one country, plus the SaaS scaffolding (subscription lifecycle, feature
// flags, notification preferences) that later phases attach to.
//
// DESIGN RULE THROUGHOUT: every new international setting is NULLABLE, and
// NULL means "inherit". There is no column-level default duplicating a
// platform default, because two places holding the same default drift apart.
// Resolution order is farm -> organization -> platform, implemented once in
// includes/settings.php.
//
// NON-DESTRUCTIVE. No existing row is modified except:
//   - organization_users backfill, which records MEMBERSHIP ONLY as 'member'
//     and deliberately grants no management authority (see below).
// No column is dropped, no data is reinterpreted.
//
// Written in PHP against schema_helpers.php so it runs on MySQL 8 and MariaDB
// alike (baseline audit D-7).
// ============================================================================

return function (PDO $conn): array {
    $log = [];

    // -----------------------------------------------------------------------
    // Organization — international defaults.
    //
    // `name` already exists and remains the DISPLAY name. `legal_name` is the
    // registered entity name, which differs from the trading name in most
    // jurisdictions and is what invoices and contracts must carry later.
    // -----------------------------------------------------------------------
    $log[] = schemaAddColumn($conn, 'organizations', 'legal_name', 'VARCHAR(200) NULL AFTER name');
    $log[] = schemaAddColumn($conn, 'organizations', 'country_code', 'CHAR(2) NULL COMMENT \'ISO 3166-1 alpha-2\' AFTER legal_name');
    $log[] = schemaAddColumn($conn, 'organizations', 'default_timezone', 'VARCHAR(64) NULL COMMENT \'IANA tz identifier; NULL = platform default\' AFTER country_code');
    $log[] = schemaAddColumn($conn, 'organizations', 'default_locale', 'VARCHAR(10) NULL COMMENT \'BCP 47 language tag; NULL = platform default\' AFTER default_timezone');
    $log[] = schemaAddColumn($conn, 'organizations', 'default_currency', 'CHAR(3) NULL COMMENT \'ISO 4217; NULL = platform default\' AFTER default_locale');
    $log[] = schemaAddColumn($conn, 'organizations', 'measurement_system', "ENUM('metric','imperial') NULL COMMENT 'NULL = platform default' AFTER default_currency");

    // -----------------------------------------------------------------------
    // Farm — overrides. NULL means "inherit from the organization".
    //
    // A farm genuinely can differ from its organization on all of these: an
    // organization headquartered in one country may operate farms in another,
    // and those farms keep local time, local units and local labour.
    // -----------------------------------------------------------------------
    $log[] = schemaAddColumn($conn, 'farms', 'country_code', 'CHAR(2) NULL COMMENT \'ISO 3166-1 alpha-2; NULL = inherit organization\' AFTER farm_subtype');
    $log[] = schemaAddColumn($conn, 'farms', 'timezone', 'VARCHAR(64) NULL COMMENT \'IANA tz identifier; NULL = inherit organization\' AFTER country_code');
    $log[] = schemaAddColumn($conn, 'farms', 'locale', 'VARCHAR(10) NULL COMMENT \'BCP 47; NULL = inherit organization\' AFTER timezone');
    $log[] = schemaAddColumn($conn, 'farms', 'currency', 'CHAR(3) NULL COMMENT \'ISO 4217; NULL = inherit organization\' AFTER locale');
    $log[] = schemaAddColumn($conn, 'farms', 'measurement_system', "ENUM('metric','imperial') NULL COMMENT 'NULL = inherit organization' AFTER currency");

    // -----------------------------------------------------------------------
    // Locations — international postal addressing.
    //
    // Deliberately GENERIC field names. `admin_area` covers state, province,
    // region, prefecture, county and division; `locality` covers city, town
    // and village. Naming them after one country's subdivisions is exactly the
    // assumption that makes a schema untranslatable later.
    //
    // No legacy Bangladesh-specific columns exist anywhere in this schema
    // (verified against information_schema before writing this migration), so
    // there is no historical address data to preserve or migrate.
    // -----------------------------------------------------------------------
    $log[] = schemaAddColumn($conn, 'locations', 'country_code', 'CHAR(2) NULL COMMENT \'ISO 3166-1 alpha-2\' AFTER location_type');
    $log[] = schemaAddColumn($conn, 'locations', 'admin_area', 'VARCHAR(100) NULL COMMENT \'state / province / region / division\' AFTER country_code');
    $log[] = schemaAddColumn($conn, 'locations', 'locality', 'VARCHAR(100) NULL COMMENT \'city / town / village\' AFTER admin_area');
    $log[] = schemaAddColumn($conn, 'locations', 'postal_code', 'VARCHAR(20) NULL AFTER locality');
    $log[] = schemaAddColumn($conn, 'locations', 'address_line1', 'VARCHAR(200) NULL AFTER postal_code');
    $log[] = schemaAddColumn($conn, 'locations', 'address_line2', 'VARCHAR(200) NULL AFTER address_line1');
    // Coordinates: 7 decimal places is ~1cm, far beyond what a farm needs, and
    // is the conventional precision for WGS84 storage.
    $log[] = schemaAddColumn($conn, 'locations', 'latitude', 'DECIMAL(10,7) NULL AFTER address_line2');
    $log[] = schemaAddColumn($conn, 'locations', 'longitude', 'DECIMAL(10,7) NULL AFTER latitude');
    $log[] = schemaAddIndex($conn, 'locations', 'idx_locations_country', '`country_code`');

    // -----------------------------------------------------------------------
    // Subscription lifecycle — EXTENDS the existing columns.
    //
    // `organizations.plan` and `.status` already existed. They are widened,
    // not replaced, and every existing value stays valid:
    //   plan:   starter|professional|enterprise  ->  + free
    //   status: active|trial|suspended|cancelled ->  + past_due, expired
    //
    // MODIFY on an ENUM preserves rows whose value remains in the new list.
    // Both existing organizations are 'active' with a listed plan, verified
    // before writing this migration.
    // -----------------------------------------------------------------------
    $conn->exec(
        "ALTER TABLE organizations
         MODIFY COLUMN plan ENUM('free','starter','professional','enterprise')
         NOT NULL DEFAULT 'starter'"
    );
    $log[] = '  MOD   organizations.plan -> adds free (existing values preserved)';

    $conn->exec(
        "ALTER TABLE organizations
         MODIFY COLUMN status ENUM('active','trial','suspended','cancelled','past_due','expired')
         NOT NULL DEFAULT 'active'"
    );
    $log[] = '  MOD   organizations.status -> adds past_due, expired (existing values preserved)';

    // Lifecycle dates. DATE, not DATETIME: a billing period boundary is a
    // calendar concept the customer reads in their own timezone, not an
    // instant. Both NULL for existing organizations, which means "no trial,
    // no expiry" — nobody is retroactively put on a clock.
    $log[] = schemaAddColumn($conn, 'organizations', 'trial_ends_at', 'DATE NULL AFTER status');
    $log[] = schemaAddColumn($conn, 'organizations', 'current_period_ends_at', 'DATE NULL AFTER trial_ends_at');
    $log[] = schemaAddIndex($conn, 'organizations', 'idx_org_status', '`status`, `plan`');

    // -----------------------------------------------------------------------
    // Feature flags.
    //
    // Resolution is farm -> organization -> platform -> registry default,
    // mirroring the settings hierarchy so there is one mental model, not two.
    //
    // `scope_id` is polymorphic (0 for platform, otherwise an organization or
    // farm id) and therefore carries NO foreign key — a single column cannot
    // reference two tables. The alternative, nullable organization_id and
    // farm_id columns, cannot be covered by a UNIQUE key because MySQL treats
    // NULLs as distinct, which would permit duplicate flag rows. Uniqueness
    // matters more here than referential integrity on a row that is discarded
    // when its scope disappears.
    // -----------------------------------------------------------------------
    $log[] = schemaCreateTable($conn, 'feature_flags',
        "flag_id     INT AUTO_INCREMENT PRIMARY KEY,
         flag_key    VARCHAR(64) NOT NULL,
         scope       ENUM('platform','organization','farm') NOT NULL,
         scope_id    INT NOT NULL DEFAULT 0 COMMENT '0 for platform; organization_id or farm_id otherwise',
         enabled     TINYINT(1) NOT NULL DEFAULT 0,
         updated_by  INT NULL,
         created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
         UNIQUE KEY uq_flag_scope (flag_key, scope, scope_id),
         INDEX idx_flag_lookup (scope, scope_id)"
    );

    // -----------------------------------------------------------------------
    // Notification preferences.
    //
    // This is NOT a notification system. `alerts` remains the business-event
    // record and is untouched. This table records only which CHANNELS a user
    // wants a given event CATEGORY delivered through, so that when delivery is
    // built the preference model already exists and is tenant-scoped.
    //
    // Scoped per user PER FARM: someone who is a vet at one farm and a viewer
    // at another will not want the same notifications from both.
    //
    // Absence of a row means "use the registry default" — the table stores
    // deviations only, so adding a new category does not require backfilling
    // a row for every user.
    // -----------------------------------------------------------------------
    $log[] = schemaCreateTable($conn, 'notification_preferences',
        "preference_id INT AUTO_INCREMENT PRIMARY KEY,
         admin_id      INT NOT NULL,
         farm_id       INT NOT NULL,
         category      VARCHAR(64) NOT NULL COMMENT 'health | inventory | production | system',
         channel       ENUM('in_app','email','sms','push') NOT NULL,
         enabled       TINYINT(1) NOT NULL DEFAULT 1,
         created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
         updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
         UNIQUE KEY uq_notif_pref (admin_id, farm_id, category, channel),
         INDEX idx_notif_farm (farm_id, category)"
    );
    $log[] = schemaAddForeignKey($conn, 'notification_preferences', 'fk_notif_admin', 'admin_id', 'admin_users', 'admin_id', 'CASCADE');
    $log[] = schemaAddForeignKey($conn, 'notification_preferences', 'fk_notif_farm', 'farm_id', 'farms', 'farm_id', 'CASCADE');

    // -----------------------------------------------------------------------
    // organization_users backfill.
    //
    // Migration 003 backfilled this table from admin_users, but it ran BEFORE
    // seed_admin.php created any accounts, so every farm-1 user has farm
    // membership and no organization row. requireAuth() already tolerates that
    // (allowedOrganizationIds() unions org-via-farm), but currentOrganizationRole()
    // returns null, so organization-level permissions can never be satisfied.
    //
    // Membership is recorded as 'member' — the LOWEST role — deliberately.
    // A migration must not silently hand anyone management authority over an
    // organization; that is a decision an owner makes explicitly. Elevation is
    // done by seed_admin.php for the demo account, and by the members UI later.
    // -----------------------------------------------------------------------
    $backfilled = $conn->exec(
        "INSERT IGNORE INTO organization_users (organization_id, admin_id, role)
         SELECT DISTINCT f.organization_id, fu.admin_id, 'member'
           FROM farm_users fu
           JOIN farms f ON f.farm_id = fu.farm_id
          WHERE NOT EXISTS (
              SELECT 1 FROM organization_users ou
               WHERE ou.organization_id = f.organization_id
                 AND ou.admin_id = fu.admin_id
          )"
    );
    $log[] = "  DATA  backfilled organization_users: {$backfilled} membership row(s) as 'member' (no authority granted)";

    return $log;
};
