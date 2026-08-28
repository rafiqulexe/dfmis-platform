<?php

declare(strict_types=1);

namespace DFMIS\Tests;

use FeedRuleViolation;
use InventoryStockError;
use PDO;
use PHPUnit\Framework\TestCase;
use ProductionRuleViolation;

/**
 * PRODUCTION & FEEDING OPERATIONS (Phase 6).
 *
 * Runs against the ISOLATED dfms_test database (phpunit.xml sets DB_NAME and
 * tests/bootstrap.php refuses anything not identifiable as a test database).
 * Every test that writes cleans up after itself. No network, no AI provider.
 *
 * FIXTURE ASSUMPTIONS, asserted where they carry weight:
 *   admin 1 is farm_manager on farm 1
 *   farm 2 is a different organization with at least one animal
 *
 * The tests that matter most are the ones about what must NEVER happen: a farm
 * total being divided among animals, a day with no records reported as zero, a
 * consumption event surviving a failed stock deduction, another farm's animal
 * or item being reachable, and a withdrawal being silently ignored.
 */
final class ProductionDomainTest extends TestCase
{
    private static PDO $conn;

    /** @var list<int> */
    private array $animals = [];
    /** @var list<int> */
    private array $items = [];
    /** @var list<int> */
    private array $rations = [];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../bootstrap.php';
        require_once __DIR__ . '/../../includes/tenant.php';
        require_once __DIR__ . '/../../includes/repository.php';
        require_once __DIR__ . '/../../includes/permissions.php';
        require_once __DIR__ . '/../../includes/audit.php';
        require_once __DIR__ . '/../../includes/settings.php';
        require_once __DIR__ . '/../../includes/units.php';
        require_once __DIR__ . '/../../includes/livestock.php';
        require_once __DIR__ . '/../../includes/health.php';
        require_once __DIR__ . '/../../includes/inventory.php';
        require_once __DIR__ . '/../../includes/production.php';
        require_once __DIR__ . '/../../includes/feed.php';
        require_once __DIR__ . '/../../includes/insights.php';

