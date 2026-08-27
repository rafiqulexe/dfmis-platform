<?php
// ============================================================================
// TEST BOOTSTRAP & SAFETY GUARD
//
// Loaded by PHPUnit (see phpunit.xml) and available to any other test that
// wants the same protection.
//
// THE PROBLEM THIS SOLVES
// Before Phase 2B every suite ran against the development database `dfms`, and
// tests/international_unit_test.php issues `DELETE FROM feature_flags`. That is
// survivable on a laptop and unacceptable anywhere else — the same command run
// with the wrong environment loaded would delete real configuration.
//
// THE RULE
// A destructive test operation may run ONLY against a database that has
// positively identified itself as a test database. Absence of configuration is
// NEVER treated as permission: a missing environment variable makes the guard
// refuse, not proceed. That is the specific failure mode the brief calls out.
//
// A database qualifies as a test database when EITHER:
//   1. its name ends in `_test`, or
//   2. it contains a `dfms_test_marker` table (created by tools/test-db.php)
// Both are positive signals that something deliberately made this database for
// testing. Neither can be satisfied by accident.
// ============================================================================

require_once __DIR__ . '/../config/database.php';

/**
 * Is the connected database safe to destroy data in?
 *
 * Returns [bool $safe, string $reason] — the reason is reported to the
 * developer so a refusal is actionable rather than mysterious.
 */
function dfms_test_database_is_safe(?PDO $conn = null): array {
    $name = DB_NAME;

    if ($name === '' || $name === null) {
        return [false, 'No database name is configured.'];
    }

    // Signal 1 — naming convention.
    if (substr($name, -5) === '_test') {
        return [true, "Database '$name' ends in _test."];
    }

    // Signal 2 — an explicit marker table.
    try {
        $conn = $conn ?? getDBConnection();
        $stmt = $conn->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'dfms_test_marker'"
        );
        $stmt->execute([$name]);
        if ((int) $stmt->fetchColumn() > 0) {
            return [true, "Database '$name' carries a dfms_test_marker table."];
        }
    } catch (Throwable $e) {
        return [false, 'Could not inspect the database: ' . $e->getMessage()];
    }

    return [false, "Database '$name' is not identifiable as a test database. "
        . "Create one with: php tools/test-db.php --create"];
}

/**
 * Abort unless the connected database is a test database.
 *
 * Call at the top of any test that writes or deletes. Exits non-zero with an
 * explanation rather than throwing, so a misconfigured run fails loudly and
 * legibly instead of surfacing as an unrelated assertion failure.
 */
function dfms_require_test_database(?PDO $conn = null): void {
    [$safe, $reason] = dfms_test_database_is_safe($conn);
    if ($safe) {
        return;
    }

    fwrite(STDERR, "\n");
    fwrite(STDERR, "==============================================================\n");
    fwrite(STDERR, " REFUSING TO RUN DESTRUCTIVE TESTS\n");
    fwrite(STDERR, "==============================================================\n");
    fwrite(STDERR, " $reason\n\n");
    fwrite(STDERR, " A destructive test must never run against a database merely\n");
    fwrite(STDERR, " because configuration was missing.\n");
    fwrite(STDERR, "==============================================================\n\n");
    exit(1);
}

/** True when the process is running as part of a test run. */
function dfms_is_test_environment(): bool {
    return defined('DFMS_TESTING')
        || getenv('DFMS_ENV') === 'testing'
        || (PHP_SAPI === 'cli' && str_contains(implode(' ', $_SERVER['argv'] ?? []), 'phpunit'));
}

if (!defined('DFMS_TESTING')) {
    define('DFMS_TESTING', true);
}
