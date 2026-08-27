<?php
// ============================================================================
// RECORD A CLINICAL OBSERVATION.
//
// Open to STAFF (health.observe), deliberately wider than the rest of the
// module. Taking a temperature is routine stockmanship, and locking it behind
// a veterinarian's permission means it stops being recorded at all.
//
// UNITS: a numeric observation is entered in the FARM'S measurement system and
// stored canonically. The unit is shown next to the field and submitted with
// the value, so an imperial farm types 103 °F and the record holds 39.4 °C.
// An unsupported unit is refused by includes/health.php rather than converted
// on an assumption.
// ============================================================================

define('DFMS_HEALTH_PAGE', true);
require_once __DIR__ . '/_shared.php';

requireAuth('health.observe');

$conn = getDBConnection();
$error = '';
$animals = healthSelectableAnimals($conn);

$gv = new DfmsValidator($_GET);
$preCow = $gv->id('cow_id', ['nullable' => true]);
$preCase = $gv->id('case_id', ['nullable' => true]);
$preType = $gv->string('type', 0, 32, ['nullable' => true]);
if (!dfms_is_valid_observation_type($preType)) {
    $preType = null;
}

$cases = $preCow !== null ? healthOpenCasesFor($conn, (int) $preCow) : [];
$system = currentMeasurementSystem();
$submitted = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $submitted = $_POST;

    $v = new DfmsValidator($_POST);
    $cowId = $v->id('cow_id');
    $type = $v->enum('observation_type', array_keys(DFMS_OBSERVATION_TYPES));
    $observedAt = $v->datetime('observed_at');
    $caseId = $v->id('case_id', ['nullable' => true]);
    $notes = $v->text('notes', 500, ['nullable' => true]);

    if ($v->fails()) {
        $error = $v->firstError();
    } else {
        // The value field depends on the chosen type: numeric types read
        // value_<type>, coded types read code_<type>. Namespacing them keeps
        // every type's input on the page at once without one overwriting
        // another when the browser submits them all.
        $isNumeric = dfms_observation_is_numeric((string) $type);
        $payload = [
            'observation_type' => $type,
            'observed_at'      => $observedAt,
            'case_id'          => $caseId,
            'notes'            => $notes,
        ];
        if ($isNumeric) {
            $payload['value'] = $_POST['value_' . $type] ?? '';
            $payload['input_unit'] = $_POST['unit_' . $type] ?? null;
        } else {
            $payload['value_code'] = $_POST['code_' . $type] ?? '';
        }

        try {
            healthRecordObservation($conn, (int) $cowId, $payload);
            $target = $caseId !== null
                ? 'modules/health/case.php?id=' . (int) $caseId
                : 'modules/cows/view.php?id=' . (int) $cowId . '&tab=health';
            redirectWith($target, 'success', 'Observation recorded.');
        } catch (HealthRuleViolation $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = safeError($e, 'Could not record the observation');
        }
    }
}

$page_title = 'Record an observation';
$page_breadcrumb = [
    ['label' => 'Health', 'url' => BASE_URL . 'modules/health/'],
    ['label' => 'Record an observation'],
];
require_once __DIR__ . '/../../includes/header.php';
?>
<?php healthScopedStyles(); ?>
<div class="dfms-health-scope">


<style>
    .obs-value-block { display: none; }
    .obs-value-block.is-active { display: block; }
    .obs-unit {
        display: inline-block;
        margin-left: var(--space-2);
        font-size: var(--text-sm);
        color: var(--text-muted);
    }
    /* Without JavaScript every value block is shown, each clearly labelled, so
       the form is still completable. The script below narrows it to one. */
    .no-js-all .obs-value-block { display: block; }
</style>

<?php dfms_page_header('Record an observation',
    'A single measurement or sign for one animal. Numbers are stored in the system\'s canonical units.',
    '', 'Veterinary'); ?>

<?php dfms_render_flashes(); ?>
<?php if ($error): ?>
    <?php dfms_alert($error, 'error'); ?>
<?php endif; ?>

<?php if (empty($animals)): ?>
    <div class="dfms-card">
        <?php dfms_empty_state('No animals available',
            'Observations can only be recorded against an animal with an active lifecycle status.',
            dfms_button_html('Go to livestock', BASE_URL . 'modules/cows/', 'primary')); ?>
    </div>
