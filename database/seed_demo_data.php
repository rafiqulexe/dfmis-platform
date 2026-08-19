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
$update = $conn->prepare(
    "UPDATE cows SET name = ?, breed = ?, health_status = ?, reproductive_status = ?, lactation_status = ?, deleted_at = NULL WHERE unique_id = ?"
);
$insert = $conn->prepare(
    "INSERT INTO cows (farm_id, unique_id, name, breed, health_status, reproductive_status, lactation_status, date_of_birth, notes)
     VALUES (1, ?, ?, ?, ?, ?, ?, '2021-06-01', 'Demo herd record')"
);
foreach ($cows as $uniqueId => $c) {
    $update->execute([$c['name'], $c['breed'], $c['health'], $c['repro'], $c['lactation'], $uniqueId]);
    if ($update->rowCount() === 0) {
        $exists = $conn->prepare("SELECT 1 FROM cows WHERE unique_id = ?");
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

echo "Done.\n";