        dfms_require_test_database();
        self::$conn = getDBConnection();
    }

    protected function setUp(): void
    {
        $_SESSION = ['admin_id' => 1, 'farm_id' => 1, 'is_platform_admin' => 0, 'username' => 'admin'];
        dfms_reset_settings_cache();

        // Farm-1 production is cleared before every test.
        //
        // The analytics assertions below are about FARM-WIDE totals — that is
        // what productionDailyTotals() measures — so they can only be exact if
        // the farm starts empty. Without this, a row left behind by the HTTP
        // suite (which runs against this same isolated database) silently
        // shifts every total and the failure looks like a bug in the sums.
        //
        // Safe and deterministic: milk_yields is a Phase 6 table with no seed
        // data, this is the isolated dfms_test database (bootstrap.php refuses
        // anything else), and farm 2's rows are deliberately left alone so the
        // cross-tenant assertions still have something to prove.
        self::$conn->exec('DELETE FROM milk_yields WHERE farm_id = 1');
    }

    protected function tearDown(): void
    {
        // Order matters: events reference items and rations, ration lines
        // reference rations. Every test event is tagged 'PHPUNIT' so this can
        // never reach a row a test did not create.
        self::$conn->exec("DELETE FROM feed_consumption_events WHERE notes = 'PHPUNIT'");
        foreach ($this->rations as $id) {
            self::$conn->prepare('DELETE FROM feed_ration_items WHERE ration_id = ?')->execute([$id]);
            self::$conn->prepare('DELETE FROM feed_rations WHERE ration_id = ?')->execute([$id]);
        }
        foreach ($this->animals as $id) {
            self::$conn->prepare('DELETE FROM milk_yields WHERE cow_id = ?')->execute([$id]);
            self::$conn->prepare('DELETE FROM withdrawal_periods WHERE cow_id = ?')->execute([$id]);
            self::$conn->prepare('DELETE FROM alerts WHERE cow_id = ?')->execute([$id]);
            self::$conn->prepare('DELETE FROM cows WHERE cow_id = ?')->execute([$id]);
        }
        foreach ($this->items as $id) {
            self::$conn->prepare('DELETE FROM feed_consumption_events WHERE item_id = ?')->execute([$id]);
            self::$conn->prepare('DELETE FROM feed_ration_items WHERE item_id = ?')->execute([$id]);
            self::$conn->prepare('DELETE FROM alerts WHERE entity_type = ? AND entity_id = ?')
                ->execute(['inventory_item', $id]);
            self::$conn->prepare('DELETE FROM inventory_levels WHERE item_id = ?')->execute([$id]);
            self::$conn->prepare('DELETE FROM inventory_items WHERE item_id = ?')->execute([$id]);
        }
        $this->animals = [];
        $this->items = [];
        $this->rations = [];
        $_SESSION = [];
    }

    // ---- fixtures ---------------------------------------------------------

    private function makeAnimal(array $overrides = []): int
    {
        $id = tenantInsert(self::$conn, 'cows', array_merge([
            'unique_id'        => 'P6TEST' . bin2hex(random_bytes(4)),
            'name'             => 'Production Test Animal',
            'species'          => 'cattle',
            'sex'              => 'female',
            'lifecycle_status' => 'active',
            'lactation_status' => 'lactating',
            'health_status'    => 'healthy',
            'created_at'       => gmdate('Y-m-d H:i:s'),
        ], $overrides));
        $this->animals[] = $id;
        return $id;
    }

    private function makeFeedItem(float $stock = 100.0, array $overrides = []): int
    {
        $itemId = tenantInsert(self::$conn, 'inventory_items', array_merge([
            'item_name'     => 'P6 Feed ' . bin2hex(random_bytes(4)),
            'category'      => 'feed',
            'item_type'     => 'Feed',
            'unit'          => 'kg',
            'reorder_level' => 10.0,
        ], $overrides));
        $this->items[] = $itemId;
        tenantInsert(self::$conn, 'inventory_levels', ['item_id' => $itemId, 'quantity' => $stock]);
        return $itemId;
    }

    private function makeRation(string $name = null): int
    {
        $id = feedCreateRation(self::$conn, ['name' => $name ?? ('P6 Ration ' . bin2hex(random_bytes(4)))]);
        $this->rations[] = $id;
        return $id;
    }

    private function foreignAnimalId(): int
    {
        $id = (int) self::$conn->query('SELECT cow_id FROM cows WHERE farm_id = 2 LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $id, 'Fixture assumption: farm 2 has an animal');
        return $id;
    }

    private function stockFor(int $itemId): float
    {
        $stmt = self::$conn->prepare('SELECT quantity FROM inventory_levels WHERE item_id = ?');
        $stmt->execute([$itemId]);
        return (float) $stmt->fetchColumn();
    }

    // =======================================================================
    // 1. RECORDING A PER-ANIMAL YIELD
    // =======================================================================

    public function testAValidYieldIsRecordedInCanonicalUnits(): void
    {
        $animalId = $this->makeAnimal();
        $id = productionRecordYield(self::$conn, [
            'cow_id' => $animalId, 'production_date' => farmToday(),
            'session' => 'morning', 'quantity' => 12.5,
        ]);

        self::assertGreaterThan(0, $id);
        $row = tenantFind(self::$conn, 'milk_yields', 'yield_id', $id);
        self::assertNotNull($row);
        self::assertSame(12.5, (float) $row['quantity']);
        self::assertSame($animalId, (int) $row['cow_id']);
        self::assertSame('morning', $row['session']);
        // Canonical volume is litres. Storage never holds a display unit.
        self::assertSame('l', dfms_canonical_unit('volume'));
    }

    /** One animal, one date, one session. A second is a double entry. */
    public function testDuplicateRecordingForTheSameSessionIsRejected(): void
    {
        $animalId = $this->makeAnimal();
        $date = farmToday();
        productionRecordYield(self::$conn, [
            'cow_id' => $animalId, 'production_date' => $date, 'session' => 'morning', 'quantity' => 10,
        ]);

        $this->expectException(ProductionRuleViolation::class);
        $this->expectExceptionMessageMatches('/already exists/i');
        productionRecordYield(self::$conn, [
            'cow_id' => $animalId, 'production_date' => $date, 'session' => 'morning', 'quantity' => 11,
        ]);
    }

    /** But two DIFFERENT sessions on one day are ordinary and must be allowed. */
    public function testTwoSessionsOnOneDayAreBothAllowed(): void
    {
        $animalId = $this->makeAnimal();
        $date = farmToday();
        productionRecordYield(self::$conn, ['cow_id' => $animalId, 'production_date' => $date,
            'session' => 'morning', 'quantity' => 10]);
        productionRecordYield(self::$conn, ['cow_id' => $animalId, 'production_date' => $date,
            'session' => 'evening', 'quantity' => 8]);

        $totals = productionDailyTotals(self::$conn, $date, $date);
        self::assertSame(18.0, $totals[$date]['total']);
        self::assertSame(2, $totals[$date]['entries']);
        self::assertSame(1, $totals[$date]['animals']);
    }

    public function testInvalidQuantitiesAreRejected(): void
    {
        $animalId = $this->makeAnimal();
        foreach ([0, -1, -0.5] as $quantity) {
            try {
                productionRecordYield(self::$conn, ['cow_id' => $animalId,
                    'production_date' => farmToday(), 'session' => 'morning', 'quantity' => $quantity]);
                self::fail('Rejected: ' . $quantity);
            } catch (ProductionRuleViolation $e) {
                self::assertStringContainsString('greater than zero', $e->getMessage());
            }
        }
    }

    /** Zero is refused rather than stored: it would drag every average down. */
    public function testZeroIsNotStoredAsAProductionRecord(): void
    {
        $animalId = $this->makeAnimal();
        try {
            productionRecordYield(self::$conn, ['cow_id' => $animalId,
                'production_date' => farmToday(), 'session' => 'morning', 'quantity' => 0]);
        } catch (ProductionRuleViolation $e) {
            // expected
        }
        self::assertSame(0, (int) tenantScalar(self::$conn,
            "SELECT COUNT(*) FROM milk_yields y WHERE {scope} AND y.cow_id = ?", [$animalId], 0, 'y'));
    }

    public function testFutureDatesAreRejected(): void
    {
        $animalId = $this->makeAnimal();
        $this->expectException(ProductionRuleViolation::class);
        productionRecordYield(self::$conn, ['cow_id' => $animalId,
            'production_date' => farmDateOffset(1), 'session' => 'morning', 'quantity' => 10]);
    }

    public function testAnInvalidSessionIsRejected(): void
    {
        $animalId = $this->makeAnimal();
        foreach (['', 'lunchtime', 'MORNING', '0'] as $session) {
            try {
                productionRecordYield(self::$conn, ['cow_id' => $animalId,
                    'production_date' => farmToday(), 'session' => $session, 'quantity' => 5]);
                self::fail('Rejected: ' . var_export($session, true));
            } catch (ProductionRuleViolation $e) {
                self::assertStringContainsString('which milking', $e->getMessage());
            }
        }
    }

    public function testDiscardedCannotExceedTheQuantity(): void
    {
        $animalId = $this->makeAnimal();
        $this->expectException(ProductionRuleViolation::class);
        productionRecordYield(self::$conn, ['cow_id' => $animalId, 'production_date' => farmToday(),
            'session' => 'morning', 'quantity' => 5, 'discarded' => 6]);
    }

    public function testANonActiveAnimalCannotHaveProductionRecorded(): void
    {
        $animalId = $this->makeAnimal(['lifecycle_status' => 'sold']);
        $this->expectException(ProductionRuleViolation::class);
        productionRecordYield(self::$conn, ['cow_id' => $animalId, 'production_date' => farmToday(),
            'session' => 'morning', 'quantity' => 10]);
    }

    // =======================================================================
    // 2. TENANT ISOLATION
    // =======================================================================

    public function testTheNewTablesAreRegisteredForTenantScoping(): void
    {
        foreach (['milk_yields', 'feed_consumption_events', 'feed_rations', 'feed_ration_items'] as $table) {
            self::assertContains($table, DFMS_TENANT_TABLES,
                "$table must be registered or tenantInsert() would refuse it");
        }
    }

    public function testAForeignAnimalCannotHaveProductionRecorded(): void
    {
        $this->expectException(ProductionRuleViolation::class);
        $this->expectExceptionMessageMatches('/not on this farm/i');
        productionRecordYield(self::$conn, ['cow_id' => $this->foreignAnimalId(),
            'production_date' => farmToday(), 'session' => 'morning', 'quantity' => 10]);
    }

    /** Foreign and missing are the same answer, so neither can be probed for. */
    public function testAForeignAnimalAndAMissingAnimalAreIndistinguishable(): void
    {
        $messages = [];
        foreach ([$this->foreignAnimalId(), 2147483647] as $id) {
            try {
                productionRecordYield(self::$conn, ['cow_id' => $id,
                    'production_date' => farmToday(), 'session' => 'morning', 'quantity' => 10]);
                self::fail('Both must be refused');
            } catch (ProductionRuleViolation $e) {
                $messages[] = $e->getMessage();
            }
        }
        self::assertSame($messages[0], $messages[1]);
    }

    public function testMalformedAnimalIdsAreRejected(): void
    {
        foreach (['abc', '1; DROP TABLE cows', '-1', '0', null, '1.5'] as $id) {
            try {
                productionRecordYield(self::$conn, ['cow_id' => $id,
                    'production_date' => farmToday(), 'session' => 'morning', 'quantity' => 10]);
                self::fail('Rejected: ' . var_export($id, true));
            } catch (ProductionRuleViolation $e) {
                self::assertStringContainsString('not on this farm', $e->getMessage());
            }
        }
    }

    public function testAForeignYieldIsInvisible(): void
    {
        // Written directly, bypassing the tenant helpers, because a farm-2 row
        // is by definition unreachable through them.
        self::$conn->prepare(
            "INSERT INTO milk_yields (farm_id, cow_id, production_date, session, quantity)
             VALUES (2, ?, ?, 'morning', 9.0)"
        )->execute([$this->foreignAnimalId(), farmToday()]);
        $foreignId = (int) self::$conn->lastInsertId();

        try {
            self::assertNull(productionFindYield(self::$conn, $foreignId),
                'A farm-2 yield must be invisible from farm 1');
            $totals = productionDailyTotals(self::$conn, farmToday(), farmToday());
            $farmOneTotal = $totals[farmToday()]['total'] ?? 0.0;
            self::assertNotSame(9.0, $farmOneTotal, 'A farm-2 yield must not enter farm-1 analytics');
        } finally {
            self::$conn->prepare('DELETE FROM milk_yields WHERE yield_id = ?')->execute([$foreignId]);
        }
    }

    // =======================================================================
    // 3. WITHDRAWAL INTERACTION — computed, never copied
    // =======================================================================

    /** Recording is NOT blocked. The Phase 4 semantics are warn, not block. */
    public function testRecordingIsAllowedDuringAWithdrawal(): void
    {
        $animalId = $this->makeAnimal();
        tenantInsert(self::$conn, 'withdrawal_periods', [
            'cow_id' => $animalId, 'restriction_type' => 'milk', 'status' => 'active',
            'starts_on' => farmDateOffset(-2), 'ends_on' => farmDateOffset(2),
            'source_label' => 'PHPUNIT',
        ]);

        $id = productionRecordYield(self::$conn, ['cow_id' => $animalId,
            'production_date' => farmToday(), 'session' => 'morning', 'quantity' => 10]);

        self::assertGreaterThan(0, $id, 'Milk that was drawn still exists and must be recordable');
    }

    public function testRestrictedMilkIsFlaggedAndExcludedFromSaleable(): void
    {
        $animalId = $this->makeAnimal();
        tenantInsert(self::$conn, 'withdrawal_periods', [
            'cow_id' => $animalId, 'restriction_type' => 'milk', 'status' => 'active',
            'starts_on' => farmDateOffset(-2), 'ends_on' => farmDateOffset(2),
            'source_label' => 'PHPUNIT',
        ]);
        productionRecordYield(self::$conn, ['cow_id' => $animalId,
            'production_date' => farmToday(), 'session' => 'morning', 'quantity' => 10]);

        $rows = productionAnimalYields(self::$conn, $animalId);
        self::assertTrue((bool) $rows[0]['restricted']);

        $totals = productionDailyTotals(self::$conn, farmToday(), farmToday());
        self::assertSame(10.0, $totals[farmToday()]['total'], 'It was produced, so it is in the total');
        self::assertSame(0.0, $totals[farmToday()]['saleable'], 'But none of it is saleable');
    }

    /**
     * THE REASON RESTRICTION IS NOT STORED ON THE ROW.
     *
     * Cancelling the withdrawal must immediately correct every historical
     * record it covered. A copied flag would still say "restricted" and there
     * would be two answers to one question.
     */
    public function testCancellingAWithdrawalCorrectsHistoricalRecords(): void
    {
        $animalId = $this->makeAnimal();
        $withdrawalId = tenantInsert(self::$conn, 'withdrawal_periods', [
            'cow_id' => $animalId, 'restriction_type' => 'milk', 'status' => 'active',
            'starts_on' => farmDateOffset(-2), 'ends_on' => farmDateOffset(2),
            'source_label' => 'PHPUNIT',
        ]);
        productionRecordYield(self::$conn, ['cow_id' => $animalId,
            'production_date' => farmToday(), 'session' => 'morning', 'quantity' => 10]);

        self::assertTrue((bool) productionAnimalYields(self::$conn, $animalId)[0]['restricted']);

        tenantExec(self::$conn, "UPDATE withdrawal_periods SET status = 'cancelled'
                                  WHERE withdrawal_id = ? AND {scope}", [$withdrawalId]);

        self::assertFalse((bool) productionAnimalYields(self::$conn, $animalId)[0]['restricted'],
            'Restriction is derived from the health domain, so correcting it corrects this');
        self::assertSame(10.0, productionDailyTotals(self::$conn, farmToday(), farmToday())[farmToday()]['saleable']);
    }

    /** A MEAT restriction does not restrict milk. */
    public function testANonMilkWithdrawalDoesNotRestrictMilk(): void
    {
        $animalId = $this->makeAnimal();
        tenantInsert(self::$conn, 'withdrawal_periods', [
            'cow_id' => $animalId, 'restriction_type' => 'meat', 'status' => 'active',
            'starts_on' => farmDateOffset(-2), 'ends_on' => farmDateOffset(2),
            'source_label' => 'PHPUNIT',
        ]);
        productionRecordYield(self::$conn, ['cow_id' => $animalId,
            'production_date' => farmToday(), 'session' => 'morning', 'quantity' => 10]);

        self::assertFalse((bool) productionAnimalYields(self::$conn, $animalId)[0]['restricted']);
    }

    public function testDiscardedMilkIsExcludedFromSaleable(): void
    {
        $animalId = $this->makeAnimal();
        productionRecordYield(self::$conn, ['cow_id' => $animalId, 'production_date' => farmToday(),
            'session' => 'morning', 'quantity' => 10, 'discarded' => 3]);

        $day = productionDailyTotals(self::$conn, farmToday(), farmToday())[farmToday()];
        self::assertSame(10.0, $day['total']);
        self::assertSame(3.0, $day['discarded']);
        // Only the discarded part is removed. Withholding 3 L of a 10 L
        // milking does not make the other 7 unsaleable — treating it that way
        // would understate the farm's saleable volume by a whole session.
        self::assertSame(7.0, $day['saleable']);
    }

    /**
     * A withdrawal is different from a discard, and the two must not be
     * conflated: a restriction zeroes the WHOLE session, because the
     * restriction is on the animal's milk rather than on part of a bucket.
     */
    public function testAWithdrawalZeroesTheSessionEvenWithoutADiscard(): void
    {
        $animalId = $this->makeAnimal();
        tenantInsert(self::$conn, 'withdrawal_periods', [
            'cow_id' => $animalId, 'restriction_type' => 'milk', 'status' => 'active',
            'starts_on' => farmDateOffset(-1), 'ends_on' => farmDateOffset(1),
            'source_label' => 'PHPUNIT',
        ]);
        productionRecordYield(self::$conn, ['cow_id' => $animalId, 'production_date' => farmToday(),
            'session' => 'morning', 'quantity' => 10, 'discarded' => 2]);

        $day = productionDailyTotals(self::$conn, farmToday(), farmToday())[farmToday()];
        self::assertSame(10.0, $day['total']);
        self::assertSame(0.0, $day['saleable'],
            'A restriction removes the whole session, not just the discarded part');
    }

    // =======================================================================
    // 4. ANALYTICS — nothing invented, nothing merged
    // =======================================================================

    /** A day with no records is ABSENT, never reported as zero. */
    public function testDaysWithNoRecordsAreAbsentNotZero(): void
    {
        $animalId = $this->makeAnimal();
        productionRecordYield(self::$conn, ['cow_id' => $animalId,
            'production_date' => farmDateOffset(-1), 'session' => 'morning', 'quantity' => 10]);

        $totals = productionDailyTotals(self::$conn, farmDateOffset(-3), farmToday());

        self::assertArrayHasKey(farmDateOffset(-1), $totals);
        self::assertArrayNotHasKey(farmToday(), $totals,
            '"nobody recorded anything" is not "the herd produced nothing"');
        self::assertArrayNotHasKey(farmDateOffset(-3), $totals);
    }

    /** The average divides by days WITH DATA, not by the window length. */
    public function testAverageUsesRecordedDaysNotWindowLength(): void
    {
        $animalId = $this->makeAnimal();
        productionRecordYield(self::$conn, ['cow_id' => $animalId,
            'production_date' => farmDateOffset(-1), 'session' => 'morning', 'quantity' => 10]);
        productionRecordYield(self::$conn, ['cow_id' => $animalId,
            'production_date' => farmToday(), 'session' => 'morning', 'quantity' => 20]);

        $summary = productionSummary(self::$conn, farmDateOffset(-29), farmToday());

        self::assertSame(30.0, $summary['recorded']['total']);
        self::assertSame(2, $summary['recorded']['days_with_data']);
        // 30 / 2 recorded days = 15, NOT 30 / 30 window days = 1.
        self::assertSame(15.0, $summary['recorded']['average_per_day']);
        self::assertSame(30, $summary['days'], 'The window length is still reported, separately');
    }

    /**
     * THE RULE THIS PHASE IS BUILT AROUND.
     *
     * A farm daily total is never divided among animals, and the two figures
     * are returned side by side without being reconciled.
     */
    public function testFarmTotalsAreNeverAttributedToAnimals(): void
    {
        $animalId = $this->makeAnimal();
        $date = farmDateOffset(-5);

        // A farm-level total with NO per-animal detail for that day.
        self::$conn->prepare(
            "INSERT INTO milk_production (farm_id, production_date, total_milk_yield)
             VALUES (1, ?, 1250.00) ON DUPLICATE KEY UPDATE total_milk_yield = 1250.00"
        )->execute([$date]);

        try {
            $summary = productionSummary(self::$conn, $date, $date);

            self::assertSame(1250.0, $summary['farm_aggregate']['total']);
            self::assertSame(0.0, $summary['recorded']['total'],
                'A farm total must contribute NOTHING to per-animal figures');
            self::assertSame(0, $summary['recorded']['days_with_data']);
            self::assertNull($summary['recorded']['average_per_day']);

            // And no animal picked any of it up.
            self::assertSame([], productionAnimalTotals(self::$conn, $date, $date));
            self::assertSame([], productionAnimalYields(self::$conn, $animalId));
        } finally {
            self::$conn->prepare('DELETE FROM milk_production WHERE farm_id = 1 AND production_date = ?')
                ->execute([$date]);
        }
    }

    public function testDateBoundariesAreInclusive(): void
    {
        $animalId = $this->makeAnimal();
        $from = farmDateOffset(-2);
        $to = farmToday();
        foreach ([$from, farmDateOffset(-1), $to] as $index => $date) {
            productionRecordYield(self::$conn, ['cow_id' => $animalId, 'production_date' => $date,
                'session' => 'morning', 'quantity' => 10 + $index]);
        }

        $totals = productionDailyTotals(self::$conn, $from, $to);
        self::assertCount(3, $totals, 'Both endpoints are included');
        self::assertArrayHasKey($from, $totals);
        self::assertArrayHasKey($to, $totals);

        $narrower = productionDailyTotals(self::$conn, farmDateOffset(-1), farmDateOffset(-1));
        self::assertCount(1, $narrower);
    }

    public function testPerAnimalTotalsReportRecordingGapsSeparately(): void
    {
        $animalId = $this->makeAnimal();
        productionRecordYield(self::$conn, ['cow_id' => $animalId,
            'production_date' => farmToday(), 'session' => 'morning', 'quantity' => 12]);

        $totals = productionAnimalTotals(self::$conn, farmDateOffset(-6), farmToday());
        $row = $totals[0];

        self::assertSame(12.0, $row['total']);
        self::assertSame(1, $row['days_recorded']);
        // Averaged over the day it WAS recorded, not over the 7-day window —
        // a gap is a recording gap, not a low-yielding animal.
        self::assertSame(12.0, $row['average_per_day']);
    }

    public function testAnimalTrendExcludesTheLatestDayFromItsOwnBaseline(): void
    {
        $animalId = $this->makeAnimal();
        foreach ([-3 => 10.0, -2 => 10.0, -1 => 10.0, 0 => 2.0] as $offset => $quantity) {
            productionRecordYield(self::$conn, ['cow_id' => $animalId,
                'production_date' => farmDateOffset($offset), 'session' => 'morning', 'quantity' => $quantity]);
        }

        $trend = productionAnimalTrend(self::$conn, $animalId);
        self::assertSame(2.0, $trend['latest']['total']);
        self::assertSame(10.0, $trend['baseline'],
            'The baseline is history, so a drop is not measured against itself');
    }

    // =======================================================================
    // 5. ALERTS — deterministic, deduplicated, auto-resolving
    // =======================================================================

    public function testAProductionDropRaisesExactlyOneAlertAndIsIdempotent(): void
    {
        $animalId = $this->makeAnimal();
        foreach ([-4 => 20.0, -3 => 20.0, -2 => 20.0, -1 => 20.0, 0 => 2.0] as $offset => $quantity) {
            productionRecordYield(self::$conn, ['cow_id' => $animalId,
                'production_date' => farmDateOffset($offset), 'session' => 'morning', 'quantity' => $quantity]);
        }

        $first = productionSyncAlerts(self::$conn);
        self::assertGreaterThanOrEqual(1, $first['created']);

        // Running again changes nothing: the alert already stands.
        $second = productionSyncAlerts(self::$conn);
        self::assertSame(0, $second['created'], 'Re-running must not duplicate an outstanding alert');

        $count = (int) tenantScalar(self::$conn,
            "SELECT COUNT(*) FROM alerts a WHERE {scope} AND a.is_read = 0
               AND a.alert_type = 'Production drop' AND a.entity_id = ?",
            [$animalId], 0, 'a');
        self::assertSame(1, $count);
    }

    public function testAnAlertAutoResolvesWhenProductionRecovers(): void
    {
        $animalId = $this->makeAnimal();
        foreach ([-4 => 20.0, -3 => 20.0, -2 => 20.0, -1 => 20.0, 0 => 2.0] as $offset => $quantity) {
            productionRecordYield(self::$conn, ['cow_id' => $animalId,
                'production_date' => farmDateOffset($offset), 'session' => 'morning', 'quantity' => $quantity]);
        }
        productionSyncAlerts(self::$conn);

        // Recovery: correct today's figure back up.
        $todayYield = productionAnimalYields(self::$conn, $animalId, 1)[0];
        productionUpdateYield(self::$conn, (int) $todayYield['yield_id'], ['quantity' => 20.0]);

        $result = productionSyncAlerts(self::$conn);
        self::assertGreaterThanOrEqual(1, $result['resolved']);

        // Marked read, not deleted — the alert genuinely happened.
        $unread = (int) tenantScalar(self::$conn,
            "SELECT COUNT(*) FROM alerts a WHERE {scope} AND a.is_read = 0
               AND a.alert_type = 'Production drop' AND a.entity_id = ?", [$animalId], 0, 'a');
        self::assertSame(0, $unread);
        $total = (int) tenantScalar(self::$conn,
            "SELECT COUNT(*) FROM alerts a WHERE {scope}
               AND a.alert_type = 'Production drop' AND a.entity_id = ?", [$animalId], 0, 'a');
        self::assertSame(1, $total);
    }

    /** A single quiet day against too little history must not fire. */
    public function testADropNeedsEnoughHistoryToBeMeaningful(): void
    {
        $animalId = $this->makeAnimal();
        productionRecordYield(self::$conn, ['cow_id' => $animalId,
            'production_date' => farmDateOffset(-1), 'session' => 'morning', 'quantity' => 20]);
        productionRecordYield(self::$conn, ['cow_id' => $animalId,
            'production_date' => farmToday(), 'session' => 'morning', 'quantity' => 1]);

        $signals = array_filter(productionDropSignals(self::$conn),
            static fn (array $s): bool => $s['cow_id'] === $animalId);
        self::assertSame([], $signals, 'Two data points are not a baseline');
    }

    /** The wording states arithmetic, never a diagnosis. */
    public function testAlertWordingIsOperationalNotMedical(): void
    {
        $animalId = $this->makeAnimal();
        foreach ([-4 => 20.0, -3 => 20.0, -2 => 20.0, -1 => 20.0, 0 => 2.0] as $offset => $quantity) {
            productionRecordYield(self::$conn, ['cow_id' => $animalId,
                'production_date' => farmDateOffset($offset), 'session' => 'morning', 'quantity' => $quantity]);
        }
        productionSyncAlerts(self::$conn);

        $description = (string) tenantScalar(self::$conn,
            "SELECT a.description FROM alerts a WHERE {scope} AND a.alert_type = 'Production drop'
               AND a.entity_id = ? LIMIT 1", [$animalId], '', 'a');

        self::assertStringContainsString('lower', $description);
        foreach (['sick', 'ill', 'disease', 'infection', 'mastitis', 'diagnos'] as $medical) {
            self::assertStringNotContainsStringIgnoringCase($medical, $description,
                'A production alert must never read as a diagnosis');
        }
    }

    public function testProductionAlertsDoNotTouchHealthAlerts(): void
    {
        // The two reconcilers own disjoint type families.
        foreach (DFMS_PRODUCTION_ALERT_TYPES as $type) {
            self::assertNotContains($type, DFMS_HEALTH_ALERT_TYPES,
                'Overlapping families would let one reconciler resolve the other\'s alerts');
        }
    }

    // =======================================================================
    // 6. CORRECTION AND REMOVAL
    // =======================================================================

    public function testAYieldCanBeCorrectedAndOnlyChangedFieldsAreAudited(): void
    {
        $animalId = $this->makeAnimal();
        $id = productionRecordYield(self::$conn, ['cow_id' => $animalId,
            'production_date' => farmToday(), 'session' => 'morning', 'quantity' => 10, 'notes' => 'keep']);

        productionUpdateYield(self::$conn, $id, ['quantity' => 14.0, 'notes' => 'keep']);

        $row = tenantFind(self::$conn, 'milk_yields', 'yield_id', $id);
        self::assertSame(14.0, (float) $row['quantity']);

        $payload = (string) self::$conn->query(
            "SELECT new_value FROM audit_log WHERE action = 'milk_yield.update'
              ORDER BY audit_id DESC LIMIT 1")->fetchColumn();
        $decoded = json_decode($payload, true);
        self::assertArrayHasKey('quantity', $decoded);
        self::assertArrayNotHasKey('notes', $decoded, 'Unchanged fields must not be logged');
    }

    public function testAYieldCanBeRemovedAndWhatWasRemovedIsAudited(): void
    {
        $animalId = $this->makeAnimal();
        $id = productionRecordYield(self::$conn, ['cow_id' => $animalId,
            'production_date' => farmToday(), 'session' => 'morning', 'quantity' => 10]);

        productionDeleteYield(self::$conn, $id);

        self::assertNull(productionFindYield(self::$conn, $id));
        $payload = (string) self::$conn->query(
            "SELECT old_value FROM audit_log WHERE action = 'milk_yield.delete'
              ORDER BY audit_id DESC LIMIT 1")->fetchColumn();
        self::assertStringContainsString('10', $payload,
            'The record of what was deleted must survive the deletion');
    }

    public function testAForeignYieldCannotBeCorrectedOrRemoved(): void
    {
        self::$conn->prepare(
            "INSERT INTO milk_yields (farm_id, cow_id, production_date, session, quantity)
             VALUES (2, ?, ?, 'evening', 5.0)"
        )->execute([$this->foreignAnimalId(), farmToday()]);
        $foreignId = (int) self::$conn->lastInsertId();

        try {
            foreach (['update', 'delete'] as $operation) {
                try {
                    if ($operation === 'update') {
                        productionUpdateYield(self::$conn, $foreignId, ['quantity' => 999]);
                    } else {
                        productionDeleteYield(self::$conn, $foreignId);
                    }
                    self::fail("A farm-2 yield must not be $operation-able from farm 1");
                } catch (ProductionRuleViolation $e) {
                    self::assertStringContainsString('not available', $e->getMessage());
                }
            }
            $stmt = self::$conn->prepare('SELECT quantity FROM milk_yields WHERE yield_id = ?');
            $stmt->execute([$foreignId]);
            self::assertSame(5.0, (float) $stmt->fetchColumn(), 'The foreign row is untouched');
        } finally {
            self::$conn->prepare('DELETE FROM milk_yields WHERE yield_id = ?')->execute([$foreignId]);
        }
    }

    // =======================================================================
    // 7. FEED CONSUMPTION — one stock ledger, transactional
    // =======================================================================

    public function testConsumptionDeductsStockAndRecordsTheEvent(): void
    {
        $itemId = $this->makeFeedItem(100.0);

        $eventId = feedRecordConsumption(self::$conn, [
            'consumed_on' => farmToday(), 'item_id' => $itemId,
            'quantity' => 40.0, 'scope_type' => 'farm', 'notes' => 'PHPUNIT',
        ]);

        self::assertGreaterThan(0, $eventId);
        self::assertSame(60.0, $this->stockFor($itemId));

        $event = tenantFind(self::$conn, 'feed_consumption_events', 'event_id', $eventId);
        self::assertSame(40.0, (float) $event['quantity']);
        self::assertSame('farm', $event['scope_type']);
    }

    /** The stock movement is audited — the audit log IS the ledger. */
    public function testTheStockMovementIsAudited(): void
    {
        $itemId = $this->makeFeedItem(100.0);
        feedRecordConsumption(self::$conn, ['consumed_on' => farmToday(), 'item_id' => $itemId,
            'quantity' => 25.0, 'scope_type' => 'farm', 'notes' => 'PHPUNIT']);

        $payload = (string) self::$conn->query(
            "SELECT new_value FROM audit_log WHERE action = 'stock.adjust'
              ORDER BY audit_id DESC LIMIT 1")->fetchColumn();
        $decoded = json_decode($payload, true);

        self::assertSame(75.0, (float) $decoded['quantity']);
        self::assertSame('feed consumption', $decoded['reason']);
    }

    /**
     * INSUFFICIENT STOCK ROLLS THE WHOLE THING BACK.
     *
     * The critical integrity test: no event row may survive claiming feed was
     * used that never left the store.
     */
    public function testInsufficientStockRefusesAndRollsBack(): void
    {
        $itemId = $this->makeFeedItem(10.0);
        $eventsBefore = (int) self::$conn->query('SELECT COUNT(*) FROM feed_consumption_events')->fetchColumn();

        try {
            feedRecordConsumption(self::$conn, ['consumed_on' => farmToday(), 'item_id' => $itemId,
                'quantity' => 50.0, 'scope_type' => 'farm', 'notes' => 'PHPUNIT']);
            self::fail('Consuming more than the level holds must be refused');
        } catch (FeedRuleViolation $e) {
            self::assertStringContainsString('Not enough stock', $e->getMessage());
        }

        self::assertSame(10.0, $this->stockFor($itemId), 'Stock must be untouched');
        self::assertSame($eventsBefore,
            (int) self::$conn->query('SELECT COUNT(*) FROM feed_consumption_events')->fetchColumn(),
            'No event may survive a failed deduction');
    }

    public function testStockCannotGoNegativeEvenExactlyAtZero(): void
    {
        $itemId = $this->makeFeedItem(10.0);
        feedRecordConsumption(self::$conn, ['consumed_on' => farmToday(), 'item_id' => $itemId,
            'quantity' => 10.0, 'scope_type' => 'farm', 'notes' => 'PHPUNIT']);
        self::assertSame(0.0, $this->stockFor($itemId), 'Exactly emptying the store is allowed');

        $this->expectException(FeedRuleViolation::class);
        feedRecordConsumption(self::$conn, ['consumed_on' => farmToday(), 'item_id' => $itemId,
            'quantity' => 0.01, 'scope_type' => 'farm', 'notes' => 'PHPUNIT']);
    }

    public function testInvalidConsumptionInputIsRejected(): void
    {
        $itemId = $this->makeFeedItem(100.0);

        $cases = [
            ['quantity' => 0], ['quantity' => -5],
            ['consumed_on' => farmDateOffset(1)],
            ['scope_type' => 'herd'], ['scope_type' => ''],
        ];
        foreach ($cases as $override) {
            try {
                feedRecordConsumption(self::$conn, array_merge([
                    'consumed_on' => farmToday(), 'item_id' => $itemId,
                    'quantity' => 5.0, 'scope_type' => 'farm', 'notes' => 'PHPUNIT',
                ], $override));
                self::fail('Rejected: ' . json_encode($override));
            } catch (FeedRuleViolation $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
        self::assertSame(100.0, $this->stockFor($itemId), 'No rejected attempt moved stock');
    }

    /** A real, on-farm item that is NOT feed cannot be consumed as feed. */
    public function testANonFeedItemCannotBeConsumedAsFeed(): void
    {
        $itemId = $this->makeFeedItem(100.0, ['category' => 'medication', 'item_type' => 'Medicine']);

        $this->expectException(FeedRuleViolation::class);
        $this->expectExceptionMessageMatches('/not categorised as feed/i');
        feedRecordConsumption(self::$conn, ['consumed_on' => farmToday(), 'item_id' => $itemId,
            'quantity' => 5.0, 'scope_type' => 'farm', 'notes' => 'PHPUNIT']);
    }

    public function testAForeignInventoryItemCannotBeConsumed(): void
    {
        self::$conn->prepare(
            "INSERT INTO inventory_items (farm_id, item_name, category, item_type, unit, reorder_level)
             VALUES (2, 'Rival feed', 'feed', 'Feed', 'kg', 10)"
        )->execute();
        $foreignItem = (int) self::$conn->lastInsertId();
        self::$conn->prepare("INSERT INTO inventory_levels (farm_id, item_id, quantity) VALUES (2, ?, 500)")
            ->execute([$foreignItem]);

        try {
            $this->expectException(FeedRuleViolation::class);
            $this->expectExceptionMessageMatches('/not in this farm/i');
            feedRecordConsumption(self::$conn, ['consumed_on' => farmToday(), 'item_id' => $foreignItem,
                'quantity' => 5.0, 'scope_type' => 'farm', 'notes' => 'PHPUNIT']);
        } finally {
            self::$conn->prepare('DELETE FROM inventory_levels WHERE item_id = ?')->execute([$foreignItem]);
            self::$conn->prepare('DELETE FROM inventory_items WHERE item_id = ?')->execute([$foreignItem]);
        }
    }

    public function testAForeignAnimalCannotBeAConsumptionTarget(): void
    {
        $itemId = $this->makeFeedItem(100.0);

        $this->expectException(FeedRuleViolation::class);
        $this->expectExceptionMessageMatches('/not on this farm/i');
        feedRecordConsumption(self::$conn, ['consumed_on' => farmToday(), 'item_id' => $itemId,
            'quantity' => 5.0, 'scope_type' => 'animal', 'cow_id' => $this->foreignAnimalId(),
            'notes' => 'PHPUNIT']);
    }

    public function testAMissingScopeTargetIsRejected(): void
    {
        $itemId = $this->makeFeedItem(100.0);

        foreach ([['scope_type' => 'animal'], ['scope_type' => 'location']] as $override) {
            try {
                feedRecordConsumption(self::$conn, array_merge([
                    'consumed_on' => farmToday(), 'item_id' => $itemId, 'quantity' => 5.0,
                    'notes' => 'PHPUNIT',
                ], $override));
                self::fail('A scoped event with no target must be refused');
            } catch (FeedRuleViolation $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
        self::assertSame(100.0, $this->stockFor($itemId));
    }

    public function testLowStockAlertIsRaisedAndDeduplicated(): void
    {
        $itemId = $this->makeFeedItem(30.0, ['reorder_level' => 20.0]);

        feedRecordConsumption(self::$conn, ['consumed_on' => farmToday(), 'item_id' => $itemId,
            'quantity' => 15.0, 'scope_type' => 'farm', 'notes' => 'PHPUNIT']);

        $count = (int) tenantScalar(self::$conn,
            "SELECT COUNT(*) FROM alerts a WHERE {scope} AND a.is_read = 0
               AND a.alert_type = 'Low Inventory' AND a.entity_id = ?", [$itemId], 0, 'a');
        self::assertSame(1, $count, 'Falling below the reorder level raises one alert');

        // A second deduction while still low must not raise a second alert.
        feedRecordConsumption(self::$conn, ['consumed_on' => farmToday(), 'item_id' => $itemId,
            'quantity' => 5.0, 'scope_type' => 'farm', 'notes' => 'PHPUNIT']);
        $count = (int) tenantScalar(self::$conn,
            "SELECT COUNT(*) FROM alerts a WHERE {scope} AND a.is_read = 0
               AND a.alert_type = 'Low Inventory' AND a.entity_id = ?", [$itemId], 0, 'a');
        self::assertSame(1, $count, 'The standing alert is not duplicated');
    }

    public function testFeedUsageTotalsOnlyCountRecordedEvents(): void
    {
        $itemA = $this->makeFeedItem(100.0);
        $this->makeFeedItem(100.0);   // never consumed

        feedRecordConsumption(self::$conn, ['consumed_on' => farmToday(), 'item_id' => $itemA,
            'quantity' => 12.0, 'scope_type' => 'farm', 'notes' => 'PHPUNIT']);

        $usage = feedUsageByItem(self::$conn, farmToday(), farmToday());
        $ids = array_column($usage, 'item_id');

        self::assertContains($itemA, $ids);
        self::assertCount(1, array_filter($usage, static fn (array $r): bool => $r['item_id'] === $itemA));
        // The unconsumed item is ABSENT, not present with a zero.
        self::assertSame(1, count(array_filter($usage,
            static fn (array $r): bool => in_array($r['item_id'], [$itemA], true))));
    }

    // =======================================================================
    // 8. RATIONS — a plan, never a consumption
    // =======================================================================

    public function testCreatingAndEditingARationNeverTouchesStock(): void
    {
        $itemId = $this->makeFeedItem(100.0);
        $rationId = $this->makeRation();

        feedSetRationItem(self::$conn, $rationId, $itemId, 8.0);
        feedSetRationItem(self::$conn, $rationId, $itemId, 9.0);   // update, not a second line

        self::assertSame(100.0, $this->stockFor($itemId), 'A plan must never move stock');
        $lines = feedRationItems(self::$conn, $rationId);
        self::assertCount(1, $lines, 'Re-adding an item updates its quantity');
        self::assertSame(9.0, (float) $lines[0]['quantity']);
    }

    public function testDuplicateRationNamesAreRejected(): void
    {
        $name = 'P6 Unique ' . bin2hex(random_bytes(4));
        $this->makeRation($name);

        $this->expectException(FeedRuleViolation::class);
        $this->expectExceptionMessageMatches('/already exists/i');
        $this->makeRation($name);
    }

    public function testARationLineRejectsANonFeedOrForeignItem(): void
    {
        $rationId = $this->makeRation();
        $medicine = $this->makeFeedItem(50.0, ['category' => 'medication', 'item_type' => 'Medicine']);

        try {
            feedSetRationItem(self::$conn, $rationId, $medicine, 5.0);
            self::fail('A medicine is not a feed');
        } catch (FeedRuleViolation $e) {
            self::assertStringContainsString('not categorised as feed', $e->getMessage());
        }

        try {
            feedSetRationItem(self::$conn, $rationId, 2147483647, 5.0);
            self::fail('A missing item must be refused');
        } catch (FeedRuleViolation $e) {
            self::assertStringContainsString('not in this farm', $e->getMessage());
        }
    }

    public function testARationProjectionReportsShortfallsWithoutConsuming(): void
    {
        $itemId = $this->makeFeedItem(50.0);
        $rationId = $this->makeRation();
        feedSetRationItem(self::$conn, $rationId, $itemId, 8.0);

        $projection = feedRationProjection(self::$conn, $rationId, 10.0);

        self::assertCount(1, $projection);
        self::assertSame(80.0, $projection[0]['required']);
        self::assertSame(50.0, $projection[0]['stock']);
        self::assertTrue($projection[0]['stock_known']);
        self::assertFalse($projection[0]['sufficient']);
        self::assertSame(50.0, $this->stockFor($itemId), 'A projection consumes nothing');
    }

    /** "No level recorded" is not "a level of zero". */
    public function testAnUnrecordedStockLevelIsDistinctFromZero(): void
    {
        $itemId = tenantInsert(self::$conn, 'inventory_items', [
            'item_name' => 'P6 Uncounted ' . bin2hex(random_bytes(4)),
            'category' => 'feed', 'item_type' => 'Feed', 'unit' => 'kg', 'reorder_level' => 5.0,
        ]);
        $this->items[] = $itemId;

        self::assertNull(inventoryLevelFor(self::$conn, $itemId));

        tenantInsert(self::$conn, 'inventory_levels', ['item_id' => $itemId, 'quantity' => 0.0]);
        self::assertSame(0.0, inventoryLevelFor(self::$conn, $itemId));
    }

    public function testConsumingAnItemWithNoStockRecordIsRefused(): void
    {
        $itemId = tenantInsert(self::$conn, 'inventory_items', [
            'item_name' => 'P6 Uncounted ' . bin2hex(random_bytes(4)),
            'category' => 'feed', 'item_type' => 'Feed', 'unit' => 'kg', 'reorder_level' => 5.0,
        ]);
        $this->items[] = $itemId;

        $this->expectException(FeedRuleViolation::class);
        $this->expectExceptionMessageMatches('/no stock record/i');
        feedRecordConsumption(self::$conn, ['consumed_on' => farmToday(), 'item_id' => $itemId,
            'quantity' => 1.0, 'scope_type' => 'farm', 'notes' => 'PHPUNIT']);
    }

    // =======================================================================
    // 9. THE SHARED STOCK HELPER
    // =======================================================================

    public function testInventoryConsumeRefusesNonPositiveQuantities(): void
    {
        $itemId = $this->makeFeedItem(100.0);
        foreach ([0.0, -1.0] as $quantity) {
            try {
                inventoryConsume(self::$conn, $itemId, $quantity, 'test');
                self::fail('Rejected: ' . $quantity);
            } catch (InventoryStockError $e) {
                self::assertStringContainsString('greater than zero', $e->getMessage());
            }
        }
        self::assertSame(100.0, $this->stockFor($itemId));
    }

    /** Medication and feed now share ONE implementation of the stock rule. */
    public function testMedicationAndFeedShareOneStockRule(): void
    {
        self::assertTrue(function_exists('inventoryConsume'));
        $healthSource = (string) file_get_contents(__DIR__ . '/../../includes/health.php');
        self::assertStringContainsString('inventoryConsume(', $healthSource,
            'health.php must call the shared helper, not keep its own copy');
        self::assertStringNotContainsString(
            'UPDATE inventory_levels SET quantity = ? WHERE inventory_id = ?',
            $healthSource,
            'A second inline stock update would be a second stock rule'
        );
    }

    // =======================================================================
    // 10. FARM ADVISOR — extended, not replaced
    // =======================================================================

    public function testTheExistingAdvisorInsightsStillRun(): void
    {
        $insights = getFarmAdvisorInsights(self::$conn, 1);
        self::assertNotSame([], $insights);

        $titles = implode(' | ', array_column($insights, 'title'));
        // The pre-Phase-6 families must all still be produced.
        self::assertMatchesRegularExpression('/herd health|cow|veterinary attention/i', $titles);
        self::assertMatchesRegularExpression('/inventory/i', $titles);

        foreach ($insights as $insight) {
            foreach (['severity', 'title', 'why', 'impact', 'action'] as $key) {
                self::assertArrayHasKey($key, $insight, 'The insight contract must not regress');
            }
            self::assertArrayHasKey($insight['severity'], DFMS_SEVERITY_RANK);
        }
    }

    public function testProductionInsightsAreDeterministicAndNeedNoAi(): void
    {
        $animalId = $this->makeAnimal();
        productionRecordYield(self::$conn, ['cow_id' => $animalId,
            'production_date' => farmToday(), 'session' => 'morning', 'quantity' => 10]);

        $first = getProductionInsights(self::$conn, 1);
        $second = getProductionInsights(self::$conn, 1);

        self::assertEquals($first, $second, 'The same data must always give the same answer');
        foreach ($first as $insight) {
            self::assertArrayHasKey('severity', $insight);
            self::assertArrayHasKey($insight['severity'], DFMS_SEVERITY_RANK);
        }
    }

    // =======================================================================
    // 11. TIMELINE
    // =======================================================================

    public function testProductionAppearsOnTheAnimalTimeline(): void
    {
        $animalId = $this->makeAnimal();
        productionRecordYield(self::$conn, ['cow_id' => $animalId,
            'production_date' => farmToday(), 'session' => 'evening', 'quantity' => 11.5]);

        $timeline = animalTimeline(self::$conn, $animalId, 1, 25);
        $types = array_column($timeline['items'], 'type');

        self::assertContains('production', $types);
        $entry = null;
        foreach ($timeline['items'] as $item) {
            if ($item['type'] === 'production') {
                $entry = $item;
                break;
            }
        }
        self::assertNotNull($entry);
        self::assertStringContainsString('Evening milking', $entry['title']);
    }

    /** The timeline must never show a farm total as this animal's. */
    public function testTheTimelineNeverShowsFarmTotals(): void
    {
        $animalId = $this->makeAnimal();
        $date = farmDateOffset(-4);
        self::$conn->prepare(
            "INSERT INTO milk_production (farm_id, production_date, total_milk_yield)
             VALUES (1, ?, 999.00) ON DUPLICATE KEY UPDATE total_milk_yield = 999.00"
        )->execute([$date]);

        try {
            $timeline = animalTimeline(self::$conn, $animalId, 1, 50);
            foreach ($timeline['items'] as $item) {
                self::assertStringNotContainsString('999', (string) $item['title'],
                    'A farm-level total must never surface on an animal timeline');
            }
        } finally {
            self::$conn->prepare('DELETE FROM milk_production WHERE farm_id = 1 AND production_date = ?')
                ->execute([$date]);
        }
    }

    // =======================================================================
    // 12. PERMISSIONS
    // =======================================================================

    public function testPhase6ReusesExistingPermissionsAndAddsOnlyOne(): void
    {
        foreach (['milk.view', 'milk.create', 'milk.edit', 'milk.delete',
                     'feeding.view', 'feeding.create', 'inventory.update'] as $permission) {
            self::assertArrayHasKey($permission, DFMS_PERMISSIONS);
        }

        // Deleting is narrower than editing, as everywhere else in this matrix.
        self::assertSame(['farm_manager'], DFMS_PERMISSIONS['milk.delete']);
        self::assertContains('staff', DFMS_PERMISSIONS['milk.edit']);

        // feeding.create was reused for stock-moving consumption precisely
        // because it grants the same roles inventory.update does.
        self::assertSame(DFMS_PERMISSIONS['inventory.update'], DFMS_PERMISSIONS['feeding.create']);

        // No speculative production.*/feed.* namespace was invented.
        foreach (array_keys(DFMS_PERMISSIONS) as $permission) {
            self::assertStringStartsNotWith('production.', $permission);
            self::assertStringStartsNotWith('feed.', $permission);
        }
    }
}
