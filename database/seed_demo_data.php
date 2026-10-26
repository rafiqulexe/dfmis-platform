<?php
// Demo/dev data seeder: gives the seeded cows realistic identity + status,
// and regenerates the time-series tables (milk, feed, temperature) with
// recent dates so the dashboard has something real to show. Safe to re-run.
// Run from the command line: php database/seed_demo_data.php

require_once __DIR__ . '/../config/database.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

$conn = getDBConnection();

// ---- Cow identity + status ----
$cows = [
    'COW123' => ['name' => 'Ganga', 'breed' => 'Local',              'health' => 'healthy', 'repro' => 'open',     'lactation' => 'lactating'],
    'BD001'  => ['name' => 'Nodi',  'breed' => 'Pabna',               'health' => 'at_risk', 'repro' => 'open',     'lactation' => 'lactating'],
    'BD002'  => ['name' => 'Chaya', 'breed' => 'Sahiwal',             'health' => 'healthy', 'repro' => 'pregnant', 'lactation' => 'dry'],
    'BD003'  => ['name' => 'Tara',  'breed' => 'Red Chittagong',      'health' => 'sick',    'repro' => 'open',     'lactation' => 'lactating'],
    'BD004'  => ['name' => 'Megh',  'breed' => 'Holstein Friesian',   'health' => 'healthy', 'repro' => 'open',     'lactation' => 'lactating'],
    'BD005'  => ['name' => 'Runi',  'breed' => 'North Bengal Grey',   'health' => 'healthy', 'repro' => 'pregnant', 'lactation' => 'lactating'],
];

// Upsert so the demo herd is restored even if rows were removed while
// exploring the app.
//
// Every lookup below is scoped by farm_id. Animal tags are unique PER FARM
// since migration 005, so matching on unique_id alone would now be ambiguous
// (and used to work only because of the global-unique bug it replaced).
$update = $conn->prepare(
    "UPDATE cows SET name = ?, breed = ?, health_status = ?, reproductive_status = ?, lactation_status = ?, deleted_at = NULL
      WHERE unique_id = ? AND farm_id = 1"
);
$insert = $conn->prepare(
    "INSERT INTO cows (farm_id, unique_id, name, breed, health_status, reproductive_status, lactation_status, date_of_birth, notes)
     VALUES (1, ?, ?, ?, ?, ?, ?, '2021-06-01', 'Demo herd record')"
);
foreach ($cows as $uniqueId => $c) {
    $update->execute([$c['name'], $c['breed'], $c['health'], $c['repro'], $c['lactation'], $uniqueId]);
    if ($update->rowCount() === 0) {
        $exists = $conn->prepare("SELECT 1 FROM cows WHERE unique_id = ? AND farm_id = 1");
        $exists->execute([$uniqueId]);
        if (!$exists->fetchColumn()) {
            $insert->execute([$uniqueId, $c['name'], $c['breed'], $c['health'], $c['repro'], $c['lactation']]);
        }
    }
}
echo "Ensured " . count($cows) . " cow profiles.\n";

