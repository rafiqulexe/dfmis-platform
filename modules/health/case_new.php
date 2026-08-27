<?php
// ============================================================================
// OPEN A HEALTH CASE.
//
// Deliberately SHORT. A case is the container an episode is built in, so this
// form asks only what is needed to open one — what animal, what is wrong, how
// serious, when. Symptoms, diagnoses, treatments and medications are added on
// the case page afterwards, each in its own focused step.
//
// The alternative — one screen with every clinical field — is exactly what the
// brief warns against, and in a veterinary context it is also how fields get
// filled in with guesses just to satisfy the form.
// ============================================================================

define('DFMS_HEALTH_PAGE', true);
require_once __DIR__ . '/_shared.php';

requireAuth('health.create');

$conn = getDBConnection();
$error = '';
$animals = healthSelectableAnimals($conn);

// Pre-select an animal when arriving from that animal's profile.
$preselect = null;
$pv = new DfmsValidator($_GET);
$preselect = $pv->id('cow_id', ['nullable' => true]);

$submitted = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $v = new DfmsValidator($_POST);
    $cowId = $v->id('cow_id');
    $title = $v->string('title', 3, 150);
    $caseType = $v->enum('case_type', array_keys(DFMS_CASE_TYPES));
    $severity = $v->enum('severity', DFMS_CASE_SEVERITIES, ['default' => 'routine']);
    $openedOn = $v->date('opened_on', ['max' => farmToday()]);
    // A follow-up is by nature in the future, so this date must be allowed to be.
    $followUpOn = $v->date('follow_up_on', ['nullable' => true, 'allow_future' => true]);
    $healthStatus = $v->enum('health_status', array_keys(DFMS_HEALTH_STATUSES), ['nullable' => true]);
    $notes = $v->text('notes', 5000, ['nullable' => true]);
    $isContagious = !empty($_POST['is_contagious']);

    $submitted = $_POST;

    if ($v->fails()) {
        $error = $v->firstError();
    } elseif ($followUpOn !== null && $followUpOn < $openedOn) {
        $error = 'A follow-up date cannot be before the case was opened.';
    } else {
        try {
            $caseId = healthOpenCase($conn, (int) $cowId, [
                'title'         => $title,
                'case_type'     => $caseType,
                'severity'      => $severity,
                'opened_on'     => $openedOn,
                'follow_up_on'  => $followUpOn,
                'is_contagious' => $isContagious,
                'health_status' => $healthStatus,
                'notes'         => $notes,
            ]);
            redirectWith('modules/health/case.php?id=' . $caseId, 'success',
                'Case opened. Add symptoms, a diagnosis and treatments below.');
        } catch (HealthRuleViolation $e) {
            // A domain rule the user broke — safe to show verbatim.
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = safeError($e, 'Could not open the case');
        }
    }
}

$page_title = 'Open a health case';
$page_breadcrumb = [
    ['label' => 'Health', 'url' => BASE_URL . 'modules/health/'],
    ['label' => 'Open a case'],
];
require_once __DIR__ . '/../../includes/header.php';
?>
<?php healthScopedStyles(); ?>
<div class="dfms-health-scope">


<?php dfms_page_header('Open a health case',
    'A case groups everything about one episode of illness or injury for one animal.', '', 'Veterinary'); ?>

<?php dfms_render_flashes(); ?>
<?php if ($error): ?>
    <?php dfms_alert($error, 'error'); ?>
<?php endif; ?>

<?php if (empty($animals)): ?>
    <div class="dfms-card">
        <?php dfms_empty_state('No animals available',
            'A health case needs an animal with an active lifecycle status. Register one first, or return an archived animal to active.',
            dfms_button_html('Go to livestock', BASE_URL . 'modules/cows/', 'primary')); ?>
    </div>
