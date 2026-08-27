<?php
require_once 'includes/auth.php';
require_once 'includes/errors.php';
require_once 'includes/audit.php';
require_once 'includes/repository.php';
require_once 'includes/functions.php';
require_once 'includes/permissions.php';
require_once 'includes/insights.php';

// Baseline audit C-1 (reproduced): a logged-in user with no farm_users row
// produced a null farm id, which was passed to a non-nullable int parameter
// and crashed the page with a TypeError that leaked the server path.
// requireAuth() now renders an explicit "no farm assigned" state instead.
requireAuth();

$farmId = currentFarmId();
$conn = getDBConnection();
$error_message = '';

try {
    $stmt = $conn->prepare("SELECT name FROM farms WHERE farm_id = ?");
    $stmt->execute([$farmId]);
    $farmName = $stmt->fetchColumn() ?: 'Your Farm';

    // Every read below goes through the repository layer rather than a
    // hand-written "WHERE farm_id = ?" (baseline audit D-3).
    $totalCows = (int) tenantScalar($conn, "SELECT COUNT(*) FROM cows WHERE {scope} AND deleted_at IS NULL");

    $herdCounts = ['healthy' => 0, 'at_risk' => 0, 'sick' => 0, 'treatment' => 0];
    foreach (tenantQuery($conn, "SELECT health_status, COUNT(*) AS n FROM cows WHERE {scope} AND deleted_at IS NULL GROUP BY health_status") as $row) {
        // Guard against an ENUM value the dashboard does not know about, which
        // would otherwise silently add an unlabelled segment to the herd bar.
        if (array_key_exists($row['health_status'], $herdCounts)) {
            $herdCounts[$row['health_status']] = (int) $row['n'];
        }
    }

    $pregnantCount = (int) tenantScalar($conn, "SELECT COUNT(*) FROM cows WHERE {scope} AND deleted_at IS NULL AND reproductive_status = 'pregnant'");
    $lactatingCount = (int) tenantScalar($conn, "SELECT COUNT(*) FROM cows WHERE {scope} AND deleted_at IS NULL AND lactation_status = 'lactating'");

    $todayMilk = (float) tenantScalar($conn, "SELECT total_milk_yield FROM milk_production WHERE {scope} AND production_date = CURDATE()", [], 0);
    $last7Avg = (float) tenantScalar($conn, "SELECT AVG(total_milk_yield) FROM milk_production WHERE {scope} AND production_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)", [], 0);
    $last30Avg = (float) tenantScalar($conn, "SELECT AVG(total_milk_yield) FROM milk_production WHERE {scope} AND production_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)", [], 0);

    // Baseline audit C-4: this had no date bound, so the chart labelled
    // "30-Day" plotted the entire history and serialized all of it into the
    // page. Now bounded to match the two averages computed above.
    $milkSeries = tenantQuery(
        $conn,
        "SELECT production_date, total_milk_yield FROM milk_production
          WHERE {scope} AND production_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
          ORDER BY production_date ASC"
    );

    $insights = getFarmAdvisorInsights($conn, $farmId);
    $attentionItems = getAttentionItems($conn, $farmId);
} catch (PDOException $e) {
    $error_message = safeError($e, 'Dashboard data error');
    $totalCows = 0;
    $herdCounts = ['healthy' => 0, 'at_risk' => 0, 'sick' => 0, 'treatment' => 0];
    $pregnantCount = 0;
    $lactatingCount = 0;
    $todayMilk = 0;
    $last7Avg = 0;
    $last30Avg = 0;
    $milkSeries = [];
    $insights = [];
    $attentionItems = [];
    $farmName = 'Your Farm';
}

$herdTotal = max(1, array_sum($herdCounts));
$herdColors = ['healthy' => 'var(--primary)', 'at_risk' => 'var(--brand-gold)', 'sick' => 'var(--danger)', 'treatment' => 'var(--info)'];
$herdLabels = ['healthy' => 'Healthy', 'at_risk' => 'At risk', 'sick' => 'Sick', 'treatment' => 'In treatment'];

$weekDelta = $last30Avg > 0 ? (($last7Avg - $last30Avg) / $last30Avg) * 100 : 0;
$weekDeltaClass = $weekDelta > 1 ? 'up' : ($weekDelta < -1 ? 'down' : 'flat');
$weekDeltaSign = $weekDelta > 0 ? '+' : '';

