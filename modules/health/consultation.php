<?php
// ============================================================================
// VETERINARY CONSULTATION.
//
// THE VETERINARIAN IS TEXT, NOT AN ACCOUNT.
//
// A farm calls whichever practice is available. Requiring every external vet to
// first exist as a DFMIS login would make this record unusable and would drag
// an entire external-identity system into a health phase. So the vet's name,
// practice, registration number and contact are plain fields, and nothing on
// this page touches authentication.
//
// The only link to an internal account is vet_user_id, and includes/health.php
// sets it ONLY after confirming the id is a member of this farm — it is never
// taken from the form on trust.
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

$cases = $preCow !== null ? healthOpenCasesFor($conn, (int) $preCow) : [];
$submitted = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $submitted = $_POST;

    $v = new DfmsValidator($_POST);
    $cowId = $v->id('cow_id');
    $vetName = $v->string('vet_name', 2, 150);
    $vetPractice = $v->string('vet_practice', 0, 150, ['nullable' => true]);
    $vetRegistration = $v->string('vet_registration', 0, 60, ['nullable' => true]);
    $vetContact = $v->string('vet_contact', 0, 120, ['nullable' => true]);
    $type = $v->enum('consultation_type', array_keys(DFMS_CONSULTATION_TYPES));
    $consultedAt = $v->datetime('consulted_at');
    $reason = $v->text('reason', 5000, ['nullable' => true]);
    $findings = $v->text('findings', 5000, ['nullable' => true]);
    $recommendations = $v->text('recommendations', 5000, ['nullable' => true]);
    $followUpOn = $v->date('follow_up_on', ['nullable' => true, 'allow_future' => true]);
    $cost = $v->decimal('cost_amount', ['nullable' => true, 'min' => 0, 'max' => 99999999]);
    $caseId = $v->id('case_id', ['nullable' => true]);
    $notes = $v->text('notes', 5000, ['nullable' => true]);

    if ($v->fails()) {
        $error = $v->firstError();
    } else {
        try {
            healthRecordConsultation($conn, (int) $cowId, [
                'vet_name'          => $vetName,
                'vet_practice'      => $vetPractice,
                'vet_registration'  => $vetRegistration,
                'vet_contact'       => $vetContact,
                'consultation_type' => $type,
                'consulted_at'      => $consultedAt,
                'reason'            => $reason,
                'findings'          => $findings,
                'recommendations'   => $recommendations,
                'follow_up_on'      => $followUpOn,
                'cost_amount'       => $cost,
                'case_id'           => $caseId,
                'notes'             => $notes,
            ]);
            $target = $caseId !== null
                ? 'modules/health/case.php?id=' . (int) $caseId
                : 'modules/cows/view.php?id=' . (int) $cowId . '&tab=health';
            redirectWith($target, 'success', 'Consultation recorded.');
        } catch (HealthRuleViolation $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = safeError($e, 'Could not record the consultation');
        }
    }
}

$page_title = 'Record a veterinary consultation';
$page_breadcrumb = [
    ['label' => 'Health', 'url' => BASE_URL . 'modules/health/'],
    ['label' => 'Consultation'],
];
require_once __DIR__ . '/../../includes/header.php';
?>
<?php healthScopedStyles(); ?>
<div class="dfms-health-scope">


<?php dfms_page_header('Record a veterinary consultation',
    'A visit, call or clinic appointment for one animal. The veterinarian does not need a DFMIS account.',
    '', 'Veterinary'); ?>

<?php dfms_render_flashes(); ?>
<?php if ($error): ?>
    <?php dfms_alert($error, 'error'); ?>
<?php endif; ?>

<?php if (empty($animals)): ?>
    <div class="dfms-card">
        <?php dfms_empty_state('No animals available',
            'A consultation can only be recorded against an animal with an active lifecycle status.',
            dfms_button_html('Go to livestock', BASE_URL . 'modules/cows/', 'primary')); ?>
    </div>
<?php else: ?>
<form method="POST" novalidate>
    <?php csrf_field(); ?>

    <section class="dfms-section">
        <div class="dfms-card">
            <div class="dfms-card-header">
                <h2 class="dfms-card-title">Who and when</h2>
            </div>
            <div class="dfms-card-body">
                <div class="dfms-form-grid">
                    <?php
                    healthAnimalField($animals, $submitted['cow_id'] ?? $preCow);

                    healthInputField('vet_name', 'Veterinarian', 'text', $submitted['vet_name'] ?? '', [
                        'required'     => true,
                        'maxlength'    => 150,
                        'autocomplete' => 'name',
                    ]);

                    healthInputField('vet_practice', 'Practice or clinic', 'text',
                        $submitted['vet_practice'] ?? '', ['maxlength' => 150, 'autocomplete' => 'organization']);

                    healthInputField('vet_registration', 'Registration number', 'text',
                        $submitted['vet_registration'] ?? '', [
                            'maxlength'    => 60,
                            'autocomplete' => 'off',
                            'spellcheck'   => 'false',
                            'hint'         => 'Practitioner licence or registration number, as supplied.',
                        ]);

                    healthInputField('vet_contact', 'Contact', 'text', $submitted['vet_contact'] ?? '', [
                        'maxlength'    => 120,
                        'autocomplete' => 'tel',
                        'hint'         => 'Phone or email, for follow-up.',
                    ]);

                    healthSelectField('consultation_type', 'Type', DFMS_CONSULTATION_TYPES,
                        $submitted['consultation_type'] ?? 'on_farm', ['required' => true]);

                    healthInputField('consulted_at', 'When', 'datetime-local',
                        $submitted['consulted_at'] ?? gmdate('Y-m-d\TH:i'), ['required' => true]);
                    ?>
                </div>
            </div>
        </div>
    </section>

    <section class="dfms-section">
        <div class="dfms-card">
            <div class="dfms-card-header">
                <h2 class="dfms-card-title">What was said</h2>
                <p class="dfms-card-subtitle">Recorded as given. DFMIS does not interpret, summarise or act on any of it.</p>
            </div>
            <div class="dfms-card-body">
                <?php
                healthTextareaField('reason', 'Reason for the consultation', $submitted['reason'] ?? '',
                    ['rows' => 3, 'maxlength' => 5000]);
                healthTextareaField('findings', 'Findings', $submitted['findings'] ?? '',
                    ['rows' => 4, 'maxlength' => 5000]);
                healthTextareaField('recommendations', 'Recommendations', $submitted['recommendations'] ?? '',
                    ['rows' => 4, 'maxlength' => 5000]);
                ?>
                <div class="dfms-form-grid">
                    <?php
                    healthInputField('follow_up_on', 'Follow-up date', 'date',
                        $submitted['follow_up_on'] ?? '', ['hint' => 'Optional.']);

                    healthInputField('cost_amount', 'Cost', 'number', $submitted['cost_amount'] ?? '', [
                        'min'       => '0',
                        'step'      => '0.01',
                        'inputmode' => 'decimal',
                        'hint'      => 'Optional, in ' . currentCurrency() . '. Recorded only — nothing aggregates it yet.',
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
                <?php healthTextareaField('notes', 'Notes', $submitted['notes'] ?? '', ['rows' => 2, 'maxlength' => 5000]); ?>
            </div>
        </div>
    </section>

    <div class="dfms-form-actions">
        <a class="dfms-btn dfms-btn-secondary" href="<?php echo e(BASE_URL); ?>modules/health/">Cancel</a>
        <button type="submit" class="dfms-btn dfms-btn-primary">Record consultation</button>
    </div>
</form>
<?php endif; ?>

</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
