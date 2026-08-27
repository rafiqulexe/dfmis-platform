<?php
// ============================================================================
// TREATMENTS — start a course, or close one out.
//
// A treatment is a PLAN with a lifecycle; the individual doses are medication
// administrations recorded separately. That separation is what lets "three
// injections over five days" be one treatment with three dose records rather
// than three treatments or one treatment with no dose history.
//
// HISTORY IS NEVER REWRITTEN. Completing or cancelling changes only the status,
// outcome, end date and notes. What was prescribed, when it started and who
// prescribed it are fixed at the moment of recording.
// ============================================================================

define('DFMS_HEALTH_PAGE', true);
require_once __DIR__ . '/_shared.php';

requireAuth('health.create');

$conn = getDBConnection();
$error = '';
$animals = healthSelectableAnimals($conn);

$gv = new DfmsValidator($_GET);
$preCow = $gv->id('cow_id', ['nullable' => true]);
$preCase = $gv->id('case_id', ['nullable' => true]);
$closeId = $gv->id('close', ['nullable' => true]);

$treatment = null;
$treatmentAnimal = null;
if ($closeId !== null) {
    $treatment = tenantFindOr404($conn, 'health_treatments', 'treatment_id', $closeId);
    $treatmentAnimal = animalFind($conn, (int) $treatment['cow_id'], true);
    if (in_array($treatment['status'], ['completed', 'cancelled'], true)) {
        redirectWith('modules/health/?tab=treatments', 'error',
            'That treatment is already ' . $treatment['status'] . ' and its history cannot be rewritten.');
    }
}

$cases = $preCow !== null ? healthOpenCasesFor($conn, (int) $preCow) : [];
$submitted = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $submitted = $_POST;

    $v = new DfmsValidator($_POST);
    $mode = $v->enum('mode', ['start', 'close']);

    try {
        if ($v->fails()) {
            $error = $v->firstError();
        } elseif ($mode === 'close') {
            $treatmentId = $v->id('treatment_id');
            $status = $v->enum('close_status', ['completed', 'cancelled']);
            $outcome = $v->enum('outcome', array_keys(DFMS_TREATMENT_OUTCOMES), ['nullable' => true]);
            $endedOn = $v->date('ended_on', ['max' => farmToday()]);
            $notes = $v->text('notes', 5000, ['nullable' => true]);

            if ($v->fails()) {
                $error = $v->firstError();
            } else {
                healthCompleteTreatment($conn, (int) $treatmentId, (string) $status, [
                    'outcome'   => $outcome,
                    'ended_on'  => $endedOn,
                    'notes'     => $notes,
                ]);
                redirectWith('modules/health/?tab=treatments', 'success', 'Treatment closed out.');
            }
        } else {
            $cowId = $v->id('cow_id');
            $type = $v->enum('treatment_type', array_keys(DFMS_TREATMENT_TYPES));
            $name = $v->string('name', 2, 150);
            $protocol = $v->text('protocol', 5000, ['nullable' => true]);
            $status = $v->enum('status', ['planned', 'active'], ['default' => 'active']);
            $startedOn = $v->date('started_on', ['allow_future' => true]);
            $caseId = $v->id('case_id', ['nullable' => true]);
            $responsible = $v->string('responsible_name', 0, 150, ['nullable' => true]);
            $notes = $v->text('notes', 5000, ['nullable' => true]);

            if ($v->fails()) {
                $error = $v->firstError();
            } else {
                $treatmentId = healthStartTreatment($conn, (int) $cowId, [
                    'treatment_type'   => $type,
                    'name'             => $name,
                    'protocol'         => $protocol,
                    'status'           => $status,
                    'started_on'       => $startedOn,
                    'case_id'          => $caseId,
                    'responsible_name' => $responsible,
                    'notes'            => $notes,
                ]);
                $target = $caseId !== null
                    ? 'modules/health/case.php?id=' . (int) $caseId
                    : 'modules/health/?tab=treatments';
                redirectWith($target, 'success', 'Treatment started. Record each dose as a medication administration.');
            }
        }
    } catch (HealthRuleViolation $e) {
        $error = $e->getMessage();
    } catch (PDOException $e) {
        $error = safeError($e, 'Could not save the treatment');
    }
}

$page_title = $treatment !== null ? 'Close out treatment' : 'Start a treatment';
$page_breadcrumb = [
    ['label' => 'Health', 'url' => BASE_URL . 'modules/health/'],
    ['label' => 'Treatment'],
];
require_once __DIR__ . '/../../includes/header.php';
?>
<?php healthScopedStyles(); ?>
<div class="dfms-health-scope">


<?php if ($treatment !== null): ?>
    <?php dfms_page_header('Close out treatment',
        'Recording how a course ended. The treatment itself stays on the record either way.', '', 'Veterinary'); ?>
<?php else: ?>
    <?php dfms_page_header('Start a treatment',
        'A course of care for one animal. Individual doses are recorded separately as they are given.',
        '', 'Veterinary'); ?>
<?php endif; ?>

<?php dfms_render_flashes(); ?>
<?php if ($error): ?>
    <?php dfms_alert($error, 'error'); ?>
<?php endif; ?>

