-- Migration 003: organization layer + explicit membership model
--   mysql -u root dfms < database/migrations/003_organizations_and_membership.sql
--
-- WHY ORGANIZATIONS NOW, NOT LATER
-- A farm belongs to exactly one organization, so organization context is
-- always *derivable* from a row's farm_id. Business tables therefore keep
-- farm_id as their ONLY tenant key — we deliberately do not denormalize
-- organization_id onto them, because two independent tenant keys on the
-- same row can drift out of sync and a stale one becomes a security hole.
-- One tenant key, one source of truth, joined up to the organization.
--
-- WHY MEMBERSHIP TABLES
-- admin_users.role was a single global role per user. That cannot express
-- "Dr. Rahman is a veterinarian at Farm A1 and a viewer at Farm B1", nor
-- "one user belongs to two organizations" — both of which the SaaS model
-- requires. Roles therefore move onto the membership rows themselves.

USE dfms;

-- ------------------------------
-- organizations (the customer / billing boundary)
-- ------------------------------
CREATE TABLE IF NOT EXISTS organizations (
    organization_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    -- Subscription foundation. No billing logic is built yet; these columns
    -- exist so plan gating and usage limits can be added without a migration
    -- against live customer data later.
    plan ENUM('starter','professional','enterprise') NOT NULL DEFAULT 'starter',
    status ENUM('active','trial','suspended','cancelled') NOT NULL DEFAULT 'active',
    settings JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO organizations (organization_id, name, plan, status)
    SELECT 1, 'Green Valley Dairy Group', 'enterprise', 'active'
    WHERE NOT EXISTS (SELECT 1 FROM organizations WHERE organization_id = 1);

-- ------------------------------
-- farms belong to exactly one organization
-- ------------------------------
ALTER TABLE farms ADD COLUMN IF NOT EXISTS organization_id INT NOT NULL DEFAULT 1 AFTER farm_id;
UPDATE farms SET organization_id = 1 WHERE organization_id IS NULL OR organization_id = 0;

-- ------------------------------
-- Platform-level administration is a property of the user account itself,
-- separate from any per-tenant role.
-- ------------------------------
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS is_platform_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER role;

-- ------------------------------
-- organization_users: one user <-> many organizations, with an org-level role
-- ------------------------------
CREATE TABLE IF NOT EXISTS organization_users (
    organization_user_id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL,
    admin_id INT NOT NULL,
    role ENUM('owner','admin','member') NOT NULL DEFAULT 'member',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_org_user (organization_id, admin_id)
);

-- ------------------------------
-- farm_users: per-farm role. This is the authoritative role for all
-- day-to-day permission checks.
-- ------------------------------
CREATE TABLE IF NOT EXISTS farm_users (
    farm_user_id INT AUTO_INCREMENT PRIMARY KEY,
    farm_id INT NOT NULL,
    admin_id INT NOT NULL,
    role ENUM('farm_manager','veterinarian','staff','accountant','viewer') NOT NULL DEFAULT 'viewer',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_farm_user (farm_id, admin_id)
);

-- ------------------------------
-- Backfill membership from the legacy single-role model so existing
-- accounts keep working identically after this migration.
-- ------------------------------
INSERT IGNORE INTO organization_users (organization_id, admin_id, role)
SELECT 1, admin_id, CASE WHEN role = 'admin' THEN 'owner' ELSE 'member' END
FROM admin_users;

INSERT IGNORE INTO farm_users (farm_id, admin_id, role)
SELECT u.farm_id, u.admin_id,
       CASE u.role
           WHEN 'admin' THEN 'farm_manager'
           WHEN 'veterinarian' THEN 'veterinarian'
           WHEN 'staff' THEN 'staff'
           ELSE 'viewer'
       END
FROM admin_users u;

-- The original 'admin' account also acts as the platform operator in dev.
UPDATE admin_users SET is_platform_admin = 1 WHERE role = 'admin';

-- ------------------------------
-- AI usage ledger: every AI call is attributed to a tenant, user and
-- feature so plan-based limits and per-customer cost reporting can be
-- added later without retrofitting the AI call sites.
-- ------------------------------
CREATE TABLE IF NOT EXISTS ai_usage (
    ai_usage_id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT NOT NULL,
    farm_id INT NULL,
    admin_id INT NULL,
    feature VARCHAR(64) NOT NULL,
    model VARCHAR(64) NULL,
    cow_id INT NULL,
    input_tokens INT NOT NULL DEFAULT 0,
    output_tokens INT NOT NULL DEFAULT 0,
    status ENUM('success','error','blocked') NOT NULL DEFAULT 'success',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------
-- Indexes
-- ------------------------------
CREATE INDEX IF NOT EXISTS idx_farms_org ON farms (organization_id);
CREATE INDEX IF NOT EXISTS idx_org_users_admin ON organization_users (admin_id);
CREATE INDEX IF NOT EXISTS idx_farm_users_admin ON farm_users (admin_id);
CREATE INDEX IF NOT EXISTS idx_ai_usage_org_created ON ai_usage (organization_id, created_at);

-- ------------------------------
-- Foreign keys
-- ------------------------------
ALTER TABLE farms ADD CONSTRAINT fk_farms_org FOREIGN KEY (organization_id) REFERENCES organizations(organization_id);
ALTER TABLE organization_users ADD CONSTRAINT fk_org_users_org FOREIGN KEY (organization_id) REFERENCES organizations(organization_id) ON DELETE CASCADE;
ALTER TABLE organization_users ADD CONSTRAINT fk_org_users_admin FOREIGN KEY (admin_id) REFERENCES admin_users(admin_id) ON DELETE CASCADE;
ALTER TABLE farm_users ADD CONSTRAINT fk_farm_users_farm FOREIGN KEY (farm_id) REFERENCES farms(farm_id) ON DELETE CASCADE;
ALTER TABLE farm_users ADD CONSTRAINT fk_farm_users_admin FOREIGN KEY (admin_id) REFERENCES admin_users(admin_id) ON DELETE CASCADE;
ALTER TABLE ai_usage ADD CONSTRAINT fk_ai_usage_org FOREIGN KEY (organization_id) REFERENCES organizations(organization_id);
