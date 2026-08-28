<?php
// ============================================================================
// RECORD FEED CONSUMPTION — the event that draws down stock.
//
// This is the ONLY screen in the feeding module that changes inventory, and it
// does so through includes/feed.php -> includes/inventory.php, the same helper
// medication administration uses. Nothing here touches inventory_levels
// directly and nothing here computes a stock rule of its own.
//
// A ration may PREFILL this form. It never submits it: a plan is what should
// happen, and only a person can say what did.
// ============================================================================

define('DFMS_FEEDING_PAGE', true);
require_once __DIR__ . '/_shared.php';

// feeding.create, not inventory.update. The two permissions have IDENTICAL
// role lists (farm_manager, staff), so this grants nothing extra — see the
// note in includes/permissions.php.
requireAuth('feeding.create');

$conn = getDBConnection();
$error = '';
$success = '';

$items = inventoryFeedItems($conn);
$locations = feedSelectableLocations($conn);
$animals = feedSelectableAnimals($conn);
$rations = feedRations($conn, true);

$submitted = [];
$defaultDate = farmToday();

// Optional prefill from a ration. Read-only: it fills fields, nothing else.
$gv = new DfmsValidator($_GET);
$prefillRationId = $gv->id('ration_id', ['nullable' => true]);
$prefillAnimals = $gv->decimal('animals', ['nullable' => true, 'min' => 0, 'max' => 100000]);
$projection = [];
if ($prefillRationId !== null) {
    $ration = feedFindRation($conn, $prefillRationId);
    if ($ration !== null) {
        $projection = feedRationProjection($conn, (int) $ration['ration_id'], (float) ($prefillAnimals ?? 1));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $submitted = $_POST;

    $v = new DfmsValidator($_POST);
    $itemId = $v->id('item_id');
    $date = $v->date('consumed_on');
    $quantity = $v->decimal('quantity', ['min' => 0, 'max' => 1000000]);
    $scopeType = $v->enum('scope_type', array_keys(DFMS_FEED_SCOPES));
    $locationId = $v->id('location_id', ['nullable' => true]);
    $cowId = $v->id('cow_id', ['nullable' => true]);
    $rationId = $v->id('ration_id', ['nullable' => true]);
    $notes = $v->string('notes', 0, 500, ['nullable' => true]);

    if ($v->fails()) {
        $error = $v->firstError();
    } else {
        try {
            $displayUnit = dfms_display_unit('mass', currentMeasurementSystem());

            feedRecordConsumption($conn, [
                'consumed_on' => $date,
                'item_id'     => $itemId,
                // Typed in the operator's unit, stored canonical (kg).
                'quantity'    => dfms_to_canonical($quantity, 'mass', $displayUnit),
                'scope_type'  => $scopeType,
                'location_id' => $locationId,
                'cow_id'      => $cowId,
                'ration_id'   => $rationId,
                'notes'       => $notes,
            ]);

            redirectWith('modules/feeding/?tab=consumption', 'success',
                'Feed consumption recorded and taken out of stock.');
        } catch (FeedRuleViolation $e) {
            // Our own domain messages — safe verbatim, and they are the ones
            // that say "not enough stock", which the operator needs to read.
            $error = $e->getMessage();
        } catch (PDOException $e) {
            $error = safeError($e, 'Could not record the consumption');
        }
    }
}

$page_title = 'Record feed use';
$page_breadcrumb = [['label' => 'Feeding', 'url' => 'index.php'], ['label' => 'Record feed use']];
require_once __DIR__ . '/../../includes/header.php';

$displayUnit = dfms_display_unit('mass', currentMeasurementSystem());
$unitLabel = dfms_unit_label($displayUnit);
?>
<?php feedScopedStyles(); ?>
<div class="dfms-feed-scope">

<?php
dfms_page_header(
    'Record feed use',
    'What was actually fed. This takes the quantity out of inventory stock.',
    dfms_button_html('Back to feeding', BASE_URL . 'modules/feeding/', 'secondary'),
    'Feeding'
);
?>

<?php dfms_render_flashes(); ?>
<?php if ($error !== ''): ?><?php dfms_alert($error, 'error'); ?><?php endif; ?>

<?php if ($items === []): ?>
    <div class="dfms-card"><div class="dfms-card-body">
        <?php dfms_empty_state(
            'No feed items in inventory',
            'Add an inventory item categorised as Feed or Supplement before recording consumption.',
            hasPermission('inventory.create')
                ? dfms_button_html('Add inventory item', BASE_URL . 'modules/inventory/add.php', 'primary') : '',
            'inbox'
        ); ?>
    </div></div>
<?php else: ?>

<?php if ($projection !== []): ?>
    <div class="feed-note">
        <strong>Prefilled from a ration.</strong>
        This is what the plan calls for. Nothing has been consumed yet — check the figures
        and record each item that was actually fed.
        <ul style="margin:var(--space-2) 0 0;padding-left:1.2rem;">
            <?php foreach ($projection as $line): ?>
                <li>
                    <?php echo e($line['item_name']); ?>:
                    <?php echo e(fmt_measure($line['required'], 'mass')); ?> needed,
                    <?php if (!$line['stock_known']): ?>
                        <span class="dfms-text-muted">stock level not recorded</span>
                    <?php else: ?>
                        <?php echo e(fmt_measure($line['stock'], 'mass')); ?> in stock<?php
                            echo $line['sufficient'] ? '' : ' — not enough'; ?>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="dfms-card"><div class="dfms-card-body">
    <form method="POST" autocomplete="off" novalidate>
        <?php csrf_field(); ?>

        <div class="feed-filters">
            <div class="dfms-field">
                <label class="dfms-label" for="consumed_on">
                    Date<span class="dfms-required" aria-hidden="true">*</span>
                </label>
                <input class="dfms-input" type="date" id="consumed_on" name="consumed_on"
                       required max="<?php echo e(farmToday()); ?>"
                       value="<?php echo e($submitted['consumed_on'] ?? $defaultDate); ?>">
            </div>

            <div class="dfms-field">
                <label class="dfms-label" for="quantity">
                    Quantity (<?php echo e($unitLabel); ?>)<span class="dfms-required" aria-hidden="true">*</span>
                </label>
                <input class="dfms-input" type="number" id="quantity" name="quantity"
                       required step="0.01" min="0.01" inputmode="decimal" placeholder="e.g. 240"
                       value="<?php echo e($submitted['quantity'] ?? ''); ?>">
            </div>
        </div>

        <div class="dfms-field">
            <label class="dfms-label" for="item_id">
                Feed item<span class="dfms-required" aria-hidden="true">*</span>
            </label>
            <select class="dfms-select" id="item_id" name="item_id" required aria-describedby="item-hint">
                <option value="">Choose a feed item…</option>
                <?php foreach ($items as $item): ?>
                    <option value="<?php echo (int) $item['item_id']; ?>"
                        <?php echo (string) ($submitted['item_id'] ?? '') === (string) $item['item_id'] ? 'selected' : ''; ?>>
                        <?php
                        echo e($item['item_name']);
                        // "not counted" and "none left" are different facts and
                        // the operator is told which one they are looking at.
                        echo !empty($item['stock_unknown'])
                            ? ' (stock not recorded)'
                            : ' (' . e(fmt_measure((float) $item['quantity'], 'mass')) . ' in stock)';
                        ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="dfms-hint" id="item-hint">
                Only items categorised as Feed or Supplement appear. Recording is refused if
                there is not enough stock.
            </p>
        </div>

        <div class="dfms-field">
            <label class="dfms-label" for="scope_type">
                Fed to<span class="dfms-required" aria-hidden="true">*</span>
            </label>
            <select class="dfms-select" id="scope_type" name="scope_type" required>
                <?php foreach (DFMS_FEED_SCOPES as $key => $label): ?>
                    <option value="<?php echo e($key); ?>"
                        <?php echo ($submitted['scope_type'] ?? 'farm') === $key ? 'selected' : ''; ?>>
                        <?php echo e($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="feed-filters">
            <div class="dfms-field">
                <label class="dfms-label" for="location_id">Location</label>
                <select class="dfms-select" id="location_id" name="location_id"
                        aria-describedby="target-hint">
                    <option value="">—</option>
                    <?php foreach ($locations as $location): ?>
                        <option value="<?php echo (int) $location['location_id']; ?>"
                            <?php echo (string) ($submitted['location_id'] ?? '') === (string) $location['location_id'] ? 'selected' : ''; ?>>
                            <?php echo e($location['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="dfms-field">
                <label class="dfms-label" for="cow_id">Animal</label>
                <select class="dfms-select" id="cow_id" name="cow_id" aria-describedby="target-hint">
                    <option value="">—</option>
                    <?php foreach ($animals as $animal): ?>
                        <option value="<?php echo (int) $animal['cow_id']; ?>"
                            <?php echo (string) ($submitted['cow_id'] ?? '') === (string) $animal['cow_id'] ? 'selected' : ''; ?>>
                            <?php echo e($animal['unique_id'] . ($animal['name'] ? ' — ' . $animal['name'] : '')); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <p class="dfms-hint" id="target-hint">
            Fill in only the one matching "Fed to". A whole-farm entry needs neither.
        </p>

        <?php if ($rations !== []): ?>
            <div class="dfms-field">
                <label class="dfms-label" for="ration_id">From ration (optional)</label>
                <select class="dfms-select" id="ration_id" name="ration_id">
                    <option value="">—</option>
                    <?php foreach ($rations as $ration): ?>
                        <option value="<?php echo (int) $ration['ration_id']; ?>"
                            <?php echo (string) ($submitted['ration_id'] ?? $prefillRationId ?? '') === (string) $ration['ration_id'] ? 'selected' : ''; ?>>
                            <?php echo e($ration['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <div class="dfms-field">
            <label class="dfms-label" for="notes">Notes</label>
            <textarea class="dfms-textarea" id="notes" name="notes" rows="2" maxlength="500"><?php
                echo e($submitted['notes'] ?? ''); ?></textarea>
        </div>

        <div class="dfms-form-actions">
            <button type="submit" class="dfms-btn dfms-btn-primary">Record and deduct stock</button>
            <a class="dfms-btn dfms-btn-secondary" href="<?php echo BASE_URL; ?>modules/feeding/">Cancel</a>
        </div>
    </form>
</div></div>

<?php endif; ?>

</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
