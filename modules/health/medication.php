<?php
// ============================================================================
// ADMINISTER MEDICATION.
//
// The highest-consequence form in Phase 4: it can consume inventory AND create
// a food-safety withdrawal restriction. It therefore gets its own page rather
// than an inline block on the case screen, so the operator sees the whole
// decision — product, dose, batch, stock, withdrawal — before submitting.
//
// The withdrawal fields are deliberately EMPTY unless the inventory product
// carries a recorded default. A blank withdrawal creates NO restriction and
// the form says so in as many words. Pre-filling zero would be the single most
// dangerous default in this system.
// ============================================================================

define('DFMS_HEALTH_PAGE', true);
require_once __DIR__ . '/_shared.php';

requireAuth('health.medicate');

$conn = getDBConnection();
$error = '';
$animals = healthSelectableAnimals($conn);
$items = healthMedicationItems($conn);

$gv = new DfmsValidator($_GET);
$preCow = $gv->id('cow_id', ['nullable' => true]);
$preCase = $gv->id('case_id', ['nullable' => true]);
$preTreatment = $gv->id('treatment_id', ['nullable' => true]);

// Cases and treatments are only offered when an animal is already in context.
// Listing every case on the farm here would be a picker the domain layer then
// rejects, because a case must belong to the animal being treated.
$cases = [];
$treatments = [];
$selectedAnimal = null;
if ($preCow !== null) {
    $selectedAnimal = animalFind($conn, (int) $preCow, true);
    if ($selectedAnimal !== null) {
        $cases = healthOpenCasesFor($conn, (int) $preCow);
        $treatments = healthActiveTreatments($conn, (int) $preCow);
    }
}

$submitted = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $submitted = $_POST;

    $v = new DfmsValidator($_POST);
    $cowId = $v->id('cow_id');
    $itemId = $v->id('item_id', ['nullable' => true]);
    $medicationName = $v->string('medication_name', 0, 150, ['nullable' => true]);
    $batchLot = $v->string('batch_lot', 0, 60, ['nullable' => true]);
    $expiryDate = $v->date('expiry_date', ['nullable' => true, 'allow_future' => true]);
    $dose = $v->decimal('dose', ['min' => 0.001, 'max' => 999999]);
    $doseUnit = $v->enum('dose_unit', array_keys(DFMS_DOSE_UNITS));
    $route = $v->enum('route', array_keys(DFMS_ADMIN_ROUTES));
    $frequency = $v->string('frequency', 0, 60, ['nullable' => true]);
    $administeredAt = $v->datetime('administered_at');
    $administratorName = $v->string('administered_by_name', 0, 150, ['nullable' => true]);
    $caseId = $v->id('case_id', ['nullable' => true]);
    $treatmentId = $v->id('treatment_id', ['nullable' => true]);
    $quantityUsed = $v->decimal('quantity_used', ['nullable' => true, 'min' => 0, 'max' => 999999]);
    $notes = $v->text('notes', 500, ['nullable' => true]);

    // Withdrawal days arrive as one field per restriction type. They are
    // validated inside healthNormaliseWithdrawalInput() against the species,
    // so an inapplicable type is rejected rather than silently ignored.
    $withdrawals = [];
    foreach (array_keys(DFMS_WITHDRAWAL_TYPES) as $type) {
        $raw = $_POST['withdrawal_' . $type] ?? '';
        if (is_scalar($raw) && trim((string) $raw) !== '') {
            $withdrawals[$type] = trim((string) $raw);
        }
    }

    if ($v->fails()) {
        $error = $v->firstError();
    } else {
        try {
            $administrationId = healthAdministerMedication($conn, (int) $cowId, [
                'item_id'              => $itemId,
                'medication_name'      => $medicationName,
                'batch_lot'            => $batchLot,
                'expiry_date'          => $expiryDate,
                'dose'                 => $dose,
                'dose_unit'            => $doseUnit,
                'route'                => $route,
                'frequency'            => $frequency,
                'administered_at'      => $administeredAt,
                'administered_by_name' => $administratorName,
                'case_id'              => $caseId,
                'treatment_id'         => $treatmentId,
                'deduct_stock'         => !empty($_POST['deduct_stock']),
                'quantity_used'        => $quantityUsed,
                'withdrawals'          => $withdrawals,
                'notes'                => $notes,
            ]);

            $message = 'Medication recorded.';
            if ($withdrawals !== []) {
                $message .= ' Withdrawal restrictions are now active for this animal.';
            } else {
                $message .= ' NO withdrawal restriction was created — none was specified.';
            }
            $target = $caseId !== null
                ? 'modules/health/case.php?id=' . (int) $caseId
                : 'modules/cows/view.php?id=' . (int) $cowId . '&tab=health';
            redirectWith($target, 'success', $message);
        } catch (HealthRuleViolation $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = safeError($e, 'Could not record the medication');
        }
    }
}

