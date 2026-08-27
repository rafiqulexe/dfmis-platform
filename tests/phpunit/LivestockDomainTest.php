<?php

declare(strict_types=1);

namespace DFMIS\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Animal Digital Twin domain rules.
 *
 * Runs against the ISOLATED dfms_test database (phpunit.xml sets DB_NAME, and
 * tests/bootstrap.php refuses to proceed against anything not identifiable as
 * a test database).
 *
 * Every test that writes cleans up after itself in tearDown, so a failure
 * cannot leave the test schema in a state that breaks the next run.
 */
final class LivestockDomainTest extends TestCase
{
    private static \PDO $conn;

    /** @var list<int> animal ids created by a test, removed in tearDown */
    private array $created = [];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../bootstrap.php';
        require_once __DIR__ . '/../../includes/tenant.php';
        require_once __DIR__ . '/../../includes/repository.php';
        require_once __DIR__ . '/../../includes/settings.php';
        require_once __DIR__ . '/../../includes/livestock.php';

        // Destructive-capable test: refuse anything but a test database.
        dfms_require_test_database();

        self::$conn = getDBConnection();
    }

    protected function setUp(): void
    {
        // Act as the seeded farm-1 manager. Tenant context comes from the
        // session exactly as it does in a real request.
        $_SESSION = ['admin_id' => 1, 'farm_id' => 1, 'is_platform_admin' => 0, 'username' => 'admin'];
        dfms_reset_settings_cache();
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            // Children first — FKs are ON DELETE CASCADE, but being explicit
            // keeps the cleanup readable and order-independent.
            self::$conn->prepare('DELETE FROM animal_identifiers WHERE cow_id = ?')->execute([$id]);
            self::$conn->prepare('DELETE FROM animal_lifecycle_events WHERE cow_id = ?')->execute([$id]);
            self::$conn->prepare('DELETE FROM animal_transfers WHERE cow_id = ?')->execute([$id]);
            self::$conn->prepare('DELETE FROM animal_ownership WHERE cow_id = ?')->execute([$id]);
            self::$conn->prepare('UPDATE cows SET dam_id = NULL, sire_id = NULL WHERE dam_id = ? OR sire_id = ?')->execute([$id, $id]);
            self::$conn->prepare('DELETE FROM cows WHERE cow_id = ?')->execute([$id]);
        }
        $this->created = [];
        $_SESSION = [];
    }

    /** Creates an animal on farm 1 and registers it for cleanup. */
    private function makeAnimal(array $overrides = []): int
    {
        $id = tenantInsert(self::$conn, 'cows', array_merge([
            'unique_id'        => 'TEST' . bin2hex(random_bytes(4)),
            'name'             => 'Test Animal',
            'species'          => 'cattle',
            'sex'              => 'female',
            'lifecycle_status' => 'active',
            'created_at'       => dfms_utc_now(),
        ], $overrides));
        $this->created[] = $id;
        return $id;
    }

    // ---- classification ----------------------------------------------------

    public function testSpeciesRegistryIsClosed(): void
    {
        self::assertTrue(dfms_is_valid_species('cattle'));
        self::assertTrue(dfms_is_valid_species('goat'));
        self::assertFalse(dfms_is_valid_species('dragon'));
        self::assertFalse(dfms_is_valid_species(null));
    }

    /** Species-correct vocabulary: a goat has does and bucks, not cows and bulls. */
    public function testSexLabelsFollowSpecies(): void
    {
        self::assertSame('Cow', dfms_animal_sex_label('cattle', 'female'));
        self::assertSame('Bull', dfms_animal_sex_label('cattle', 'male'));
        self::assertSame('Doe', dfms_animal_sex_label('goat', 'female'));
        self::assertSame('Ram', dfms_animal_sex_label('sheep', 'male'));
    }

    public function testExistingAnimalsAreClassifiedAsCattle(): void
    {
        // Migration 007 backfilled species='cattle'. That is a statement of
        // fact about seeded dairy cattle, and it must not have been left null.
        $animal = animalFind(self::$conn, 1);
        self::assertNotNull($animal, 'The seeded animal must still exist after migration 007');
        self::assertSame('cattle', $animal['species']);
    }

    /** Step 22: migration must not have invented data it could not know. */
    public function testHistoricalRecordsHaveNoFabricatedAcquisition(): void
    {
        $animal = animalFind(self::$conn, 1);
        self::assertNull($animal['acquisition_type'], 'Acquisition must be NULL, not guessed, for pre-existing animals');
        self::assertNull($animal['dam_id'], 'Parentage must not be invented');
        self::assertNull($animal['sire_id'], 'Parentage must not be invented');
    }

    // ---- identity ----------------------------------------------------------

    public function testIdentifierCanBeAddedAndListed(): void
    {
        $id = $this->makeAnimal();
        animalAddIdentifier(self::$conn, $id, [
            'identifier_type'  => 'rfid',
            'identifier_value' => 'RFID-' . bin2hex(random_bytes(3)),
        ]);
        self::assertCount(1, animalIdentifiers(self::$conn, $id));
    }

    public function testUnknownIdentifierTypeIsRejected(): void
    {
        $id = $this->makeAnimal();
        $this->expectException(InvalidArgumentException::class);
        animalAddIdentifier(self::$conn, $id, ['identifier_type' => 'tattoo', 'identifier_value' => 'X']);
    }

    public function testEmptyIdentifierValueIsRejected(): void
    {
        $id = $this->makeAnimal();
        $this->expectException(InvalidArgumentException::class);
        animalAddIdentifier(self::$conn, $id, ['identifier_type' => 'rfid', 'identifier_value' => '   ']);
    }

    /**
     * Uniqueness is PER FARM. Two animals on the same farm cannot share an
     * identifier — and the failure must be a constraint violation, not a
     * silent duplicate.
     */
    public function testIdentifierIsUniqueWithinAFarm(): void
    {
        $first = $this->makeAnimal();
        $second = $this->makeAnimal();
        $value = 'DUP-' . bin2hex(random_bytes(3));

        animalAddIdentifier(self::$conn, $first, ['identifier_type' => 'rfid', 'identifier_value' => $value]);

        $this->expectException(\PDOException::class);
        animalAddIdentifier(self::$conn, $second, ['identifier_type' => 'rfid', 'identifier_value' => $value]);
    }

    /**
     * The SAME identifier value may exist on another farm.
     *
     * This is the deliberate trade-off recorded in migration 007: global
     * uniqueness would let one tenant discover another tenant's animal by
     * watching an insert fail — the T-1 enumeration leak Phase 1 fixed.
     */
    public function testSameIdentifierMayExistOnAnotherFarm(): void
    {
        $value = 'SHARED-' . bin2hex(random_bytes(3));

        $mine = $this->makeAnimal();
        animalAddIdentifier(self::$conn, $mine, ['identifier_type' => 'rfid', 'identifier_value' => $value]);

        // Insert directly for farm 2 — a different tenant, same value.
        self::$conn->prepare(
            "INSERT INTO animal_identifiers (farm_id, cow_id, identifier_type, identifier_value)
             VALUES (2, (SELECT cow_id FROM cows WHERE farm_id = 2 LIMIT 1), 'rfid', ?)"
        )->execute([$value]);

        $count = (int) self::$conn->query(
            "SELECT COUNT(*) FROM animal_identifiers WHERE identifier_value = " . self::$conn->quote($value)
        )->fetchColumn();
        self::assertSame(2, $count, 'The same identifier must be permitted on two different farms');

        self::$conn->prepare('DELETE FROM animal_identifiers WHERE farm_id = 2 AND identifier_value = ?')->execute([$value]);
    }

    /** A crafted animal id must not attach an identifier to another tenant. */
    public function testIdentifierCannotBeAttachedToAnotherTenantsAnimal(): void
    {
        $this->expectException(\RuntimeException::class);
        // cow 7 belongs to farm 2; the session is farm 1.
        animalAddIdentifier(self::$conn, 7, ['identifier_type' => 'rfid', 'identifier_value' => 'X1']);
    }

    // ---- lifecycle ---------------------------------------------------------

    public function testValidTransitionIsAccepted(): void
    {
        self::assertTrue(dfms_lifecycle_transition_allowed('active', 'sold'));
        self::assertTrue(dfms_lifecycle_transition_allowed('active', 'deceased'));
        self::assertTrue(dfms_lifecycle_transition_allowed(null, 'active'));
    }

    public function testNoOpTransitionIsRejected(): void
    {
        self::assertFalse(dfms_lifecycle_transition_allowed('active', 'active'));
    }

    /**
     * A terminal state may only return to active. Going sideways — sold
     * straight to deceased — would describe a sequence that did not happen.
     */
    public function testTerminalStatesOnlyReturnToActive(): void
    {
        self::assertTrue(dfms_lifecycle_transition_allowed('sold', 'active'));
        self::assertFalse(dfms_lifecycle_transition_allowed('sold', 'deceased'));
        self::assertFalse(dfms_lifecycle_transition_allowed('deceased', 'sold'));
        self::assertFalse(dfms_lifecycle_transition_allowed('archived', 'retired'));
    }

    public function testUnknownStatusIsRejected(): void
    {
        self::assertFalse(dfms_lifecycle_transition_allowed('active', 'abducted'));
    }

    public function testLifecycleChangeIsPersistedAndHistoryPreserved(): void
    {
        $id = $this->makeAnimal();

        animalChangeLifecycleStatus(self::$conn, $id, 'sold', ['reason' => 'Sold at market']);

        $animal = animalFind(self::$conn, $id, true);
        self::assertSame('sold', $animal['lifecycle_status']);
        self::assertNotNull($animal['deleted_at'], 'A terminal status removes the animal from the working herd');

        $history = animalLifecycleHistory(self::$conn, $id);
        self::assertCount(1, $history);
        self::assertSame('active', $history[0]['previous_status']);
        self::assertSame('sold', $history[0]['new_status']);
        self::assertSame('Sold at market', $history[0]['reason']);
    }

    /** Correcting a mistake adds history; it does not erase it. */
    public function testCorrectionPreservesTheOriginalEvent(): void
    {
        $id = $this->makeAnimal();
        animalChangeLifecycleStatus(self::$conn, $id, 'sold');
        animalChangeLifecycleStatus(self::$conn, $id, 'active', ['reason' => 'Recorded in error']);

        $history = animalLifecycleHistory(self::$conn, $id);
        self::assertCount(2, $history, 'Both the mistake and the correction must remain visible');

        $animal = animalFind(self::$conn, $id, true);
        self::assertSame('active', $animal['lifecycle_status']);
        self::assertNull($animal['deleted_at'], 'Returning to active restores the animal to the working herd');
    }

    public function testInvalidTransitionThrows(): void
    {
        $id = $this->makeAnimal();
        animalChangeLifecycleStatus(self::$conn, $id, 'deceased');

        $this->expectException(InvalidArgumentException::class);
        animalChangeLifecycleStatus(self::$conn, $id, 'sold');
    }

    // ---- parentage ---------------------------------------------------------

    public function testInternalParentIsAccepted(): void
    {
        $dam = $this->makeAnimal(['sex' => 'female']);
        $calf = $this->makeAnimal();

        $result = animalValidateParent(self::$conn, $calf, $dam, 'dam');
        self::assertTrue($result['ok'], $result['error'] ?? '');
        self::assertNotNull($result['parent']);
    }

    /** An external parent is a name, and must not require a fake animal record. */
    public function testExternalParentNeedsNoInternalRecord(): void
    {
        $before = (int) self::$conn->query('SELECT COUNT(*) FROM cows')->fetchColumn();

        $calf = $this->makeAnimal(['sire_external' => 'Champion Bull (external AI sire)']);

        $after = (int) self::$conn->query('SELECT COUNT(*) FROM cows')->fetchColumn();
        self::assertSame($before + 1, $after, 'Only the calf is created — no phantom sire');

        $animal = animalFind(self::$conn, $calf);
        self::assertSame('Champion Bull (external AI sire)', $animal['sire_external']);
        self::assertNull($animal['sire_id']);
    }

    public function testAnimalCannotBeItsOwnParent(): void
    {
        $id = $this->makeAnimal();
        $result = animalValidateParent(self::$conn, $id, $id, 'dam');
        self::assertFalse($result['ok']);
        self::assertStringContainsString('its own parent', (string) $result['error']);
    }

    /** A bull cannot be a dam: that is a data error, not a preference. */
    public function testParentSexMustMatchTheRole(): void
    {
        $bull = $this->makeAnimal(['sex' => 'male']);
        $calf = $this->makeAnimal();

        $asDam = animalValidateParent(self::$conn, $calf, $bull, 'dam');
        self::assertFalse($asDam['ok']);
        self::assertStringContainsString('sex does not match', (string) $asDam['error']);

        $asSire = animalValidateParent(self::$conn, $calf, $bull, 'sire');
        self::assertTrue($asSire['ok'], $asSire['error'] ?? '');
    }

    /** A pedigree must not become a cycle. */
    public function testDescendantCannotBecomeItsAncestorsParent(): void
    {
        $dam = $this->makeAnimal(['sex' => 'female']);
        $calf = $this->makeAnimal(['sex' => 'female', 'dam_id' => $dam]);

        $result = animalValidateParent(self::$conn, $dam, $calf, 'dam');
        self::assertFalse($result['ok']);
        self::assertStringContainsString('descendant', (string) $result['error']);
    }

    public function testParentFromAnotherTenantIsRejected(): void
    {
        $calf = $this->makeAnimal();
        // cow 7 is on farm 2.
        $result = animalValidateParent(self::$conn, $calf, 7, 'dam');
        self::assertFalse($result['ok']);
        self::assertStringContainsString('could not be found', (string) $result['error']);
    }

    public function testOffspringAreListed(): void
    {
        $dam = $this->makeAnimal(['sex' => 'female']);
        $this->makeAnimal(['dam_id' => $dam]);
        $this->makeAnimal(['dam_id' => $dam]);

        self::assertCount(2, animalOffspring(self::$conn, $dam));
    }

    // ---- transfers ---------------------------------------------------------

    public function testInternalMoveRecordsHistory(): void
    {
        $id = $this->makeAnimal();
        $locations = farmLocations(self::$conn);
        if (empty($locations)) {
            self::markTestSkipped('Farm 1 has no locations seeded.');
        }
        $destination = (int) $locations[0]['location_id'];

        self::assertTrue(animalMoveWithinFarm(self::$conn, $id, $destination));

        $animal = animalFind(self::$conn, $id);
        self::assertSame($destination, (int) $animal['location_id']);

        $history = animalTransferHistory(self::$conn, $id);
        self::assertCount(1, $history);
        self::assertSame('internal', $history[0]['transfer_type']);
    }

    public function testMovingToTheSameLocationRecordsNothing(): void
    {
        $id = $this->makeAnimal();
        self::assertFalse(animalMoveWithinFarm(self::$conn, $id, null), 'A no-op move must not create a history row');
        self::assertCount(0, animalTransferHistory(self::$conn, $id));
    }

    /**
     * A location on another farm must not be assignable.
     *
     * The fixture is CREATED here rather than skipping when the seed data
     * happens not to include a farm-2 location. A security assertion that
     * quietly skips is worse than no assertion, because the report still
     * looks green.
     */
    public function testCannotMoveToAnotherFarmsLocation(): void
    {
        $id = $this->makeAnimal();

        self::$conn->exec(
            "INSERT INTO locations (farm_id, name, location_type)
             VALUES (2, 'Foreign Barn (test fixture)', 'barn')"
        );
        $foreignLocationId = (int) self::$conn->lastInsertId();

        try {
            animalMoveWithinFarm(self::$conn, $id, $foreignLocationId);
            self::fail('Assigning an animal to another farm\'s location must be refused');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('not available on this farm', $e->getMessage());
        } finally {
            self::$conn->prepare('DELETE FROM locations WHERE location_id = ?')->execute([$foreignLocationId]);
        }

        // The animal must be exactly where it started.
        $animal = animalFind(self::$conn, $id);
        self::assertNull($animal['location_id']);
        self::assertCount(0, animalTransferHistory(self::$conn, $id));
    }

    /**
     * The critical one: transferring to a farm the user does not belong to must
     * be refused. Farm 2 exists, and the seeded admin is NOT a member of it.
     */
    public function testTransferToAFarmTheUserDoesNotBelongToIsRefused(): void
    {
        $id = $this->makeAnimal();

        $this->expectException(\RuntimeException::class);
        animalTransferToFarm(self::$conn, $id, 2);
    }

    /** A refused transfer must leave the animal exactly where it was. */
    public function testRefusedTransferLeavesTheAnimalUnchanged(): void
    {
        $id = $this->makeAnimal();

        try {
            animalTransferToFarm(self::$conn, $id, 2);
            self::fail('The transfer should have been refused');
        } catch (\RuntimeException $e) {
            // expected
        }

        $animal = animalFind(self::$conn, $id);
        self::assertNotNull($animal, 'The animal must still belong to farm 1');
        self::assertSame(1, (int) $animal['farm_id']);
        self::assertCount(0, animalTransferHistory(self::$conn, $id), 'No transfer row may be written');
    }

    public function testTransferToTheSameFarmIsRejected(): void
    {
        $id = $this->makeAnimal();
        $this->expectException(InvalidArgumentException::class);
        animalTransferToFarm(self::$conn, $id, 1);
    }

    // ---- ownership ---------------------------------------------------------

    public function testOwnershipChangeClosesThePreviousPeriod(): void
    {
        $id = $this->makeAnimal();

        animalRecordOwnership(self::$conn, $id, ['ownership_type' => 'owned', 'effective_from' => '2026-01-01']);
        animalRecordOwnership(self::$conn, $id, ['ownership_type' => 'leased_out', 'owner_name' => 'Neighbour Farm', 'effective_from' => '2026-06-01']);

        $history = animalOwnershipHistory(self::$conn, $id);
        self::assertCount(2, $history);

        $open = array_filter($history, static fn(array $row): bool => $row['effective_to'] === null);
        self::assertCount(1, $open, 'Exactly one ownership period may be open at a time');

        $current = animalCurrentOwnership(self::$conn, $id);
        self::assertSame('leased_out', $current['ownership_type']);
    }

    public function testUnknownOwnershipTypeIsRejected(): void
    {
        $id = $this->makeAnimal();
        $this->expectException(InvalidArgumentException::class);
        animalRecordOwnership(self::$conn, $id, ['ownership_type' => 'timeshare']);
    }

    // ---- timeline ----------------------------------------------------------

    public function testTimelineIsChronologicalAndPaginated(): void
    {
        $id = $this->makeAnimal();
        animalChangeLifecycleStatus(self::$conn, $id, 'retired', ['event_date' => '2026-03-01']);
        animalChangeLifecycleStatus(self::$conn, $id, 'active', ['event_date' => '2026-05-01']);

        $timeline = animalTimeline(self::$conn, $id, 1, 10);
        self::assertGreaterThanOrEqual(2, $timeline['total']);

        $dates = array_column($timeline['items'], 'occurred_at');
        $sorted = $dates;
        rsort($sorted);
        self::assertSame($sorted, $dates, 'The timeline must be newest-first');
    }

    public function testTimelineRespectsPageSize(): void
    {
        $id = $this->makeAnimal();
        animalChangeLifecycleStatus(self::$conn, $id, 'retired', ['event_date' => '2026-03-01']);
        animalChangeLifecycleStatus(self::$conn, $id, 'active', ['event_date' => '2026-04-01']);
        animalChangeLifecycleStatus(self::$conn, $id, 'sold', ['event_date' => '2026-05-01']);

        $firstPage = animalTimeline(self::$conn, $id, 1, 2);
        self::assertCount(2, $firstPage['items']);
        self::assertGreaterThan(2, $firstPage['total']);
    }

    /** The timeline must never surface another tenant's animal. */
    public function testTimelineOfAForeignAnimalIsEmpty(): void
    {
        $timeline = animalTimeline(self::$conn, 7, 1, 25);
        self::assertSame(0, $timeline['total']);
    }

    // ---- search ------------------------------------------------------------

    public function testSearchIsTenantScoped(): void
    {
        $result = animalSearch(self::$conn, ['search' => 'RIVAL001']);
        self::assertSame(0, $result['total'], "Farm 2's animal must not be findable from farm 1");
    }

    public function testSearchFindsByTagAndName(): void
    {
        self::assertGreaterThan(0, animalSearch(self::$conn, ['search' => 'BD001'])['total']);
        self::assertGreaterThan(0, animalSearch(self::$conn, ['search' => 'Nodi'])['total']);
    }

    public function testSearchFindsByIdentifier(): void
    {
        $id = $this->makeAnimal();
        $value = 'FIND-' . bin2hex(random_bytes(3));
        animalAddIdentifier(self::$conn, $id, ['identifier_type' => 'rfid', 'identifier_value' => $value]);

        self::assertSame(1, animalSearch(self::$conn, ['search' => $value])['total']);
    }

    public function testArchivedAnimalsAreHiddenByDefault(): void
    {
        $id = $this->makeAnimal();
        $visibleBefore = animalSearch(self::$conn, [])['total'];

        animalChangeLifecycleStatus(self::$conn, $id, 'archived');

        self::assertSame($visibleBefore - 1, animalSearch(self::$conn, [])['total']);
        self::assertSame($visibleBefore, animalSearch(self::$conn, ['include_archived' => true])['total']);
    }

    public function testFiltersNarrowResults(): void
    {
        $all = animalSearch(self::$conn, [])['total'];
        $males = animalSearch(self::$conn, ['sex' => 'male'])['total'];
        $females = animalSearch(self::$conn, ['sex' => 'female'])['total'];
        self::assertSame($all, $males + $females);
    }

    // ---- helpers -----------------------------------------------------------

    public function testAgeIsComputedFromBirthDate(): void
    {
        $twoYearsAgo = (new \DateTimeImmutable(farmToday()))->modify('-24 months')->format('Y-m-d');
        self::assertSame(24, animalAgeInMonths($twoYearsAgo));
        self::assertNull(animalAgeInMonths(null));
        self::assertNull(animalAgeInMonths('0000-00-00'));
    }

    /** A future birth date is invalid data, not a negative age. */
    public function testFutureBirthDateYieldsNoAge(): void
    {
        $future = (new \DateTimeImmutable(farmToday()))->modify('+1 month')->format('Y-m-d');
        self::assertNull(animalAgeInMonths($future));
        self::assertSame('Unknown', animalAgeLabel($future));
    }

    public function testDisplayNameFallsBackToTag(): void
    {
        self::assertSame('Nodi', animalDisplayName(['name' => 'Nodi', 'unique_id' => 'BD001']));
        self::assertSame('BD001', animalDisplayName(['name' => '', 'unique_id' => 'BD001']));
        self::assertSame('BD001', animalDisplayName(['name' => null, 'unique_id' => 'BD001']));
    }
}
