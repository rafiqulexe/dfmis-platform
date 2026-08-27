<?php

declare(strict_types=1);

namespace DFMIS\Tests;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Veterinary & Animal Health domain rules (Phase 4).
 *
 * Runs against the ISOLATED dfms_test database (phpunit.xml sets DB_NAME, and
 * tests/bootstrap.php refuses to proceed against anything not identifiable as
 * a test database). Every test that writes cleans up after itself in tearDown,
 * following the Phase 3 convention, so a failure cannot leave the test schema
 * in a state that breaks the next run.
 *
 * These are the rules that would be expensive to get wrong: withdrawal date
 * arithmetic, unit conversion on a clinical measurement, whether a scheduled
 * vaccination can silently become an administered one, and whether a terminal
 * lifecycle state really does lock the clinical record.
 */
final class HealthDomainTest extends TestCase
{
    private static PDO $conn;

    /** @var list<int> animal ids created by a test, removed in tearDown */
    private array $created = [];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../bootstrap.php';
        require_once __DIR__ . '/../../includes/tenant.php';
        require_once __DIR__ . '/../../includes/repository.php';
        require_once __DIR__ . '/../../includes/settings.php';
        require_once __DIR__ . '/../../includes/livestock.php';
        require_once __DIR__ . '/../../includes/health.php';

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
            // Children first. The foreign keys cascade, but being explicit
            // keeps the cleanup readable and order-independent.
            foreach ([
                'withdrawal_periods', 'medication_administrations', 'vaccination_records',
                'laboratory_tests', 'health_quarantines', 'health_treatments',
                'health_diagnoses', 'veterinary_consultations', 'health_symptoms',
                'health_observations', 'health_status_events', 'health_events', 'health_cases',
                'alerts',
            ] as $table) {
                self::$conn->prepare("DELETE FROM $table WHERE cow_id = ?")->execute([$id]);
            }
            self::$conn->prepare('DELETE FROM cows WHERE cow_id = ?')->execute([$id]);
        }
        $this->created = [];
        $_SESSION = [];
    }

    /** Creates an animal on farm 1 and registers it for cleanup. */
    private function makeAnimal(array $overrides = []): int
    {
        $id = tenantInsert(self::$conn, 'cows', array_merge([
            'unique_id'        => 'HTEST' . bin2hex(random_bytes(4)),
            'name'             => 'Health Test Animal',
            'species'          => 'cattle',
            'sex'              => 'female',
            'lifecycle_status' => 'active',
            'health_status'    => 'healthy',
            'created_at'       => gmdate('Y-m-d H:i:s'),
        ], $overrides));
        $this->created[] = $id;
        return $id;
    }

    private function makeCase(int $animalId, array $overrides = []): int
    {
        return healthOpenCase(self::$conn, $animalId, array_merge([
            'title'     => 'Test case',
            'case_type' => 'illness',
            'severity'  => 'routine',
            'opened_on' => farmToday(),
        ], $overrides));
    }

    // =======================================================================
    // A. HEALTH STATUS MODEL
    // =======================================================================

    public function testHealthStatusRegistryIsClosed(): void
    {
        self::assertTrue(dfms_is_valid_health_status('healthy'));
        self::assertTrue(dfms_is_valid_health_status('quarantined'));
        self::assertTrue(dfms_is_valid_health_status('recovered'));
        self::assertFalse(dfms_is_valid_health_status('cured'));
        self::assertFalse(dfms_is_valid_health_status(null));
    }

    /**
     * The four pre-Phase-4 values must still be valid. Migration 008 APPENDED
     * to the ENUM; if it had rewritten it, every existing row would have been
     * reinterpreted.
     */
    public function testPreExistingHealthStatusesSurvived(): void
    {
        foreach (['healthy', 'at_risk', 'sick', 'treatment'] as $legacy) {
            self::assertTrue(dfms_is_valid_health_status($legacy), "$legacy must remain valid");
        }
    }

    /**
     * Death is a LIFECYCLE fact. Adding it to health_status too would create
     * two columns that can disagree about whether an animal is alive.
     */
    public function testDeceasedIsNotAHealthStatus(): void
    {
        self::assertFalse(dfms_is_valid_health_status('deceased'));
        self::assertArrayHasKey('deceased', DFMS_LIFECYCLE_STATUSES);
    }

    public function testHealthStatusTransitionRefusesANoOp(): void
    {
        self::assertFalse(dfms_health_transition_allowed('sick', 'sick'));
        self::assertTrue(dfms_health_transition_allowed('sick', 'recovered'));
        self::assertTrue(dfms_health_transition_allowed('recovered', 'sick'));
        self::assertFalse(dfms_health_transition_allowed('sick', 'nonsense'));
    }

    public function testStatusChangeWritesHistoryAndUpdatesTheAnimal(): void
    {
        $id = $this->makeAnimal();
        healthSetStatus(self::$conn, $id, 'sick', ['reason' => 'Off feed']);

        $animal = animalFind(self::$conn, $id, true);
        self::assertSame('sick', $animal['health_status']);

        $history = healthStatusHistory(self::$conn, $id);
        self::assertCount(1, $history);
        self::assertSame('healthy', $history[0]['previous_status']);
        self::assertSame('sick', $history[0]['new_status']);
        self::assertSame('Off feed', $history[0]['reason']);
    }

    public function testRepeatingTheSameStatusIsRefused(): void
    {
        $id = $this->makeAnimal(['health_status' => 'sick']);
        $this->expectException(\HealthRuleViolation::class);
        healthSetStatus(self::$conn, $id, 'sick');
    }

    // =======================================================================
    // B. LIFECYCLE COMPATIBILITY — lifecycle is authoritative over health
    // =======================================================================

    /**
     * The rule the brief is explicit about: a deceased animal must not come
     * back to healthy, and its clinical record must not keep growing.
     */
    public function testDeceasedAnimalRefusesEveryHealthWrite(): void
    {
        $id = $this->makeAnimal(['lifecycle_status' => 'deceased']);

        $this->expectException(\HealthRuleViolation::class);
        healthSetStatus(self::$conn, $id, 'recovered');
    }

    public function testTerminalLifecycleBlocksCaseCreation(): void
    {
        $id = $this->makeAnimal(['lifecycle_status' => 'sold']);
        $this->expectException(\HealthRuleViolation::class);
        $this->makeCase($id);
    }

    public function testTerminalLifecycleBlocksMedication(): void
    {
        $id = $this->makeAnimal(['lifecycle_status' => 'transferred']);
        $this->expectException(\HealthRuleViolation::class);
        healthAdministerMedication(self::$conn, $id, [
            'medication_name' => 'Anything',
            'dose' => 1.0, 'dose_unit' => 'ml', 'route' => 'im',
        ]);
    }

    public function testActiveAnimalIsNotBlocked(): void
    {
        $id = $this->makeAnimal();
        $animal = animalFind(self::$conn, $id, true);
        self::assertFalse(healthLifecycleBlocks($animal));
    }

    // =======================================================================
    // C. CASES
    // =======================================================================

    public function testCaseReferenceIsSequentialPerFarm(): void
    {
        $id = $this->makeAnimal();
        $first = healthFindCase(self::$conn, $this->makeCase($id));
        $second = healthFindCase(self::$conn, $this->makeCase($id));

        self::assertMatchesRegularExpression('/^HC-\d{4}-\d{4}$/', (string) $first['case_reference']);
        self::assertNotSame($first['case_reference'], $second['case_reference']);
    }

    public function testClosingACaseRequiresAnOutcome(): void
    {
        $id = $this->makeAnimal();
        $caseId = $this->makeCase($id);

        $this->expectException(\HealthRuleViolation::class);
        healthUpdateCase(self::$conn, $caseId, ['status' => 'resolved', 'outcome' => null]);
    }

    /**
     * "Outcome unknown" must be accepted. Many real episodes end without a
     * definitive answer, and a system that refuses to record that will be
     * given a fabricated outcome instead.
     */
    public function testUnknownIsAValidCaseOutcome(): void
    {
        $id = $this->makeAnimal();
        $caseId = $this->makeCase($id);

        healthUpdateCase(self::$conn, $caseId, ['status' => 'closed', 'outcome' => 'unknown']);
        $case = healthFindCase(self::$conn, $caseId);
        self::assertSame('closed', $case['status']);
        self::assertSame('unknown', $case['outcome']);
    }

    public function testACaseCannotCloseBeforeItOpened(): void
    {
        $id = $this->makeAnimal();
        $caseId = $this->makeCase($id, ['opened_on' => farmToday()]);

        $this->expectException(\HealthRuleViolation::class);
        healthUpdateCase(self::$conn, $caseId, [
            'status' => 'closed', 'outcome' => 'recovered',
            'closed_on' => farmDateOffset(-5),
        ]);
    }

    public function testOpeningACaseCanMoveHealthStatusInTheSameTransaction(): void
    {
        $id = $this->makeAnimal();
        $this->makeCase($id, ['health_status' => 'sick']);

        $animal = animalFind(self::$conn, $id, true);
        self::assertSame('sick', $animal['health_status']);
        self::assertCount(1, healthStatusHistory(self::$conn, $id));
    }

    // =======================================================================
    // D. MULTIPLE SYMPTOMS
    // =======================================================================

    public function testACaseSupportsManySymptoms(): void
    {
        $id = $this->makeAnimal();
        $caseId = $this->makeCase($id);

        healthAddSymptom(self::$conn, $caseId, ['symptom_code' => 'fever', 'severity' => 'moderate']);
        healthAddSymptom(self::$conn, $caseId, ['symptom_code' => 'appetite_loss', 'severity' => 'mild']);
        healthAddSymptom(self::$conn, $caseId, ['symptom_code' => 'udder_swelling', 'severity' => 'severe']);

        self::assertCount(3, healthCaseSymptoms(self::$conn, $caseId));
    }

    public function testUncodedSymptomRequiresADescription(): void
    {
        $id = $this->makeAnimal();
        $caseId = $this->makeCase($id);

        $this->expectException(\HealthRuleViolation::class);
        healthAddSymptom(self::$conn, $caseId, ['symptom_code' => 'other', 'symptom_label' => '  ']);
    }

    public function testUncodedSymptomIsAcceptedWithADescription(): void
    {
        $id = $this->makeAnimal();
        $caseId = $this->makeCase($id);

        healthAddSymptom(self::$conn, $caseId, [
            'symptom_code'  => 'other',
            'symptom_label' => 'Unusual gait on the left fore',
        ]);
        $symptoms = healthCaseSymptoms(self::$conn, $caseId);
        self::assertSame('Unusual gait on the left fore', dfms_symptom_label($symptoms[0]));
    }

    public function testUnknownSymptomCodeIsRejected(): void
    {
        $id = $this->makeAnimal();
        $caseId = $this->makeCase($id);

        $this->expectException(\HealthRuleViolation::class);
        healthAddSymptom(self::$conn, $caseId, ['symptom_code' => 'spontaneous_combustion']);
    }

    // =======================================================================
    // E. MULTIPLE DIAGNOSES — including ruled-out ones
    // =======================================================================

    public function testACaseSupportsManyDiagnosesIncludingRuledOut(): void
    {
        $id = $this->makeAnimal();
        $caseId = $this->makeCase($id);

        healthAddDiagnosis(self::$conn, $caseId, [
            'condition_code' => 'mastitis', 'status' => 'confirmed',
            'confidence' => 'high', 'diagnosed_on' => farmToday(),
        ]);
        healthAddDiagnosis(self::$conn, $caseId, [
            'condition_code' => 'metritis', 'status' => 'ruled_out',
            'diagnosed_on' => farmToday(),
        ]);

        $diagnoses = healthCaseDiagnoses(self::$conn, $caseId);
        self::assertCount(2, $diagnoses);
        $statuses = array_column($diagnoses, 'status');
        self::assertContains('confirmed', $statuses);
        self::assertContains('ruled_out', $statuses, 'A ruled-out differential is part of the record');
    }

    /**
     * A diagnosis is never rewritten into a different condition — that would
     * erase what was actually believed at the time. Only its status moves.
     */
    public function testDiagnosisStatusChangesButTheConditionDoesNot(): void
    {
        $id = $this->makeAnimal();
        $caseId = $this->makeCase($id);
        $diagnosisId = healthAddDiagnosis(self::$conn, $caseId, [
            'condition_code' => 'pneumonia', 'status' => 'suspected', 'diagnosed_on' => farmToday(),
        ]);

        healthUpdateDiagnosisStatus(self::$conn, $diagnosisId, 'ruled_out');

        $row = tenantFind(self::$conn, 'health_diagnoses', 'diagnosis_id', $diagnosisId);
        self::assertSame('ruled_out', $row['status']);
        self::assertSame('pneumonia', $row['condition_code'], 'The condition itself must be immutable');
    }

    /** Confidence is the clinician's, never computed by DFMIS. */
    public function testConfidenceIsStoredExactlyAsStatedOrNotAtAll(): void
    {
        $id = $this->makeAnimal();
        $caseId = $this->makeCase($id);

        $withConfidence = healthAddDiagnosis(self::$conn, $caseId, [
            'condition_code' => 'ketosis', 'diagnosed_on' => farmToday(), 'confidence' => 'low',
        ]);
        $without = healthAddDiagnosis(self::$conn, $caseId, [
            'condition_code' => 'bloat', 'diagnosed_on' => farmToday(),
        ]);

        self::assertSame('low', tenantFind(self::$conn, 'health_diagnoses', 'diagnosis_id', $withConfidence)['confidence']);
        self::assertNull(tenantFind(self::$conn, 'health_diagnoses', 'diagnosis_id', $without)['confidence'],
            'Absent confidence must stay NULL, never be inferred');
    }

    // =======================================================================
    // F. TREATMENTS
    // =======================================================================

    public function testAnAnimalSupportsManyTreatmentsAndHistoryIsKept(): void
    {
        $id = $this->makeAnimal();
        $caseId = $this->makeCase($id);

        $first = healthStartTreatment(self::$conn, $id, [
            'name' => 'Course A', 'started_on' => farmToday(), 'case_id' => $caseId,
        ]);
        healthStartTreatment(self::$conn, $id, [
            'name' => 'Course B', 'started_on' => farmToday(), 'case_id' => $caseId,
        ]);

        healthCompleteTreatment(self::$conn, $first, 'cancelled', ['ended_on' => farmToday()]);

        $treatments = healthTreatments(self::$conn, $id);
        self::assertCount(2, $treatments, 'A cancelled treatment stays visible');
        $statuses = array_column($treatments, 'status');
        self::assertContains('cancelled', $statuses);
        self::assertContains('active', $statuses);
    }

    public function testCompletedTreatmentCannotBeRewritten(): void
    {
        $id = $this->makeAnimal();
        $treatmentId = healthStartTreatment(self::$conn, $id, [
            'name' => 'Course', 'started_on' => farmToday(),
        ]);
        healthCompleteTreatment(self::$conn, $treatmentId, 'completed', ['outcome' => 'resolved']);

        $this->expectException(\HealthRuleViolation::class);
        healthCompleteTreatment(self::$conn, $treatmentId, 'cancelled');
    }

    public function testCompletingATreatmentRequiresAnOutcome(): void
    {
        $id = $this->makeAnimal();
        $treatmentId = healthStartTreatment(self::$conn, $id, [
            'name' => 'Course', 'started_on' => farmToday(),
        ]);

        $this->expectException(\HealthRuleViolation::class);
        healthCompleteTreatment(self::$conn, $treatmentId, 'completed', ['outcome' => null]);
    }

    public function testTreatmentCannotEndBeforeItStarts(): void
    {
        $id = $this->makeAnimal();
        $this->expectException(\HealthRuleViolation::class);
        healthStartTreatment(self::$conn, $id, [
            'name' => 'Course', 'started_on' => farmToday(), 'ended_on' => farmDateOffset(-3),
        ]);
    }

    // =======================================================================
    // G. VACCINATION SCHEDULING
    // =======================================================================

    /**
     * The rule that matters most here: time never promotes a schedule into a
     * record. An assumed vaccination is worse than a missing one.
     */
    public function testScheduledVaccinationIsNotAdministered(): void
    {
        $id = $this->makeAnimal();
        $vaccinationId = healthRecordVaccination(self::$conn, $id, [
            'status' => 'scheduled', 'vaccine_name' => 'FMD', 'scheduled_on' => farmDateOffset(7),
        ]);

        $row = tenantFind(self::$conn, 'vaccination_records', 'vaccination_id', $vaccinationId);
        self::assertSame('scheduled', $row['status']);
        self::assertNull($row['administered_on'], 'A scheduled dose has no administration date');
        self::assertNull($row['administered_by']);
    }

    public function testMarkingGivenIsAnExplicitAction(): void
    {
        $id = $this->makeAnimal();
        $vaccinationId = healthRecordVaccination(self::$conn, $id, [
            'status' => 'scheduled', 'vaccine_name' => 'FMD', 'scheduled_on' => farmToday(),
        ]);

        healthMarkVaccinationGiven(self::$conn, $vaccinationId, [
            'administered_on' => farmToday(), 'batch_lot' => 'LOT-1',
        ]);

        $row = tenantFind(self::$conn, 'vaccination_records', 'vaccination_id', $vaccinationId);
        self::assertSame('administered', $row['status']);
        self::assertSame(farmToday(), $row['administered_on']);
        self::assertSame('LOT-1', $row['batch_lot']);
    }

    public function testOnlyAScheduledVaccinationCanBeMarkedGiven(): void
    {
        $id = $this->makeAnimal();
        $vaccinationId = healthRecordVaccination(self::$conn, $id, [
            'status' => 'administered', 'vaccine_name' => 'FMD', 'administered_on' => farmToday(),
        ]);

        $this->expectException(\HealthRuleViolation::class);
        healthMarkVaccinationGiven(self::$conn, $vaccinationId, ['administered_on' => farmToday()]);
    }

    /**
     * A correction leaves the mistake visible. The original is superseded and
     * retained, never edited away.
     */
    public function testCorrectingAVaccinationSupersedesRatherThanEdits(): void
    {
        $id = $this->makeAnimal();
        $original = healthRecordVaccination(self::$conn, $id, [
            'status' => 'administered', 'vaccine_name' => 'Wrong vaccine',
            'administered_on' => farmToday(),
        ]);

        $replacement = healthSupersedeVaccination(self::$conn, $original, [
            'vaccine_name' => 'Correct vaccine', 'notes' => 'Recorded against the wrong product',
        ]);

        $old = tenantFind(self::$conn, 'vaccination_records', 'vaccination_id', $original);
        $new = tenantFind(self::$conn, 'vaccination_records', 'vaccination_id', $replacement);

        self::assertSame('superseded', $old['status']);
        self::assertSame('Wrong vaccine', $old['vaccine_name'], 'The original values must survive the correction');
        self::assertSame('Correct vaccine', $new['vaccine_name']);
        self::assertSame($original, (int) $new['supersedes_id']);
    }

    public function testScheduledVaccinationNeedsAScheduledDate(): void
    {
        $id = $this->makeAnimal();
        $this->expectException(\HealthRuleViolation::class);
        healthRecordVaccination(self::$conn, $id, ['status' => 'scheduled', 'vaccine_name' => 'FMD']);
    }

    // =======================================================================
    // H. QUARANTINE LIFECYCLE
    // =======================================================================

    public function testQuarantineMovesHealthStatusAndIsSingular(): void
    {
        $id = $this->makeAnimal();
        healthStartQuarantine(self::$conn, $id, ['reason' => 'New arrival', 'quarantine_type' => 'new_arrival']);

        self::assertSame('quarantined', animalFind(self::$conn, $id, true)['health_status']);
        self::assertNotNull(healthActiveQuarantine(self::$conn, $id));

        // A second concurrent quarantine would make "is this animal isolated"
        // ambiguous and would double every alert.
        $this->expectException(\HealthRuleViolation::class);
        healthStartQuarantine(self::$conn, $id, ['reason' => 'Again']);
    }

    public function testReleasingQuarantineDoesNotGuessTheHealthStatus(): void
    {
        $id = $this->makeAnimal();
        $quarantineId = healthStartQuarantine(self::$conn, $id, ['reason' => 'Suspected disease']);

        // No health_status passed — the animal must stay quarantined-in-status
        // rather than being silently declared healthy.
        healthEndQuarantine(self::$conn, $quarantineId, ['status' => 'released']);

        self::assertSame('quarantined', animalFind(self::$conn, $id, true)['health_status'],
            'Releasing must not invent a recovery');
        self::assertNull(healthActiveQuarantine(self::$conn, $id));
    }

    public function testReleasingQuarantineAppliesTheStatusWhenAsked(): void
    {
        $id = $this->makeAnimal();
        $quarantineId = healthStartQuarantine(self::$conn, $id, ['reason' => 'Precaution']);
        healthEndQuarantine(self::$conn, $quarantineId, [
            'status' => 'released', 'health_status' => 'recovered',
        ]);
        self::assertSame('recovered', animalFind(self::$conn, $id, true)['health_status']);
    }

    public function testAnEndedQuarantineCannotBeEndedAgain(): void
    {
        $id = $this->makeAnimal();
        $quarantineId = healthStartQuarantine(self::$conn, $id, ['reason' => 'Precaution']);
        healthEndQuarantine(self::$conn, $quarantineId, ['status' => 'released']);

        $this->expectException(\HealthRuleViolation::class);
        healthEndQuarantine(self::$conn, $quarantineId, ['status' => 'released']);
    }

    // =======================================================================
    // I. WITHDRAWAL CALCULATION — food-safety arithmetic
    // =======================================================================

    /**
     * ends_on is INCLUSIVE. A 4-day withdrawal starting on the 1st runs through
     * the 4th and lifts on the 5th. An off-by-one here puts unsafe product into
     * the food chain, so it is asserted on exact dates rather than a duration.
     */
    public function testWithdrawalEndDateIsInclusive(): void
    {
        $id = $this->makeAnimal();
        healthAdministerMedication(self::$conn, $id, [
            'medication_name' => 'Test antibiotic',
            'dose' => 10.0, 'dose_unit' => 'ml', 'route' => 'im',
            'administered_at' => '2026-03-01 08:00:00',
            'withdrawals' => ['milk' => 4, 'meat' => 28],
        ]);

        $withdrawals = healthWithdrawalHistory(self::$conn, $id);
        $byType = [];
        foreach ($withdrawals as $withdrawal) {
            $byType[$withdrawal['restriction_type']] = $withdrawal;
        }

        self::assertSame('2026-03-01', $byType['milk']['starts_on']);
        self::assertSame('2026-03-04', $byType['milk']['ends_on'], '4 days inclusive = 1st through 4th');
        self::assertSame('2026-03-28', $byType['meat']['ends_on'], '28 days inclusive = 1st through 28th');
    }

    /**
     * The most dangerous possible default. A blank withdrawal must create NO
     * restriction, never a zero-day one — "not specified" and "no withdrawal
     * period" are different claims.
     */
    public function testBlankWithdrawalCreatesNoRestriction(): void
    {
        $id = $this->makeAnimal();
        healthAdministerMedication(self::$conn, $id, [
            'medication_name' => 'Test product',
            'dose' => 1.0, 'dose_unit' => 'ml', 'route' => 'oral',
            'withdrawals' => ['milk' => '', 'meat' => null, 'eggs' => '0'],
        ]);

        self::assertSame([], healthWithdrawalHistory(self::$conn, $id),
            'An unspecified withdrawal must not become a zero-day restriction');
    }

    public function testWithdrawalTypeMustSuitTheSpecies(): void
    {
        $id = $this->makeAnimal(['species' => 'cattle']);
        $this->expectException(\HealthRuleViolation::class);
        healthAdministerMedication(self::$conn, $id, [
            'medication_name' => 'Test',
            'dose' => 1.0, 'dose_unit' => 'ml', 'route' => 'oral',
            // Cattle do not lay eggs.
            'withdrawals' => ['eggs' => 7],
        ]);
    }

    public function testSpeciesSpecificWithdrawalTypes(): void
    {
        $cattle = dfms_withdrawal_types_for_species('cattle');
        self::assertArrayHasKey('milk', $cattle);
        self::assertArrayHasKey('meat', $cattle);
        self::assertArrayNotHasKey('eggs', $cattle);

        $chicken = dfms_withdrawal_types_for_species('chicken');
        self::assertArrayHasKey('eggs', $chicken);
        self::assertArrayNotHasKey('milk', $chicken);

        // An unknown species gets only the universally-applicable types, never
        // the full list — offering egg withdrawal for a cow invites nonsense.
        $unknown = dfms_withdrawal_types_for_species(null);
        self::assertArrayHasKey('meat', $unknown);
        self::assertArrayNotHasKey('milk', $unknown);
        self::assertArrayNotHasKey('eggs', $unknown);
    }

    public function testAnAbsurdWithdrawalLengthIsRejected(): void
    {
        $id = $this->makeAnimal();
        $this->expectException(\HealthRuleViolation::class);
        healthAdministerMedication(self::$conn, $id, [
            'medication_name' => 'Test',
            'dose' => 1.0, 'dose_unit' => 'ml', 'route' => 'oral',
            'withdrawals' => ['milk' => 4000],
        ]);
    }

    public function testActiveWithdrawalsExcludeExpiredAndCancelledOnes(): void
    {
        $id = $this->makeAnimal();
        healthAdministerMedication(self::$conn, $id, [
            'medication_name' => 'Current product',
            'dose' => 1.0, 'dose_unit' => 'ml', 'route' => 'oral',
            'withdrawals' => ['milk' => 10],
        ]);
        self::assertCount(1, healthActiveWithdrawals(self::$conn, $id));

        $withdrawal = healthWithdrawalHistory(self::$conn, $id)[0];
        healthCancelWithdrawal(self::$conn, (int) $withdrawal['withdrawal_id'], 'Recorded against the wrong animal');

        self::assertCount(0, healthActiveWithdrawals(self::$conn, $id),
            'A cancelled restriction is no longer in force');
        self::assertCount(1, healthWithdrawalHistory(self::$conn, $id),
            'But it stays in the history');
    }

    public function testCancellingAWithdrawalRequiresAReason(): void
    {
        $id = $this->makeAnimal();
        healthAdministerMedication(self::$conn, $id, [
            'medication_name' => 'Test',
            'dose' => 1.0, 'dose_unit' => 'ml', 'route' => 'oral',
            'withdrawals' => ['milk' => 5],
        ]);
        $withdrawal = healthWithdrawalHistory(self::$conn, $id)[0];

        $this->expectException(\HealthRuleViolation::class);
        healthCancelWithdrawal(self::$conn, (int) $withdrawal['withdrawal_id'], '   ');
    }

    // =======================================================================
    // J. OBSERVATIONS AND UNITS
    // =======================================================================

    /**
     * A temperature entered in Fahrenheit must be STORED in Celsius. Getting
     * this wrong produces a plausible-looking number that is clinically wrong.
     */
    public function testFahrenheitTemperatureIsStoredAsCelsius(): void
    {
        $id = $this->makeAnimal();
        healthRecordObservation(self::$conn, $id, [
            'observation_type' => 'body_temperature',
            'value'            => 103.0,
            'input_unit'       => 'f',
        ]);

        $observation = healthObservations(self::$conn, $id)[0];
        self::assertSame('c', $observation['unit'], 'Storage is always canonical');
        self::assertEqualsWithDelta(39.44, (float) $observation['value_numeric'], 0.01);
    }

    public function testCelsiusTemperatureRoundTrips(): void
    {
        $id = $this->makeAnimal();
        healthRecordObservation(self::$conn, $id, [
            'observation_type' => 'body_temperature',
            'value'            => 38.6,
            'input_unit'       => 'c',
        ]);
        $observation = healthObservations(self::$conn, $id)[0];
        self::assertEqualsWithDelta(38.6, (float) $observation['value_numeric'], 0.001);
    }

    /**
     * An unsupported unit must FAIL rather than be silently treated as
     * canonical — that is the difference between a rejected record and a wrong
     * clinical number.
     */
    public function testUnsupportedUnitFailsRatherThanConvertingWrongly(): void
    {
        $id = $this->makeAnimal();
        $this->expectException(\HealthRuleViolation::class);
        healthRecordObservation(self::$conn, $id, [
            'observation_type' => 'body_temperature',
            'value'            => 38.5,
            'input_unit'       => 'kelvin',
        ]);
    }

    public function testOutOfRangeObservationIsRejected(): void
    {
        $id = $this->makeAnimal();
        $this->expectException(\HealthRuleViolation::class);
        healthRecordObservation(self::$conn, $id, [
            'observation_type' => 'body_temperature',
            'value'            => 500,
            'input_unit'       => 'c',
        ]);
    }

    /** The range check runs on the CANONICAL value, so 103 °F must pass. */
    public function testAFeverInFahrenheitPassesTheRangeCheck(): void
    {
        $id = $this->makeAnimal();
        $observationId = healthRecordObservation(self::$conn, $id, [
            'observation_type' => 'body_temperature',
            'value'            => 105.0,
            'input_unit'       => 'f',
        ]);
        self::assertGreaterThan(0, $observationId);
    }

    public function testQualitativeObservationUsesItsCodedScale(): void
    {
        $id = $this->makeAnimal();
        healthRecordObservation(self::$conn, $id, [
            'observation_type' => 'appetite',
            'value_code'       => 'poor',
        ]);

        $observation = healthObservations(self::$conn, $id)[0];
        self::assertSame('poor', $observation['value_code']);
        self::assertNull($observation['value_numeric']);
        self::assertSame('Poor', healthFormatObservation($observation));
    }

    public function testQualitativeObservationRejectsAValueOutsideItsScale(): void
    {
        $id = $this->makeAnimal();
        $this->expectException(\HealthRuleViolation::class);
        healthRecordObservation(self::$conn, $id, [
            'observation_type' => 'appetite',
            'value_code'       => 'ravenous',
        ]);
    }

    public function testUnknownObservationTypeIsRejected(): void
    {
        $id = $this->makeAnimal();
        $this->expectException(\HealthRuleViolation::class);
        healthRecordObservation(self::$conn, $id, ['observation_type' => 'aura_reading', 'value' => 1]);
    }

    // =======================================================================
    // K. INVALID DATES AND FUTURE TIMES
    // =======================================================================

    /**
     * A medication dated in the future would create a withdrawal that has not
     * started, leaving the animal unflagged today. That is a food-safety
     * failure, so the domain layer refuses it regardless of which form called.
     */
    public function testMedicationCannotBeRecordedInTheFuture(): void
    {
        $id = $this->makeAnimal();
        $this->expectException(\HealthRuleViolation::class);
        healthAdministerMedication(self::$conn, $id, [
            'medication_name' => 'Test',
            'dose' => 1.0, 'dose_unit' => 'ml', 'route' => 'oral',
            'administered_at' => gmdate('Y-m-d H:i:s', time() + (60 * 86400)),
        ]);
    }

    public function testObservationCannotBeRecordedInTheFuture(): void
    {
        $id = $this->makeAnimal();
        $this->expectException(\HealthRuleViolation::class);
        healthRecordObservation(self::$conn, $id, [
            'observation_type' => 'body_temperature',
            'value' => 38.5, 'input_unit' => 'c',
            'observed_at' => gmdate('Y-m-d H:i:s', time() + (30 * 86400)),
        ]);
    }

    /**
     * A small forward tolerance IS allowed, because the browser sends the
     * operator's local wall clock while storage is UTC. A farm several hours
     * ahead must still be able to record something that just happened.
     */
    public function testASmallTimezoneSkewIsTolerated(): void
    {
        $id = $this->makeAnimal();
        $administrationId = healthAdministerMedication(self::$conn, $id, [
            'medication_name' => 'Test',
            'dose' => 1.0, 'dose_unit' => 'ml', 'route' => 'oral',
            'administered_at' => gmdate('Y-m-d H:i:s', time() + (6 * 3600)),
        ]);
        self::assertGreaterThan(0, $administrationId);
    }

    public function testQuarantineCannotEndBeforeItStarted(): void
    {
        $id = $this->makeAnimal();
        $quarantineId = healthStartQuarantine(self::$conn, $id, [
            'reason' => 'Test', 'started_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $this->expectException(\HealthRuleViolation::class);
        healthEndQuarantine(self::$conn, $quarantineId, [
            'status' => 'released',
            'ended_at' => gmdate('Y-m-d H:i:s', time() - 86400),
        ]);
    }

    public function testLabResultCannotPredateItsSample(): void
    {
        $id = $this->makeAnimal();
        $this->expectException(\HealthRuleViolation::class);
        healthRecordLabTest(self::$conn, $id, [
            'test_type' => 'milk_culture', 'test_name' => 'Culture',
            'sample_taken_on' => farmToday(),
            'result_received_on' => farmDateOffset(-5),
            'result_status' => 'received', 'result_code' => 'positive',
        ]);
    }

    public function testAPendingLabTestCannotCarryAResult(): void
    {
        $id = $this->makeAnimal();
        $this->expectException(\HealthRuleViolation::class);
        healthRecordLabTest(self::$conn, $id, [
            'test_type' => 'serology', 'test_name' => 'Antibody titre',
            'sample_taken_on' => farmToday(),
            'result_status' => 'pending', 'result_code' => 'positive',
        ]);
    }

    // =======================================================================
    // L. CROSS-RESOURCE OWNERSHIP — a real id that belongs to the wrong animal
    // =======================================================================

    /**
     * The check tenantFind() alone does NOT make. A case id can name a real
     * case on the SAME farm but a DIFFERENT animal; attaching a medication to
     * it would corrupt both animals' records.
     */
    public function testACaseFromAnotherAnimalIsRefused(): void
    {
        $animalA = $this->makeAnimal();
        $animalB = $this->makeAnimal();
        $caseOnA = $this->makeCase($animalA);

        $this->expectException(\HealthRuleViolation::class);
        healthRecordObservation(self::$conn, $animalB, [
            'observation_type' => 'body_temperature',
            'value' => 38.5, 'input_unit' => 'c',
            'case_id' => $caseOnA,
        ]);
    }

    public function testATreatmentFromAnotherAnimalIsRefused(): void
    {
        $animalA = $this->makeAnimal();
        $animalB = $this->makeAnimal();
        $treatmentOnA = healthStartTreatment(self::$conn, $animalA, [
            'name' => 'Course', 'started_on' => farmToday(),
        ]);

        $this->expectException(\HealthRuleViolation::class);
        healthAdministerMedication(self::$conn, $animalB, [
            'medication_name' => 'Test', 'dose' => 1.0, 'dose_unit' => 'ml', 'route' => 'oral',
            'treatment_id' => $treatmentOnA,
        ]);
    }

    // =======================================================================
    // M. TENANT ISOLATION
    // =======================================================================

    /**
     * Farm 2's animal must be invisible from farm 1 — indistinguishable from
     * an animal that does not exist.
     */
    public function testAnotherFarmsAnimalCannotReceiveHealthRecords(): void
    {
        // Resolve a farm-2 animal directly, bypassing the tenant helpers.
        $stmt = self::$conn->query("SELECT cow_id FROM cows WHERE farm_id = 2 LIMIT 1");
        $foreignId = $stmt->fetchColumn();
        if ($foreignId === false) {
            self::fail('The test database must contain a farm-2 animal for the isolation tests.');
        }

        self::assertNull(animalFind(self::$conn, (int) $foreignId, true),
            'A farm-2 animal must be invisible from farm 1');
    }

    /**
     * Every case a search returns must belong to the ACTIVE farm. The {scope}
     * token is applied from the tenant context, never from anything a caller
     * supplies, so this is a property of the query rather than of the filters.
     */
    public function testHealthCaseSearchOnlyReturnsActiveFarmRows(): void
    {
        $id = $this->makeAnimal();
        $this->makeCase($id);

        $result = healthSearchCases(self::$conn, ['status' => 'active'], 1, 100);
        self::assertGreaterThan(0, $result['total']);

        // Asserted as ONE aggregate rather than one assertion per row. Looping
        // would make this test's assertion count grow with whatever data the
        // HTTP suite happens to have left in dfms_test, which turns the
        // project's headline assertion total into a moving number.
        $returnedIds = array_map(static function (array $case): int {
            return (int) $case['case_id'];
        }, $result['items']);
        $placeholders = implode(',', array_fill(0, count($returnedIds), '?'));
        $stmt = self::$conn->prepare(
            "SELECT COUNT(*) FROM health_cases WHERE case_id IN ($placeholders) AND farm_id <> 1"
        );
        $stmt->execute($returnedIds);
        self::assertSame(0, (int) $stmt->fetchColumn(),
            'Every returned case must belong to the active farm');
    }

    /**
     * A session that names a farm the user does NOT belong to must not grant
     * access to it. currentFarmId() re-validates against live membership on
     * every request, so a tampered session falls back to a farm the user really
     * is a member of rather than silently switching tenant.
     *
     * The seeded admin is a member of farm 1 only, which is what makes farm 2 a
     * valid probe here.
     */
    public function testATamperedSessionFarmIdIsNotHonoured(): void
    {
        $membership = self::$conn->query(
            'SELECT COUNT(*) FROM farm_users WHERE admin_id = 1 AND farm_id = 2'
        )->fetchColumn();
        self::assertSame(0, (int) $membership,
            'Fixture assumption: admin 1 must NOT be a member of farm 2');

        $_SESSION['farm_id'] = 2;
        self::assertSame(1, currentFarmId(),
            'A farm id the user has no membership for must be refused, not adopted');
        $_SESSION['farm_id'] = 1;
    }

    /**
     * Every Phase 4 table must be registered in DFMS_TENANT_TABLES. An
     * unregistered table is refused by tenantFind()/tenantInsert() by design,
     * so a missing entry is a bug that would surface as a runtime exception.
     */
    public function testEveryHealthTableIsRegisteredForTenantScoping(): void
    {
        $healthTables = [
            'health_cases', 'health_status_events', 'health_observations', 'health_symptoms',
            'health_diagnoses', 'veterinary_consultations', 'health_treatments',
            'medication_administrations', 'vaccination_records', 'health_quarantines',
            'withdrawal_periods', 'laboratory_tests',
        ];
        foreach ($healthTables as $table) {
            self::assertContains($table, DFMS_TENANT_TABLES, "$table must be tenant-registered");
        }
    }

    /** Every health table must actually carry the tenant key. */
    public function testEveryHealthTableCarriesFarmId(): void
    {
        $healthTables = [
            'health_cases', 'health_status_events', 'health_observations', 'health_symptoms',
            'health_diagnoses', 'veterinary_consultations', 'health_treatments',
            'medication_administrations', 'vaccination_records', 'health_quarantines',
            'withdrawal_periods', 'laboratory_tests',
        ];
        foreach ($healthTables as $table) {
            $stmt = self::$conn->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'farm_id'"
            );
            $stmt->execute([$table]);
            self::assertSame(1, (int) $stmt->fetchColumn(), "$table must carry farm_id");
        }
    }

    /** tenantInsert() ignores a caller-supplied farm_id — never honours it. */
    public function testASuppliedFarmIdIsIgnoredOnInsert(): void
    {
        $id = $this->makeAnimal();
        $caseId = tenantInsert(self::$conn, 'health_cases', [
            'cow_id'         => $id,
            'farm_id'        => 2,               // an attempt to write into farm 2
            'case_reference' => 'HC-TEST-' . bin2hex(random_bytes(3)),
            'title'          => 'Scope test',
            'case_type'      => 'illness',
            'severity'       => 'routine',
            'opened_on'      => farmToday(),
        ]);

        $row = self::$conn->query("SELECT farm_id FROM health_cases WHERE case_id = $caseId")->fetchColumn();
        self::assertSame(1, (int) $row, 'A caller-supplied farm_id must never be honoured');
    }

    // =======================================================================
    // N. INVENTORY-LINKED MEDICATION — transaction behaviour
    // =======================================================================

    public function testStockIsDeductedInsideTheSameTransaction(): void
    {
        $id = $this->makeAnimal();
        $item = tenantQueryOne(self::$conn,
            "SELECT i.item_id, l.quantity FROM inventory_items i
               JOIN inventory_levels l ON l.item_id = i.item_id AND l.farm_id = i.farm_id
              WHERE {scope} LIMIT 1", [], 'i');
        if ($item === null) {
            self::fail('The test database must contain an inventory item with a stock level.');
        }
        $before = (float) $item['quantity'];

        healthAdministerMedication(self::$conn, $id, [
            'item_id' => (int) $item['item_id'],
            'dose' => 10.0, 'dose_unit' => 'ml', 'route' => 'im',
            'deduct_stock' => true, 'quantity_used' => 2.0,
        ]);

        $after = (float) tenantScalar(self::$conn,
            "SELECT quantity FROM inventory_levels WHERE {scope} AND item_id = ?",
            [(int) $item['item_id']], 0);
        self::assertEqualsWithDelta($before - 2.0, $after, 0.001);

        // Restore, so the fixture is unchanged for the next test.
        tenantExec(self::$conn,
            "UPDATE inventory_levels SET quantity = ? WHERE item_id = ? AND {scope}",
            [$before, (int) $item['item_id']]);
    }

    /**
     * Stock must never go negative, and a refused administration must leave
     * NOTHING behind — no medication row, no withdrawal, no stock change.
     */
    public function testInsufficientStockRollsBackEverything(): void
    {
        $id = $this->makeAnimal();
        $item = tenantQueryOne(self::$conn,
            "SELECT i.item_id, l.quantity FROM inventory_items i
               JOIN inventory_levels l ON l.item_id = i.item_id AND l.farm_id = i.farm_id
              WHERE {scope} LIMIT 1", [], 'i');
        if ($item === null) {
            self::fail('The test database must contain an inventory item with a stock level.');
        }
        $before = (float) $item['quantity'];

        $threw = false;
        try {
            healthAdministerMedication(self::$conn, $id, [
                'item_id' => (int) $item['item_id'],
                'dose' => 10.0, 'dose_unit' => 'ml', 'route' => 'im',
                'deduct_stock' => true,
                'quantity_used' => $before + 1000,   // far more than exists
                'withdrawals' => ['milk' => 4],
            ]);
        } catch (\HealthRuleViolation $e) {
            $threw = true;
        }

        self::assertTrue($threw, 'Over-drawing stock must be refused');

        $after = (float) tenantScalar(self::$conn,
            "SELECT quantity FROM inventory_levels WHERE {scope} AND item_id = ?",
            [(int) $item['item_id']], 0);
        self::assertEqualsWithDelta($before, $after, 0.001, 'Stock must be unchanged after a refusal');
        self::assertSame([], healthMedicationHistory(self::$conn, $id),
            'No medication row may survive a rolled-back administration');
        self::assertSame([], healthWithdrawalHistory(self::$conn, $id),
            'No withdrawal may survive a rolled-back administration');
    }

    /**
     * The clinical name is captured at administration time so the record stays
     * readable after the inventory item is renamed or removed.
     */
    public function testMedicationNameIsCapturedNotLookedUp(): void
    {
        $id = $this->makeAnimal();
        $item = tenantQueryOne(self::$conn,
            "SELECT item_id, item_name FROM inventory_items i WHERE {scope} LIMIT 1", [], 'i');
        if ($item === null) {
            self::fail('The test database must contain an inventory item.');
        }

        healthAdministerMedication(self::$conn, $id, [
            'item_id' => (int) $item['item_id'],
            'dose' => 1.0, 'dose_unit' => 'ml', 'route' => 'oral',
        ]);

        $administration = healthMedicationHistory(self::$conn, $id)[0];
        self::assertSame($item['item_name'], $administration['medication_name']);
    }

    /**
     * A cross-tenant medication attack: a real inventory item id belonging to
     * ANOTHER farm, submitted as if it were ours.
     *
     * The farm-2 fixture is created here rather than depending on the seeder,
     * so the test genuinely runs instead of skipping when the seed data has no
     * farm-2 inventory. Fail-don't-skip is the project's rule.
     */
    public function testAnInventoryItemFromAnotherFarmIsRefused(): void
    {
        $id = $this->makeAnimal();

        // Inserted directly, bypassing the tenant helpers, because a farm-2
        // row is by definition unreachable through them.
        self::$conn->prepare(
            "INSERT INTO inventory_items (farm_id, item_name, item_type, unit, reorder_level)
             VALUES (2, 'Foreign antibiotic', 'Medicine', 'Bottle', 1)"
        )->execute();
        $foreignItemId = (int) self::$conn->lastInsertId();

        try {
            $threw = false;
            try {
                healthAdministerMedication(self::$conn, $id, [
                    'item_id' => $foreignItemId,
                    'dose' => 1.0, 'dose_unit' => 'ml', 'route' => 'oral',
                ]);
            } catch (\HealthRuleViolation $e) {
                $threw = true;
            }
            self::assertTrue($threw, 'A farm-2 inventory item must be unreachable from farm 1');
            self::assertSame([], healthMedicationHistory(self::$conn, $id),
                'No medication row may be written against a foreign product');
        } finally {
            self::$conn->prepare('DELETE FROM inventory_items WHERE item_id = ?')->execute([$foreignItemId]);
        }
    }

    public function testUnknownDoseUnitAndRouteAreRejected(): void
    {
        $id = $this->makeAnimal();
        $this->expectException(\HealthRuleViolation::class);
        healthAdministerMedication(self::$conn, $id, [
            'medication_name' => 'Test', 'dose' => 1.0,
            'dose_unit' => 'spoonful', 'route' => 'im',
        ]);
    }

    // =======================================================================
    // O. AUDIT LOGGING
    // =======================================================================

    public function testHealthMutationsAreAudited(): void
    {
        $id = $this->makeAnimal();

        $before = (int) self::$conn->query("SELECT COUNT(*) FROM audit_log WHERE farm_id = 1")->fetchColumn();

        $caseId = $this->makeCase($id, ['health_status' => 'sick']);
        healthAdministerMedication(self::$conn, $id, [
            'medication_name' => 'Audited product',
            'dose' => 5.0, 'dose_unit' => 'ml', 'route' => 'im',
            'case_id' => $caseId,
            'withdrawals' => ['milk' => 3],
        ]);

        $after = (int) self::$conn->query("SELECT COUNT(*) FROM audit_log WHERE farm_id = 1")->fetchColumn();
        self::assertGreaterThan($before, $after, 'Health mutations must be audited');

        $actions = self::$conn->query(
            "SELECT action FROM audit_log WHERE farm_id = 1 ORDER BY audit_id DESC LIMIT 10"
        )->fetchAll(PDO::FETCH_COLUMN);

        self::assertContains('health_case.create', $actions);
        self::assertContains('medication_administration.create', $actions);
        self::assertContains('withdrawal_period.create', $actions);
        self::assertContains('health.status_change', $actions);
    }

    /** The Phase 1 credential deny-list must still apply to health payloads. */
    public function testAuditPayloadsAreStillCredentialFiltered(): void
    {
        $sanitised = dfms_audit_sanitize([
            'medication_name' => 'Antibiotic',
            'password'        => 'hunter2',
            'nested'          => ['api_key' => 'sk-abcdefghijklmnopqrst'],
        ]);
        self::assertSame('Antibiotic', $sanitised['medication_name']);
        self::assertSame('[REDACTED]', $sanitised['password']);
        self::assertSame('[REDACTED]', $sanitised['nested']['api_key']);
    }

    // =======================================================================
    // P. ALERTS — deduplication and auto-resolution
    // =======================================================================

    /**
     * The failure mode the brief calls out by name: an alert generator that
     * runs on page load must not create a duplicate every time.
     */
    public function testAlertSyncIsIdempotent(): void
    {
        $id = $this->makeAnimal();
        // A follow-up that is already due raises exactly one alert.
        $this->makeCase($id, ['follow_up_on' => farmDateOffset(-1), 'severity' => 'moderate']);

        $first = healthSyncAlerts(self::$conn);
        self::assertGreaterThan(0, $first['created']);

        $second = healthSyncAlerts(self::$conn);
        self::assertSame(0, $second['created'], 'A second sync must create nothing');

        $third = healthSyncAlerts(self::$conn);
        self::assertSame(0, $third['created'], 'And a third must still create nothing');

        $count = (int) tenantScalar(self::$conn,
            "SELECT COUNT(*) FROM alerts a WHERE {scope} AND a.cow_id = ? AND a.alert_type = 'Treatment follow-up due'",
            [$id], 0, 'a');
        self::assertSame(1, $count, 'Three syncs must leave exactly one alert');
    }

    /**
     * An alert whose condition has passed is marked read by the system, not
     * deleted — the alert genuinely happened.
     */
    public function testAlertsAutoResolveWhenTheConditionPasses(): void
    {
        $id = $this->makeAnimal();
        $caseId = $this->makeCase($id, ['follow_up_on' => farmDateOffset(-1), 'severity' => 'moderate']);

        healthSyncAlerts(self::$conn);
        self::assertSame(1, (int) tenantScalar(self::$conn,
            "SELECT COUNT(*) FROM alerts a WHERE {scope} AND a.cow_id = ? AND a.is_read = 0", [$id], 0, 'a'));

        // Resolve the case: the follow-up no longer applies.
        healthUpdateCase(self::$conn, $caseId, ['status' => 'resolved', 'outcome' => 'recovered']);
        $result = healthSyncAlerts(self::$conn);

        self::assertGreaterThan(0, $result['resolved']);
        self::assertSame(0, (int) tenantScalar(self::$conn,
            "SELECT COUNT(*) FROM alerts a WHERE {scope} AND a.cow_id = ? AND a.is_read = 0", [$id], 0, 'a'),
            'The stale alert must be cleared from the queue');
        self::assertSame(1, (int) tenantScalar(self::$conn,
            "SELECT COUNT(*) FROM alerts a WHERE {scope} AND a.cow_id = ?", [$id], 0, 'a'),
            'But the row must survive — auto-resolve marks read, it does not delete');
    }

    // =======================================================================
    // Q. TIMELINE
    // =======================================================================

    public function testHealthEventsAppearInTheAnimalTimeline(): void
    {
        $id = $this->makeAnimal();
        $caseId = $this->makeCase($id, ['health_status' => 'sick']);
        healthRecordObservation(self::$conn, $id, [
            'observation_type' => 'body_temperature', 'value' => 40.1, 'input_unit' => 'c', 'case_id' => $caseId,
        ]);
        healthAdministerMedication(self::$conn, $id, [
            'medication_name' => 'Timeline product',
            'dose' => 5.0, 'dose_unit' => 'ml', 'route' => 'im',
            'case_id' => $caseId, 'withdrawals' => ['milk' => 3],
        ]);

        $timeline = animalTimeline(self::$conn, $id, 1, 100);
        $types = array_column($timeline['items'], 'type');

        foreach (['health_case', 'observation', 'medication', 'withdrawal', 'health_status'] as $expected) {
            self::assertContains($expected, $types, "The timeline must include $expected events");
        }
    }

    /**
     * A SCHEDULED vaccination is a future intention, not history. Putting it on
     * a timeline would misrepresent what happened.
     */
    public function testScheduledVaccinationsAreNotTimelineEvents(): void
    {
        $id = $this->makeAnimal();
        healthRecordVaccination(self::$conn, $id, [
            'status' => 'scheduled', 'vaccine_name' => 'Future dose',
            'scheduled_on' => farmDateOffset(30),
        ]);

        $events = healthTimelineEvents(self::$conn, $id);
        $types = array_column($events, 'type');
        self::assertNotContains('vaccination', $types,
            'A scheduled dose must not appear as a historical event');

        // And once it IS given, it does appear — so the assertion above is
        // proving a rule rather than an empty timeline.
        $vaccinationId = (int) healthVaccinations(self::$conn, $id)[0]['vaccination_id'];
        healthMarkVaccinationGiven(self::$conn, $vaccinationId, ['administered_on' => farmToday()]);
        self::assertContains('vaccination', array_column(healthTimelineEvents(self::$conn, $id), 'type'),
            'An administered dose must appear');
    }

    // =======================================================================
    // R. REGISTRY INTEGRITY
    // =======================================================================

    public function testEveryObservationTypeIsWellFormed(): void
    {
        foreach (DFMS_OBSERVATION_TYPES as $key => $meta) {
            self::assertArrayHasKey('label', $meta, "$key must declare a label");
            if (dfms_observation_is_numeric($key)) {
                self::assertArrayHasKey('min', $meta, "$key is numeric and must declare a min");
                self::assertArrayHasKey('max', $meta, "$key is numeric and must declare a max");
                self::assertLessThan($meta['max'], $meta['min'], "$key must have min < max");
                if (!empty($meta['kind'])) {
                    // A convertible type's declared unit must BE the canonical
                    // unit for its kind, or storage would silently drift.
                    self::assertSame(dfms_canonical_unit((string) $meta['kind']), $meta['unit'],
                        "$key must declare the canonical unit for its measurement kind");
                }
            } else {
                self::assertNotEmpty(dfms_observation_scale($key), "$key must declare a scale");
            }
        }
    }

    public function testConditionRegistryIsWellFormed(): void
    {
        foreach (DFMS_CONDITIONS as $key => $meta) {
            self::assertArrayHasKey('label', $meta, "$key must declare a label");
            self::assertArrayHasKey('group', $meta, "$key must declare a group");
            self::assertArrayHasKey('contagious', $meta, "$key must declare contagiousness");
            self::assertIsBool($meta['contagious']);
        }
        self::assertArrayHasKey('other', DFMS_CONDITIONS, 'An escape hatch must exist');
    }

    public function testHealthPermissionsExistAndAreCorrectlyScoped(): void
    {
        require_once __DIR__ . '/../../includes/permissions.php';

        foreach (['health.view', 'health.create', 'health.observe', 'health.medicate'] as $permission) {
            self::assertArrayHasKey($permission, DFMS_PERMISSIONS);
        }

        // observe is deliberately WIDER than create — routine stockmanship.
        self::assertContains('staff', DFMS_PERMISSIONS['health.observe']);
        self::assertNotContains('staff', DFMS_PERMISSIONS['health.create']);

        // medicate is the narrowest: it consumes stock and creates a
        // food-safety restriction.
        self::assertNotContains('staff', DFMS_PERMISSIONS['health.medicate']);
        self::assertNotContains('viewer', DFMS_PERMISSIONS['health.medicate']);
        self::assertNotContains('accountant', DFMS_PERMISSIONS['health.medicate']);
        self::assertContains('veterinarian', DFMS_PERMISSIONS['health.medicate']);
    }

    // =======================================================================
    // S. MIGRATION SAFETY
    // =======================================================================

    /** Migration 008 must not have invented medical history. */
    public function testMigrationInventedNoMedicalHistory(): void
    {
        // The seeded animals predate Phase 4 and must have no clinical records
        // beyond anything a test created and cleaned up.
        $preExisting = (int) self::$conn->query(
            "SELECT COUNT(*) FROM vaccination_records v
               JOIN cows c ON c.cow_id = v.cow_id
              WHERE c.unique_id NOT LIKE 'HTEST%' AND c.unique_id NOT LIKE 'TEST%'"
        )->fetchColumn();
        self::assertSame(0, $preExisting, 'No vaccination history may be fabricated for existing animals');
    }

    /** The legacy event log keeps working, with case_id NULL. */
    public function testLegacyHealthEventsAreUntouched(): void
    {
        $orphaned = (int) self::$conn->query(
            "SELECT COUNT(*) FROM health_events WHERE case_id IS NOT NULL AND farm_id = 1"
        )->fetchColumn();
        // Pre-existing rows must all still be unlinked; a test may have added
        // linked ones, so this asserts the pre-existing ones specifically.
        $preExisting = (int) self::$conn->query(
            "SELECT COUNT(*) FROM health_events h
               JOIN cows c ON c.cow_id = h.cow_id
              WHERE c.unique_id NOT LIKE 'HTEST%' AND h.case_id IS NOT NULL"
        )->fetchColumn();
        self::assertSame(0, $preExisting,
            'Historical health events must keep case_id NULL — no case existed when they were written');
        self::assertGreaterThanOrEqual(0, $orphaned);
    }
}
