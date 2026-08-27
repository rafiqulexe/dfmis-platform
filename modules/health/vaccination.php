<?php
// ============================================================================
// VACCINATIONS — schedule a dose, record a dose, or mark a scheduled dose given.
//
// THE RULE THIS PAGE EXISTS TO ENFORCE: a scheduled vaccination is never
// promoted to "administered" by the passage of time. Only the explicit
// "mark given" action does that, and it is a separate submission with its own
// date and batch fields. An assumed vaccination is worse than a missing one,
// because it stops anyone looking.
//
// ?given=<id> switches this page into the mark-given mode for one scheduled row.
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
$givenId = $gv->id('given', ['nullable' => true]);

// Mark-given mode: resolve the scheduled row through the tenant-scoped finder,
// so another farm's vaccination is a 404 exactly like a missing one.
$scheduled = null;
if ($givenId !== null) {
    $scheduled = tenantFindOr404($conn, 'vaccination_records', 'vaccination_id', $givenId);
    if ($scheduled['status'] !== 'scheduled') {
        redirectWith('modules/health/?tab=vaccinations', 'error',
            'That vaccination is not scheduled, so it cannot be marked as given.');
    }
}

$scheduledAnimal = $scheduled !== null ? animalFind($conn, (int) $scheduled['cow_id'], true) : null;
$species = $scheduledAnimal['species'] ?? null;
$withdrawalTypes = dfms_withdrawal_types_for_species($species);
$submitted = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $submitted = $_POST;

    $v = new DfmsValidator($_POST);
    $mode = $v->enum('mode', ['create', 'mark_given']);

    $withdrawals = [];
    foreach (array_keys(DFMS_WITHDRAWAL_TYPES) as $type) {
        $raw = $_POST['withdrawal_' . $type] ?? '';
        if (is_scalar($raw) && trim((string) $raw) !== '') {
            $withdrawals[$type] = trim((string) $raw);
        }
    }

    try {
        if ($v->fails()) {
            $error = $v->firstError();
        } elseif ($mode === 'mark_given') {
            $vaccinationId = $v->id('vaccination_id');
            $administeredOn = $v->date('administered_on', ['max' => farmToday()]);
            $batchLot = $v->string('batch_lot', 0, 60, ['nullable' => true]);
            $expiry = $v->date('expiry_date', ['nullable' => true, 'allow_future' => true]);
            $administrator = $v->string('administrator_name', 0, 150, ['nullable' => true]);
            $nextDue = $v->date('next_due_on', ['nullable' => true, 'allow_future' => true]);

            if ($v->fails()) {
                $error = $v->firstError();
            } else {
                healthMarkVaccinationGiven($conn, (int) $vaccinationId, [
                    'administered_on'    => $administeredOn,
                    'batch_lot'          => $batchLot,
                    'expiry_date'        => $expiry,
                    'administrator_name' => $administrator,
                    'next_due_on'        => $nextDue,
                    'withdrawals'        => $withdrawals,
                ]);
                redirectWith('modules/health/?tab=vaccinations', 'success', 'Vaccination recorded as given.');
            }
        } else {
            $cowId = $v->id('cow_id');
            $status = $v->enum('status', ['scheduled', 'administered']);
            $vaccineName = $v->string('vaccine_name', 2, 150);
            $diseaseTarget = $v->string('disease_target', 0, 150, ['nullable' => true]);
            $itemId = $v->id('item_id', ['nullable' => true]);
            $scheduledOn = $v->date('scheduled_on', ['nullable' => true, 'allow_future' => true]);
            $administeredOn = $v->date('administered_on', ['nullable' => true, 'max' => farmToday()]);
            $dose = $v->decimal('dose', ['nullable' => true, 'min' => 0.001, 'max' => 999999]);
            $doseUnit = $v->enum('dose_unit', array_keys(DFMS_DOSE_UNITS), ['nullable' => true]);
            $route = $v->enum('route', array_keys(DFMS_ADMIN_ROUTES), ['nullable' => true]);
            $batchLot = $v->string('batch_lot', 0, 60, ['nullable' => true]);
            $expiry = $v->date('expiry_date', ['nullable' => true, 'allow_future' => true]);
            $administrator = $v->string('administrator_name', 0, 150, ['nullable' => true]);
            $nextDue = $v->date('next_due_on', ['nullable' => true, 'allow_future' => true]);
            $notes = $v->text('notes', 500, ['nullable' => true]);

            if ($v->fails()) {
                $error = $v->firstError();
            } else {
                healthRecordVaccination($conn, (int) $cowId, [
                    'status'             => $status,
                    'vaccine_name'       => $vaccineName,
                    'disease_target'     => $diseaseTarget,
                    'item_id'            => $itemId,
                    'scheduled_on'       => $scheduledOn,
                    'administered_on'    => $administeredOn,
                    'dose'               => $dose,
                    'dose_unit'          => $doseUnit,
                    'route'              => $route,
                    'batch_lot'          => $batchLot,
                    'expiry_date'        => $expiry,
                    'administrator_name' => $administrator,
                    'next_due_on'        => $nextDue,
                    'withdrawals'        => $withdrawals,
                    'notes'              => $notes,
                ]);
                redirectWith('modules/health/?tab=vaccinations', 'success',
                    $status === 'scheduled'
                        ? 'Vaccination scheduled. It will not be marked as given until someone records the dose.'
                        : 'Vaccination recorded.');
            }
        }
    } catch (HealthRuleViolation $e) {
        $error = $e->getMessage();
    } catch (PDOException $e) {
        $error = safeError($e, 'Could not save the vaccination');
    }
}