// ---- Second farm, used only by tests/tenant_isolation_test.sh ----
// Kept reproducible here so the isolation suite never depends on
// hand-created database state.
$conn->exec("INSERT INTO organizations (organization_id, name, plan, status)
             SELECT 2, 'Rival Holdings (test fixture)', 'starter', 'active'
             WHERE NOT EXISTS (SELECT 1 FROM organizations WHERE organization_id = 2)");
$conn->exec("INSERT INTO farms (farm_id, organization_id, name)
             SELECT 2, 2, 'Rival Farm (isolation test)'
             WHERE NOT EXISTS (SELECT 1 FROM farms WHERE farm_id = 2)");
// Force the fixture into its own organization so the suite exercises true
// cross-tenant separation, not merely two farms of the same customer.
$conn->exec("UPDATE farms SET organization_id = 2 WHERE farm_id = 2");
$conn->exec("INSERT INTO cows (farm_id, unique_id, name, breed, date_of_birth, notes)
             SELECT 2, 'RIVAL001', 'Should Be Invisible', 'Test', '2022-01-01',
                    'Belongs to farm 2 - must never be visible to farm 1 users'
             WHERE NOT EXISTS (SELECT 1 FROM cows WHERE unique_id = 'RIVAL001')");
echo "Ensured cross-tenant test fixture (farm 2).\n";

// ---- Milk production: last 30 days, with a real dip in the last 7 ----
$conn->exec("DELETE FROM milk_production WHERE farm_id = 1");
$insertMilk = $conn->prepare("INSERT INTO milk_production (production_date, total_milk_yield, farm_id) VALUES (?, ?, 1)");
for ($daysAgo = 29; $daysAgo >= 0; $daysAgo--) {
    $date = date('Y-m-d', strtotime("-$daysAgo days"));
    $base = 108 + sin($daysAgo / 3) * 6; // gentle natural wobble
    if ($daysAgo < 7) {
        $base *= 0.92; // recent dip — this is what the dashboard's insight should surface
    }
    $yield = round($base + mt_rand(-30, 30) / 10, 2);
    $insertMilk->execute([$date, $yield]);
}
echo "Seeded 30 days of milk production.\n";

// ---- Feed consumption: last 30 days ----
$conn->exec("DELETE FROM feed_consumption WHERE farm_id = 1");
$insertFeed = $conn->prepare("INSERT INTO feed_consumption (consumption_date, total_feed_consumed, farm_id) VALUES (?, ?, 1)");
for ($daysAgo = 29; $daysAgo >= 0; $daysAgo--) {
    $date = date('Y-m-d', strtotime("-$daysAgo days"));
    $amount = round(50 + mt_rand(-40, 60) / 10, 2);
    $insertFeed->execute([$date, $amount]);
}
echo "Seeded 30 days of feed consumption.\n";

// ---- Temperature readings: last 7 days, 3x/day ----
$conn->exec("DELETE FROM temperature_readings WHERE farm_id = 1");
$insertTemp = $conn->prepare("INSERT INTO temperature_readings (reading_time, temperature, farm_id) VALUES (?, ?, 1)");
foreach (['08:00:00', '14:00:00', '20:00:00'] as $time) {
    for ($daysAgo = 6; $daysAgo >= 0; $daysAgo--) {
        $datetime = date('Y-m-d', strtotime("-$daysAgo days")) . " $time";
        $temp = round(36.5 + mt_rand(-8, 12) / 10, 1);
        $insertTemp->execute([$datetime, $temp]);
    }
}
echo "Seeded 7 days of temperature readings.\n";

// ---- Health events: ensure each demo cow has recent history ----
$conn->exec("DELETE FROM health_events WHERE farm_id = 1");
$insertEvent = $conn->prepare(
    "INSERT INTO health_events (farm_id, cow_id, event_date, event_type, severity, description)
     SELECT 1, cow_id, DATE_SUB(CURDATE(), INTERVAL ? DAY), ?, ?, ? FROM cows WHERE unique_id = ?"
);
$events = [
    ['BD003', 2,  'Treatment',   'urgent',   'Foot and Mouth Disease treatment — under veterinary care'],
    ['BD001', 4,  'Deworming',   'moderate', 'Regular deworming treatment, monitoring appetite'],
    ['COW123', 9, 'Vaccination', 'routine',  'Annual vaccination completed'],
    ['BD002', 12, 'Check-up',    'routine',  'Routine pregnancy check — progressing normally'],
];
foreach ($events as [$uniqueId, $daysAgo, $type, $severity, $desc]) {
    $insertEvent->execute([$daysAgo, $type, $severity, $desc, $uniqueId]);
}
echo "Seeded health events.\n";

// ---- Alerts: recent timestamps, linked to their cow where relevant ----
$conn->exec("UPDATE alerts SET alert_time = DATE_SUB(NOW(), INTERVAL FLOOR(1 + RAND() * 72) HOUR)");
echo "Refreshed alert timestamps.\n";

// ---- Cross-ORGANIZATION user, used by tests/security_regression_test.sh ----
// Farm 2 sits in organization 2, so this account exercises true cross-tenant
// separation at the organization boundary, not merely two farms of one
// customer. Without a member on the other side, cross-org access can only be
// tested from one direction.
$rivalHash = password_hash('rival123', PASSWORD_BCRYPT);
$stmt = $conn->prepare(
    // farm_id stated explicitly — migration 005 removed its default (T-2), and
    // omitting it only ever "worked" because the development database runs
    // without STRICT_TRANS_TABLES. rival belongs to farm 2, set up just below.
    "INSERT INTO admin_users (username, password_hash, role, farm_id) VALUES ('rival', ?, 'staff', 2)
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash),
                             farm_id = VALUES(farm_id)"
);
$stmt->execute([$rivalHash]);

$rivalId = $conn->query("SELECT admin_id FROM admin_users WHERE username = 'rival'")->fetchColumn();
if ($rivalId) {
    $stmt = $conn->prepare(
        "INSERT INTO farm_users (farm_id, admin_id, role) VALUES (2, ?, 'farm_manager')
         ON DUPLICATE KEY UPDATE role = VALUES(role)"
    );
    $stmt->execute([$rivalId]);

    $stmt = $conn->prepare(
        "INSERT INTO organization_users (organization_id, admin_id, role) VALUES (2, ?, 'owner')
         ON DUPLICATE KEY UPDATE role = VALUES(role)"
    );
    $stmt->execute([$rivalId]);
    echo "Ensured cross-organization test user 'rival' (org 2 / farm 2).\n";
}

// ---- Membership-less user, for the missing-tenant-context test ----
// Baseline audit C-1 was exactly this state: an authenticated account with no
// farm_users row. It must render a handled "no farm assigned" page, not crash.
$orphanHash = password_hash('orphan123', PASSWORD_BCRYPT);
$stmt = $conn->prepare(
    // farm_id stated explicitly for the same reason as rival above. The value
    // is 1 and carries NO meaning: orphan's whole purpose is to have no farm,
    // and that is expressed by the deliberate ABSENCE of farm_users and
    // organization_users rows below — which is what the application actually
    // reads. admin_users.farm_id is vestigial and NOT NULL, so it needs some
    // value; what it must not be is silently defaulted.
    "INSERT INTO admin_users (username, password_hash, role, farm_id) VALUES ('orphan', ?, 'staff', 1)
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash),
                             farm_id = VALUES(farm_id)"
);
$stmt->execute([$orphanHash]);
$orphanId = $conn->query("SELECT admin_id FROM admin_users WHERE username = 'orphan'")->fetchColumn();
if ($orphanId) {
    // Deliberately NO farm_users / organization_users row.
    $conn->prepare("DELETE FROM farm_users WHERE admin_id = ?")->execute([$orphanId]);
    $conn->prepare("DELETE FROM organization_users WHERE admin_id = ?")->execute([$orphanId]);
    echo "Ensured membership-less test user 'orphan' (no farm).\n";
}

// Print the ids the test suite needs, so it never hardcodes an autoincrement
// value (baseline audit F-4).
$rivalCow = $conn->query("SELECT cow_id FROM cows WHERE farm_id = 2 AND unique_id = 'RIVAL001'")->fetchColumn();
echo "FIXTURE_RIVAL_COW_ID=" . ($rivalCow ?: '') . "\n";

echo "Done.\n";
