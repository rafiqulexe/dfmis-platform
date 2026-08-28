<?php
// ============================================================================
// RATIONS — reusable feed plans.
//
// A RATION IS A PLAN. Nothing on this page consumes inventory, and nothing on
// it can be made to: the only write paths are feedCreateRation(),
// feedUpdateRation(), feedSetRationItem() and feedRemoveRationItem(), none of
// which touch inventory_levels.
//
// The one link to consumption is "Record this feeding", which PREFILLS the
// consumption form. A person still confirms what was actually fed.
// ============================================================================

define('DFMS_FEEDING_PAGE', true);
require_once __DIR__ . '/_shared.php';

requireAuth('feeding.view');

$conn = getDBConnection();
$error = '';

$canManage = hasPermission('feeding.create');

$selected = null;
$lines = [];

// An id is OPTIONAL here (no id means the list view), but a MALFORMED one is
// a 404 — not a silent fallback to the list. Treating 'abc' as "no id given"
// would make ?id=abc and ?id=999999 behave differently, and would be the only
// place in the codebase where a bad route id does not 404.
if (array_key_exists('id', $_GET) && $_GET['id'] !== '') {
    $v = new DfmsValidator($_GET);
    $selectedId = $v->id('id');
    if ($v->fails() || $selectedId === null) {
        dfms_abort(404, 'feed_rations: malformed id');
    }
    // Missing and foreign are indistinguishable, per the codebase convention.
    $selected = feedFindRation($conn, $selectedId);
    if ($selected === null) {
        dfms_abort(404, 'feed_rations miss for farm ' . (currentFarmId() ?? '?'));
    }
    $lines = feedRationItems($conn, (int) $selected['ration_id']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    // Every mutation on this page needs feeding.create, checked again here
    // rather than relying on the page-level feeding.view gate.
    requirePermission('feeding.create');

    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'create') {
            $fv = new DfmsValidator($_POST);
            $name = $fv->string('name', 1, 120);
            $purpose = $fv->string('purpose', 0, 300, ['nullable' => true]);
            if ($fv->fails()) {
                throw new FeedRuleViolation((string) $fv->firstError());
            }
            $id = feedCreateRation($conn, ['name' => $name, 'purpose' => $purpose]);
            redirectWith('modules/feeding/rations.php?id=' . $id, 'success', 'Ration created.');

        } elseif ($action === 'update' && $selected !== null) {
            $fv = new DfmsValidator($_POST);
            $name = $fv->string('name', 1, 120);
            $purpose = $fv->string('purpose', 0, 300, ['nullable' => true]);
            if ($fv->fails()) {
                throw new FeedRuleViolation((string) $fv->firstError());
            }
            feedUpdateRation($conn, (int) $selected['ration_id'], [
                'name' => $name, 'purpose' => $purpose,
                'is_active' => !empty($_POST['is_active']),
            ]);
            redirectWith('modules/feeding/rations.php?id=' . (int) $selected['ration_id'],
                'success', 'Ration updated.');

        } elseif ($action === 'add_item' && $selected !== null) {
            $fv = new DfmsValidator($_POST);
            $itemId = $fv->id('item_id');
            $quantity = $fv->decimal('quantity', ['min' => 0, 'max' => 1000000]);
            if ($fv->fails()) {
                throw new FeedRuleViolation((string) $fv->firstError());
            }
            $displayUnit = dfms_display_unit('mass', currentMeasurementSystem());
            feedSetRationItem($conn, (int) $selected['ration_id'], $itemId,
                dfms_to_canonical($quantity, 'mass', $displayUnit));
            redirectWith('modules/feeding/rations.php?id=' . (int) $selected['ration_id'],
                'success', 'Ration line saved.');

        } elseif ($action === 'remove_item' && $selected !== null) {
            $fv = new DfmsValidator($_POST);
            $lineId = $fv->id('ration_item_id');
            if ($fv->fails()) {
                throw new FeedRuleViolation((string) $fv->firstError());
            }
            feedRemoveRationItem($conn, $lineId);
            redirectWith('modules/feeding/rations.php?id=' . (int) $selected['ration_id'],
                'success', 'Ration line removed.');
        }
    } catch (FeedRuleViolation $e) {
        $error = $e->getMessage();
    } catch (PDOException $e) {
        $error = safeError($e, 'Could not save the ration');
    }
}

$rations = feedRations($conn);
$feedItems = inventoryFeedItems($conn);

