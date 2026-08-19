<?php
// One-time / repeatable setup script for demo admin accounts.
// Run from the command line: php database/seed_admin.php
// Safe to re-run: existing accounts are updated in place, not duplicated.

require_once __DIR__ . '/../config/database.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

$accounts = [
    ['username' => 'admin',   'password' => 'admin123',   'role' => 'admin'],
    ['username' => 'manager', 'password' => 'manager123', 'role' => 'veterinarian'],
    ['username' => 'staff',   'password' => 'staff123',   'role' => 'staff'],
];

$conn = getDBConnection();

$stmt = $conn->prepare(
    "INSERT INTO admin_users (username, password_hash, role) VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = VALUES(role)"
);

foreach ($accounts as $account) {
    $hash = password_hash($account['password'], PASSWORD_BCRYPT);
    $stmt->execute([$account['username'], $hash, $account['role']]);
    echo "Seeded {$account['username']} ({$account['role']})\n";
}

echo "Done. Demo passwords are the same as before (e.g. admin123) but now stored hashed.\n";
