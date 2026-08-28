<?php
// ============================================================================
// FEEDING — home.
//
// Phase 6 EVOLVES this page. The original schedule list is NOT deleted: it is
// the "Schedule" tab, still reading feeding_schedules, still served by add.php.
// Four real rows live in it and they keep their page.
//
// What is new is the CONSUMPTION tab — feed that was actually used, drawn from
// inventory through the same stock helper medication administration uses — and
// the USAGE tab, which totals it.
//
// Note what is deliberately absent: the legacy `feed_consumption` table holds
// farm-level daily totals with no item, and it is left exactly as it is. Its
// rows are not shown as if they were item-level events, and item-level events
// are not summed into it.
// ============================================================================

define('DFMS_FEEDING_PAGE', true);
require_once __DIR__ . '/_shared.php';

requireAuth('feeding.view');

$conn = getDBConnection();
$error_message = '';

$v = new DfmsValidator($_GET);
$tab = $v->enum('tab', ['consumption', 'usage', 'schedule'], ['default' => 'consumption']);
if ($v->fails()) {
    $tab = 'consumption';
}
$days = $v->integer('days', ['nullable' => true, 'min' => 7, 'max' => 90]) ?? 30;
if ($v->fails()) {
    $days = 30;
}

$windowFrom = farmDateOffset(-($days - 1));
$windowTo = farmToday();

$events = [];
$usage = [];
$schedules = [];
$totalUsed = 0.0;

try {
    if ($tab === 'consumption') {
        $events = feedConsumptionEvents($conn, ['from' => $windowFrom, 'to' => $windowTo], 200);
    } elseif ($tab === 'usage') {
        $usage = feedUsageByItem($conn, $windowFrom, $windowTo);
        foreach ($usage as $row) {
            $totalUsed += $row['total'];
        }
    } else {
        $schedules = tenantQuery($conn,
            "SELECT * FROM feeding_schedules WHERE {scope} ORDER BY feed_time");
    }
} catch (PDOException $e) {
    $error_message = safeError($e, 'Could not load feeding records');
}

$page_title = 'Feeding';
$page_breadcrumb = [['label' => 'Feeding']];
require_once __DIR__ . '/../../includes/header.php';

$canCreate = hasPermission('feeding.create');
?>
<?php feedScopedStyles(); ?>
<div class="dfms-feed-scope">

<?php
$actions = '';
if ($canCreate) {
    $actions = dfms_button_html('Record feed use', BASE_URL . 'modules/feeding/consume.php', 'primary')
        . ' ' . dfms_button_html('Rations', BASE_URL . 'modules/feeding/rations.php', 'secondary');
}
dfms_page_header(
    'Feeding',
    'Feed actually used, the rations that plan it, and the daily schedule.',
    $actions,
    'Operations'
);
?>

<?php dfms_render_flashes(); ?>
<?php if ($error_message !== ''): ?><?php dfms_alert($error_message, 'error'); ?><?php endif; ?>

<nav class="feed-tabs" aria-label="Feeding views">
    <?php
    $tabs = ['consumption' => 'Feed used', 'usage' => 'Usage by item', 'schedule' => 'Schedule'];
    foreach ($tabs as $key => $label):
        ?>
        <a class="feed-tab" href="<?php echo BASE_URL; ?>modules/feeding/?tab=<?php echo e($key); ?>"
           <?php echo $tab === $key ? 'aria-current="page"' : ''; ?>><?php echo e($label); ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($tab === 'consumption'): ?>
    <div class="feed-note">
        Every entry here took its quantity out of inventory stock when it was recorded.
        The stock movement is in the audit log.
    </div>

    <div class="dfms-card"><div class="dfms-card-body">
        <?php if ($events === []): ?>
            <?php dfms_empty_state(
                'No feed use recorded in the last ' . $days . ' days',
                'Recording feed use keeps stock levels honest and shows where feed is going.',
                $canCreate ? dfms_button_html('Record feed use', BASE_URL . 'modules/feeding/consume.php', 'primary') : '',
                'inbox'
            ); ?>
        <?php else: ?>
            <?php dfms_table_open([['label' => 'Date'], ['label' => 'Feed'], ['label' => 'Quantity'],
                ['label' => 'Fed to'], ['label' => 'Ration']]); ?>
            <?php foreach ($events as $event): ?>
                <tr>
                    <?php dfms_cell('Date', e(fmt_date($event['consumed_on']))); ?>
                    <?php dfms_cell('Feed', e($event['item_name'])); ?>
                    <?php dfms_cell('Quantity', e(fmt_measure((float) $event['quantity'], 'mass'))); ?>
                    <?php dfms_cell('Fed to', e(feedEventTargetLabel($event))); ?>
                    <?php dfms_cell('Ration', $event['ration_name'] !== null
                        ? e($event['ration_name']) : '—'); ?>
                </tr>
            <?php endforeach; ?>
            <?php dfms_table_close(); ?>
        <?php endif; ?>
    </div></div>

