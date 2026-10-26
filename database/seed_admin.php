<?php
// One-time / repeatable setup script for demo admin accounts.
// Run from the command line: php database/seed_admin.php
// Safe to re-run: existing accounts are updated in place, not duplicated.

require_once __DIR__ . '/../config/database.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

// The legacy `role` column seeds the initial farm_users membership via
// migration 003; farm_users.role is authoritative thereafter.
$accounts = [
    ['username' => 'admin',      'password' => 'admin123',      'role' => 'admin'],
    ['username' => 'manager',    'password' => 'manager123',    'role' => 'veterinarian'],
    ['username' => 'staff',      'password' => 'staff123',      'role' => 'staff'],
    // Accountant and viewer exist so the two most restricted roles are
    // actually exercisable — without them, role-aware navigation cannot be
    // verified, since every other role can view every module.
    ['username' => 'accountant', 'password' => 'accountant123', 'role' => 'staff'],
    ['username' => 'viewer',     'password' => 'viewer123',     'role' => 'staff'],
];

// Farm-level roles that the legacy column cannot express.
$farmRoleOverrides = [
    'accountant' => 'accountant',
    'viewer'     => 'viewer',
];

$conn = getDBConnection();

// farm_id is supplied explicitly, and must be.
//
// Migration 005 drops `DEFAULT 1` from every farm_id (baseline finding T-2) so
// that forgetting the tenant key is a loud error rather than a silent write
// into farm 1. This INSERT used to omit it and survived only because the
// development database runs without STRICT_TRANS_TABLES: MariaDB substituted 0
// and warned. That is the exact failure T-2 exists to prevent, and it is how
// the rival and orphan fixtures ended up pointing at farm 0, which does not
// exist. Under a stock MariaDB — CI's, and most production ones — it is fatal.
//
// The column itself is vestigial: nothing in the application reads it, because
// migration 003 moved tenant membership to farm_users. It is still NOT NULL, so
// a value is required; these accounts are seeded as members of farm 1 below, so
// 1 is the truthful one. It is included in the UPDATE clause as well, so
// re-running this script repairs rows written before this fix.
$stmt = $conn->prepare(
    "INSERT INTO admin_users (username, password_hash, role, farm_id) VALUES (?, ?, ?, 1)
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash),
                             role = VALUES(role),
                             farm_id = VALUES(farm_id)"
);

foreach ($accounts as $account) {
    $hash = password_hash($account['password'], PASSWORD_BCRYPT);
    $stmt->execute([$account['username'], $hash, $account['role']]);
    echo "Seeded {$account['username']} ({$account['role']})\n";
}

// Ensure every demo account is a member of farm 1 with the intended
// farm-level role. Idempotent, and only runs once migration 003 has
// created the membership tables.
$hasMembership = $conn->query("SHOW TABLES LIKE 'farm_users'")->fetchColumn();
if ($hasMembership) {
    $link = $conn->prepare(
        "INSERT INTO farm_users (farm_id, admin_id, role) VALUES (1, ?, ?)
         ON DUPLICATE KEY UPDATE role = VALUES(role)"
    );
    $lookup = $conn->prepare("SELECT admin_id FROM admin_users WHERE username = ?");

    foreach ($accounts as $account) {
        $lookup->execute([$account['username']]);
        $adminId = $lookup->fetchColumn();
        if (!$adminId) {
            continue;
        }
        // A lookup rather than a match with a `default` arm: every role used in
        // $accounts above is mapped here, so a fallback would be unreachable
        // dead code (reported by static analysis).
        //
        // Indexing directly is also the better behaviour for a seeding script:
        // if someone adds a legacy role and forgets to map it, this fails
        // loudly during seeding rather than silently granting 'viewer' and
        // leaving a wrongly-permissioned account behind.
        $legacyRoleToFarmRole = [
            'admin'        => 'farm_manager',
            'veterinarian' => 'veterinarian',
            'staff'        => 'staff',
        ];
        $farmRole = $farmRoleOverrides[$account['username']]
            ?? $legacyRoleToFarmRole[$account['role']];
        $link->execute([$adminId, $farmRole]);
        echo "  farm role: {$account['username']} -> {$farmRole}\n";
    }
}

// ---------------------------------------------------------------------------
// Organization membership.
//
// Migration 003 backfilled organization_users from admin_users, but it ran
// BEFORE this script first created any accounts, so no farm-1 user ever got an
// organization row. Migration 006 backfilled them as 'member' — deliberately
// without authority, since a migration must not silently hand anyone control
// of an organization.
//
// Granting the demo `admin` account ownership is that explicit decision, made
// here where it is visible, so organization-level settings are reachable in
// development without depending on the platform-admin bypass.
// ---------------------------------------------------------------------------
$hasOrgMembership = $conn->query("SHOW TABLES LIKE 'organization_users'")->fetchColumn();
if ($hasOrgMembership) {
    $orgLink = $conn->prepare(
        "INSERT INTO organization_users (organization_id, admin_id, role) VALUES (1, ?, ?)
         ON DUPLICATE KEY UPDATE role = VALUES(role)"
    );
    $lookup = $conn->prepare("SELECT admin_id FROM admin_users WHERE username = ?");

    // Only `admin` is elevated. Everyone else stays 'member'.
    $orgRoles = ['admin' => 'owner'];

    foreach ($accounts as $account) {
        $lookup->execute([$account['username']]);
        $adminId = $lookup->fetchColumn();
        if (!$adminId) {
            continue;
        }
        $orgRole = $orgRoles[$account['username']] ?? 'member';
        $orgLink->execute([$adminId, $orgRole]);
        echo "  org role:  {$account['username']} -> {$orgRole}\n";
    }
}

echo "Done. Demo passwords are unchanged (e.g. admin123) and stored hashed.\n";
