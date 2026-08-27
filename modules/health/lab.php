<?php
// ============================================================================
// LABORATORY TESTS — record a sample, and attach the report.
//
// An INTEGRATION POINT, not a laboratory information system. It records what
// was sent, what came back and where the report lives. It does not model
// specimens, analysers, panels or accreditation.
//
// The report itself goes through the Phase 1 file system — same tenant scope,
// same access-controlled handler, same soft-delete. Nothing here writes a file
// path of its own, and a PDF is served as an attachment, never rendered.
// ============================================================================

define('DFMS_HEALTH_PAGE', true);
require_once __DIR__ . '/../../includes/storage.php';
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
    $testType = $v->enum('test_type', array_keys(DFMS_LAB_TEST_TYPES));
    $testName = $v->string('test_name', 2, 150);
    $laboratory = $v->string('laboratory_name', 0, 150, ['nullable' => true]);
    $sampleType = $v->string('sample_type', 0, 60, ['nullable' => true]);
    $sampleTakenOn = $v->date('sample_taken_on', ['max' => farmToday()]);
    $resultReceivedOn = $v->date('result_received_on', ['nullable' => true, 'max' => farmToday()]);
    $resultStatus = $v->enum('result_status', ['pending', 'received', 'inconclusive', 'cancelled'],
        ['default' => 'pending']);
    $resultNumeric = $v->decimal('result_numeric', ['nullable' => true, 'min' => -999999, 'max' => 99999999]);
    $resultUnit = $v->string('result_unit', 0, 24, ['nullable' => true]);
    $resultCode = $v->enum('result_code', array_keys(DFMS_LAB_RESULT_CODES), ['nullable' => true]);
    $referenceRange = $v->string('reference_range', 0, 80, ['nullable' => true]);
    $interpretation = $v->text('interpretation', 5000, ['nullable' => true]);
    $caseId = $v->id('case_id', ['nullable' => true]);
    $notes = $v->text('notes', 5000, ['nullable' => true]);

    if ($v->fails()) {
        $error = $v->firstError();
    } else {
        try {
            $labTestId = healthRecordLabTest($conn, (int) $cowId, [
                'test_type'          => $testType,
                'test_name'          => $testName,
                'laboratory_name'    => $laboratory,
                'sample_type'        => $sampleType,
                'sample_taken_on'    => $sampleTakenOn,
                'result_received_on' => $resultReceivedOn,
                'result_status'      => $resultStatus,
                'result_numeric'     => $resultNumeric,
                'result_unit'        => $resultUnit,
                'result_code'        => $resultCode,
                'reference_range'    => $referenceRange,
                'interpretation'     => $interpretation,
                'case_id'            => $caseId,
                'notes'              => $notes,
            ]);

            // The report is optional and its failure must not lose the test
            // record — the clinical fact is the sample, not the PDF.
            $uploadWarning = '';
            if (!empty($_FILES['report']['name'])) {
                if (!hasPermission('files.upload')) {
                    $uploadWarning = ' The report was not attached: you do not have permission to upload files.';
                } else {
                    try {
                        storeUploadedDocument($_FILES['report'], 'documents', [
                            'entity_type'   => 'lab_test',
                            'entity_id'     => $labTestId,
                            'document_type' => 'lab_report',
                            'caption'       => $testName,
                        ]);
                    } catch (Throwable $uploadError) {
                        $uploadWarning = ' The test was saved, but the report could not be attached: '
                            . $uploadError->getMessage();
                    }
                }
            }

            $target = $caseId !== null
                ? 'modules/health/case.php?id=' . (int) $caseId
                : 'modules/cows/view.php?id=' . (int) $cowId . '&tab=health';
            redirectWith($target, $uploadWarning === '' ? 'success' : 'error',
                'Laboratory test recorded.' . $uploadWarning);
        } catch (HealthRuleViolation $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = safeError($e, 'Could not record the laboratory test');
        }
    }
}

$page_title = 'Record a laboratory test';
$page_breadcrumb = [
    ['label' => 'Health', 'url' => BASE_URL . 'modules/health/'],
    ['label' => 'Laboratory test'],
];
require_once __DIR__ . '/../../includes/header.php';
?>
<?php healthScopedStyles(); ?>
<div class="dfms-health-scope">


<?php dfms_page_header('Record a laboratory test',
    'What was sampled, where it went and what came back. Attach the report as a PDF.', '', 'Veterinary'); ?>

<?php dfms_render_flashes(); ?>
<?php if ($error): ?>
    <?php dfms_alert($error, 'error'); ?>
<?php endif; ?>

<?php if (empty($animals)): ?>
    <div class="dfms-card">
        <?php dfms_empty_state('No animals available',
            'A laboratory test can only be recorded against an animal with an active lifecycle status.',
            dfms_button_html('Go to livestock', BASE_URL . 'modules/cows/', 'primary')); ?>
    </div>