<?php elseif ($tab === 'usage'): ?>
    <form method="GET" class="feed-filters">
        <input type="hidden" name="tab" value="usage">
        <div class="dfms-field">
            <label class="dfms-label" for="days">Window</label>
            <select class="dfms-select" id="days" name="days">
                <?php foreach ([7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'] as $value => $label): ?>
                    <option value="<?php echo (int) $value; ?>" <?php echo $days === $value ? 'selected' : ''; ?>>
                        <?php echo e($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="dfms-field" style="flex:0 0 auto;">
            <button type="submit" class="dfms-btn dfms-btn-secondary">Apply</button>
        </div>
    </form>

    <div class="feed-metrics">
        <?php echo dfms_metric_html('Feed used', $totalUsed > 0 ? fmt_measure($totalUsed, 'mass') : '—',
            '', '<span class="dfms-text-muted">recorded events only</span>'); ?>
        <?php echo dfms_metric_html('Items used', (string) count($usage)); ?>
    </div>

    <div class="dfms-card"><div class="dfms-card-body">
        <?php if ($usage === []): ?>
            <?php dfms_empty_state('Nothing recorded in this window',
                'Items with no recorded use are not listed — that is different from none being used.',
                '', 'inbox'); ?>
        <?php else: ?>
            <?php dfms_table_open([['label' => 'Feed'], ['label' => 'Total used'], ['label' => 'Entries']]); ?>
            <?php foreach ($usage as $row): ?>
                <tr>
                    <?php dfms_cell('Feed', e($row['item_name'])); ?>
                    <?php dfms_cell('Total used', e(fmt_measure($row['total'], 'mass'))); ?>
                    <?php dfms_cell('Entries', (int) $row['events']); ?>
                </tr>
            <?php endforeach; ?>
            <?php dfms_table_close(); ?>
        <?php endif; ?>
    </div></div>

<?php else: ?>
    <div class="feed-note">
        The daily feeding schedule, kept exactly as it was. A schedule is a routine —
        it does not move stock. Record what was actually fed under "Feed used".
    </div>

    <div class="dfms-card"><div class="dfms-card-body">
        <?php if ($schedules === []): ?>
            <?php dfms_empty_state('No feeding schedule set',
                'Add the times feed is given so everyone works to the same routine.',
                $canCreate ? dfms_button_html('Add schedule', BASE_URL . 'modules/feeding/add.php', 'primary') : '',
                'inbox'
            ); ?>
        <?php else: ?>
            <?php dfms_table_open([['label' => 'Time'], ['label' => 'Feed type'], ['label' => 'Amount']]); ?>
            <?php foreach ($schedules as $schedule): ?>
                <tr>
                    <?php dfms_cell('Time', e(substr((string) $schedule['feed_time'], 0, 5))); ?>
                    <?php dfms_cell('Feed type', e((string) ($schedule['feed_type'] ?? '—'))); ?>
                    <?php dfms_cell('Amount', $schedule['feed_amount'] !== null
                        ? e(fmt_measure((float) $schedule['feed_amount'], 'mass')) : '—'); ?>
                </tr>
            <?php endforeach; ?>
            <?php dfms_table_close(); ?>
            <?php if ($canCreate): ?>
                <div style="margin-top:var(--space-4);">
                    <?php echo dfms_button_html('Add schedule', BASE_URL . 'modules/feeding/add.php', 'secondary'); ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div></div>
<?php endif; ?>

</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