<?php if ($treatment !== null): ?>
<form method="POST" novalidate>
    <?php csrf_field(); ?>
    <input type="hidden" name="mode" value="close">
    <input type="hidden" name="treatment_id" value="<?php echo (int) $treatment['treatment_id']; ?>">

    <section class="dfms-section">
        <div class="dfms-card">
            <div class="dfms-card-header">
                <h2 class="dfms-card-title"><?php echo e($treatment['name']); ?></h2>
                <p class="dfms-card-subtitle">
                    <?php echo e($treatmentAnimal !== null ? animalDisplayName($treatmentAnimal) : 'Animal'); ?>
                    · started <?php echo e(fmt_date($treatment['started_on'])); ?>
                </p>
            </div>
            <div class="dfms-card-body">
                <div class="dfms-form-grid">
                    <?php
                    healthSelectField('close_status', 'How did it end', [
                        'completed' => 'Completed — the course was finished',
                        'cancelled' => 'Cancelled — the course was stopped',
                    ], $submitted['close_status'] ?? 'completed', [
                        'required' => true,
                        'hint'     => 'A cancelled treatment stays visible. "We started this and stopped" is different from "this never happened".',
                    ]);

                    healthSelectField('outcome', 'Outcome', DFMS_TREATMENT_OUTCOMES,
                        $submitted['outcome'] ?? '', [
                            'placeholder' => 'Select an outcome',
                            'hint'        => 'Required when completing. "Unknown" is a valid, honest answer.',
                        ]);

                    healthInputField('ended_on', 'Ended on', 'date',
                        $submitted['ended_on'] ?? farmToday(), ['required' => true, 'max' => farmToday()]);
                    ?>
                </div>
                <?php healthTextareaField('notes', 'Notes', $treatment['notes'], ['rows' => 3, 'maxlength' => 5000]); ?>
            </div>
        </div>
    </section>

    <div class="dfms-form-actions">
        <a class="dfms-btn dfms-btn-secondary" href="<?php echo e(BASE_URL); ?>modules/health/?tab=treatments">Cancel</a>
        <button type="submit" class="dfms-btn dfms-btn-primary">Close out treatment</button>
    </div>
</form>

<?php elseif (empty($animals)): ?>
    <div class="dfms-card">
        <?php dfms_empty_state('No animals available',
            'A treatment can only be recorded against an animal with an active lifecycle status.',
            dfms_button_html('Go to livestock', BASE_URL . 'modules/cows/', 'primary')); ?>
    </div>
<?php else: ?>
<form method="POST" novalidate>
    <?php csrf_field(); ?>
    <input type="hidden" name="mode" value="start">

    <section class="dfms-section">
        <div class="dfms-card">
            <div class="dfms-card-body">
                <?php healthClinicalNotice(); ?>
                <div class="dfms-form-grid">
                    <?php
                    healthAnimalField($animals, $submitted['cow_id'] ?? $preCow);

                    healthInputField('name', 'Treatment', 'text', $submitted['name'] ?? '', [
                        'required'    => true,
                        'maxlength'   => 150,
                        'placeholder' => 'e.g. Intramammary antibiotic course…',
                    ]);

                    healthSelectField('treatment_type', 'Type', DFMS_TREATMENT_TYPES,
                        $submitted['treatment_type'] ?? 'medication', ['required' => true]);

                    healthSelectField('status', 'Status', [
                        'active'  => 'Active — starting now',
                        'planned' => 'Planned — not started yet',
                    ], $submitted['status'] ?? 'active', ['required' => true]);

                    healthInputField('started_on', 'Start date', 'date',
                        $submitted['started_on'] ?? farmToday(), ['required' => true]);

                    healthInputField('responsible_name', 'Prescribed or overseen by', 'text',
                        $submitted['responsible_name'] ?? '', [
                            'maxlength'    => 150,
                            'autocomplete' => 'off',
                            'hint'         => 'Free text — an external veterinarian needs no DFMIS account.',
                        ]);

                    $caseOptions = [];
                    foreach ($cases as $case) {
                        $caseOptions[(int) $case['case_id']] = $case['case_reference'] . ' — ' . $case['title'];
                    }
                    healthSelectField('case_id', 'Part of health case', $caseOptions,
                        $submitted['case_id'] ?? $preCase, [
                            'placeholder' => $caseOptions ? 'Not linked to a case' : 'No open cases for this animal',
                        ]);
                    ?>
                </div>
                <?php
                healthTextareaField('protocol', 'Protocol as prescribed', $submitted['protocol'] ?? '', [
                    'rows'      => 4,
                    'maxlength' => 5000,
                    'hint'      => 'The plan in the prescriber\'s own words. Each dose actually given is recorded separately.',
                ]);
                healthTextareaField('notes', 'Notes', $submitted['notes'] ?? '', ['rows' => 2, 'maxlength' => 5000]);
                ?>
            </div>
        </div>
    </section>

    <div class="dfms-form-actions">
        <a class="dfms-btn dfms-btn-secondary" href="<?php echo e(BASE_URL); ?>modules/health/?tab=treatments">Cancel</a>
        <button type="submit" class="dfms-btn dfms-btn-primary">Start treatment</button>
    </div>
</form>
<?php endif; ?>

</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