$page_title = 'Dashboard';
$page_breadcrumb = [['label' => 'Dashboard']];
require_once 'includes/header.php';
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="dfms-reveal" style="animation-delay:.02s;">
    <?php dfms_page_header(
        $farmName . ' — Command Center',
        date('l, F j, Y') . " · here's what's happening across the farm today.",
        '',
        'Good to see you, ' . $_SESSION['username']
    ); ?>
</div>

<?php if ($error_message): ?>
    <?php dfms_alert($error_message, 'error'); ?>
<?php endif; ?>

<!-- Farm Advisor -->
<section class="dfms-section dfms-reveal" style="animation-delay:.06s;">
    <div class="dfms-section-header">
        <div>
            <h2>Farm Advisor</h2>
            <p>Automated insights, recomputed from today's data.</p>
        </div>
    </div>
    <div class="dfms-insight-grid">
        <?php foreach ($insights as $insight): ?>
            <div class="dfms-insight-card <?php echo htmlspecialchars($insight['severity']); ?>">
                <div class="dfms-insight-title"><?php echo htmlspecialchars($insight['title']); ?></div>
                <p class="dfms-insight-meta"><strong>Why:</strong> <?php echo htmlspecialchars($insight['why']); ?></p>
                <p class="dfms-insight-meta"><strong>Impact:</strong> <?php echo htmlspecialchars($insight['impact']); ?></p>
                <p class="dfms-insight-meta"><strong>Action:</strong> <?php echo htmlspecialchars($insight['action']); ?></p>
                <a class="dfms-insight-link" href="<?php echo e(BASE_URL . $insight['link']); ?>"><?php echo htmlspecialchars($insight['link_label']); ?> →</a>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- Live Farm Status -->
<section class="dfms-section dfms-reveal" style="animation-delay:.1s;">
    <div class="dfms-section-header">
        <div>
            <h2>Live Farm Status</h2>
            <p>Herd health and production, at a glance.</p>
        </div>
    </div>
    <div class="dfms-two-col">
        <div class="dfms-card">
            <div class="dfms-stat-label" style="margin-bottom:.6rem;">Herd — <?php echo $totalCows; ?> cow<?php echo $totalCows === 1 ? '' : 's'; ?></div>
            <div class="dfms-herd-bar">
                <?php foreach ($herdCounts as $key => $count): ?>
                    <?php if ($count > 0): ?>
                        <div class="dfms-herd-bar-segment" style="width:<?php echo ($count / $herdTotal) * 100; ?>%; background:<?php echo $herdColors[$key]; ?>;"></div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <div class="dfms-herd-legend">
                <?php foreach ($herdCounts as $key => $count): ?>
                    <span class="dfms-herd-legend-item">
                        <span class="dfms-herd-legend-dot" style="background:<?php echo $herdColors[$key]; ?>;"></span>
                        <?php echo $herdLabels[$key]; ?> (<?php echo $count; ?>)
                    </span>
                <?php endforeach; ?>
            </div>
            <div class="dfms-stat-row" style="margin-top:1.5rem;">
                <div>
                    <div class="dfms-stat-value"><?php echo $pregnantCount; ?></div>
                    <div class="dfms-stat-label">Pregnant</div>
                </div>
                <div>
                    <div class="dfms-stat-value"><?php echo $lactatingCount; ?></div>
                    <div class="dfms-stat-label">Lactating</div>
                </div>
            </div>
        </div>

        <div class="dfms-card">
            <div class="dfms-stat-label" style="margin-bottom:.6rem;">Milk production</div>
            <div class="dfms-stat-row">
                <div>
                    <div class="dfms-stat-value"><?php echo number_format($todayMilk, 1); ?> L</div>
                    <div class="dfms-stat-label">Today</div>
                </div>
                <div>
                    <div class="dfms-stat-value"><?php echo number_format($last7Avg, 1); ?> L</div>
                    <div class="dfms-stat-label">7-day avg</div>
                    <div class="dfms-stat-delta <?php echo $weekDeltaClass; ?>">
                        <?php echo $weekDeltaSign; ?><?php echo number_format($weekDelta, 1); ?>% vs 30-day avg
                    </div>
                </div>
                <div>
                    <div class="dfms-stat-value"><?php echo number_format($last30Avg, 1); ?> L</div>
                    <div class="dfms-stat-label">30-day avg</div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Production trend -->
