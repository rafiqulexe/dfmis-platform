<?php
// ============================================================================
// QUARANTINE — start an isolation, or release one.
//
// The isolation location comes from the farm's EXISTING location list
// (migration 004, reused by Phase 3). There is no separate "quarantine pen"
// model: a second location architecture would immediately disagree with the
// first about where an animal actually is.
//
// Releasing does NOT guess the animal's new health status. Coming out of
// isolation can mean recovered, still under observation, or still sick but no
// longer contagious, and only the person releasing knows which.
// ============================================================================

define('DFMS_HEALTH_PAGE', true);
require_once __DIR__ . '/_shared.php';

requireAuth('health.create');

$conn = getDBConnection();
$error = '';
$animals = healthSelectableAnimals($conn);
$locations = farmLocations($conn);

$gv = new DfmsValidator($_GET);
$preCow = $gv->id('cow_id', ['nullable' => true]);
$preCase = $gv->id('case_id', ['nullable' => true]);
$releaseId = $gv->id('release', ['nullable' => true]);

$quarantine = null;
$quarantineAnimal = null;
if ($releaseId !== null) {
    $quarantine = tenantFindOr404($conn, 'health_quarantines', 'quarantine_id', $releaseId);
    if ($quarantine['status'] !== 'active') {
        redirectWith('modules/health/?tab=quarantine', 'error', 'That quarantine has already ended.');
    }
    $quarantineAnimal = animalFind($conn, (int) $quarantine['cow_id'], true);
}

$cases = $preCow !== null ? healthOpenCasesFor($conn, (int) $preCow) : [];
$submitted = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $submitted = $_POST;

    $v = new DfmsValidator($_POST);
    $mode = $v->enum('mode', ['start', 'release']);

    try {
        if ($v->fails()) {
            $error = $v->firstError();
        } elseif ($mode === 'release') {
            $quarantineId = $v->id('quarantine_id');
            $status = $v->enum('release_status', ['released', 'cancelled']);
            $endedAt = $v->datetime('ended_at');
            $healthStatus = $v->enum('health_status', array_keys(DFMS_HEALTH_STATUSES), ['nullable' => true]);
            $releaseNotes = $v->text('release_notes', 500, ['nullable' => true]);

            if ($v->fails()) {
                $error = $v->firstError();
            } else {
                healthEndQuarantine($conn, (int) $quarantineId, [
                    'status'        => $status,
                    'ended_at'      => $endedAt,
                    'health_status' => $healthStatus,
                    'release_notes' => $releaseNotes,
                ]);
                redirectWith('modules/health/?tab=quarantine', 'success', 'Quarantine ended.');
            }
        } else {
            $cowId = $v->id('cow_id');
            $type = $v->enum('quarantine_type', array_keys(DFMS_QUARANTINE_TYPES));
            $reason = $v->string('reason', 3, 200);
            $locationId = $v->id('location_id', ['nullable' => true]);
            $startedAt = $v->datetime('started_at');
            $expectedEnd = $v->date('expected_end_on', ['nullable' => true, 'allow_future' => true]);
            $caseId = $v->id('case_id', ['nullable' => true]);
            $notes = $v->text('notes', 5000, ['nullable' => true]);

            if ($v->fails()) {
                $error = $v->firstError();
            } else {
                healthStartQuarantine($conn, (int) $cowId, [
                    'quarantine_type' => $type,
                    'reason'          => $reason,
                    'location_id'     => $locationId,
                    'started_at'      => $startedAt,
                    'expected_end_on' => $expectedEnd,
                    'case_id'         => $caseId,
                    'notes'           => $notes,
                ]);
                redirectWith('modules/health/?tab=quarantine', 'success',
                    'Quarantine started. The animal is now recorded as quarantined.');
            }
        }
    } catch (HealthRuleViolation $e) {
        $error = $e->getMessage();
    } catch (PDOException $e) {
        $error = safeError($e, 'Could not save the quarantine');
    }
}

$page_title = $quarantine !== null ? 'Release from quarantine' : 'Start quarantine';
$page_breadcrumb = [
    ['label' => 'Health', 'url' => BASE_URL . 'modules/health/'],
    ['label' => 'Quarantine'],
];
require_once __DIR__ . '/../../includes/header.php';
?>
<?php healthScopedStyles(); ?>
<div class="dfms-health-scope">


<?php if ($quarantine !== null): ?>
    <?php dfms_page_header('Release from quarantine',
        'Ending an isolation. You choose the health status the animal returns to.', '', 'Veterinary'); ?>
<?php else: ?>
    <?php dfms_page_header('Start quarantine',
        'Isolating one animal. Its health status becomes "Quarantined" and, if you choose a location, it is moved there.',
        '', 'Veterinary'); ?>
<?php endif; ?>

<?php dfms_render_flashes(); ?>
<?php if ($error): ?>
    <?php dfms_alert($error, 'error'); ?>
<?php endif; ?>

