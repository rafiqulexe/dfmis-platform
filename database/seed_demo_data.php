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

$stmt = $conn->prepare(
    "UPDATE cows SET name = ?, breed = ?, health_status = ?, reproductive_status = ?, lactation_status = ? WHERE unique_id = ?"
);
foreach ($cows as $uniqueId => $c) {
    $stmt->execute([$c['name'], $c['breed'], $c['health'], $c['repro'], $c['lactation'], $uniqueId]);
}
echo "Updated " . count($cows) . " cow profiles.\n";

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

// ---- Health events: shift to recent dates, keep severities already set ----
$conn->exec("UPDATE health_events SET event_date = DATE_SUB(CURDATE(), INTERVAL FLOOR(1 + RAND() * 12) DAY) WHERE cow_id IN (1,2,3,4)");
echo "Refreshed health event dates.\n";

// ---- Alerts: recent timestamps, linked to their cow where relevant ----
$conn->exec("UPDATE alerts SET alert_time = DATE_SUB(NOW(), INTERVAL FLOOR(1 + RAND() * 72) HOUR)");
echo "Refreshed alert timestamps.\n";

echo "Done.\n";