$page_title = 'Administer medication';
$page_breadcrumb = [
    ['label' => 'Health', 'url' => BASE_URL . 'modules/health/'],
    ['label' => 'Administer medication'],
];
require_once __DIR__ . '/../../includes/header.php';

// The species drives which withdrawal restrictions are even offered.
$species = $selectedAnimal['species'] ?? null;
$withdrawalTypes = dfms_withdrawal_types_for_species($species);
?>
<?php healthScopedStyles(); ?>
<div class="dfms-health-scope">


<style>
    .med-warning {
        border-left: 3px solid var(--warning);
        background: var(--warning-bg);
        color: var(--warning-fg);
        padding: var(--space-3) var(--space-4);
        border-radius: var(--radius-sm);
        font-size: var(--text-sm);
        margin-bottom: var(--space-5);
    }
    .med-withdrawal-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: var(--space-4);
    }
</style>

<?php dfms_page_header('Administer medication',
    'Records the dose given to one animal, optionally taking it from stock and starting a withdrawal period.',
    '', 'Veterinary'); ?>

<?php dfms_render_flashes(); ?>
<?php if ($error): ?>
    <?php dfms_alert($error, 'error'); ?>
<?php endif; ?>

<?php if (empty($animals)): ?>
    <div class="dfms-card">
        <?php dfms_empty_state('No animals available',
            'Medication can only be recorded against an animal with an active lifecycle status.',
            dfms_button_html('Go to livestock', BASE_URL . 'modules/cows/', 'primary')); ?>
    </div>
<?php else: ?>

<div class="med-warning">
    Withdrawal periods are <strong>not pre-filled and never default to zero</strong>. If you leave them blank,
    no restriction is created and this animal's milk and meat will not be flagged. Take the period from the
    product label or your veterinarian's instruction.
</div>