$page_title = 'Rations';
$page_breadcrumb = [['label' => 'Feeding', 'url' => 'index.php'], ['label' => 'Rations']];
require_once __DIR__ . '/../../includes/header.php';

$displayUnit = dfms_display_unit('mass', currentMeasurementSystem());
$unitLabel = dfms_unit_label($displayUnit);
?>
<?php feedScopedStyles(); ?>
<div class="dfms-feed-scope">

<?php
dfms_page_header(
    'Rations',
    'Reusable feed plans. Creating or editing a ration never changes stock.',
    dfms_button_html('Back to feeding', BASE_URL . 'modules/feeding/', 'secondary'),
    'Feeding'
);
?>

<?php dfms_render_flashes(); ?>
<?php if ($error !== ''): ?><?php dfms_alert($error, 'error'); ?><?php endif; ?>

<div class="feed-note">
    A ration is a <strong>plan</strong>, not a record of feeding. Stock only moves when you
    record feed use, and only for the quantity you confirm there.
</div>

<div class="dfms-card"><div class="dfms-card-body">
    <h2 style="font-size:1.05rem;margin-bottom:var(--space-3);">Rations on this farm</h2>
    <?php if ($rations === []): ?>
        <?php dfms_empty_state('No rations yet', 'Create one below to reuse a feeding plan.', '', 'inbox'); ?>
    <?php else: ?>
        <?php dfms_table_open([['label' => 'Name'], ['label' => 'Purpose'],
            ['label' => 'Items'], ['label' => 'Status'], ['label' => '']]); ?>
        <?php foreach ($rations as $ration): ?>
            <tr>
                <?php dfms_cell('Name', '<a href="' . BASE_URL . 'modules/feeding/rations.php?id='
                    . (int) $ration['ration_id'] . '">' . e($ration['name']) . '</a>'); ?>
                <?php dfms_cell('Purpose', $ration['purpose'] !== null ? e($ration['purpose']) : '—'); ?>
                <?php dfms_cell('Items', (int) $ration['item_count']); ?>
                <?php dfms_cell('Status', !empty($ration['is_active'])
                    ? dfms_badge_html('Active', 'success') : dfms_badge_html('Retired', 'neutral')); ?>
                <?php dfms_cell('', hasPermission('feeding.create')
                    ? '<a href="' . BASE_URL . 'modules/feeding/consume.php?ration_id='
                        . (int) $ration['ration_id'] . '">Record this feeding</a>'
                    : ''); ?>
            </tr>
        <?php endforeach; ?>
        <?php dfms_table_close(); ?>
    <?php endif; ?>
</div></div>