$page_title = $scheduled !== null ? 'Mark vaccination given' : 'Vaccination';
$page_breadcrumb = [
    ['label' => 'Health', 'url' => BASE_URL . 'modules/health/'],
    ['label' => 'Vaccination'],
];
require_once __DIR__ . '/../../includes/header.php';
?>
<?php healthScopedStyles(); ?>
<div class="dfms-health-scope">


<style>
    .vacc-withdrawal-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: var(--space-4);
    }
</style>

<?php if ($scheduled !== null): ?>
    <?php dfms_page_header('Mark vaccination given',
        'Recording the dose that was actually administered against a scheduled entry.', '', 'Veterinary'); ?>
<?php else: ?>
    <?php dfms_page_header('Vaccination',
        'Schedule a future dose, or record one that has already been given.', '', 'Veterinary'); ?>
<?php endif; ?>

<?php dfms_render_flashes(); ?>
<?php if ($error): ?>
    <?php dfms_alert($error, 'error'); ?>
<?php endif; ?>

<?php if ($scheduled !== null): ?>
<!-- ===================== MARK GIVEN ===================== -->
<form method="POST" novalidate>
    <?php csrf_field(); ?>
    <input type="hidden" name="mode" value="mark_given">
    <input type="hidden" name="vaccination_id" value="<?php echo (int) $scheduled['vaccination_id']; ?>">

    <section class="dfms-section">
        <div class="dfms-card">
            <div class="dfms-card-header">
                <h2 class="dfms-card-title"><?php echo e($scheduled['vaccine_name']); ?></h2>
                <p class="dfms-card-subtitle">
                    <?php echo e($scheduledAnimal !== null ? animalDisplayName($scheduledAnimal) : 'Animal'); ?>
                    · scheduled for <?php echo e(fmt_date($scheduled['scheduled_on'])); ?>
                </p>
            </div>
            <div class="dfms-card-body">
                <div class="dfms-form-grid">
                    <?php
                    healthInputField('administered_on', 'Date given', 'date',
                        $submitted['administered_on'] ?? farmToday(), [
                            'required' => true,
                            'max'      => farmToday(),
                            'hint'     => 'The date the dose was actually administered, which may not be the scheduled date.',
                        ]);
                    healthInputField('batch_lot', 'Batch / lot number', 'text',
                        $submitted['batch_lot'] ?? '', ['maxlength' => 60, 'autocomplete' => 'off', 'spellcheck' => 'false']);
                    healthInputField('expiry_date', 'Expiry date', 'date', $submitted['expiry_date'] ?? '');
                    healthInputField('administrator_name', 'Given by', 'text',
                        $submitted['administrator_name'] ?? '', [
                            'maxlength'    => 150,
                            'autocomplete' => 'off',
                            'hint'         => 'Leave blank if you gave it. No account is needed for an external vet.',
                        ]);
                    healthInputField('next_due_on', 'Next dose due', 'date',
                        $submitted['next_due_on'] ?? '', ['hint' => 'Optional. Drives the next reminder.']);
                    ?>
                </div>
            </div>
        </div>
    </section>

    <section class="dfms-section">
        <div class="dfms-card">
            <div class="dfms-card-header">
                <h2 class="dfms-card-title">Withdrawal periods</h2>
                <p class="dfms-card-subtitle">Days from the date given. Blank means no restriction is recorded — never assumed to be zero.</p>
            </div>
            <div class="dfms-card-body">
                <div class="vacc-withdrawal-grid">
                    <?php foreach ($withdrawalTypes as $type => $label): ?>
                        <?php healthInputField('withdrawal_' . $type, $label . ' withdrawal', 'number', '', [
                            'min' => '1', 'max' => '365', 'step' => '1', 'inputmode' => 'numeric',
                            'hint' => 'Days.',
                        ]); ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </section>

    <div class="dfms-form-actions">
        <a class="dfms-btn dfms-btn-secondary" href="<?php echo e(BASE_URL); ?>modules/health/?tab=vaccinations">Cancel</a>
        <button type="submit" class="dfms-btn dfms-btn-primary">Mark as given</button>
    </div>
</form>

<?php elseif (empty($animals)): ?>
    <div class="dfms-card">
        <?php dfms_empty_state('No animals available',
            'A vaccination can only be recorded against an animal with an active lifecycle status.',
            dfms_button_html('Go to livestock', BASE_URL . 'modules/cows/', 'primary')); ?>
    </div>
