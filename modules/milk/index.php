<?php
// ============================================================================
// MILK PRODUCTION — home.
//
// Phase 6 EVOLVES this page rather than adding a parallel "production" module:
// the navigation entry, the URL and the milk.view permission all already point
// here, and a second module would have split one subject across two places.
//
// The ORIGINAL farm-level daily list is NOT deleted. It is the "Farm totals"
// tab, still reading milk_production, still paginated the same way, still
// served by add.php/edit.php. Thirty real rows live in it and they keep their
// page — exactly the treatment Phase 4 gave the old health event log.
//
// Server-rendered tabs (?tab=), like the health module and the animal profile:
// every view is a plain URL that is linkable, bookmarkable, and works without
// JavaScript.
//
// THE RULE THIS PAGE EXISTS TO RESPECT
// A farm daily total and a sum of per-animal readings are DIFFERENT
// MEASUREMENTS. They are shown side by side, labelled, and never reconciled
// into a single "production" number, because doing so would state something
// nobody measured.
// ============================================================================

define('DFMS_MILK_PAGE', true);
require_once __DIR__ . '/_shared.php';

requireAuth('milk.view');

$conn = getDBConnection();
$error_message = '';

$v = new DfmsValidator($_GET);
$tab = $v->enum('tab', ['daily', 'animals', 'farm'], ['default' => 'daily']);
if ($v->fails()) {
    $tab = 'daily';
}

// A malformed date is treated as no date rather than an error page, matching
// how the health module handles a malformed filter.
$dateFilter = $v->date('date', ['nullable' => true]) ?? farmToday();
$days = $v->integer('days', ['nullable' => true, 'min' => 7, 'max' => 90]) ?? 30;
$page = $v->integer('page', ['nullable' => true, 'min' => 1, 'max' => 100000]) ?? 1;
if ($v->fails()) {
    $dateFilter = farmToday();
    $days = 30;
    $page = 1;
}

$windowFrom = farmDateOffset(-($days - 1));
$windowTo = farmToday();

$summary = ['recorded' => [], 'farm_aggregate' => []];
$dayYields = [];
$animalTotals = [];
$farmRows = [];
$totalPages = 1;
$totalRecords = 0;
$perPage = 50;

try {
    $summary = productionSummary($conn, $windowFrom, $windowTo);

    if ($tab === 'daily') {
        $dayYields = productionYieldsOn($conn, $dateFilter);
    } elseif ($tab === 'animals') {
        $animalTotals = productionAnimalTotals($conn, $windowFrom, $windowTo);
    } else {
        // The legacy farm-level list, unchanged in behaviour.
        $totalRecords = (int) tenantScalar($conn, "SELECT COUNT(*) FROM milk_production WHERE {scope}");
        $totalPages = max(1, (int) ceil($totalRecords / $perPage));
        $page = min($page, $totalPages);
        $farmRows = tenantQuery(
            $conn,
            "SELECT * FROM milk_production WHERE {scope} ORDER BY production_date DESC
             LIMIT " . (int) $perPage . " OFFSET " . (int) (($page - 1) * $perPage)
        );
    }
} catch (PDOException $e) {
    $error_message = safeError($e, 'Could not load production records');
}

$page_title = 'Milk Production';
$page_breadcrumb = [['label' => 'Milk Production']];
require_once __DIR__ . '/../../includes/header.php';

$canCreate = hasPermission('milk.create');
$canEdit = hasPermission('milk.edit');
$recorded = $summary['recorded'];
$aggregate = $summary['farm_aggregate'];
?>
<?php productionScopedStyles(); ?>
<div class="dfms-production-scope">

<?php
$actions = '';
if ($canCreate) {
    $actions = dfms_button_html('Record a milking', BASE_URL . 'modules/milk/record.php', 'primary')
        . ' ' . dfms_button_html('Add farm daily total', BASE_URL . 'modules/milk/add.php', 'secondary');
}
dfms_page_header(
    'Milk Production',
    'Per-animal milkings and farm daily totals, recorded separately.',
    $actions,
    'Production'
);
?>

<?php dfms_render_flashes(); ?>
<?php if ($error_message !== ''): ?><?php dfms_alert($error_message, 'error'); ?><?php endif; ?>

