<?php
// ============================================================================
// HEALTH MODULE — home.
//
// Phase 4 EVOLVES this page. Before, it was a flat list of health_events with
// no filtering. It is now the veterinary work queue: cases, treatments,
// vaccinations, quarantine and withdrawal restrictions, each on its own tab.
//
// The original event list is NOT deleted — it is the "Event log" tab, still
// reading health_events, still paginated the same way. Four real rows exist in
// it and they keep their page.
//
// Server-rendered tabs (?tab=), like the Phase 3 animal profile: every view is
// a plain URL that is linkable, bookmarkable and works without JavaScript.
// ============================================================================

define('DFMS_HEALTH_PAGE', true);
require_once __DIR__ . '/_shared.php';

requireAuth('health.view');

$conn = getDBConnection();
$error_message = '';

$v = new DfmsValidator($_GET);
$tab = $v->enum('tab', ['cases', 'treatments', 'vaccinations', 'quarantine', 'withdrawals', 'events'],
    ['default' => 'cases']);
if ($v->fails()) {
    $tab = 'cases';
}

$page = $v->integer('page', ['nullable' => true, 'min' => 1, 'max' => 100000]) ?? 1;
$search = $v->string('search', 0, 100, ['nullable' => true]) ?? '';
$statusFilter = $v->string('status', 0, 20, ['nullable' => true]) ?? '';
$typeFilter = $v->string('case_type', 0, 32, ['nullable' => true]) ?? '';
$severityFilter = $v->string('severity', 0, 20, ['nullable' => true]) ?? '';
if ($v->fails()) {
    // A malformed filter is treated as no filter rather than an error page —
    // the values are all re-validated against the registries downstream.
    $search = $statusFilter = $typeFilter = $severityFilter = '';
    $page = 1;
}

$counters = ['active_cases' => 0, 'urgent_cases' => 0, 'under_treatment' => 0,
             'quarantined' => 0, 'withdrawals' => 0, 'needs_attention' => 0, 'vaccinations_due' => 0];
$cases = ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
$treatments = $quarantines = $vaccinations = $withdrawals = [];
$events = [];
$eventPages = 1;
$eventTotal = 0;

