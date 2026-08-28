<?php
// ============================================================================
// RECORD A MILKING — one animal, one session.
//
// The raw operational entry point. Deliberately separate from add.php, which
// records the FARM DAILY TOTAL and is unchanged: they capture different facts
// and merging the two forms would invite recording one as the other.
//
// The form stays open after a successful save with the date and session
// retained, because the real workflow is a person going down a row of animals
// entering one figure each. Returning them to a list after every entry would
// make recording forty animals forty round trips.
// ============================================================================

define('DFMS_MILK_PAGE', true);
require_once __DIR__ . '/_shared.php';

requireAuth('milk.create');

$conn = getDBConnection();
$error = '';
$success = '';

$animals = productionSelectableAnimals($conn);
$withdrawals = productionMilkWithdrawalsByAnimal($conn);

// Sticky context: what the operator is currently working through.
$defaultDate = farmToday();
$sessionDefault = 'morning';
$submitted = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $submitted = $_POST;

    $v = new DfmsValidator($_POST);
    // cow_id is validated as a positive integer here and then resolved through
    // the tenant repository inside productionRecordYield(). A well-formed id
    // belonging to another farm gets past THIS check and is refused there,
    // which is the layer that can actually tell.
    $cowId = $v->id('cow_id');
    $date = $v->date('production_date');
    $session = $v->enum('session', array_keys(DFMS_MILKING_SESSIONS));
    $quantity = $v->decimal('quantity', ['min' => 0, 'max' => 500]);
    $discarded = $v->decimal('discarded', ['nullable' => true, 'min' => 0, 'max' => 500]) ?? 0.0;
    $notes = $v->string('notes', 0, 500, ['nullable' => true]);

    if ($v->fails()) {
        $error = $v->firstError();
    } else {
        try {
            // The operator types in THEIR unit; storage is canonical litres.
            // Converting at the edge is the Phase 2 rule — nothing downstream
            // ever has to ask which unit a stored number is in.
            $displayUnit = dfms_display_unit('volume', currentMeasurementSystem());
            $canonicalQty = dfms_to_canonical($quantity, 'volume', $displayUnit);
            $canonicalDiscarded = dfms_to_canonical($discarded, 'volume', $displayUnit);

            productionRecordYield($conn, [
                'cow_id'          => $cowId,
                'production_date' => $date,
                'session'         => $session,
                'quantity'        => $canonicalQty,
                'discarded'       => $canonicalDiscarded,
                'abnormal'        => !empty($_POST['abnormal']),
                'notes'           => $notes,
            ]);

            $success = 'Recorded. Enter the next animal.';
            $defaultDate = $date;
            $sessionDefault = $session;
            // Only the per-animal fields are cleared; date and session persist
            // so the next entry continues the same round.
            $submitted = ['production_date' => $date, 'session' => $session];
        } catch (ProductionRuleViolation $e) {
            // Our own domain messages are safe to display verbatim.
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = safeError($e, 'Could not record the milking');
        }
    }
}

$page_title = 'Record a milking';
$page_breadcrumb = [['label' => 'Milk Production', 'url' => 'index.php'], ['label' => 'Record a milking']];
require_once __DIR__ . '/../../includes/header.php';

$displayUnit = dfms_display_unit('volume', currentMeasurementSystem());
$unitLabel = dfms_unit_label($displayUnit);
?>
<?php productionScopedStyles(); ?>
<div class="dfms-production-scope">

<?php
dfms_page_header(
    'Record a milking',
    'One animal, one session. Farm daily totals are recorded separately.',
    dfms_button_html('Back to production', BASE_URL . 'modules/milk/', 'secondary'),
    'Production'
);
?>

<?php dfms_render_flashes(); ?>
<?php if ($error !== ''): ?><?php dfms_alert($error, 'error'); ?><?php endif; ?>
<?php if ($success !== ''): ?><?php dfms_alert($success, 'success'); ?><?php endif; ?>

<?php if ($animals === []): ?>
    <div class="dfms-card"><div class="dfms-card-body">
        <?php dfms_empty_state(
            'No animals to record against',
            'Add an animal to the herd first, or check that your animals are still active.',
            dfms_button_html('Go to animals', BASE_URL . 'modules/cows/', 'primary'),
            'inbox'
        ); ?>
    </div></div>
<?php else: ?>

