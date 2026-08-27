<?php
// ============================================================================
// MIGRATION RUNNER
//
// Baseline audit Blocker 3: applying migrations was four manual `mysql <`
// commands with nothing recording what had been applied. This runner keeps a
// schema_migrations table so an environment's state is knowable.
//
//   php database/migrate.php            apply pending migrations
//   php database/migrate.php --status   show applied / pending
//   php database/migrate.php --baseline mark existing .sql files as applied
//                                       (for a database built the old way)
//
// .php migrations receive $conn and may use database/schema_helpers.php for
// cross-engine conditional DDL. .sql migrations are executed verbatim and are
// retained only for history — new migrations should be .php so they run on
// MySQL 8 as well as MariaDB (baseline audit D-7).
// ============================================================================

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/schema_helpers.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

$conn = getDBConnection();

$conn->exec(
    "CREATE TABLE IF NOT EXISTS schema_migrations (
        migration   VARCHAR(191) NOT NULL PRIMARY KEY,
        applied_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        checksum    CHAR(64) NULL
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$applied = $conn->query("SELECT migration FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);
$applied = array_flip($applied);

$files = glob(__DIR__ . '/migrations/*.{sql,php}', GLOB_BRACE) ?: [];
sort($files, SORT_NATURAL);

$args = array_slice($argv, 1);
$mode = 'apply';
if (in_array('--status', $args, true)) {
    $mode = 'status';
}
if (in_array('--baseline', $args, true)) {
    $mode = 'baseline';
}

// ---- status ----------------------------------------------------------------
if ($mode === 'status') {
    echo "Migration status (" . schemaDatabaseName($conn) . "):\n";
    foreach ($files as $file) {
        $name = basename($file);
        echo (isset($applied[$name]) ? '  applied  ' : '  PENDING  ') . $name . "\n";
    }
    exit(0);
}

// ---- baseline --------------------------------------------------------------
// Marks every .sql migration as applied without running it. Use once, on a
// database that was built with the old manual `mysql <` sequence, so the
// runner does not try to re-apply them.
if ($mode === 'baseline') {
    $stmt = $conn->prepare("INSERT IGNORE INTO schema_migrations (migration, checksum) VALUES (?, ?)");
    $count = 0;
    foreach ($files as $file) {
        if (substr($file, -4) !== '.sql') {
            continue;
        }
        $name = basename($file);
        if (isset($applied[$name])) {
            continue;
        }
        $stmt->execute([$name, hash_file('sha256', $file)]);
        echo "  baselined  $name\n";
        $count++;
    }
    echo "Baselined $count migration(s). Run without --baseline to apply pending ones.\n";
    exit(0);
}

// ---- apply -----------------------------------------------------------------
$record = $conn->prepare("INSERT INTO schema_migrations (migration, checksum) VALUES (?, ?)");
$pending = 0;

foreach ($files as $file) {
    $name = basename($file);
    if (isset($applied[$name])) {
        continue;
    }
    $pending++;
    echo "Applying $name\n";

    try {
        if (substr($file, -4) === '.php') {
            // Migration returns a callable: fn(PDO $conn): string[]
            $migration = require $file;
            if (!is_callable($migration)) {
                throw new RuntimeException("Migration $name did not return a callable.");
            }
            foreach ((array) $migration($conn) as $line) {
                echo $line . "\n";
            }
        } else {
            $sql = file_get_contents($file);
            $conn->exec($sql);
            echo "  executed raw SQL\n";
        }
        $record->execute([$name, hash_file('sha256', $file)]);
        echo "  OK\n";
    } catch (Throwable $e) {
        // DDL is not transactional in MySQL/MariaDB, so a partial failure
        // leaves the schema half-changed. Say so loudly rather than pretending
        // the migration succeeded.
        fwrite(STDERR, "  FAILED: " . $e->getMessage() . "\n");
        fwrite(STDERR, "  $name was NOT recorded as applied. Inspect the schema before retrying.\n");
        exit(1);
    }
}

echo $pending === 0 ? "Nothing to apply — schema is up to date.\n" : "Applied $pending migration(s).\n";
