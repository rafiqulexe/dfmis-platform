<?php
// Farm Advisor: turns raw farm data into structured, actionable insights.
// Every insight is a real computation over today's data (not fabricated) —
// this is the deterministic layer the AI Farm Advisor (Phase 5) will sit
// on top of, narrating the same underlying signals in natural language.
// Shared so cow-profile "AI Health Summary" etc. can reuse the same source.

const DFMS_SEVERITY_RANK = ['critical' => 0, 'warning' => 1, 'info' => 2, 'positive' => 3];

// Structured insight cards for the dashboard's Farm Advisor panel.
// Each has severity, title, why/impact/action, and a link to act on it.
function getFarmAdvisorInsights(PDO $conn, int $farmId): array {
    $insights = [];

    // ---- Milk production trend ----
    $stmt = $conn->prepare("
        SELECT
            AVG(CASE WHEN production_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) THEN total_milk_yield END) AS last7,
            AVG(CASE WHEN production_date >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
                      AND production_date <  DATE_SUB(CURDATE(), INTERVAL 6 DAY) THEN total_milk_yield END) AS prior7
        FROM milk_production WHERE farm_id = ?
    ");
    $stmt->execute([$farmId]);
    $trend = $stmt->fetch(PDO::FETCH_ASSOC);
    $last7 = (float) ($trend['last7'] ?? 0);
    $prior7 = (float) ($trend['prior7'] ?? 0);

    if ($last7 > 0 && $prior7 > 0) {
        $deltaPct = (($last7 - $prior7) / $prior7) * 100;
        if ($deltaPct <= -5) {
            $insights[] = [
                'severity' => 'warning',
                'title' => sprintf('Milk production is %.1f%% below last week', abs($deltaPct)),
                'why' => sprintf('The 7-day average has slipped from %.1f L/day to %.1f L/day.', $prior7, $last7),
                'impact' => sprintf('At this rate the farm is producing roughly %.0f fewer liters a week than last week.', ($prior7 - $last7) * 7),
                'action' => 'Check feed intake and the cows flagged below for early signs of illness or stress.',
                'link' => 'modules/milk/', 'link_label' => 'Review milk production',
            ];
        } elseif ($deltaPct >= 5) {
            $insights[] = [
                'severity' => 'positive',
                'title' => sprintf('Milk production is up %.1f%% from last week', $deltaPct),
                'why' => sprintf('The 7-day average has risen from %.1f L/day to %.1f L/day.', $prior7, $last7),
                'impact' => 'Herd output is trending in the right direction.',
                'action' => 'Keep the current feeding and health routine consistent.',
                'link' => 'modules/milk/', 'link_label' => 'View trend',
            ];
        } else {
            $insights[] = [
                'severity' => 'info',
                'title' => sprintf('Milk production is steady around %.1f L/day', $last7),
                'why' => 'No significant change versus the prior 7-day average.',
                'impact' => 'No action needed.',
                'action' => 'Continue monitoring daily entries.',
                'link' => 'modules/milk/', 'link_label' => 'View trend',
            ];
        }
    }

    // ---- Herd health ----
    $stmt = $conn->prepare("SELECT unique_id, name, health_status FROM cows WHERE farm_id = ? AND deleted_at IS NULL AND health_status IN ('sick','at_risk')");
    $stmt->execute([$farmId]);
    $flaggedCows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $sickCount = count(array_filter($flaggedCows, fn($c) => $c['health_status'] === 'sick'));

    if (count($flaggedCows) > 0) {
        $names = array_map(fn($c) => $c['name'] ?: $c['unique_id'], $flaggedCows);
        $insights[] = [
            'severity' => $sickCount > 0 ? 'critical' : 'warning',
            'title' => sprintf('%d cow%s need%s veterinary attention', count($flaggedCows), count($flaggedCows) === 1 ? '' : 's', count($flaggedCows) === 1 ? 's' : ''),
            'why' => implode(', ', $names) . ' ' . (count($flaggedCows) === 1 ? 'is' : 'are') . ' currently flagged in the herd status.',
            'impact' => 'Untreated issues can spread, worsen, and reduce milk yield.',
            'action' => 'Review the flagged animals and schedule a vet visit for anything marked sick.',
            'link' => 'modules/health/', 'link_label' => 'View health records',
        ];
    } else {
        $insights[] = [
            'severity' => 'positive',
            'title' => 'Herd health is stable',
            'why' => 'No cows are currently flagged as sick or at risk.',
            'impact' => 'No action needed.',
            'action' => 'Continue routine checks and vaccinations on schedule.',
            'link' => 'modules/health/', 'link_label' => 'View health records',
        ];
    }

    // ---- Inventory ----
    $stmt = $conn->prepare("
        SELECT i.item_name, l.quantity, i.unit
        FROM inventory_items i JOIN inventory_levels l ON i.item_id = l.item_id
        WHERE i.farm_id = ? AND l.quantity <= 10
    ");
    $stmt->execute([$farmId]);
    $lowStock = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($lowStock) > 0) {
        $names = array_map(fn($i) => $i['item_name'], $lowStock);
        $insights[] = [
            'severity' => 'warning',
            'title' => sprintf('%d inventory item%s running low', count($lowStock), count($lowStock) === 1 ? ' is' : 's are'),
            'why' => implode(', ', $names) . ' ' . (count($lowStock) === 1 ? 'is' : 'are') . ' at or below the 10-unit threshold.',
            'impact' => 'Running out could disrupt feeding or treatment schedules.',
            'action' => 'Reorder soon — check exact quantities on the inventory page.',
            'link' => 'modules/inventory/', 'link_label' => 'Check inventory',
        ];
    } else {
        $insights[] = [
            'severity' => 'positive',
            'title' => 'Inventory levels look healthy',
            'why' => 'No items are at or below the low-stock threshold.',
            'impact' => 'No action needed.',
            'action' => 'No restocking required right now.',
            'link' => 'modules/inventory/', 'link_label' => 'View inventory',
        ];
    }

    usort($insights, fn($a, $b) => DFMS_SEVERITY_RANK[$a['severity']] <=> DFMS_SEVERITY_RANK[$b['severity']]);
    return $insights;
}

