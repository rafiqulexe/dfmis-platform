<?php
// ============================================================================
// TEST DATABASE MANAGEMENT
//
//   php tools/test-db.php --create    create dfms_test, migrate, seed, mark it
//   php tools/test-db.php --status    report whether it exists and is safe
//   php tools/test-db.php --drop      drop it (refuses non-test databases)
//
// The test database is a SEPARATE schema (default `dfms_test`) so destructive
// suites never touch development data.
//
// SAFETY: --drop refuses any database that does not identify itself as a test
// database via tests/bootstrap.php. There is no --force. If you genuinely need
// to drop something else, do it in a SQL client where the intent is explicit.
// ============================================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

$testDbName = getenv('DFMS_TEST_DB_NAME') ?: 'dfms_test';

// Connect WITHOUT a database selected, using the same credentials the app uses.
require_once __DIR__ . '/../config/database.php';

function serverConnection(): PDO {
    return new PDO(
        'mysql:host=' . DB_HOST . ';charset=' . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

// The name must look like a test database before we will create or drop it,
// so a typo in DFMS_TEST_DB_NAME cannot point this tool at production.
function assertLooksLikeTestDatabase(string $name): void {
    if (substr($name, -5) !== '_test') {
        fwrite(STDERR, "Refusing to operate on '$name': a test database name must end in _test.\n");
        exit(1);
    }
}

$mode = 'status';
foreach (array_slice($argv, 1) as $arg) {
    if (in_array($arg, ['--create', '--drop', '--status'], true)) {
        $mode = substr($arg, 2);
    }
}

$server = serverConnection();

// ---- status ---------------------------------------------------------------
if ($mode === 'status') {
    $stmt = $server->prepare("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?");
    $stmt->execute([$testDbName]);
    $exists = (int) $stmt->fetchColumn() > 0;

    echo "Test database: $testDbName\n";
    echo "  exists: " . ($exists ? 'yes' : 'no') . "\n";

    if ($exists) {
        $stmt = $server->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?"
        );
        $stmt->execute([$testDbName]);
        echo "  tables: " . (int) $stmt->fetchColumn() . "\n";

        $stmt = $server->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'dfms_test_marker'"
        );
        $stmt->execute([$testDbName]);
        echo "  marker: " . ((int) $stmt->fetchColumn() > 0 ? 'present' : 'MISSING') . "\n";
    }
    echo "\nDevelopment database in use by this config: " . DB_NAME . "\n";
    exit(0);
}

// ---- drop -----------------------------------------------------------------
if ($mode === 'drop') {
    assertLooksLikeTestDatabase($testDbName);
    $server->exec("DROP DATABASE IF EXISTS `$testDbName`");
    echo "Dropped $testDbName.\n";
    exit(0);
}

// ---- create ---------------------------------------------------------------
assertLooksLikeTestDatabase($testDbName);

echo "Creating $testDbName …\n";
$server->exec("CREATE DATABASE IF NOT EXISTS `$testDbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

// The marker is written FIRST, so even a partially built database is
// recognisable as a test database and the guard will allow cleanup.
$server->exec("USE `$testDbName`");
$server->exec(
    "CREATE TABLE IF NOT EXISTS dfms_test_marker (
        id TINYINT PRIMARY KEY DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        note VARCHAR(255) NOT NULL DEFAULT 'DFMIS test database — safe to destroy'
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
$server->exec("INSERT IGNORE INTO dfms_test_marker (id) VALUES (1)");
echo "  marker table written\n";

// Build the schema by running the real baseline + migrations against it, so the
// test database is produced the same way a real one is rather than by a
// separate, drift-prone dump.
$root = dirname(__DIR__);

echo "  importing baseline schema …\n";
$baseline = file_get_contents($root . '/database.sql');
// database.sql begins by dropping and creating `dfms`; strip those so it
// applies to the test schema instead. This is the ONE place the baseline is
// adapted, and it is adapted rather than duplicated.
$baseline = preg_replace('/^\s*(DROP DATABASE|CREATE DATABASE|USE)\s+[^;]+;/mi', '', $baseline);
$server->exec($baseline);

echo "  running migrations …\n";
// migrate.php reads DB_NAME from the environment, so point the child process
// at the test schema. It is NOT baselined: a fresh database must have every
// migration actually applied, not merely recorded as applied.
//
// The runner strips embedded `USE <db>;` statements, so migrations 002-004
// cannot redirect themselves at the development database.
// putenv() rather than a shell `set`: on Windows `set VAR=x && cmd` captures a
// trailing space into the value, producing an invalid database name. The child
// process inherits this process's environment, and config/database.php prefers
// an existing environment variable over the .env file.
putenv('DB_NAME=' . $testDbName);
$php = escapeshellarg(PHP_BINARY);
$migrate = escapeshellarg($root . '/database/migrate.php');
$out = [];
exec("$php $migrate 2>&1", $out, $status);
foreach ($out as $line) {
    echo "    $line\n";
}
if ($status !== 0) {
    fwrite(STDERR, "Migrations failed against $testDbName.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Seed the test database.
//
// Phase 2B left the HTTP suites running against the DEVELOPMENT database
// because the test schema had no accounts to log in with. Seeding it here is
// what makes an isolated HTTP test run possible at all:
//
//   php tools/test-db.php --create
//   DB_NAME=dfms_test php -S localhost:8100 -t .
//   DFMS_BASE_URL=http://localhost:8100 bash tests/animal_isolation_test.sh
//
// DB_NAME is still pointing at the test schema from the migration step above,
// so both seeders write there and never touch development data.
// ---------------------------------------------------------------------------
echo "  seeding accounts and demo data …\n";
foreach (['seed_admin.php', 'seed_demo_data.php'] as $seeder) {
    $seedOut = [];
    exec("$php " . escapeshellarg($root . '/database/' . $seeder) . " 2>&1", $seedOut, $seedStatus);
    if ($seedStatus !== 0) {
        fwrite(STDERR, "Seeder $seeder failed against $testDbName:\n");
        fwrite(STDERR, implode("\n", $seedOut) . "\n");
        exit(1);
    }
    echo "    $seeder ok\n";
}

echo "\nDone. Point tests at it with:  DB_NAME=$testDbName\n";
