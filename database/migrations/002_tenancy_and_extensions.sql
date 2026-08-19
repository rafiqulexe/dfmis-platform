-- Migration 002: multi-tenant-ready schema + entity extensions
-- Run once, after database.sql has created the baseline schema:
--   mysql -u root dfms < database/migrations/002_tenancy_and_extensions.sql
--
-- Adds a `farms` table as the tenant boundary and a farm_id column on every
-- business table. There is exactly one farm today (Green Valley Dairy Farm), but
-- every row is already tagged so enabling a second farm later is a query
-- filter, not a schema rewrite. Uses MariaDB's `IF NOT EXISTS` extensions
-- throughout so this file is safe to re-run.

USE dfms;

-- ------------------------------
-- Table: farms (tenant root)
-- ------------------------------
CREATE TABLE IF NOT EXISTS farms (
    farm_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO farms (farm_id, name)
    SELECT 1, 'Green Valley Dairy Farm'
    WHERE NOT EXISTS (SELECT 1 FROM farms WHERE farm_id = 1);

-- ------------------------------
-- farm_id on every tenant-scoped table. Existing rows default to farm 1
-- (the only farm that has ever existed), so this backfills correctly.
-- ------------------------------
ALTER TABLE admin_users        ADD COLUMN IF NOT EXISTS farm_id INT NOT NULL DEFAULT 1 AFTER role;
ALTER TABLE cows                ADD COLUMN IF NOT EXISTS farm_id INT NOT NULL DEFAULT 1 AFTER cow_id;
ALTER TABLE health_events       ADD COLUMN IF NOT EXISTS farm_id INT NOT NULL DEFAULT 1 AFTER event_id;
ALTER TABLE feeding_schedules   ADD COLUMN IF NOT EXISTS farm_id INT NOT NULL DEFAULT 1 AFTER schedule_id;
ALTER TABLE feed_consumption    ADD COLUMN IF NOT EXISTS farm_id INT NOT NULL DEFAULT 1 AFTER consumption_id;
ALTER TABLE milk_production     ADD COLUMN IF NOT EXISTS farm_id INT NOT NULL DEFAULT 1 AFTER production_id;
ALTER TABLE inventory_items     ADD COLUMN IF NOT EXISTS farm_id INT NOT NULL DEFAULT 1 AFTER item_id;
ALTER TABLE inventory_levels    ADD COLUMN IF NOT EXISTS farm_id INT NOT NULL DEFAULT 1 AFTER inventory_id;
ALTER TABLE temperature_readings ADD COLUMN IF NOT EXISTS farm_id INT NOT NULL DEFAULT 1 AFTER reading_id;
ALTER TABLE alerts              ADD COLUMN IF NOT EXISTS farm_id INT NOT NULL DEFAULT 1 AFTER alert_id;

-- ------------------------------
-- cows: identity + the three independent status dimensions the Command
-- Center dashboard and cow profile need (a cow can be Healthy AND Pregnant
-- AND Lactating at once — these are not one enum).
-- ------------------------------
ALTER TABLE cows ADD COLUMN IF NOT EXISTS name VARCHAR(100) NULL AFTER unique_id;
ALTER TABLE cows ADD COLUMN IF NOT EXISTS sex ENUM('female','male') NOT NULL DEFAULT 'female' AFTER name;
ALTER TABLE cows ADD COLUMN IF NOT EXISTS breed VARCHAR(100) NULL AFTER sex;
ALTER TABLE cows ADD COLUMN IF NOT EXISTS photo_path VARCHAR(255) NULL AFTER breed;
ALTER TABLE cows ADD COLUMN IF NOT EXISTS health_status ENUM('healthy','at_risk','sick','treatment') NOT NULL DEFAULT 'healthy' AFTER photo_path;
ALTER TABLE cows ADD COLUMN IF NOT EXISTS reproductive_status ENUM('open','pregnant','dry') NOT NULL DEFAULT 'open' AFTER health_status;
ALTER TABLE cows ADD COLUMN IF NOT EXISTS lactation_status ENUM('lactating','dry','not_applicable') NOT NULL DEFAULT 'lactating' AFTER reproductive_status;
ALTER TABLE cows ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL DEFAULT NULL AFTER notes;

-- ------------------------------
-- health_events: severity drives the dashboard's "needs attention" list
-- ------------------------------
ALTER TABLE health_events ADD COLUMN IF NOT EXISTS severity ENUM('routine','moderate','urgent') NOT NULL DEFAULT 'routine' AFTER event_type;
ALTER TABLE health_events ADD COLUMN IF NOT EXISTS recorded_by INT NULL AFTER description;

-- ------------------------------
-- alerts: link to the entity the alert is actually about, and give it a
-- real severity instead of overloading alert_type
-- ------------------------------
ALTER TABLE alerts ADD COLUMN IF NOT EXISTS cow_id INT NULL AFTER farm_id;
ALTER TABLE alerts ADD COLUMN IF NOT EXISTS severity ENUM('info','warning','critical') NOT NULL DEFAULT 'info' AFTER alert_type;

-- ------------------------------
-- audit_log: who changed what, when
-- ------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
    audit_id INT AUTO_INCREMENT PRIMARY KEY,
    farm_id INT NOT NULL DEFAULT 1,
    admin_id INT NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INT NULL,
    old_value TEXT NULL,
    new_value TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------
-- Indexes for the query patterns the dashboard and list pages actually use
-- ------------------------------
CREATE INDEX IF NOT EXISTS idx_cows_farm ON cows (farm_id);
CREATE INDEX IF NOT EXISTS idx_health_events_cow_date ON health_events (cow_id, event_date);
CREATE INDEX IF NOT EXISTS idx_milk_production_farm_date ON milk_production (farm_id, production_date);
CREATE INDEX IF NOT EXISTS idx_alerts_farm_read ON alerts (farm_id, is_read);
CREATE INDEX IF NOT EXISTS idx_alerts_cow ON alerts (cow_id);
CREATE INDEX IF NOT EXISTS idx_audit_log_entity ON audit_log (entity_type, entity_id);

-- ------------------------------
-- Foreign keys (run once — not wrapped in IF NOT EXISTS; safe on a fresh
-- baseline import, which is the only supported way to apply this file)
-- ------------------------------
ALTER TABLE cows ADD CONSTRAINT fk_cows_farm FOREIGN KEY (farm_id) REFERENCES farms(farm_id);
ALTER TABLE alerts ADD CONSTRAINT fk_alerts_cow FOREIGN KEY (cow_id) REFERENCES cows(cow_id) ON DELETE SET NULL;