<!-- ===================== SUMMARY ===================== -->
<div class="prod-metrics">
    <?php
    // Every metric below is labelled with WHICH source it came from. A reader
    // must never have to guess whether a number is a bulk-tank figure or a sum
    // of individual readings.
    echo dfms_metric_html(
        'Recorded (last ' . (int) $days . ' days)',
        $recorded['total'] > 0 ? fmt_measure($recorded['total'], 'volume') : '—',
        '',
        '<span class="dfms-text-muted">from ' . (int) $recorded['entries'] . ' per-animal entries</span>'
    );
    echo dfms_metric_html(
        'Saleable',
        $recorded['total'] > 0 ? fmt_measure($recorded['saleable'], 'volume') : '—',
        '',
        '<span class="dfms-text-muted">excludes discarded and withdrawal-restricted milk</span>'
    );
    echo dfms_metric_html(
        'Average per recorded day',
        $recorded['average_per_day'] !== null ? fmt_measure($recorded['average_per_day'], 'volume') : '—',
        '',
        '<span class="dfms-text-muted">over ' . (int) $recorded['days_with_data'] . ' day(s) with data</span>'
    );
    echo dfms_metric_html(
        'Farm daily totals',
        $aggregate['total'] !== null ? fmt_measure($aggregate['total'], 'volume') : '—',
        '',
        '<span class="dfms-text-muted">' . (int) $aggregate['days_with_data'] . ' day(s) recorded at farm level</span>'
    );
    ?>
</div>

<div class="prod-note"><?php echo e(productionSourceNote()); ?></div>

<!-- ===================== TABS ===================== -->
<nav class="prod-tabs" aria-label="Production views">
    <?php
    $tabs = [
        'daily'   => 'By day',
        'animals' => 'By animal',
        'farm'    => 'Farm totals',
    ];
    foreach ($tabs as $key => $label):
        $isActive = $tab === $key;
        ?>
        <a class="prod-tab" href="<?php echo BASE_URL; ?>modules/milk/?tab=<?php echo e($key); ?>"
           <?php echo $isActive ? 'aria-current="page"' : ''; ?>><?php echo e($label); ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($tab === 'daily'): ?>
    <!-- ===================== ONE DAY, PER ANIMAL ===================== -->
    <form method="GET" class="prod-filters">
        <input type="hidden" name="tab" value="daily">
        <div class="dfms-field">
            <label class="dfms-label" for="date">Date</label>
            <input class="dfms-input" type="date" id="date" name="date"
                   max="<?php echo e(farmToday()); ?>" value="<?php echo e($dateFilter); ?>">
        </div>
        <div class="dfms-field" style="flex:0 0 auto;">
            <button type="submit" class="dfms-btn dfms-btn-secondary">Show</button>
        </div>
    </form>

    <div class="dfms-card"><div class="dfms-card-body">
        <?php if ($dayYields === []): ?>
            <?php // dfms_empty_state() escapes its own title; passing e() would double-escape. ?>
            <?php dfms_empty_state(
                'Nothing recorded for ' . $dateFilter,
                'No per-animal milkings were recorded on this date. That is not the same as the herd producing nothing.',
                $canCreate ? dfms_button_html('Record a milking', BASE_URL . 'modules/milk/record.php', 'primary') : '',
                'inbox'
            ); ?>
        <?php else: ?>
            <?php dfms_table_open([
                ['label' => 'Animal'], ['label' => 'Milking'], ['label' => 'Quantity'],
                ['label' => 'Discarded'], ['label' => 'Status'], ['label' => ''],
            ]); ?>
            <?php foreach ($dayYields as $row): ?>
                <tr>
                    <?php dfms_cell('Animal', '<a href="' . BASE_URL . 'modules/cows/view.php?id='
                        . (int) $row['cow_id'] . '&tab=production">'
                        . e($row['unique_id'] . ($row['name'] ? ' — ' . $row['name'] : '')) . '</a>'); ?>
                    <?php dfms_cell('Milking', e(dfms_milking_session_label($row['session']))); ?>
                    <?php dfms_cell('Quantity', e(fmt_measure((float) $row['quantity'], 'volume'))); ?>
                    <?php dfms_cell('Discarded', (float) $row['discarded'] > 0
                        ? e(fmt_measure((float) $row['discarded'], 'volume')) : '—'); ?>
                    <?php
                    $badges = [];
                    if (!empty($row['restricted'])) {
                        $badges[] = dfms_badge_html('Withdrawal', 'warning');
                    }
                    if (!empty($row['abnormal'])) {
                        $badges[] = dfms_badge_html('Abnormal', 'warning');
                    }
                    dfms_cell('Status', $badges === [] ? dfms_badge_html('Normal', 'success') : implode(' ', $badges));
                    ?>
                    <?php dfms_cell('', $canEdit
                        ? '<a href="' . BASE_URL . 'modules/milk/yield.php?id=' . (int) $row['yield_id'] . '">Edit</a>'
                        : ''); ?>
                </tr>
            <?php endforeach; ?>
            <?php dfms_table_close(); ?>
        <?php endif; ?>
    </div></div>

