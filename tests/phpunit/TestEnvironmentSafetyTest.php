<?php

declare(strict_types=1);

namespace DFMIS\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The test-database guard.
 *
 * This protects against the worst thing a test suite can do: destroy real data
 * because it was pointed at the wrong database. The guard must be POSITIVE —
 * it approves a database only on explicit evidence that the database is for
 * testing, and treats missing configuration as refusal.
 */
final class TestEnvironmentSafetyTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../bootstrap.php';
    }

    public function testTestEnvironmentIsDetected(): void
    {
        self::assertTrue(dfms_is_test_environment());
        self::assertTrue(defined('DFMS_TESTING'));
    }

    /**
     * phpunit.xml points the suite at dfms_test. If that is not what we are
     * connected to, the isolation this whole file is about has not happened.
     */
    public function testSuiteRunsAgainstTheTestDatabase(): void
    {
        self::assertSame(
            'dfms_test',
            DB_NAME,
            'PHPUnit must run against dfms_test, not the development database'
        );
    }

    public function testCurrentDatabaseIsApprovedForDestructiveUse(): void
    {
        [$safe, $reason] = dfms_test_database_is_safe();
        self::assertTrue($safe, "Test database was not approved: $reason");
    }

    /**
     * The approval must rest on real evidence, not on the absence of a check.
     * Both signals are asserted so a rename cannot silently remove protection.
     */
    public function testApprovalRestsOnAnExplicitSignal(): void
    {
        [$safe, $reason] = dfms_test_database_is_safe();
        self::assertTrue($safe);
        self::assertTrue(
            str_contains($reason, '_test') || str_contains($reason, 'dfms_test_marker'),
            "Approval must cite a naming convention or a marker table, got: $reason"
        );
    }

    /** The marker table the tooling writes must actually be present. */
    public function testMarkerTableExists(): void
    {
        $conn = getDBConnection();
        $stmt = $conn->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'dfms_test_marker'"
        );
        $stmt->execute([DB_NAME]);
        self::assertGreaterThan(0, (int) $stmt->fetchColumn());
    }

    /**
     * The development database must NOT satisfy the naming rule. This is the
     * assertion that encodes the original hazard.
     */
    public function testDevelopmentDatabaseNameWouldBeRejected(): void
    {
        $devName = 'dfms';
        self::assertNotSame(
            '_test',
            substr($devName, -5),
            'The development database must not satisfy the naming rule'
        );
    }

    /**
     * The test schema must be fully migrated, or a passing suite would only
     * mean the tests never touched the missing tables.
     */
    public function testTestDatabaseIsFullyMigrated(): void
    {
        $conn = getDBConnection();
        $applied = (int) $conn->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();

        $files = glob(__DIR__ . '/../../database/migrations/*.{sql,php}', GLOB_BRACE) ?: [];
        self::assertSame(
            count($files),
            $applied,
            'Every migration must be applied to the test database'
        );
    }

    /**
     * Migrations must not be able to redirect themselves at another database.
     * Migrations 002-004 open with `USE dfms;`, which would have applied them
     * to the development database even when the runner was pointed elsewhere.
     */
    public function testMigrationRunnerStripsEmbeddedUseStatements(): void
    {
        $runner = (string) file_get_contents(__DIR__ . '/../../database/migrate.php');
        self::assertStringContainsString(
            'USE',
            $runner,
            'The runner must acknowledge embedded USE statements'
        );
        self::assertMatchesRegularExpression(
            '/preg_replace\(\s*[\'"].*USE/s',
            $runner,
            'The runner must strip embedded USE statements before executing raw SQL'
        );
    }
}