<?php else: ?>
<form method="POST" novalidate>
    <?php csrf_field(); ?>

    <section class="dfms-section">
        <div class="dfms-card">
            <div class="dfms-card-header">
                <h2 class="dfms-card-title">What happened</h2>
                <p class="dfms-card-subtitle">Fields marked <span class="dfms-required" aria-hidden="true">*</span> are required.</p>
            </div>
            <div class="dfms-card-body">
                <div class="dfms-form-grid">
                    <?php
                    healthAnimalField($animals, $submitted['cow_id'] ?? $preselect);

                    healthInputField('title', 'Short description', 'text',
                        $submitted['title'] ?? '', [
                            'required'  => true,
                            'maxlength' => 150,
                            'placeholder' => 'e.g. Swollen left hind quarter…',
                            'hint'      => 'What you would say to the vet on the phone.',
                        ]);

                    healthSelectField('case_type', 'Type', DFMS_CASE_TYPES,
                        $submitted['case_type'] ?? 'illness', ['required' => true]);

                    healthSelectField('severity', 'Severity', [
                        'routine'  => 'Routine — no urgency',
                        'moderate' => 'Moderate — needs attention soon',
                        'urgent'   => 'Urgent — needs attention now',
                    ], $submitted['severity'] ?? 'routine', ['required' => true]);

                    healthInputField('opened_on', 'Date noticed', 'date',
                        $submitted['opened_on'] ?? farmToday(), [
                            'required' => true,
                            'max'      => farmToday(),
                            'hint'     => 'When the problem was first seen, which may be before today.',
                        ]);

                    healthInputField('follow_up_on', 'Follow-up date', 'date',
                        $submitted['follow_up_on'] ?? '', [
                            'hint' => 'Optional. Raises a reminder when the date arrives.',
                        ]);
                    ?>
                </div>
            </div>
        </div>
    </section>

    <section class="dfms-section">
        <div class="dfms-card">
            <div class="dfms-card-header">
                <h2 class="dfms-card-title">Herd impact</h2>
                <p class="dfms-card-subtitle">Optional, and both can be changed later.</p>
            </div>
            <div class="dfms-card-body">
                <div class="dfms-field">
                    <label class="dfms-label" for="is_contagious" style="display:flex;align-items:flex-start;gap:.6rem;cursor:pointer;">
                        <input type="checkbox" id="is_contagious" name="is_contagious" value="1"
                               style="width:1.15rem;height:1.15rem;margin-top:.15rem;flex:0 0 auto;"
                               aria-describedby="is_contagious-hint"
                               <?php echo !empty($submitted['is_contagious']) ? 'checked' : ''; ?>>
                        <span>Treat as potentially contagious</span>
                    </label>
                    <p class="dfms-hint" id="is_contagious-hint">
                        Your judgement, recorded as yours. DFMIS does not decide whether a condition is transmissible
                        and does not isolate anything on its own.
                    </p>
                </div>

                <?php
                // Only statuses that are a real change from the animal's current
                // one are offered — but the animal is chosen above, so the full
                // list is shown and the domain layer refuses a no-op with a clear
                // message rather than the form silently dropping it.
                healthSelectField('health_status', 'Set the animal\'s health status to',
                    array_map(static function (array $meta): string {
                        return $meta['label'];
                    }, DFMS_HEALTH_STATUSES),
                    $submitted['health_status'] ?? '', [
                        'placeholder' => 'Leave unchanged',
                        'hint'        => 'Opening a case does not change an animal\'s status unless you ask it to.',
                    ]);

                healthTextareaField('notes', 'Notes', $submitted['notes'] ?? '', [
                    'rows'      => 4,
                    'maxlength' => 5000,
                    'hint'      => 'Anything else worth recording. Symptoms and diagnoses are added on the next screen.',
                ]);
                ?>
            </div>
        </div>
    </section>

    <div class="dfms-form-actions">
        <a class="dfms-btn dfms-btn-secondary" href="<?php echo e(BASE_URL); ?>modules/health/">Cancel</a>
        <button type="submit" class="dfms-btn dfms-btn-primary">Open case</button>
    </div>
</form>
<?php endif; ?>

</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