// Unified, severity-sorted feed for the "What Needs Your Attention" list.
function getAttentionItems(PDO $conn, int $farmId, int $limit = 6): array {
    $items = [];

    $stmt = $conn->prepare("
        SELECT h.event_type, h.severity, h.event_date, h.description, c.cow_id, c.unique_id, c.name
        FROM health_events h JOIN cows c ON h.cow_id = c.cow_id
        WHERE h.farm_id = ? AND h.severity IN ('urgent','moderate') AND h.event_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
        ORDER BY FIELD(h.severity, 'urgent', 'moderate'), h.event_date DESC
    ");
    $stmt->execute([$farmId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $items[] = [
            'severity' => $row['severity'] === 'urgent' ? 'critical' : 'warning',
            'title' => ($row['name'] ?: $row['unique_id']) . ' — ' . $row['event_type'],
            'subtitle' => $row['description'] ?: ('Recorded ' . date('M j', strtotime($row['event_date']))),
            'link' => 'modules/cows/view.php?id=' . $row['cow_id'],
        ];
    }

    $stmt = $conn->prepare("
        SELECT alert_id, alert_type, severity, description, cow_id
        FROM alerts WHERE farm_id = ? AND is_read = 0 AND severity IN ('critical','warning')
        ORDER BY FIELD(severity, 'critical', 'warning'), alert_time DESC
    ");
    $stmt->execute([$farmId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $items[] = [
            'severity' => $row['severity'],
            'title' => $row['alert_type'],
            'subtitle' => $row['description'],
            'link' => $row['cow_id'] ? 'modules/cows/view.php?id=' . $row['cow_id'] : 'modules/alerts/',
        ];
    }

    $stmt = $conn->prepare("
        SELECT i.item_name, l.quantity, i.unit
        FROM inventory_items i JOIN inventory_levels l ON i.item_id = l.item_id
        WHERE i.farm_id = ? AND l.quantity <= 10
    ");
    $stmt->execute([$farmId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $items[] = [
            'severity' => 'warning',
            'title' => $row['item_name'] . ' running low',
            'subtitle' => number_format($row['quantity'], 2) . ' ' . $row['unit'] . ' remaining',
            'link' => 'modules/inventory/',
        ];
    }

    usort($items, fn($a, $b) => DFMS_SEVERITY_RANK[$a['severity']] <=> DFMS_SEVERITY_RANK[$b['severity']]);
    return array_slice($items, 0, $limit);
}