<?php if ($selected !== null): ?>
    <!-- ===================== ONE RATION ===================== -->
    <div class="dfms-card" style="margin-top:var(--space-5);"><div class="dfms-card-body">
        <h2 style="font-size:1.05rem;margin-bottom:var(--space-3);"><?php echo e($selected['name']); ?></h2>

        <?php if ($lines === []): ?>
            <?php dfms_empty_state('No items in this ration',
                'Add the feeds this ration is made up of.', '', 'inbox'); ?>
        <?php else: ?>
            <?php dfms_table_open([['label' => 'Feed'], ['label' => 'Per animal per day'],
                ['label' => 'In stock'], ['label' => '']]); ?>
            <?php foreach ($lines as $line): ?>
                <tr>
                    <?php dfms_cell('Feed', e($line['item_name'])); ?>
                    <?php dfms_cell('Per animal per day', e(fmt_measure((float) $line['quantity'], 'mass'))); ?>
                    <?php dfms_cell('In stock', e(fmt_measure((float) $line['stock_quantity'], 'mass'))); ?>
                    <?php if ($canManage): ?>
                        <td data-label="">
                            <form method="POST" autocomplete="off" style="display:inline;"
                                  onsubmit="return confirm('Remove this feed from the ration?');">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="remove_item">
                                <input type="hidden" name="ration_item_id"
                                       value="<?php echo (int) $line['ration_item_id']; ?>">
                                <button type="submit" class="dfms-btn dfms-btn-secondary"
                                        style="padding:.3rem .6rem;font-size:.82rem;">Remove</button>
                            </form>
                        </td>
                    <?php else: ?>
                        <?php dfms_cell('', ''); ?>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php dfms_table_close(); ?>
        <?php endif; ?>

        <?php if ($canManage): ?>
            <h3 style="font-size:.95rem;margin:var(--space-5) 0 var(--space-3);">Add or update a feed</h3>
            <?php if ($feedItems === []): ?>
                <p class="dfms-text-muted" style="font-size:.9rem;">
                    No inventory items are categorised as Feed or Supplement yet.
                </p>
            <?php else: ?>
                <form method="POST" autocomplete="off" novalidate>
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="add_item">
                    <div class="feed-filters">
                        <div class="dfms-field">
                            <label class="dfms-label" for="item_id">Feed<span class="dfms-required" aria-hidden="true">*</span></label>
                            <select class="dfms-select" id="item_id" name="item_id" required>
                                <option value="">Choose…</option>
                                <?php foreach ($feedItems as $item): ?>
                                    <option value="<?php echo (int) $item['item_id']; ?>">
                                        <?php echo e($item['item_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="dfms-field">
                            <label class="dfms-label" for="quantity">
                                Per animal per day (<?php echo e($unitLabel); ?>)<span class="dfms-required" aria-hidden="true">*</span>
                            </label>
                            <input class="dfms-input" type="number" id="quantity" name="quantity"
                                   required step="0.01" min="0.01" inputmode="decimal" placeholder="e.g. 8">
                        </div>
                        <div class="dfms-field" style="flex:0 0 auto;">
                            <button type="submit" class="dfms-btn dfms-btn-primary">Save feed</button>
                        </div>
                    </div>
                    <p class="dfms-hint">Adding a feed already in the ration updates its quantity.</p>
                </form>
            <?php endif; ?>

            <h3 style="font-size:.95rem;margin:var(--space-5) 0 var(--space-3);">Ration details</h3>
            <form method="POST" autocomplete="off" novalidate>
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="update">
                <div class="dfms-field">
                    <label class="dfms-label" for="name">Name<span class="dfms-required" aria-hidden="true">*</span></label>
                    <input class="dfms-input" type="text" id="name" name="name" required maxlength="120"
                           value="<?php echo e($selected['name']); ?>">
                </div>
                <div class="dfms-field">
                    <label class="dfms-label" for="purpose">Purpose</label>
                    <input class="dfms-input" type="text" id="purpose" name="purpose" maxlength="300"
                           placeholder="e.g. Lactating cows in early lactation…"
                           value="<?php echo e((string) ($selected['purpose'] ?? '')); ?>">
                </div>
                <div class="dfms-field">
                    <label class="dfms-label" for="is_active" style="display:flex;align-items:flex-start;gap:.6rem;cursor:pointer;">
                        <input type="checkbox" id="is_active" name="is_active" value="1"
                               style="width:1.15rem;height:1.15rem;margin-top:.15rem;flex:0 0 auto;"
                               aria-describedby="is_active-hint"
                            <?php echo !empty($selected['is_active']) ? 'checked' : ''; ?>>
                        <span>Active</span>
                    </label>
                    <p class="dfms-hint" id="is_active-hint">
                        Retiring a ration hides it from the feeding form. It is never deleted, because
                        past consumption events refer to it.
                    </p>
                </div>
                <div class="dfms-form-actions">
                    <button type="submit" class="dfms-btn dfms-btn-primary">Save ration</button>
                </div>
            </form>
        <?php endif; ?>
    </div></div>
<?php endif; ?>

<?php if ($canManage): ?>
    <div class="dfms-card" style="margin-top:var(--space-5);"><div class="dfms-card-body">
        <h2 style="font-size:1.05rem;margin-bottom:var(--space-3);">Create a ration</h2>
        <form method="POST" autocomplete="off" novalidate>
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="create">
            <div class="feed-filters">
                <div class="dfms-field">
                    <label class="dfms-label" for="new_name">Name<span class="dfms-required" aria-hidden="true">*</span></label>
                    <input class="dfms-input" type="text" id="new_name" name="name" required maxlength="120"
                           placeholder="e.g. Lactating cow ration…">
                </div>
                <div class="dfms-field">
                    <label class="dfms-label" for="new_purpose">Purpose</label>
                    <input class="dfms-input" type="text" id="new_purpose" name="purpose" maxlength="300"
                           placeholder="Who it is for…">
                </div>
                <div class="dfms-field" style="flex:0 0 auto;">
                    <button type="submit" class="dfms-btn dfms-btn-primary">Create ration</button>
                </div>
            </div>
        </form>
    </div></div>
<?php endif; ?>

</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