try {
    $counters = healthFarmCounters($conn);

    // Keep the alert queue in step with the data on the way in. Idempotent by
    // construction (see healthSyncAlerts), so opening this page repeatedly
    // cannot produce duplicate alerts — the failure mode the brief calls out.
    //
    // Gated on the permission to raise alerts, so a viewer loading the page
    // does not perform a write on the farm's behalf.
    if (hasPermission('alerts.create')) {
        healthSyncAlerts($conn);
    }

    if ($tab === 'cases') {
        $cases = healthSearchCases($conn, [
            'search'    => $search,
            'status'    => $statusFilter !== '' ? $statusFilter : 'active',
            'case_type' => $typeFilter,
            'severity'  => $severityFilter,
        ], $page, 25);
    } elseif ($tab === 'treatments') {
        $treatments = healthFarmActiveTreatments($conn, 200);
    } elseif ($tab === 'vaccinations') {
        $vaccinations = healthUpcomingVaccinations($conn, 60, 200);
    } elseif ($tab === 'quarantine') {
        $quarantines = healthFarmActiveQuarantines($conn, 200);
    } elseif ($tab === 'withdrawals') {
        $withdrawals = healthFarmActiveWithdrawals($conn);
    } else {
        // The pre-Phase-4 event list, unchanged in behaviour.
        $perPage = 50;
        $eventTotal = (int) tenantScalar(
            $conn,
            "SELECT COUNT(*) FROM health_events h
               JOIN cows c ON h.cow_id = c.cow_id AND c.farm_id = h.farm_id
              WHERE {scope}",
            [], 0, 'h'
        );
        $eventPages = max(1, (int) ceil($eventTotal / $perPage));
        $page = min($page, $eventPages);
        $offset = ($page - 1) * $perPage;
        $events = tenantQuery($conn, "
            SELECT h.*, c.unique_id, c.name
            FROM health_events h
            JOIN cows c ON h.cow_id = c.cow_id AND c.farm_id = h.farm_id
            WHERE {scope}
            ORDER BY h.event_date DESC, h.event_id DESC
            LIMIT " . (int) $perPage . " OFFSET " . (int) $offset, [], 'h');
    }
} catch (PDOException $e) {
    $error_message = safeError($e, 'Database error');
}

$page_title = 'Health';
$page_breadcrumb = [['label' => 'Health']];
require_once __DIR__ . '/../../includes/header.php';

$canCreate = hasPermission('health.create');
$canObserve = hasPermission('health.observe');
$canMedicate = hasPermission('health.medicate');
$baseTab = BASE_URL . 'modules/health/?tab=' . $tab;
?>
<?php healthScopedStyles(); ?>
<div class="dfms-health-scope">


<style>
    .health-counters {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: var(--space-4);
        margin-bottom: var(--space-6);
    }
    .health-filters {
        display: flex;
        flex-wrap: wrap;
        gap: var(--space-3);
        align-items: flex-end;
        margin-bottom: var(--space-4);
    }
    .health-filters .dfms-field { margin-bottom: 0; min-width: 10rem; flex: 1 1 10rem; }
    .health-actions { display: flex; flex-wrap: wrap; gap: var(--space-2); }
    .health-note {
        border-left: 3px solid var(--warning);
        padding: var(--space-3) var(--space-4);
        background: var(--warning-bg);
        color: var(--warning-fg);
        border-radius: var(--radius-sm);
        font-size: var(--text-sm);
        margin-bottom: var(--space-5);
    }
    .health-note strong { display: block; margin-bottom: var(--space-1); }
    @media (max-width: 640px) {
        .health-filters .dfms-field { flex-basis: 100%; }
        .health-actions .dfms-btn { flex: 1 1 100%; justify-content: center; }
    }
</style>

<?php
$actions = '';
if ($canCreate) {
    $actions .= dfms_button_html('Open a case', BASE_URL . 'modules/health/case_new.php', 'primary', ['icon' => 'plus']);
}
if ($canObserve) {
    $actions .= dfms_button_html('Record observation', BASE_URL . 'modules/health/observation.php', 'secondary');
}
dfms_page_header('Animal health', 'Cases, treatments, vaccinations and product restrictions across this farm.', $actions, 'Veterinary');
?>

<?php dfms_render_flashes(); ?>
<?php if ($error_message): ?>
    <?php dfms_alert($error_message, 'error'); ?>
<?php endif; ?>

<div class="health-counters">
    <?php
    echo dfms_metric_html('Active cases', (string) $counters['active_cases'], '',
        $counters['urgent_cases'] > 0
            ? dfms_badge_html($counters['urgent_cases'] . ' urgent', 'danger')
            : dfms_badge_html('none urgent', 'success'));
    echo dfms_metric_html('Under treatment', (string) $counters['under_treatment'], 'animals');
    echo dfms_metric_html('Quarantined', (string) $counters['quarantined'], 'animals');
    echo dfms_metric_html('Active restrictions', (string) $counters['withdrawals'], '',
        $counters['withdrawals'] > 0 ? dfms_badge_html('product withheld', 'warning') : '');
    echo dfms_metric_html('Vaccinations due', (string) $counters['vaccinations_due'], 'in 14 days');
    ?>
</div>

<?php healthRenderTabs($tab); ?>

<?php if ($tab === 'cases'): ?>
    <!-- ===================== CASES ===================== -->
    <section class="dfms-section">
        <form method="GET" class="health-filters" role="search">
            <input type="hidden" name="tab" value="cases">
            <div class="dfms-field">
                <label class="dfms-label" for="search">Search</label>
                <input class="dfms-input" type="search" id="search" name="search"
                       value="<?php echo e($search); ?>"
                       placeholder="Case reference, title or animal…">
            </div>
            <?php
            healthSelectField('status', 'Status', array_merge(
                ['active' => 'All open cases', 'closed' => 'Resolved and closed'],
                array_map(static function (array $meta): string { return $meta['label']; }, DFMS_CASE_STATUSES)
            ), $statusFilter !== '' ? $statusFilter : 'active');
            healthSelectField('case_type', 'Type', DFMS_CASE_TYPES, $typeFilter, ['placeholder' => 'Any type']);
            healthSelectField('severity', 'Severity', [
                'routine' => 'Routine', 'moderate' => 'Moderate', 'urgent' => 'Urgent',
            ], $severityFilter, ['placeholder' => 'Any severity']);
            ?>
            <div class="dfms-field">
                <button type="submit" class="dfms-btn dfms-btn-secondary">Apply filters</button>
            </div>
        </form>

        <div class="dfms-card">
            <?php if (empty($cases['items'])): ?>
                <?php dfms_empty_state(
                    'No cases match',
                    'A health case groups the symptoms, diagnoses and treatments for one episode of illness or injury.',
                    $canCreate ? dfms_button_html('Open a case', BASE_URL . 'modules/health/case_new.php', 'primary') : '',
                    'heart'
                ); ?>
            <?php else: ?>
                <?php dfms_table_open([
                    ['label' => 'Case'], ['label' => 'Animal'], ['label' => 'Type'],
                    ['label' => 'Severity'], ['label' => 'Status'], ['label' => 'Opened'], ['label' => ''],
                ]); ?>
                <?php foreach ($cases['items'] as $case): ?>
                    <?php $statusMeta = DFMS_CASE_STATUSES[$case['status']] ?? ['label' => $case['status'], 'tone' => 'neutral']; ?>
                    <tr>
                        <?php
                        dfms_cell('Case', '<span class="dfms-cell-strong">' . e($case['case_reference']) . '</span><br>'
                            . '<span class="dfms-text-sm">' . e($case['title']) . '</span>');
                        dfms_cell('Animal', '<a href="' . e(BASE_URL . 'modules/cows/view.php?id=' . (int) $case['cow_id'] . '&tab=health') . '">'
                            . e($case['unique_id'] . ($case['name'] ? ' — ' . $case['name'] : '')) . '</a>');
                        dfms_cell('Type', e(DFMS_CASE_TYPES[$case['case_type']] ?? $case['case_type']));
                        dfms_cell('Severity', dfms_badge_html(ucfirst($case['severity']),
                            $case['severity'] === 'urgent' ? 'danger' : ($case['severity'] === 'moderate' ? 'warning' : 'neutral')));
                        dfms_cell('Status', dfms_badge_html($statusMeta['label'], $statusMeta['tone'], true));
                        dfms_cell('Opened', e(fmt_date($case['opened_on'])));
                        dfms_cell('', '<a class="dfms-btn dfms-btn-ghost dfms-btn-sm" href="'
                            . e(BASE_URL . 'modules/health/case.php?id=' . (int) $case['case_id']) . '">Open<span class="dfms-visually-hidden"> case '
                            . e($case['case_reference']) . '</span></a>', 'dfms-cell-actions');
                        ?>
                    </tr>
                <?php endforeach; ?>
                <?php dfms_table_close(); ?>
                <?php dfms_pagination($cases['page'], $cases['pages'],
                    BASE_URL . 'modules/health/?tab=cases&search=' . urlencode($search)
                    . '&status=' . urlencode($statusFilter) . '&case_type=' . urlencode($typeFilter)
                    . '&severity=' . urlencode($severityFilter), $cases['total']); ?>
            <?php endif; ?>
        </div>
    </section>

<?php elseif ($tab === 'treatments'): ?>
    <!-- ===================== TREATMENTS ===================== -->
    <section class="dfms-section">
        <div class="dfms-section-header">
            <div>
                <h2>Treatments in progress</h2>
                <p>Planned and active courses. Completed and cancelled treatments stay on the animal's record.</p>
            </div>
            <?php if ($canCreate): ?>
                <div class="health-actions">
                    <a class="dfms-btn dfms-btn-primary dfms-btn-sm" href="<?php echo e(BASE_URL); ?>modules/health/treatment.php">Start a treatment</a>
                </div>
            <?php endif; ?>
        </div>
        <div class="dfms-card">
            <?php if (empty($treatments)): ?>
                <?php dfms_empty_state('No treatments in progress', 'Nothing is currently planned or running on this farm.'); ?>
            <?php else: ?>
                <?php dfms_table_open([
                    ['label' => 'Animal'], ['label' => 'Treatment'], ['label' => 'Type'],
                    ['label' => 'Started'], ['label' => 'Status'], ['label' => ''],
                ]); ?>
                <?php foreach ($treatments as $treatment): ?>
                    <?php $meta = DFMS_TREATMENT_STATUSES[$treatment['status']] ?? ['label' => $treatment['status'], 'tone' => 'neutral']; ?>
                    <tr>
                        <?php
                        dfms_cell('Animal', '<a href="' . e(BASE_URL . 'modules/cows/view.php?id=' . (int) $treatment['cow_id'] . '&tab=health') . '">'
                            . e($treatment['unique_id'] . ($treatment['name'] ? '' : '')) . '</a>');
                        dfms_cell('Treatment', '<span class="dfms-cell-strong">' . e($treatment['name']) . '</span>'
                            . ($treatment['case_reference'] ? '<br><span class="dfms-text-sm">' . e($treatment['case_reference']) . '</span>' : ''));
                        dfms_cell('Type', e(DFMS_TREATMENT_TYPES[$treatment['treatment_type']] ?? $treatment['treatment_type']));
                        dfms_cell('Started', e(fmt_date($treatment['started_on'])));
                        dfms_cell('Status', dfms_badge_html($meta['label'], $meta['tone'], true));
                        dfms_cell('', $canMedicate
                            ? '<a class="dfms-btn dfms-btn-ghost dfms-btn-sm" href="'
                              . e(BASE_URL . 'modules/health/medication.php?cow_id=' . (int) $treatment['cow_id']
                                  . '&treatment_id=' . (int) $treatment['treatment_id'])
                              . '">Give medication<span class="dfms-visually-hidden"> for ' . e($treatment['name']) . '</span></a>'
                            : '', 'dfms-cell-actions');
                        ?>
                    </tr>
                <?php endforeach; ?>
                <?php dfms_table_close(); ?>
            <?php endif; ?>
        </div>
    </section>

<?php elseif ($tab === 'vaccinations'): ?>
    <!-- ===================== VACCINATIONS ===================== -->
    <section class="dfms-section">
        <div class="dfms-section-header">
            <div>
                <h2>Vaccinations due</h2>
                <p>Scheduled doses and doses falling due within 60 days. A scheduled dose is never marked given automatically.</p>
            </div>
            <?php if ($canMedicate): ?>
                <div class="health-actions">
                    <a class="dfms-btn dfms-btn-primary dfms-btn-sm" href="<?php echo e(BASE_URL); ?>modules/health/vaccination.php">Record or schedule</a>
                </div>
            <?php endif; ?>
        </div>
        <div class="dfms-card">
            <?php if (empty($vaccinations)): ?>
                <?php dfms_empty_state('Nothing due', 'No vaccination is scheduled or falling due in the next 60 days.'); ?>
            <?php else: ?>
                <?php dfms_table_open([
                    ['label' => 'Animal'], ['label' => 'Vaccine'], ['label' => 'Protects against'],
                    ['label' => 'Due'], ['label' => 'Source'], ['label' => ''],
                ]); ?>
                <?php $today = farmToday(); ?>
                <?php foreach ($vaccinations as $vaccination): ?>
                    <?php $overdue = (string) $vaccination['due_on'] < $today; ?>
                    <tr>
                        <?php
                        dfms_cell('Animal', '<a href="' . e(BASE_URL . 'modules/cows/view.php?id=' . (int) $vaccination['cow_id'] . '&tab=health') . '">'
                            . e($vaccination['unique_id']) . '</a>');
                        dfms_cell('Vaccine', '<span class="dfms-cell-strong">' . e($vaccination['vaccine_name']) . '</span>');
                        dfms_cell('Protects against', e($vaccination['disease_target'] ?: '—'));
                        dfms_cell('Due', e(fmt_date($vaccination['due_on'])) . ' '
                            . dfms_badge_html($overdue ? 'Overdue' : 'Upcoming', $overdue ? 'danger' : 'info'));
                        dfms_cell('Source', e($vaccination['status'] === 'scheduled' ? 'Scheduled' : 'Next due after last dose'));
                        dfms_cell('', $canMedicate && $vaccination['status'] === 'scheduled'
                            ? '<a class="dfms-btn dfms-btn-ghost dfms-btn-sm" href="'
                              . e(BASE_URL . 'modules/health/vaccination.php?given=' . (int) $vaccination['vaccination_id'])
                              . '">Mark given<span class="dfms-visually-hidden"> — ' . e($vaccination['vaccine_name']) . '</span></a>'
                            : '', 'dfms-cell-actions');
                        ?>
                    </tr>
                <?php endforeach; ?>
                <?php dfms_table_close(); ?>
            <?php endif; ?>
        </div>
    </section>

<?php elseif ($tab === 'quarantine'): ?>
    <!-- ===================== QUARANTINE ===================== -->
    <section class="dfms-section">
        <div class="dfms-section-header">
            <div>
                <h2>Animals in isolation</h2>
                <p>Isolation locations come from the farm's own location list — there is no separate quarantine map.</p>
            </div>
            <?php if ($canCreate): ?>
                <div class="health-actions">
                    <a class="dfms-btn dfms-btn-primary dfms-btn-sm" href="<?php echo e(BASE_URL); ?>modules/health/quarantine.php">Start quarantine</a>
                </div>
            <?php endif; ?>
        </div>
        <div class="dfms-card">
            <?php if (empty($quarantines)): ?>
                <?php dfms_empty_state('No animals in isolation', 'Nothing on this farm is currently quarantined.'); ?>
            <?php else: ?>
                <?php dfms_table_open([
                    ['label' => 'Animal'], ['label' => 'Reason'], ['label' => 'Location'],
                    ['label' => 'Started'], ['label' => 'Expected end'], ['label' => ''],
                ]); ?>
                <?php $today = farmToday(); ?>
                <?php foreach ($quarantines as $quarantine): ?>
                    <?php $overdue = $quarantine['expected_end_on'] !== null && (string) $quarantine['expected_end_on'] < $today; ?>
                    <tr>
                        <?php
                        dfms_cell('Animal', '<a href="' . e(BASE_URL . 'modules/cows/view.php?id=' . (int) $quarantine['cow_id'] . '&tab=health') . '">'
                            . e($quarantine['unique_id']) . '</a>');
                        dfms_cell('Reason', '<span class="dfms-cell-strong">'
                            . e(DFMS_QUARANTINE_TYPES[$quarantine['quarantine_type']] ?? $quarantine['quarantine_type'])
                            . '</span><br><span class="dfms-text-sm">' . e($quarantine['reason']) . '</span>');
                        dfms_cell('Location', e($quarantine['location_name'] ?: 'Not recorded'));
                        dfms_cell('Started', e(fmt_instant($quarantine['started_at'], 'Y-m-d')));
                        dfms_cell('Expected end', $quarantine['expected_end_on']
                            ? e(fmt_date($quarantine['expected_end_on'])) . ($overdue ? ' ' . dfms_badge_html('Review due', 'warning') : '')
                            : '<span class="dfms-muted">Open-ended</span>');
                        dfms_cell('', $canCreate
                            ? '<a class="dfms-btn dfms-btn-ghost dfms-btn-sm" href="'
                              . e(BASE_URL . 'modules/health/quarantine.php?release=' . (int) $quarantine['quarantine_id'])
                              . '">Release<span class="dfms-visually-hidden"> ' . e($quarantine['unique_id']) . '</span></a>'
                            : '', 'dfms-cell-actions');
                        ?>
                    </tr>
                <?php endforeach; ?>
                <?php dfms_table_close(); ?>
            <?php endif; ?>
        </div>
    </section>

<?php elseif ($tab === 'withdrawals'): ?>
    <!-- ===================== WITHDRAWALS ===================== -->
    <section class="dfms-section">
        <div class="dfms-section-header">
            <div>
                <h2>Active product restrictions</h2>
                <p>Animals whose milk, meat or other product is withheld following a treatment.</p>
            </div>
        </div>

        <!--
            The honest statement of what this system can and cannot do. It is on
            the page itself, not only in the documentation, because the person
            filling the bulk tank is the one who needs to know.
        -->
        <div class="health-note">
            <strong>These restrictions are tracked per animal, but not enforced against production records.</strong>
            Milk production is recorded as a farm-level daily total with no per-animal attribution, so DFMIS cannot
            verify that a restricted animal's milk was kept out of the tank. Treat this list as the authoritative
            record of which animals are withheld, and apply it at milking.
        </div>

        <div class="dfms-card">
            <?php if (empty($withdrawals)): ?>
                <?php dfms_empty_state('No active restrictions', 'No animal on this farm is currently inside a withdrawal period.'); ?>
            <?php else: ?>
                <?php dfms_table_open([
                    ['label' => 'Animal'], ['label' => 'Restriction'], ['label' => 'Caused by'],
                    ['label' => 'Ends (inclusive)'], ['label' => 'Days left'],
                ]); ?>
                <?php foreach ($withdrawals as $withdrawal): ?>
                    <?php $daysLeft = healthWithdrawalDaysRemaining($withdrawal); ?>
                    <tr>
                        <?php
                        dfms_cell('Animal', '<a href="' . e(BASE_URL . 'modules/cows/view.php?id=' . (int) $withdrawal['cow_id'] . '&tab=health') . '">'
                            . e($withdrawal['unique_id'] . ($withdrawal['name'] ? ' — ' . $withdrawal['name'] : '')) . '</a>');
                        dfms_cell('Restriction', dfms_badge_html(
                            DFMS_WITHDRAWAL_TYPES[$withdrawal['restriction_type']]['label'] ?? $withdrawal['restriction_type'],
                            'warning', true));
                        dfms_cell('Caused by', e($withdrawal['source_label']));
                        dfms_cell('Ends (inclusive)', e(fmt_date($withdrawal['ends_on'])), 'health-figure');
                        dfms_cell('Days left', '<span class="dfms-num">' . e($daysLeft) . '</span>', 'health-figure');
                        ?>
                    </tr>
                <?php endforeach; ?>
                <?php dfms_table_close(); ?>
            <?php endif; ?>
        </div>
    </section>

<?php else: ?>
    <!-- ===================== EVENT LOG (pre-Phase-4, preserved) ===================== -->
    <section class="dfms-section">
        <div class="dfms-section-header">
            <div>
                <h2>Health event log</h2>
                <p>Free-form dated notes. Kept exactly as it was — structured clinical detail now lives on cases.</p>
            </div>
            <?php if ($canCreate): ?>
                <div class="health-actions">
                    <a class="dfms-btn dfms-btn-secondary dfms-btn-sm" href="<?php echo e(BASE_URL); ?>modules/health/add.php">Log an event</a>
                </div>
            <?php endif; ?>
        </div>
        <div class="dfms-card">
            <?php if (empty($events)): ?>
                <?php dfms_empty_state('No health events recorded yet', 'Use a health case for anything that needs following up.'); ?>
            <?php else: ?>
                <?php dfms_table_open([
                    ['label' => 'Animal'], ['label' => 'Date'], ['label' => 'Type'],
                    ['label' => 'Severity'], ['label' => 'Description'],
                ]); ?>
                <?php foreach ($events as $event): ?>
                    <tr>
                        <?php
                        dfms_cell('Animal', '<a href="' . e(BASE_URL . 'modules/cows/view.php?id=' . (int) $event['cow_id'] . '&tab=health') . '">'
                            . e($event['unique_id']) . '</a>');
                        dfms_cell('Date', e(fmt_date($event['event_date'])));
                        dfms_cell('Type', e($event['event_type']));
                        dfms_cell('Severity', dfms_badge_html(ucfirst($event['severity']),
                            $event['severity'] === 'urgent' ? 'danger' : ($event['severity'] === 'moderate' ? 'warning' : 'neutral')));
                        dfms_cell('Description', e($event['description'] ?: '—'));
                        ?>
                    </tr>
                <?php endforeach; ?>
                <?php dfms_table_close(); ?>
                <?php dfms_pagination($page, $eventPages, BASE_URL . 'modules/health/?tab=events', $eventTotal); ?>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
