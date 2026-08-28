<?php
// ============================================================================
// CORRECT OR REMOVE ONE PER-ANIMAL MILKING.
//
// Only the MEASURED facts are editable. The animal, the date and the session
// are fixed, because changing any of them turns a correction into a different
// record — and would let a row slip past the uniqueness key it was originally
// checked against. Moving a reading to another animal or session is a delete
// and a fresh entry, which is also the honest audit trail for what happened.
//
// Deleting requires milk.delete (farm manager), which is deliberately narrower
// than the milk.edit needed to correct a figure. See includes/permissions.php.
// ============================================================================

define('DFMS_MILK_PAGE', true);
require_once __DIR__ . '/_shared.php';

requireAuth('milk.edit');

$conn = getDBConnection();
$error = '';

$yieldId = dfms_route_id('id');
// tenantFindOr404() returns 404 for a missing record AND for another farm's,
// indistinguishably — the convention every module here follows.
$yield = tenantFindOr404($conn, 'milk_yields', 'yield_id', $yieldId);

$animal = animalFind($conn, (int) $yield['cow_id'], true);
$animalLabel = $animal !== null
    ? $animal['unique_id'] . ($animal['name'] ? ' — ' . $animal['name'] : '')
    : 'Unknown animal';

$displayUnit = dfms_display_unit('volume', currentMeasurementSystem());
$unitLabel = dfms_unit_label($displayUnit);

$submitted = [
    'quantity'  => dfms_from_canonical((float) $yield['quantity'], 'volume', $displayUnit),
    'discarded' => dfms_from_canonical((float) $yield['discarded'], 'volume', $displayUnit),
    'abnormal'  => (int) $yield['abnormal'],
    'notes'     => $yield['notes'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $action = ($_POST['action'] ?? '') === 'delete' ? 'delete' : 'update';

    if ($action === 'delete') {
        // A SECOND permission check, for a genuinely different decision. The
        // page-level requireAuth('milk.edit') admitted this user; erasing the
        // record needs more than that.
        requirePermission('milk.delete');
        try {
            productionDeleteYield($conn, (int) $yield['yield_id']);
            redirectWith('modules/milk/?tab=daily&date=' . urlencode((string) $yield['production_date']),
                'success', 'Production record removed.');
        } catch (ProductionRuleViolation $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = safeError($e, 'Could not remove the record');
        }
    } else {
        $v = new DfmsValidator($_POST);
        $quantity = $v->decimal('quantity', ['min' => 0, 'max' => 500]);
        $discarded = $v->decimal('discarded', ['nullable' => true, 'min' => 0, 'max' => 500]) ?? 0.0;
        $notes = $v->string('notes', 0, 500, ['nullable' => true]);

        if ($v->fails()) {
            $error = $v->firstError();
            $submitted = array_merge($submitted, $_POST);
        } else {
            try {
                productionUpdateYield($conn, (int) $yield['yield_id'], [
                    'quantity'  => dfms_to_canonical($quantity, 'volume', $displayUnit),
                    'discarded' => dfms_to_canonical($discarded, 'volume', $displayUnit),
                    'abnormal'  => !empty($_POST['abnormal']),
                    'notes'     => $notes,
                ]);
                redirectWith('modules/milk/?tab=daily&date=' . urlencode((string) $yield['production_date']),
                    'success', 'Production record updated.');
            } catch (ProductionRuleViolation $e) {
                $error = $e->getMessage();
                $submitted = array_merge($submitted, $_POST);
            } catch (PDOException $e) {
                $error = safeError($e, 'Could not update the record');
            }
        }
    }
}

$page_title = 'Correct a milking';
$page_breadcrumb = [
    ['label' => 'Milk Production', 'url' => 'index.php'],
    ['label' => 'Correct a milking'],
];
require_once __DIR__ . '/../../includes/header.php';

$canDelete = hasPermission('milk.delete');
?>
<?php productionScopedStyles(); ?>
<div class="dfms-production-scope">

<?php
// dfms_page_header() escapes the subtitle itself — e() here would double-escape.
dfms_page_header(
    'Correct a milking',
    $animalLabel . ' · ' . fmt_date($yield['production_date'])
        . ' · ' . dfms_milking_session_label($yield['session']),
    dfms_button_html('Back to production', BASE_URL . 'modules/milk/?tab=daily&date='
        . urlencode((string) $yield['production_date']), 'secondary'),
    'Production'
);
?>

<?php if ($error !== ''): ?><?php dfms_alert($error, 'error'); ?><?php endif; ?>

<div class="prod-note">
    The animal, date and milking cannot be changed here. If a reading was filed against the
    wrong one, remove it and record it again — that keeps the history honest about what happened.
</div>

<div class="dfms-card"><div class="dfms-card-body">
    <form method="POST" autocomplete="off" novalidate>
        <?php csrf_field(); ?>
        <input type="hidden" name="action" value="update">

        <div class="prod-filters">
            <div class="dfms-field">
                <label class="dfms-label" for="quantity">
                    Quantity (<?php echo e($unitLabel); ?>)<span class="dfms-required" aria-hidden="true">*</span>
                </label>
                <input class="dfms-input" type="number" id="quantity" name="quantity"
                       required step="0.01" min="0.01" max="500" inputmode="decimal"
                       value="<?php echo e((string) round((float) $submitted['quantity'], 2)); ?>">
            </div>
            <div class="dfms-field">
                <label class="dfms-label" for="discarded">Discarded (<?php echo e($unitLabel); ?>)</label>
                <input class="dfms-input" type="number" id="discarded" name="discarded"
                       step="0.01" min="0" max="500" inputmode="decimal"
                       value="<?php echo e((string) round((float) $submitted['discarded'], 2)); ?>">
            </div>
        </div>

        <div class="dfms-field">
            <label class="dfms-label" for="abnormal" style="display:flex;align-items:flex-start;gap:.6rem;cursor:pointer;">
                <input type="checkbox" id="abnormal" name="abnormal" value="1"
                       style="width:1.15rem;height:1.15rem;margin-top:.15rem;flex:0 0 auto;"
                    <?php echo !empty($submitted['abnormal']) ? 'checked' : ''; ?>>
                <span>The milk looked abnormal</span>
            </label>
        </div>

        <div class="dfms-field">
            <label class="dfms-label" for="notes">Notes</label>
            <textarea class="dfms-textarea" id="notes" name="notes" rows="2" maxlength="500"><?php
                echo e((string) ($submitted['notes'] ?? '')); ?></textarea>
        </div>

        <div class="dfms-form-actions">
            <button type="submit" class="dfms-btn dfms-btn-primary">Save correction</button>
            <a class="dfms-btn dfms-btn-secondary"
               href="<?php echo BASE_URL; ?>modules/milk/?tab=daily&date=<?php
                   echo urlencode((string) $yield['production_date']); ?>">Cancel</a>
        </div>
    </form>
</div></div>

<?php if ($canDelete): ?>
    <div class="dfms-card" style="margin-top:var(--space-5);"><div class="dfms-card-body">
        <h2 style="font-size:1.05rem;margin-bottom:var(--space-2);">Remove this record</h2>
        <p class="dfms-text-muted" style="font-size:.9rem;margin-bottom:var(--space-4);">
            This permanently removes the reading. What was removed is kept in the audit log.
        </p>
        <form method="POST" autocomplete="off"
              onsubmit="return confirm('Remove this production record? This cannot be undone.');">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="delete">
            <button type="submit" class="dfms-btn dfms-btn-danger">Remove record</button>
        </form>
    </div></div>
<?php endif; ?>

</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
