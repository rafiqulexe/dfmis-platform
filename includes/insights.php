<?php
// Farm Advisor: turns raw farm data into structured, actionable insights.
// Every insight is a real computation over today's data (not fabricated) —
// this is the deterministic layer the AI Farm Advisor (Phase 5) will sit
// on top of, narrating the same underlying signals in natural language.
// Shared so cow-profile "AI Health Summary" etc. can reuse the same source.

// Date windows are resolved in the farm's timezone via the Phase 2
// configuration hierarchy rather than the database server's clock.
require_once __DIR__ . '/settings.php';

const DFMS_SEVERITY_RANK = ['critical' => 0, 'warning' => 1, 'info' => 2, 'positive' => 3];

// Maps an alert's generic (entity_type, entity_id) target to the page that
// shows it. New verticals add a case here rather than changing the alerts
// schema or the core notification code.
function alertTargetLink($entityType, $entityId) {
    if ($entityId === null) {
        return 'modules/alerts/';
    }
    switch ($entityType) {
        case 'cow':
            return 'modules/cows/view.php?id=' . (int) $entityId;
        case 'inventory_item':
            return 'modules/inventory/';
        default:
            return 'modules/alerts/';
    }
}

// Structured insight cards for the dashboard's Farm Advisor panel.
// Each has severity, title, why/impact/action, and a link to act on it.
function getFarmAdvisorInsights(PDO $conn, ?int $farmId): array {
    // Baseline audit C-1: this parameter was a non-nullable int, and
    // currentFarmId() returns null for a user with no farm membership — which
    // crashed the dashboard with a TypeError that leaked the server path.
    // Callers are now gated by requireAuth(); this is defence in depth.
    if ($farmId === null) {
        return [];
    }

    $insights = [];

    // Date windows are computed in THIS FARM's timezone, not the database
    // server's — see dashboard.php for the same reasoning. Resolved per farm
    // id rather than from the active tenant, so this function stays correct
    // if it is ever called for a farm other than the current one.
    $timezone = dfms_setting('timezone', $farmId);
    $day6 = dfms_date_offset($timezone, -6);
    $day13 = dfms_date_offset($timezone, -13);

    // ---- Milk production trend ----
    $stmt = $conn->prepare("
        SELECT
            AVG(CASE WHEN production_date >= ? THEN total_milk_yield END) AS last7,
            AVG(CASE WHEN production_date >= ?
                      AND production_date <  ? THEN total_milk_yield END) AS prior7
        FROM milk_production WHERE farm_id = ?
    ");
    $stmt->execute([$day6, $day13, $day6, $farmId]);
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
        SELECT i.item_name, l.quantity, i.unit, i.reorder_level
        FROM inventory_items i JOIN inventory_levels l ON i.item_id = l.item_id AND l.farm_id = i.farm_id
        WHERE i.farm_id = ? AND l.quantity <= i.reorder_level
    ");
    $stmt->execute([$farmId]);
    $lowStock = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($lowStock) > 0) {
        $names = array_map(fn($i) => $i['item_name'], $lowStock);
        $insights[] = [
            'severity' => 'warning',
            'title' => sprintf('%d inventory item%s running low', count($lowStock), count($lowStock) === 1 ? ' is' : 's are'),
            'why' => implode(', ', $names) . ' ' . (count($lowStock) === 1 ? 'is' : 'are') . ' at or below its reorder level.',
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

    // ---- Per-animal production (Phase 6) ----
    //
    // ADDED TO the advisor, not replacing anything. The farm-level trend above
    // is untouched and still runs first; this contributes the signal that only
    // per-animal records can produce — WHICH animal moved — which a farm total
    // by definition cannot tell you.
    //
    // Guarded on the table existing so the advisor keeps working on an
    // installation that has not yet run migration 012, exactly as it already
    // degrades when a farm has no data.
    foreach (getProductionInsights($conn, $farmId) as $insight) {
        $insights[] = $insight;
    }

    usort($insights, fn($a, $b) => DFMS_SEVERITY_RANK[$a['severity']] <=> DFMS_SEVERITY_RANK[$b['severity']]);
    return $insights;
}

/**
 * Deterministic per-animal production insights.
 *
 * NO AI. Plain arithmetic over recorded rows, phrased as arithmetic: the
 * advisor reports that a figure moved, never why. "Production decreased
 * significantly" is a fact about the numbers; "the animal is sick" would be a
 * diagnosis this layer is not entitled to make and has no evidence for.
 *
 * Returns an empty list — not a fabricated reassurance — when there is nothing
 * recorded to reason about.
 *
 * @return list<array<string, string>>
 */
function getProductionInsights(PDO $conn, int $farmId): array {
    $insights = [];

    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'milk_yields'"
    );
    $stmt->execute();
    if ((int) $stmt->fetchColumn() === 0) {
        return $insights;
    }

    $timezone = dfms_setting('timezone', $farmId);
    $today = dfms_date_offset($timezone, 0);
    $windowStart = dfms_date_offset($timezone, -13);

    // How much of the herd is actually being recorded. A farm that has adopted
    // per-animal recording for three of forty cows should be told, because
    // every other per-animal number on the page is only about those three.
    $stmt = $conn->prepare(
        "SELECT
            (SELECT COUNT(DISTINCT y.cow_id) FROM milk_yields y
              WHERE y.farm_id = ? AND y.production_date BETWEEN ? AND ?) AS recorded_animals,
            (SELECT COUNT(*) FROM cows c
              WHERE c.farm_id = ? AND c.deleted_at IS NULL AND c.lifecycle_status = 'active'
                AND c.lactation_status = 'lactating') AS lactating"
    );
    $stmt->execute([$farmId, $windowStart, $today, $farmId]);
    $coverage = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['recorded_animals' => 0, 'lactating' => 0];

    $recorded = (int) $coverage['recorded_animals'];
    $lactating = (int) $coverage['lactating'];

    if ($recorded === 0) {
        // Nothing recorded is not a problem to alarm anyone about — it is a
        // capability they may not know exists. Stated once, as info.
        if ($lactating > 0) {
            $insights[] = [
                'severity' => 'info',
                'title'    => 'Per-animal milk recording is not in use yet',
                'why'      => sprintf(
                    '%d animal%s marked lactating, but no per-animal yields have been recorded in the last 14 days.',
                    $lactating, $lactating === 1 ? ' is' : 's are'
                ),
                'impact'   => 'Farm totals show the herd trend, but not which animal is behind a change.',
                'action'   => 'Record a milking session to start building per-animal history.',
                'link'     => 'modules/milk/record.php', 'link_label' => 'Record a milking',
            ];
        }
        return $insights;
    }

    if ($lactating > 0 && $recorded < $lactating) {
        $insights[] = [
            'severity' => 'info',
            'title'    => sprintf('Per-animal recording covers %d of %d lactating animals', $recorded, $lactating),
            'why'      => 'The remaining animals have no individual yields in the last 14 days.',
            // Says plainly what the partial coverage means for every other
            // per-animal figure shown, rather than letting it be read as
            // herd-wide.
            'impact'   => 'Per-animal figures below describe only the animals being recorded, not the whole herd.',
            'action'   => 'Record the remaining animals to make herd comparisons meaningful.',
            'link'     => 'modules/milk/?tab=animals', 'link_label' => 'View production',
        ];
    }

    // Animals whose latest recorded day sits well below their OWN baseline.
    // Comparing an animal to itself, not to the herd — on a mixed farm a
    // high-yielding cow and a late-lactation cow say nothing about each other.
    $stmt = $conn->prepare(
        "SELECT a.alert_id, a.description FROM alerts a
          WHERE a.farm_id = ? AND a.is_read = 0 AND a.alert_type = 'Production drop'
          ORDER BY FIELD(a.severity,'critical','warning','info'), a.alert_time DESC
          LIMIT 5"
    );
    $stmt->execute([$farmId]);
    $drops = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($drops !== []) {
        $insights[] = [
            'severity' => 'warning',
            'title'    => sprintf('%d animal%s producing well below their recent average',
                count($drops), count($drops) === 1 ? ' is' : 's are'),
            'why'      => implode(' ', array_column($drops, 'description')),
            'impact'   => 'A sustained drop reduces saleable volume and can be an early operational signal.',
            // Deliberately does NOT say what is wrong. It says where to look.
            'action'   => 'Check feed intake, the milking routine and the animal\'s health record.',
            'link'     => 'modules/milk/?tab=animals', 'link_label' => 'Review production',
        ];
    }

    return $insights;
}

// Unified, severity-sorted feed for the "What Needs Your Attention" list.
function getAttentionItems(PDO $conn, ?int $farmId, int $limit = 6): array {
    if ($farmId === null) {
        return [];
    }

    $items = [];

    // Farm-timezone window, as in getFarmAdvisorInsights().
    $fourteenDaysAgo = dfms_date_offset(dfms_setting('timezone', $farmId), -14);

    $stmt = $conn->prepare("
        SELECT h.event_type, h.severity, h.event_date, h.description, c.cow_id, c.unique_id, c.name
        FROM health_events h JOIN cows c ON h.cow_id = c.cow_id AND c.farm_id = h.farm_id
        WHERE h.farm_id = ? AND h.severity IN ('urgent','moderate') AND h.event_date >= ?
        ORDER BY FIELD(h.severity, 'urgent', 'moderate'), h.event_date DESC
    ");
    $stmt->execute([$farmId, $fourteenDaysAgo]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $items[] = [
            'severity' => $row['severity'] === 'urgent' ? 'critical' : 'warning',
            'title' => ($row['name'] ?: $row['unique_id']) . ' — ' . $row['event_type'],
            'subtitle' => $row['description'] ?: ('Recorded ' . date('M j', strtotime($row['event_date']))),
            'link' => 'modules/cows/view.php?id=' . $row['cow_id'],
        ];
    }

    $stmt = $conn->prepare("
        SELECT alert_id, alert_type, severity, description, entity_type, entity_id
        FROM alerts WHERE farm_id = ? AND is_read = 0 AND severity IN ('critical','warning')
        ORDER BY FIELD(severity, 'critical', 'warning'), alert_time DESC
    ");
    $stmt->execute([$farmId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $items[] = [
            'severity' => $row['severity'],
            'title' => $row['alert_type'],
            'subtitle' => $row['description'],
            'link' => alertTargetLink($row['entity_type'], $row['entity_id']),
        ];
    }

    $stmt = $conn->prepare("
        SELECT i.item_name, l.quantity, i.unit, i.reorder_level
        FROM inventory_items i JOIN inventory_levels l ON i.item_id = l.item_id AND l.farm_id = i.farm_id
        WHERE i.farm_id = ? AND l.quantity <= i.reorder_level
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