<?php else: ?>
<form method="POST" novalidate>
    <?php csrf_field(); ?>

    <section class="dfms-section">
        <div class="dfms-card">
            <div class="dfms-card-body">
                <div class="dfms-form-grid">
                    <?php
                    healthAnimalField($animals, $submitted['cow_id'] ?? $preCow);

                    $typeOptions = [];
                    foreach (DFMS_OBSERVATION_TYPES as $key => $meta) {
                        $typeOptions[$key] = $meta['label'];
                    }
                    healthSelectField('observation_type', 'What are you recording', $typeOptions,
                        $submitted['observation_type'] ?? $preType, [
                            'required'    => true,
                            'placeholder' => 'Select a measurement',
                            'id'          => 'observation_type',
                        ]);

                    healthInputField('observed_at', 'When', 'datetime-local',
                        $submitted['observed_at'] ?? gmdate('Y-m-d\TH:i'), ['required' => true]);

                    $caseOptions = [];
                    foreach ($cases as $case) {
                        $caseOptions[(int) $case['case_id']] = $case['case_reference'] . ' — ' . $case['title'];
                    }
                    healthSelectField('case_id', 'Part of health case', $caseOptions,
                        $submitted['case_id'] ?? $preCase, [
                            'placeholder' => $caseOptions ? 'Routine — not part of a case' : 'No open cases for this animal',
                            'hint'        => 'Routine monitoring does not need a case.',
                        ]);
                    ?>
                </div>

                <div id="obs-values" class="no-js-all">
                    <?php foreach (DFMS_OBSERVATION_TYPES as $key => $meta): ?>
                        <div class="obs-value-block" data-type="<?php echo e($key); ?>">
                            <?php if (dfms_observation_is_numeric($key)): ?>
                                <?php
                                // The unit the farm works in. Sent with the value so
                                // the domain layer converts from the right unit rather
                                // than assuming canonical.
                                $inputUnit = !empty($meta['kind'])
                                    ? dfms_display_unit((string) $meta['kind'], $system)
                                    : (string) $meta['unit'];
                                healthInputField('value_' . $key, $meta['label'] . ' (' . dfms_unit_label($inputUnit) . ')',
                                    'number', $submitted['value_' . $key] ?? '', [
                                        'step'      => '0.01',
                                        'inputmode' => 'decimal',
                                        'id'        => 'value_' . $key,
                                        'hint'      => $meta['hint'] ?? null,
                                    ]);
                                ?>
                                <input type="hidden" name="unit_<?php echo e($key); ?>" value="<?php echo e($inputUnit); ?>">
                            <?php else: ?>
                                <?php healthSelectField('code_' . $key, $meta['label'],
                                    dfms_observation_scale($key), $submitted['code_' . $key] ?? '', [
                                        'placeholder' => 'Select a value',
                                        'id'          => 'code_' . $key,
                                    ]); ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php healthTextareaField('notes', 'Notes', $submitted['notes'] ?? '', ['rows' => 3, 'maxlength' => 500]); ?>
            </div>
        </div>
    </section>

    <div class="dfms-form-actions">
        <a class="dfms-btn dfms-btn-secondary" href="<?php echo e(BASE_URL); ?>modules/health/">Cancel</a>
        <button type="submit" class="dfms-btn dfms-btn-primary">Record observation</button>
    </div>
</form>

<script>
// PROGRESSIVE ENHANCEMENT. Without this script every value field is visible and
// labelled, so the form still works; the script simply narrows it to the one
// that matches the selected measurement.
(function () {
    var select = document.getElementById('observation_type');
    var container = document.getElementById('obs-values');
    if (!select || !container) { return; }
    container.classList.remove('no-js-all');

    function sync() {
        var chosen = select.value;
        var blocks = container.querySelectorAll('.obs-value-block');
        for (var i = 0; i < blocks.length; i++) {
            var match = blocks[i].getAttribute('data-type') === chosen;
            blocks[i].classList.toggle('is-active', match);
            // A hidden field must not be submitted as a competing empty value,
            // and must not be a keyboard trap for anyone tabbing through.
            var fields = blocks[i].querySelectorAll('input:not([type=hidden]), select');
            for (var j = 0; j < fields.length; j++) {
                fields[j].disabled = !match;
            }
        }
    }
    select.addEventListener('change', sync);
    sync();
})();
</script>
<?php endif; ?>

</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