<?php else: ?>
<form method="POST" enctype="multipart/form-data" novalidate>
    <?php csrf_field(); ?>

    <section class="dfms-section">
        <div class="dfms-card">
            <div class="dfms-card-header">
                <h2 class="dfms-card-title">The sample</h2>
            </div>
            <div class="dfms-card-body">
                <div class="dfms-form-grid">
                    <?php
                    healthAnimalField($animals, $submitted['cow_id'] ?? $preCow);

                    healthSelectField('test_type', 'Test type', DFMS_LAB_TEST_TYPES,
                        $submitted['test_type'] ?? '', ['required' => true, 'placeholder' => 'Select a type']);

                    healthInputField('test_name', 'Test name', 'text', $submitted['test_name'] ?? '', [
                        'required'    => true,
                        'maxlength'   => 150,
                        'placeholder' => 'e.g. Milk culture and sensitivity…',
                    ]);

                    healthInputField('laboratory_name', 'Laboratory', 'text',
                        $submitted['laboratory_name'] ?? '', ['maxlength' => 150, 'autocomplete' => 'organization']);

                    healthInputField('sample_type', 'Sample', 'text', $submitted['sample_type'] ?? '', [
                        'maxlength'   => 60,
                        'placeholder' => 'e.g. Milk, blood, faeces, swab…',
                    ]);

                    healthInputField('sample_taken_on', 'Sample taken on', 'date',
                        $submitted['sample_taken_on'] ?? farmToday(), ['required' => true, 'max' => farmToday()]);

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
            </div>
        </div>
    </section>

    <section class="dfms-section">
        <div class="dfms-card">
            <div class="dfms-card-header">
                <h2 class="dfms-card-title">The result</h2>
                <p class="dfms-card-subtitle">Leave this section as "Pending" until the laboratory reports back.</p>
            </div>
            <div class="dfms-card-body">
                <div class="dfms-form-grid">
                    <?php
                    healthSelectField('result_status', 'Result status', [
                        'pending'      => 'Pending — not back yet',
                        'received'     => 'Received',
                        'inconclusive' => 'Inconclusive',
                        'cancelled'    => 'Cancelled',
                    ], $submitted['result_status'] ?? 'pending', ['required' => true]);

                    healthInputField('result_received_on', 'Result received on', 'date',
                        $submitted['result_received_on'] ?? '', ['max' => farmToday()]);

                    healthSelectField('result_code', 'Result', DFMS_LAB_RESULT_CODES,
                        $submitted['result_code'] ?? '', [
                            'placeholder' => 'Not applicable',
                            'hint'        => 'For qualitative tests. Leave blank for a purely numeric result.',
                        ]);

                    healthInputField('result_numeric', 'Numeric result', 'number',
                        $submitted['result_numeric'] ?? '', ['step' => '0.0001', 'inputmode' => 'decimal']);

                    healthInputField('result_unit', 'Result unit', 'text', $submitted['result_unit'] ?? '', [
                        'maxlength' => 24,
                        'hint'      => 'As printed by the laboratory. DFMIS does not convert laboratory units.',
                    ]);

                    healthInputField('reference_range', 'Reference range', 'text',
                        $submitted['reference_range'] ?? '', ['maxlength' => 80, 'placeholder' => 'e.g. 2.5–7.5…', 'spellcheck' => 'false']);
                    ?>
                </div>
                <?php healthTextareaField('interpretation', 'Interpretation', $submitted['interpretation'] ?? '', [
                    'rows'      => 3,
                    'maxlength' => 5000,
                    'hint'      => 'The laboratory\'s or your veterinarian\'s interpretation, recorded as given.',
                ]); ?>
            </div>
        </div>
    </section>

    <?php if (hasPermission('files.upload')): ?>
    <section class="dfms-section">
        <div class="dfms-card">
            <div class="dfms-card-header">
                <h2 class="dfms-card-title">Report</h2>
            </div>
            <div class="dfms-card-body">
                <div class="dfms-field">
                    <label class="dfms-label" for="report">Laboratory report (PDF)</label>
                    <input class="dfms-input" type="file" id="report" name="report"
                           accept="application/pdf" aria-describedby="report-hint">
                    <p class="dfms-hint" id="report-hint">
                        Optional, PDF only, up to 8&nbsp;MB. Stored outside the web root and downloadable only through
                        the access-controlled file handler. If the upload fails, the test is still saved.
                    </p>
                </div>
                <?php healthTextareaField('notes', 'Notes', $submitted['notes'] ?? '', ['rows' => 2, 'maxlength' => 5000]); ?>
            </div>
        </div>
    </section>
    <?php else: ?>
        <?php healthTextareaField('notes', 'Notes', $submitted['notes'] ?? '', ['rows' => 2, 'maxlength' => 5000]); ?>
    <?php endif; ?>

    <div class="dfms-form-actions">
        <a class="dfms-btn dfms-btn-secondary" href="<?php echo e(BASE_URL); ?>modules/health/">Cancel</a>
        <button type="submit" class="dfms-btn dfms-btn-primary">Record test</button>
    </div>
</form>
<?php endif; ?>

</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