<?php else: ?>
<!-- ===================== SCHEDULE OR RECORD ===================== -->
<form method="POST" novalidate>
    <?php csrf_field(); ?>
    <input type="hidden" name="mode" value="create">

    <section class="dfms-section">
        <div class="dfms-card">
            <div class="dfms-card-body">
                <div class="dfms-form-grid">
                    <?php
                    healthAnimalField($animals, $submitted['cow_id'] ?? $preCow);

                    healthSelectField('status', 'Is this already given?', [
                        'scheduled'    => 'No — schedule it for a future date',
                        'administered' => 'Yes — record a dose already given',
                    ], $submitted['status'] ?? 'scheduled', [
                        'required' => true,
                        'hint'     => 'A scheduled dose is never marked given automatically; someone records it.',
                    ]);

                    healthInputField('vaccine_name', 'Vaccine', 'text',
                        $submitted['vaccine_name'] ?? '', ['required' => true, 'maxlength' => 150]);

                    healthInputField('disease_target', 'Protects against', 'text',
                        $submitted['disease_target'] ?? '', [
                            'maxlength' => 150,
                            'placeholder' => 'e.g. Foot and mouth disease…',
                        ]);

                    $itemOptions = [];
                    foreach ($items as $item) {
                        $itemOptions[(int) $item['item_id']] = $item['item_name'];
                    }
                    healthSelectField('item_id', 'Product from inventory', $itemOptions,
                        $submitted['item_id'] ?? '', ['placeholder' => 'Not from inventory']);

                    healthInputField('scheduled_on', 'Scheduled for', 'date',
                        $submitted['scheduled_on'] ?? '', ['hint' => 'Required when scheduling.']);

                    healthInputField('administered_on', 'Date given', 'date',
                        $submitted['administered_on'] ?? '', [
                            'max'  => farmToday(),
                            'hint' => 'Required when recording a dose already given.',
                        ]);

                    healthInputField('next_due_on', 'Next dose due', 'date', $submitted['next_due_on'] ?? '');
                    ?>
                </div>
            </div>
        </div>
    </section>

    <section class="dfms-section">
        <div class="dfms-card">
            <div class="dfms-card-header">
                <h2 class="dfms-card-title">Dose details</h2>
                <p class="dfms-card-subtitle">Optional when scheduling — fill them in when the dose is actually given.</p>
            </div>
            <div class="dfms-card-body">
                <div class="dfms-form-grid">
                    <?php
                    healthInputField('dose', 'Dose', 'number', $submitted['dose'] ?? '',
                        ['min' => '0.001', 'step' => '0.001', 'inputmode' => 'decimal']);
                    healthSelectField('dose_unit', 'Dose unit', DFMS_DOSE_UNITS,
                        $submitted['dose_unit'] ?? '', ['placeholder' => 'Not recorded']);
                    healthSelectField('route', 'Route', DFMS_ADMIN_ROUTES,
                        $submitted['route'] ?? '', ['placeholder' => 'Not recorded']);
                    healthInputField('batch_lot', 'Batch / lot number', 'text',
                        $submitted['batch_lot'] ?? '', ['maxlength' => 60, 'autocomplete' => 'off', 'spellcheck' => 'false']);
                    healthInputField('expiry_date', 'Expiry date', 'date', $submitted['expiry_date'] ?? '');
                    healthInputField('administrator_name', 'Given by', 'text',
                        $submitted['administrator_name'] ?? '', ['maxlength' => 150, 'autocomplete' => 'off']);
                    ?>
                </div>
                <?php healthTextareaField('notes', 'Notes', $submitted['notes'] ?? '', ['rows' => 2, 'maxlength' => 500]); ?>
            </div>
        </div>
    </section>

    <section class="dfms-section">
        <div class="dfms-card">
            <div class="dfms-card-header">
                <h2 class="dfms-card-title">Withdrawal periods</h2>
                <p class="dfms-card-subtitle">Only applied when recording a dose as given. Blank means no restriction is recorded.</p>
            </div>
            <div class="dfms-card-body">
                <div class="vacc-withdrawal-grid">
                    <?php foreach (dfms_withdrawal_types_for_species(null) as $type => $label): ?>
                        <?php healthInputField('withdrawal_' . $type, $label . ' withdrawal', 'number',
                            $submitted['withdrawal_' . $type] ?? '', [
                                'min' => '1', 'max' => '365', 'step' => '1', 'inputmode' => 'numeric', 'hint' => 'Days.',
                            ]); ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </section>

    <div class="dfms-form-actions">
        <a class="dfms-btn dfms-btn-secondary" href="<?php echo e(BASE_URL); ?>modules/health/?tab=vaccinations">Cancel</a>
        <button type="submit" class="dfms-btn dfms-btn-primary">Save vaccination</button>
    </div>
</form>
<?php endif; ?>

</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