<form method="POST" novalidate>
    <?php csrf_field(); ?>

    <section class="dfms-section">
        <div class="dfms-card">
            <div class="dfms-card-header">
                <h2 class="dfms-card-title">What was given, and to which animal</h2>
            </div>
            <div class="dfms-card-body">
                <div class="dfms-form-grid">
                    <?php
                    healthAnimalField($animals, $submitted['cow_id'] ?? $preCow);

                    $itemOptions = [];
                    foreach ($items as $item) {
                        $itemOptions[(int) $item['item_id']] = $item['item_name']
                            . ' (' . fmt_number((float) $item['quantity'], 2) . ' ' . (string) $item['unit'] . ' in stock)';
                    }
                    healthSelectField('item_id', 'Product from inventory', $itemOptions,
                        $submitted['item_id'] ?? '', [
                            'placeholder' => 'Not from inventory',
                            'hint'        => $itemOptions
                                ? 'Optional. Choosing a product lets you take the quantity from stock below.'
                                : 'No medication or vaccine products are categorised in this farm\'s inventory yet.',
                        ]);

                    healthInputField('medication_name', 'Medication name', 'text',
                        $submitted['medication_name'] ?? '', [
                            'maxlength' => 150,
                            'hint'      => 'Required if no inventory product is selected. Stored on the record so it survives an inventory change.',
                        ]);

                    healthInputField('batch_lot', 'Batch / lot number', 'text',
                        $submitted['batch_lot'] ?? '', [
                            'maxlength'    => 60,
                            'autocomplete' => 'off',
                            'spellcheck'   => 'false',
                            'hint'         => 'As printed on the container. Inventory does not track stock by batch, so this is recorded here.',
                        ]);

                    healthInputField('expiry_date', 'Expiry date', 'date',
                        $submitted['expiry_date'] ?? '', ['hint' => 'As printed on the container.']);
                    ?>
                </div>
            </div>
        </div>
    </section>

    <section class="dfms-section">
        <div class="dfms-card">
            <div class="dfms-card-header">
                <h2 class="dfms-card-title">Dose and route</h2>
                <p class="dfms-card-subtitle">Dose units are recorded as entered — DFMIS never converts between ml and mg, because that depends on the product's concentration.</p>
            </div>
            <div class="dfms-card-body">
                <div class="dfms-form-grid">
                    <?php
                    healthInputField('dose', 'Dose', 'number', $submitted['dose'] ?? '', [
                        'required'  => true,
                        'min'       => '0.001',
                        'step'      => '0.001',
                        'inputmode' => 'decimal',
                    ]);
                    healthSelectField('dose_unit', 'Dose unit', DFMS_DOSE_UNITS,
                        $submitted['dose_unit'] ?? 'ml', ['required' => true]);
                    healthSelectField('route', 'Route', DFMS_ADMIN_ROUTES,
                        $submitted['route'] ?? '', ['required' => true, 'placeholder' => 'Select a route']);
                    healthInputField('frequency', 'Frequency as prescribed', 'text',
                        $submitted['frequency'] ?? '', [
                            'maxlength'   => 60,
                            'placeholder' => 'e.g. once daily for 3 days…',
                            'hint'        => 'Free text. Each actual dose is recorded separately.',
                        ]);
                    healthInputField('administered_at', 'Date and time given', 'datetime-local',
                        $submitted['administered_at'] ?? gmdate('Y-m-d\TH:i'), ['required' => true]);
                    healthInputField('administered_by_name', 'Given by (if not you)', 'text',
                        $submitted['administered_by_name'] ?? '', [
                            'maxlength'    => 150,
                            'autocomplete' => 'off',
                            'hint'         => 'For an external veterinarian or contractor. No account is needed.',
                        ]);
                    ?>
                </div>
            </div>
        </div>
    </section>

    <section class="dfms-section">
        <div class="dfms-card">
            <div class="dfms-card-header">
                <h2 class="dfms-card-title">Withdrawal periods</h2>
                <p class="dfms-card-subtitle">In days from the date of administration. The restriction is inclusive of its end date.</p>
            </div>
            <div class="dfms-card-body">
                <div class="med-withdrawal-grid">
                    <?php foreach ($withdrawalTypes as $type => $label): ?>
                        <?php healthInputField('withdrawal_' . $type, $label . ' withdrawal', 'number',
                            $submitted['withdrawal_' . $type] ?? '', [
                                'min'       => '1',
                                'max'       => '365',
                                'step'      => '1',
                                'inputmode' => 'numeric',
                                'hint'      => 'Days. Blank = no restriction recorded.',
                            ]); ?>
                    <?php endforeach; ?>
                </div>
                <?php if ($species === null): ?>
                    <p class="dfms-hint">
                        Restriction types shown are the ones that apply to every species. Choose the animal first
                        and reload to see species-specific options such as egg withdrawal.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="dfms-section">
        <div class="dfms-card">
            <div class="dfms-card-header">
                <h2 class="dfms-card-title">Stock and linkage</h2>
            </div>
            <div class="dfms-card-body">
                <div class="dfms-field">
                    <label class="dfms-label" for="deduct_stock" style="display:flex;align-items:flex-start;gap:.6rem;cursor:pointer;">
                        <input type="checkbox" id="deduct_stock" name="deduct_stock" value="1"
                               style="width:1.15rem;height:1.15rem;margin-top:.15rem;flex:0 0 auto;"
                               aria-describedby="deduct_stock-hint"
                               <?php echo !empty($submitted['deduct_stock']) ? 'checked' : ''; ?>>
                        <span>Take this from inventory stock</span>
                    </label>
                    <p class="dfms-hint" id="deduct_stock-hint">
                        Only possible when an inventory product is selected above. Stock is never driven below zero —
                        the whole record is refused rather than written half-way.
                    </p>
                </div>

                <div class="dfms-form-grid">
                    <?php
                    healthInputField('quantity_used', 'Quantity taken from stock', 'number',
                        $submitted['quantity_used'] ?? '', [
                            'min'       => '0.01',
                            'step'      => '0.01',
                            'inputmode' => 'decimal',
                            'hint'      => 'In the inventory item\'s own unit (bottles, packs), which may differ from the dose unit.',
                        ]);

                    $caseOptions = [];
                    foreach ($cases as $case) {
                        $caseOptions[(int) $case['case_id']] = $case['case_reference'] . ' — ' . $case['title'];
                    }
                    healthSelectField('case_id', 'Part of health case', $caseOptions,
                        $submitted['case_id'] ?? $preCase, [
                            'placeholder' => $caseOptions ? 'Not linked to a case' : 'No open cases for this animal',
                            'hint'        => 'Choose the animal and reload to see its open cases.',
                        ]);

                    $treatmentOptions = [];
                    foreach ($treatments as $treatment) {
                        $treatmentOptions[(int) $treatment['treatment_id']] =
                            $treatment['name'] . ' (from ' . $treatment['started_on'] . ')';
                    }
                    healthSelectField('treatment_id', 'Part of treatment', $treatmentOptions,
                        $submitted['treatment_id'] ?? $preTreatment, [
                            'placeholder' => $treatmentOptions ? 'Not linked to a treatment' : 'No active treatments for this animal',
                        ]);
                    ?>
                </div>

                <?php healthTextareaField('notes', 'Notes', $submitted['notes'] ?? '', ['rows' => 2, 'maxlength' => 500]); ?>
            </div>
        </div>
    </section>

    <div class="dfms-form-actions">
        <a class="dfms-btn dfms-btn-secondary" href="<?php echo e(BASE_URL); ?>modules/health/">Cancel</a>
        <button type="submit" class="dfms-btn dfms-btn-primary">Record medication</button>
    </div>
</form>
<?php endif; ?>

</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