<?php if ($quarantine !== null): ?>
<form method="POST" novalidate>
    <?php csrf_field(); ?>
    <input type="hidden" name="mode" value="release">
    <input type="hidden" name="quarantine_id" value="<?php echo (int) $quarantine['quarantine_id']; ?>">

    <section class="dfms-section">
        <div class="dfms-card">
            <div class="dfms-card-header">
                <h2 class="dfms-card-title"><?php echo e($quarantineAnimal !== null ? animalDisplayName($quarantineAnimal) : 'Animal'); ?></h2>
                <p class="dfms-card-subtitle">
                    <?php echo e($quarantine['reason']); ?>
                    · isolated since <?php echo e(fmt_instant($quarantine['started_at'])); ?>
                </p>
            </div>
            <div class="dfms-card-body">
                <div class="dfms-form-grid">
                    <?php
                    healthSelectField('release_status', 'How is this ending', [
                        'released'  => 'Released — the isolation ran its course',
                        'cancelled' => 'Cancelled — it should not have been started',
                    ], $submitted['release_status'] ?? 'released', ['required' => true]);

                    healthInputField('ended_at', 'Ended at', 'datetime-local',
                        $submitted['ended_at'] ?? gmdate('Y-m-d\TH:i'), ['required' => true]);

                    healthSelectField('health_status', 'Health status now',
                        array_map(static function (array $m): string { return $m['label']; }, DFMS_HEALTH_STATUSES),
                        $submitted['health_status'] ?? '', [
                            'placeholder' => 'Leave unchanged',
                            'hint'        => 'DFMIS does not guess this. Releasing an animal does not mean it has recovered.',
                        ]);
                    ?>
                </div>
                <?php healthTextareaField('release_notes', 'Release notes', $submitted['release_notes'] ?? '',
                    ['rows' => 3, 'maxlength' => 500]); ?>
            </div>
        </div>
    </section>

    <div class="dfms-form-actions">
        <a class="dfms-btn dfms-btn-secondary" href="<?php echo e(BASE_URL); ?>modules/health/?tab=quarantine">Cancel</a>
        <button type="submit" class="dfms-btn dfms-btn-primary">End quarantine</button>
    </div>
</form>

<?php elseif (empty($animals)): ?>
    <div class="dfms-card">
        <?php dfms_empty_state('No animals available',
            'Quarantine can only be recorded against an animal with an active lifecycle status.',
            dfms_button_html('Go to livestock', BASE_URL . 'modules/cows/', 'primary')); ?>
    </div>
<?php else: ?>
<form method="POST" novalidate>
    <?php csrf_field(); ?>
    <input type="hidden" name="mode" value="start">

    <section class="dfms-section">
        <div class="dfms-card">
            <div class="dfms-card-body">
                <div class="dfms-form-grid">
                    <?php
                    healthAnimalField($animals, $submitted['cow_id'] ?? $preCow);

                    healthSelectField('quarantine_type', 'Why', DFMS_QUARANTINE_TYPES,
                        $submitted['quarantine_type'] ?? 'precautionary', ['required' => true]);

                    healthInputField('reason', 'Reason', 'text', $submitted['reason'] ?? '', [
                        'required'  => true,
                        'maxlength' => 200,
                        'placeholder' => 'e.g. Suspected foot and mouth, pending vet visit…',
                    ]);

                    $locationOptions = [];
                    foreach ($locations as $location) {
                        $locationOptions[(int) $location['location_id']] = $location['name'];
                    }
                    healthSelectField('location_id', 'Isolation location', $locationOptions,
                        $submitted['location_id'] ?? '', [
                            'placeholder' => $locationOptions ? 'Leave where it is' : 'No locations defined on this farm',
                            'hint'        => 'From this farm\'s own location list. Choosing one also moves the animal and records the move.',
                        ]);

                    healthInputField('started_at', 'Started at', 'datetime-local',
                        $submitted['started_at'] ?? gmdate('Y-m-d\TH:i'), ['required' => true]);

                    healthInputField('expected_end_on', 'Expected to end', 'date',
                        $submitted['expected_end_on'] ?? '', [
                            'hint' => 'Optional. Raises a review reminder once the date passes.',
                        ]);

                    $caseOptions = [];
                    foreach ($cases as $case) {
                        $caseOptions[(int) $case['case_id']] = $case['case_reference'] . ' — ' . $case['title'];
                    }
                    healthSelectField('case_id', 'Related health case', $caseOptions,
                        $submitted['case_id'] ?? $preCase, [
                            'placeholder' => $caseOptions ? 'Not linked to a case' : 'No open cases for this animal',
                        ]);
                    ?>
                </div>
                <?php healthTextareaField('notes', 'Notes', $submitted['notes'] ?? '', ['rows' => 3, 'maxlength' => 5000]); ?>
            </div>
        </div>
    </section>

    <div class="dfms-form-actions">
        <a class="dfms-btn dfms-btn-secondary" href="<?php echo e(BASE_URL); ?>modules/health/?tab=quarantine">Cancel</a>
        <button type="submit" class="dfms-btn dfms-btn-primary">Start quarantine</button>
    </div>
</form>
<?php endif; ?>

</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