<div class="dfms-card">
    <div class="dfms-card-body">
        <form method="POST" autocomplete="off" novalidate>
            <?php csrf_field(); ?>

            <div class="prod-filters">
                <div class="dfms-field">
                    <label class="dfms-label" for="production_date">
                        Date<span class="dfms-required" aria-hidden="true">*</span>
                    </label>
                    <input class="dfms-input" type="date" id="production_date" name="production_date"
                           required max="<?php echo e(farmToday()); ?>"
                           value="<?php echo e($submitted['production_date'] ?? $defaultDate); ?>">
                </div>

                <div class="dfms-field">
                    <label class="dfms-label" for="session">
                        Milking<span class="dfms-required" aria-hidden="true">*</span>
                    </label>
                    <select class="dfms-select" id="session" name="session" required>
                        <?php foreach (DFMS_MILKING_SESSIONS as $key => $label): ?>
                            <option value="<?php echo e($key); ?>"
                                <?php echo ($submitted['session'] ?? $sessionDefault) === $key ? 'selected' : ''; ?>>
                                <?php echo e($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="dfms-field">
                <label class="dfms-label" for="cow_id">
                    Animal<span class="dfms-required" aria-hidden="true">*</span>
                </label>
                <select class="dfms-select" id="cow_id" name="cow_id" required aria-describedby="cow-hint">
                    <option value="">Choose an animal…</option>
                    <?php foreach ($animals as $animal): ?>
                        <?php $restricted = isset($withdrawals[(int) $animal['cow_id']]); ?>
                        <option value="<?php echo (int) $animal['cow_id']; ?>"
                            <?php echo (string) ($submitted['cow_id'] ?? '') === (string) $animal['cow_id'] ? 'selected' : ''; ?>>
                            <?php
                            echo e($animal['unique_id'] . ($animal['name'] ? ' — ' . $animal['name'] : ''));
                            // The restriction is surfaced in the option itself,
                            // so it is visible before the figure is typed
                            // rather than after the record is saved.
                            echo $restricted ? ' (milk withdrawal in force)' : '';
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="dfms-hint" id="cow-hint">
                    Only active animals appear. Recording is never blocked by a withdrawal —
                    the milk is recorded and excluded from the saleable total.
                </p>
            </div>

            <?php if ($withdrawals !== []): ?>
                <div class="prod-warn">
                    <strong><?php echo count($withdrawals); ?> animal<?php echo count($withdrawals) === 1 ? '' : 's'; ?>
                        under an active milk withdrawal.</strong>
                    Their milk is still recorded, marked restricted, and left out of the saleable total.
                    Check the health module before sending anything to the tank.
                </div>
            <?php endif; ?>

            <div class="prod-filters">
                <div class="dfms-field">
                    <label class="dfms-label" for="quantity">
                        Quantity (<?php echo e($unitLabel); ?>)<span class="dfms-required" aria-hidden="true">*</span>
                    </label>
                    <input class="dfms-input" type="number" id="quantity" name="quantity"
                           required step="0.01" min="0.01" max="500" inputmode="decimal"
                           placeholder="e.g. 12.5"
                           value="<?php echo e($submitted['quantity'] ?? ''); ?>">
                </div>

                <div class="dfms-field">
                    <label class="dfms-label" for="discarded">Discarded (<?php echo e($unitLabel); ?>)</label>
                    <input class="dfms-input" type="number" id="discarded" name="discarded"
                           step="0.01" min="0" max="500" inputmode="decimal"
                           aria-describedby="discarded-hint"
                           value="<?php echo e($submitted['discarded'] ?? ''); ?>">
                    <p class="dfms-hint" id="discarded-hint">Withheld at the parlour. Leave blank if none.</p>
                </div>
            </div>

            <div class="dfms-field">
                <label class="dfms-label" for="abnormal" style="display:flex;align-items:flex-start;gap:.6rem;cursor:pointer;">
                    <input type="checkbox" id="abnormal" name="abnormal" value="1"
                           style="width:1.15rem;height:1.15rem;margin-top:.15rem;flex:0 0 auto;"
                           aria-describedby="abnormal-hint"
                        <?php echo !empty($submitted['abnormal']) ? 'checked' : ''; ?>>
                    <span>The milk looked abnormal</span>
                </label>
                <p class="dfms-hint" id="abnormal-hint">
                    An observation, not a diagnosis. Record clinical detail in the health module.
                </p>
            </div>

            <div class="dfms-field">
                <label class="dfms-label" for="notes">Notes</label>
                <textarea class="dfms-textarea" id="notes" name="notes" rows="2" maxlength="500"
                          placeholder="Anything worth remembering about this milking…"><?php
                    echo e($submitted['notes'] ?? ''); ?></textarea>
            </div>

            <div class="dfms-form-actions">
                <button type="submit" class="dfms-btn dfms-btn-primary">Record milking</button>
                <a class="dfms-btn dfms-btn-secondary" href="<?php echo BASE_URL; ?>modules/milk/">Done</a>
            </div>
        </form>
    </div>
</div>

<?php endif; ?>

</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