<section class="dfms-section dfms-reveal" style="animation-delay:.14s;">
    <div class="dfms-card dfms-chart-card">
        <div class="dfms-section-header" style="margin-bottom:.75rem;">
            <div>
                <h2>30-Day Production Trend</h2>
                <p>Is milk production improving?</p>
            </div>
        </div>
        <div class="dfms-chart">
            <canvas id="milkProductionChart" role="img" aria-label="Line chart of daily milk production over the last 30 days"></canvas>
        </div>
    </div>
</section>

<!-- What Needs Your Attention -->
<section class="dfms-section dfms-reveal" style="animation-delay:.18s;">
    <div class="dfms-section-header">
        <div>
            <h2>What Needs Your Attention</h2>
            <p>Prioritized by severity, across the whole farm.</p>
        </div>
    </div>
    <div class="dfms-card">
        <?php if (empty($attentionItems)): ?>
            <div class="dfms-empty-state">Nothing needs attention right now — the farm is running clean.</div>
        <?php else: ?>
            <div class="dfms-attention-list">
                <?php foreach ($attentionItems as $item): ?>
                    <a class="dfms-attention-item" href="<?php echo e(BASE_URL . $item['link']); ?>">
                        <span class="dfms-attention-dot <?php echo htmlspecialchars($item['severity']); ?>"></span>
                        <span>
                            <span class="dfms-attention-title"><?php echo htmlspecialchars($item['title']); ?></span>
                            <span class="dfms-attention-subtitle"><?php echo htmlspecialchars($item['subtitle']); ?></span>
                        </span>
                        <span class="dfms-attention-chevron">&rsaquo;</span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- Quick Actions -->
<section class="dfms-section dfms-reveal" style="animation-delay:.22s;">
    <div class="dfms-section-header">
        <div>
            <h2>Quick Actions</h2>
        </div>
    </div>
    <div class="dfms-quick-actions">
        <?php if (hasPermission('cows.create')): ?>
            <a class="dfms-quick-action" href="<?php echo BASE_URL; ?>modules/cows/add.php">
                <span class="dfms-quick-action-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                </span>
                <span>
                    <span class="dfms-quick-action-title">Add New Cow</span><br>
                    <span class="dfms-quick-action-sub">Register a new animal</span>
                </span>
            </a>
        <?php endif; ?>
        <?php if (hasPermission('milk.create')): ?>
            <a class="dfms-quick-action" href="<?php echo BASE_URL; ?>modules/milk/add.php">
                <span class="dfms-quick-action-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" /></svg>
                </span>
                <span>
                    <span class="dfms-quick-action-title">Record Milk Production</span><br>
                    <span class="dfms-quick-action-sub">Log today's yield</span>
                </span>
            </a>
        <?php endif; ?>
        <?php if (hasPermission('health.create')): ?>
            <a class="dfms-quick-action" href="<?php echo BASE_URL; ?>modules/health/add.php">
                <span class="dfms-quick-action-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" /></svg>
                </span>
                <span>
                    <span class="dfms-quick-action-title">Log Health Event</span><br>
                    <span class="dfms-quick-action-sub">Record a check-up or treatment</span>
                </span>
            </a>
        <?php endif; ?>
        <a class="dfms-quick-action" href="<?php echo BASE_URL; ?>modules/inventory/">
            <span class="dfms-quick-action-icon">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" /></svg>
            </span>
            <span>
                <span class="dfms-quick-action-title">Check Inventory</span><br>
                <span class="dfms-quick-action-sub">Feed and medicine levels</span>
            </span>
        </a>
    </div>
</section>

<script>
const milkCtx = document.getElementById('milkProductionChart').getContext('2d');
new Chart(milkCtx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode(array_map(fn($r) => date('M j', strtotime($r['production_date'])), $milkSeries)); ?>,
        datasets: [{
            label: 'Milk Production (L)',
            data: <?php echo json_encode(array_map(fn($r) => (float) $r['total_milk_yield'], $milkSeries)); ?>,
            borderColor: '#16a34a',
            backgroundColor: 'rgba(22, 163, 74, 0.08)',
            pointRadius: 0,
            pointHoverRadius: 4,
            borderWidth: 2,
            tension: 0.35,
            fill: true
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: false, grid: { color: '#e8f0e8' }, ticks: { font: { family: 'Inter' } } },
            x: { grid: { display: false }, ticks: { font: { family: 'Inter' }, maxTicksLimit: 10 } }
        }
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
