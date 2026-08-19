-- Migration 004: multi-vertical foundation
--   mysql -u root dfms < database/migrations/004_multivertical_foundation.sql
--
-- The platform's first vertical is dairy/livestock, but the CORE layer
-- (alerts, AI, audit, tenancy) must not assume it. This migration removes
-- the livestock-specific columns that had leaked into core tables and adds
-- the two structural pieces that are cheap now and expensive later:
-- farm typing, and the location tier between farm and entity.

USE dfms;

-- ------------------------------
-- Farm typing. The dashboard, navigation and enabled modules will key off
-- these rather than assuming cows.
-- ------------------------------
ALTER TABLE farms ADD COLUMN IF NOT EXISTS farm_type
    ENUM('livestock','poultry','aquaculture','crop','greenhouse','mixed')
    NOT NULL DEFAULT 'livestock' AFTER name;
ALTER TABLE farms ADD COLUMN IF NOT EXISTS farm_subtype VARCHAR(50) NULL AFTER farm_type;

UPDATE farms SET farm_type = 'livestock', farm_subtype = 'dairy' WHERE farm_id = 1;

-- ------------------------------
-- locations: the tier between farm and entity. Deliberately NOT called
-- "barns" — the same table serves a poultry house, a pond, or a field, so
-- the vertical lives in location_type rather than in the table name.
-- ------------------------------
CREATE TABLE IF NOT EXISTS locations (
    location_id INT AUTO_INCREMENT PRIMARY KEY,
    farm_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    location_type ENUM('barn','house','pond','field','greenhouse','paddock','other')
        NOT NULL DEFAULT 'barn',
    capacity INT NULL,
    notes TEXT NULL,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO locations (location_id, farm_id, name, location_type, capacity)
    SELECT 1, 1, 'Barn A', 'barn', 20 WHERE NOT EXISTS (SELECT 1 FROM locations WHERE location_id = 1);
INSERT INTO locations (location_id, farm_id, name, location_type, capacity)
    SELECT 2, 1, 'Barn B', 'barn', 20 WHERE NOT EXISTS (SELECT 1 FROM locations WHERE location_id = 2);

ALTER TABLE cows ADD COLUMN IF NOT EXISTS location_id INT NULL AFTER farm_id;

-- ------------------------------
-- De-specialize the CORE tables. A notification or an AI call must be able
-- to reference a flock, pond or field as naturally as a cow, so the target
-- becomes a generic (entity_type, entity_id) pair.
-- ------------------------------
ALTER TABLE alerts ADD COLUMN IF NOT EXISTS entity_type VARCHAR(32) NULL AFTER farm_id;
ALTER TABLE alerts ADD COLUMN IF NOT EXISTS entity_id INT NULL AFTER entity_type;
UPDATE alerts SET entity_type = 'cow', entity_id = cow_id WHERE cow_id IS NOT NULL AND entity_id IS NULL;

ALTER TABLE ai_usage ADD COLUMN IF NOT EXISTS entity_type VARCHAR(32) NULL AFTER feature;
ALTER TABLE ai_usage ADD COLUMN IF NOT EXISTS entity_id INT NULL AFTER entity_type;
UPDATE ai_usage SET entity_type = 'cow', entity_id = cow_id WHERE cow_id IS NOT NULL AND entity_id IS NULL;

-- The old columns are left in place (nullable, unused) so this migration is
-- reversible. Drop them in a later migration once nothing reads them:
--   ALTER TABLE alerts   DROP FOREIGN KEY fk_alerts_cow, DROP COLUMN cow_id;
--   ALTER TABLE ai_usage DROP COLUMN cow_id;

-- ------------------------------
-- Indexes
-- ------------------------------
CREATE INDEX IF NOT EXISTS idx_locations_farm ON locations (farm_id);
CREATE INDEX IF NOT EXISTS idx_cows_location ON cows (location_id);
CREATE INDEX IF NOT EXISTS idx_alerts_entity ON alerts (entity_type, entity_id);

-- ------------------------------
-- Foreign keys
-- ------------------------------
ALTER TABLE locations ADD CONSTRAINT fk_locations_farm FOREIGN KEY (farm_id) REFERENCES farms(farm_id);
ALTER TABLE cows ADD CONSTRAINT fk_cows_location FOREIGN KEY (location_id) REFERENCES locations(location_id) ON DELETE SET NULL;