<?php elseif ($tab === 'animals'): ?>
    <!-- ===================== PER ANIMAL, OVER A WINDOW ===================== -->
    <form method="GET" class="prod-filters">
        <input type="hidden" name="tab" value="animals">
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

    <div class="dfms-card"><div class="dfms-card-body">
        <?php if ($animalTotals === []): ?>
            <?php dfms_empty_state(
                'No per-animal production in this window',
                'Record a milking to start building each animal\'s history.',
                $canCreate ? dfms_button_html('Record a milking', BASE_URL . 'modules/milk/record.php', 'primary') : '',
                'inbox'
            ); ?>
        <?php else: ?>
            <?php dfms_table_open([
                ['label' => 'Animal'], ['label' => 'Total'], ['label' => 'Days recorded'],
                ['label' => 'Average per recorded day'], ['label' => 'Discarded'],
            ]); ?>
            <?php foreach ($animalTotals as $row): ?>
                <tr>
                    <?php dfms_cell('Animal', '<a href="' . BASE_URL . 'modules/cows/view.php?id='
                        . (int) $row['cow_id'] . '&tab=production">'
                        . e($row['unique_id'] . ($row['name'] ? ' — ' . $row['name'] : '')) . '</a>'); ?>
                    <?php dfms_cell('Total', e(fmt_measure($row['total'], 'volume'))); ?>
                    <?php
                    // Days recorded is shown NEXT TO the average on purpose:
                    // an average over 3 of 30 days is a recording gap, not a
                    // low-yielding animal, and the reader can see which.
                    dfms_cell('Days recorded', (int) $row['days_recorded'] . ' of ' . (int) $days);
                    ?>
                    <?php dfms_cell('Average per recorded day', e(fmt_measure($row['average_per_day'], 'volume'))); ?>
                    <?php dfms_cell('Discarded', $row['discarded'] > 0
                        ? e(fmt_measure($row['discarded'], 'volume')) : '—'); ?>
                </tr>
            <?php endforeach; ?>
            <?php dfms_table_close(); ?>
        <?php endif; ?>
    </div></div>

<?php else: ?>
    <!-- ===================== FARM DAILY TOTALS (legacy, preserved) ========= -->
    <div class="prod-note">
        These are whole-farm daily totals, recorded against the farm rather than against any
        animal. They are kept exactly as they were recorded and are never divided among animals.
    </div>

    <div class="dfms-card"><div class="dfms-card-body">
        <?php if ($farmRows === []): ?>
            <?php dfms_empty_state(
                'No farm daily totals recorded',
                'Record the bulk tank figure for a day to start a farm-level history.',
                $canCreate ? dfms_button_html('Add farm daily total', BASE_URL . 'modules/milk/add.php', 'primary') : '',
                'inbox'
            ); ?>
        <?php else: ?>
            <?php dfms_table_open([
                ['label' => 'Date'], ['label' => 'Total yield'], ['label' => ''],
            ]); ?>
            <?php foreach ($farmRows as $row): ?>
                <tr>
                    <?php dfms_cell('Date', e(fmt_date($row['production_date']))); ?>
                    <?php dfms_cell('Total yield', $row['total_milk_yield'] !== null
                        ? e(fmt_measure((float) $row['total_milk_yield'], 'volume')) : '—'); ?>
                    <?php dfms_cell('', $canEdit
                        ? '<a href="' . BASE_URL . 'modules/milk/edit.php?id=' . (int) $row['production_id'] . '">Edit</a>'
                        : ''); ?>
                </tr>
            <?php endforeach; ?>
            <?php dfms_table_close(); ?>
            <?php dfms_pagination($page, $totalPages, BASE_URL . 'modules/milk/?tab=farm', $totalRecords); ?>
        <?php endif; ?>
    </div></div>
<?php endif; ?>

</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
