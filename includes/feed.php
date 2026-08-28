<?php
// ============================================================================
// FEED — rations (a plan) and consumption (an event that moves stock).
//
// ---------------------------------------------------------------------------
// A PLAN IS NOT A CONSUMPTION EVENT
//
// This is the distinction the whole file is built around.
//
//   RATION      "A lactating cow should get 8 kg of dairy meal a day."
//               A reusable definition. Changing it changes nothing physical.
//   CONSUMPTION "On 12 March we fed 240 kg of dairy meal to the milking barn."
//               A fact about the past that DREW DOWN STOCK.
//
// A ration NEVER consumes inventory by existing, and it never consumes it on a
// schedule. If it did, the stock level would become a projection of what
// should have happened rather than a count of what is in the store — and
// nobody looking at the number could tell which they were reading. A ration
// can PREFILL a consumption form; a person still confirms what was fed.
//
// ---------------------------------------------------------------------------
// FEED IS INVENTORY. THERE IS NO SECOND STOCK SYSTEM.
//
// The audit found feed already modelled: inventory_items.category = 'feed'
// (Phase 4). So there is no feed_items table here. Consumption resolves an
// inventory_items id through the tenant repository and deducts through
// inventoryConsume(), the same function medication administration uses. One
// stock rule, one zero-floor check, one audit action, two callers.
//
// ---------------------------------------------------------------------------
// SCOPE: WHY LOCATION AND NOT A NEW "GROUP" TABLE
//
// Feed is fed to a place far more often than to one animal. This codebase
// already has `locations` (barn, paddock, field…) from migration 004, and no
// animal-group concept at all. Inventing a grouping system for this phase
// would be speculative architecture; location IS the group, and an animal-
// level scope exists for the cases where one animal really is fed separately.
// ============================================================================

require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/repository.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/units.php';
require_once __DIR__ . '/livestock.php';
require_once __DIR__ . '/inventory.php';

/** A domain rule the user broke — safe to display verbatim. */
class FeedRuleViolation extends RuntimeException {}

/**
 * What a consumption event was fed to.
 *
 * A CLOSED SET. 'farm' means the whole herd and carries no target id; the
 * other two each require their own id and reject the other's.
 */
const DFMS_FEED_SCOPES = [
    'farm'     => 'Whole farm',
    'location' => 'A location',
    'animal'   => 'One animal',
];

function dfms_feed_scopes(): array {
    return DFMS_FEED_SCOPES;
}

function dfms_is_valid_feed_scope(?string $scope): bool {
    return $scope !== null && isset(DFMS_FEED_SCOPES[$scope]);
}

function dfms_feed_scope_label(?string $scope): string {
    return DFMS_FEED_SCOPES[$scope ?? ''] ?? (string) $scope;
}

// ===========================================================================
// RATIONS — the plan
// ===========================================================================

/**
 * Every ration on this farm, with its line count.
 *
 * @return list<array<string, mixed>>
 */
function feedRations(PDO $conn, bool $activeOnly = false): array {
    $filter = $activeOnly ? ' AND r.is_active = 1' : '';
    return tenantQuery(
        $conn,
        "SELECT r.*,
                (SELECT COUNT(*) FROM feed_ration_items ri
                  WHERE ri.ration_id = r.ration_id AND ri.farm_id = r.farm_id) AS item_count
           FROM feed_rations r
          WHERE {scope}" . $filter . "
          ORDER BY r.is_active DESC, r.name",
        [],
        'r'
    );
}

/** One ration, or null for missing AND foreign alike. */
function feedFindRation(PDO $conn, $rationId): ?array {
    return tenantFind($conn, 'feed_rations', 'ration_id', $rationId);
}

/**
 * The lines of a ration, joined to their inventory items.
 *
 * The join is scoped on farm_id on BOTH sides, so a line can never surface an
 * item belonging to another tenant even if a row were somehow mis-written.
 *
 * @return list<array<string, mixed>>
 */
function feedRationItems(PDO $conn, int $rationId): array {
    return tenantQuery(
        $conn,
        "SELECT ri.*, i.item_name, i.unit, i.category,
                COALESCE(l.quantity, 0) AS stock_quantity
           FROM feed_ration_items ri
           JOIN inventory_items i ON i.item_id = ri.item_id AND i.farm_id = ri.farm_id
           LEFT JOIN inventory_levels l ON l.item_id = i.item_id AND l.farm_id = i.farm_id
          WHERE {scope} AND ri.ration_id = ?
          ORDER BY i.item_name",
        [$rationId],
        'ri'
    );
}

/**
 * Create a ration.
 *
 * @throws FeedRuleViolation
 */
function feedCreateRation(PDO $conn, array $data): int {
    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '') {
        throw new FeedRuleViolation('Give the ration a name.');
    }

    try {
        $rationId = tenantInsert($conn, 'feed_rations', [
            'name'       => $name,
            'purpose'    => $data['purpose'] ?? null,
            'is_active'  => array_key_exists('is_active', $data) && !$data['is_active'] ? 0 : 1,
            'created_by' => currentUserId(),
        ]);
    } catch (PDOException $e) {
        // UNIQUE(farm_id, name). Let the database decide rather than racing a
        // SELECT, the same way milk_yields does.
        if ($e->getCode() === '23000') {
            throw new FeedRuleViolation('A ration with that name already exists on this farm.');
        }
        throw $e;
    }

    auditCreate('feed_ration', $rationId, ['name' => $name, 'purpose' => $data['purpose'] ?? null]);
    return $rationId;
}

/**
 * Rename or retire a ration. Lines are managed separately.
 *
 * @throws FeedRuleViolation
 */
function feedUpdateRation(PDO $conn, $rationId, array $data): bool {
    $existing = feedFindRation($conn, $rationId);
    if ($existing === null) {
        throw new FeedRuleViolation('That ration is not available.');
    }
    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '') {
        throw new FeedRuleViolation('Give the ration a name.');
    }

    $after = [
        'name'      => $name,
        'purpose'   => $data['purpose'] ?? null,
        'is_active' => array_key_exists('is_active', $data) && !$data['is_active'] ? 0 : 1,
    ];

    try {
        tenantExec(
            $conn,
            "UPDATE feed_rations SET name = ?, purpose = ?, is_active = ?, updated_at = NOW()
              WHERE ration_id = ? AND {scope}",
            [$after['name'], $after['purpose'], $after['is_active'], (int) $existing['ration_id']]
        );
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            throw new FeedRuleViolation('A ration with that name already exists on this farm.');
        }
        throw $e;
    }

    // Changed fields only.
    $before = [];
    $changed = [];
    foreach ($after as $field => $value) {
        if ((string) ($existing[$field] ?? '') !== (string) $value) {
            $before[$field] = $existing[$field] ?? null;
            $changed[$field] = $value;
        }
    }
    if ($changed !== []) {
        auditLog('feed_ration.update', 'feed_ration', (int) $existing['ration_id'], $before, $changed);
    }
    return true;
}

/**
 * Add or replace one line of a ration.
 *
 * The item is resolved through tenantFind() and then CHECKED FOR BEING FEED.
 * A posted item id that is real and on this farm but is a syringe would
 * otherwise become a ration line, and later a feed consumption.
 *
 * @throws FeedRuleViolation
 */
function feedSetRationItem(PDO $conn, $rationId, $itemId, float $quantity): int {
    $ration = feedFindRation($conn, $rationId);
    if ($ration === null) {
        throw new FeedRuleViolation('That ration is not available.');
    }
    $item = tenantFind($conn, 'inventory_items', 'item_id', $itemId);
    if ($item === null) {
        throw new FeedRuleViolation('That item is not in this farm\'s inventory.');
    }
    if (!inventoryIsFeedItem($item)) {
        throw new FeedRuleViolation('That item is not categorised as feed or a supplement.');
    }
    if ($quantity <= 0) {
        throw new FeedRuleViolation('Enter a quantity greater than zero.');
    }

    $existing = tenantQuery(
        $conn,
        "SELECT ri.ration_item_id FROM feed_ration_items ri
          WHERE {scope} AND ri.ration_id = ? AND ri.item_id = ?",
        [(int) $ration['ration_id'], (int) $item['item_id']],
        'ri'
    );

    if ($existing !== []) {
        $lineId = (int) $existing[0]['ration_item_id'];
        tenantExec(
            $conn,
            "UPDATE feed_ration_items SET quantity = ? WHERE ration_item_id = ? AND {scope}",
            [$quantity, $lineId]
        );
        auditLog('feed_ration.item_update', 'feed_ration', (int) $ration['ration_id'], null, [
            'item_id' => (int) $item['item_id'], 'quantity' => $quantity,
        ]);
        return $lineId;
    }

    $lineId = tenantInsert($conn, 'feed_ration_items', [
        'ration_id' => (int) $ration['ration_id'],
        'item_id'   => (int) $item['item_id'],
        'quantity'  => $quantity,
    ]);
    auditLog('feed_ration.item_add', 'feed_ration', (int) $ration['ration_id'], null, [
        'item_id' => (int) $item['item_id'], 'quantity' => $quantity,
    ]);
    return $lineId;
}

/**
 * Remove one line from a ration.
 *
 * @throws FeedRuleViolation
 */
function feedRemoveRationItem(PDO $conn, $rationItemId): bool {
    $line = tenantFind($conn, 'feed_ration_items', 'ration_item_id', $rationItemId);
    if ($line === null) {
        throw new FeedRuleViolation('That ration line is not available.');
    }
    tenantExec($conn, "DELETE FROM feed_ration_items WHERE ration_item_id = ? AND {scope}",
        [(int) $line['ration_item_id']]);
    auditLog('feed_ration.item_remove', 'feed_ration', (int) $line['ration_id'], [
        'item_id' => (int) $line['item_id'], 'quantity' => (float) $line['quantity'],
    ], null);
    return true;
}

// ===========================================================================
// CONSUMPTION — the event that moves stock
// ===========================================================================

/**
 * Record that feed was consumed, and take it out of stock.
 *
 * ONE TRANSACTION covers the stock deduction, the event row and the low-stock
 * alert. If any part fails — most importantly, if the deduction would take the
 * level below zero — the whole thing rolls back and no event row survives
 * claiming feed was used that never left the store.
 *
 * @param array{consumed_on: string, item_id: mixed, quantity: float,
 *              scope_type: string, location_id?: mixed, cow_id?: mixed,
 *              ration_id?: mixed, notes?: ?string} $data
 * @return int the new event id
 * @throws FeedRuleViolation
 */
function feedRecordConsumption(PDO $conn, array $data): int {
    // ---- everything that can be rejected without a write, first ----------
    $item = tenantFind($conn, 'inventory_items', 'item_id', $data['item_id'] ?? null);
    if ($item === null) {
        throw new FeedRuleViolation('That item is not in this farm\'s inventory.');
    }
    if (!inventoryIsFeedItem($item)) {
        throw new FeedRuleViolation('That item is not categorised as feed or a supplement.');
    }

    $quantity = (float) ($data['quantity'] ?? 0);
    if ($quantity <= 0) {
        throw new FeedRuleViolation('Enter a quantity greater than zero.');
    }

    $date = (string) ($data['consumed_on'] ?? '');
    if ($date === '' || $date > farmToday()) {
        throw new FeedRuleViolation('Feed consumption cannot be recorded for a future date.');
    }

    $scopeType = (string) ($data['scope_type'] ?? 'farm');
    if (!dfms_is_valid_feed_scope($scopeType)) {
        throw new FeedRuleViolation('Choose what the feed was given to.');
    }

    // Exactly one target, and only the one the scope names. Enforced here
    // rather than by a CHECK constraint — see migration 012 for why.
    $locationId = null;
    $cowId = null;

    if ($scopeType === 'location') {
        $location = tenantFind($conn, 'locations', 'location_id', $data['location_id'] ?? null);
        if ($location === null) {
            throw new FeedRuleViolation('That location is not on this farm.');
        }
        $locationId = (int) $location['location_id'];
    } elseif ($scopeType === 'animal') {
        $animal = animalFind($conn, $data['cow_id'] ?? null);
        if ($animal === null) {
            throw new FeedRuleViolation('That animal is not on this farm.');
        }
        $cowId = (int) $animal['cow_id'];
    }

    $rationId = null;
    if (!empty($data['ration_id'])) {
        $ration = feedFindRation($conn, $data['ration_id']);
        if ($ration === null) {
            throw new FeedRuleViolation('That ration is not available.');
        }
        $rationId = (int) $ration['ration_id'];
    }

    // ---- one transaction: deduct, record, reconcile the alert -------------
    try {
        return tenantTransaction($conn, function (PDO $conn) use (
            $item, $quantity, $date, $scopeType, $locationId, $cowId, $rationId, $data
        ): int {
            // The SHARED helper — the same one medication administration uses.
            // It locks, refuses to go below zero, updates and writes the
            // 'stock.adjust' movement entry.
            $movement = inventoryConsume(
                $conn,
                (int) $item['item_id'],
                $quantity,
                'feed consumption',
                array_filter([
                    'scope_type'  => $scopeType,
                    'location_id' => $locationId,
                    'cow_id'      => $cowId,
                ], static fn ($v): bool => $v !== null),
                $item['unit'] !== null ? (string) $item['unit'] : null
            );

            $eventId = tenantInsert($conn, 'feed_consumption_events', [
                'consumed_on' => $date,
                'item_id'     => (int) $item['item_id'],
                'quantity'    => $quantity,
                'scope_type'  => $scopeType,
                'location_id' => $locationId,
                'cow_id'      => $cowId,
                'ration_id'   => $rationId,
                'notes'       => $data['notes'] ?? null,
                'recorded_by' => currentUserId(),
            ]);

            // Inside the transaction, so a rolled-back deduction cannot leave
            // a low-stock alert behind describing a level that never happened.
            inventorySyncLowStockAlert($conn, $item, $movement['after']);

            return $eventId;
        });
    } catch (InventoryStockError $e) {
        // Translated into this domain's error so the caller catches one type.
        // The message is ours and safe to show verbatim.
        throw new FeedRuleViolation($e->getMessage());
    }
}

/**
 * Recent consumption events, newest first, with their item and target.
 *
 * @return list<array<string, mixed>>
 */
function feedConsumptionEvents(PDO $conn, array $filters = [], int $limit = 100): array {
    $limit = max(1, min($limit, 500));
    $where = '';
    $params = [];

    if (!empty($filters['from'])) {
        $where .= ' AND e.consumed_on >= ?';
        $params[] = (string) $filters['from'];
    }
    if (!empty($filters['to'])) {
        $where .= ' AND e.consumed_on <= ?';
        $params[] = (string) $filters['to'];
    }
    if (!empty($filters['item_id'])) {
        $where .= ' AND e.item_id = ?';
        $params[] = (int) $filters['item_id'];
    }

    return tenantQuery(
        $conn,
        "SELECT e.*, i.item_name, i.unit,
                l.name AS location_name, c.unique_id AS animal_tag, c.name AS animal_name,
                r.name AS ration_name
           FROM feed_consumption_events e
           JOIN inventory_items i ON i.item_id = e.item_id AND i.farm_id = e.farm_id
           LEFT JOIN locations l ON l.location_id = e.location_id AND l.farm_id = e.farm_id
           LEFT JOIN cows c ON c.cow_id = e.cow_id AND c.farm_id = e.farm_id
           LEFT JOIN feed_rations r ON r.ration_id = e.ration_id AND r.farm_id = e.farm_id
          WHERE {scope}" . $where . "
          ORDER BY e.consumed_on DESC, e.event_id DESC
          LIMIT " . (int) $limit,
        $params,
        'e'
    );
}

/**
 * Feed used per item over a window.
 *
 * Only items that were ACTUALLY consumed appear. An item with no events is
 * absent rather than present with a zero, for the same reason a day with no
 * production is absent from productionDailyTotals(): "none was used" and
 * "nobody recorded any" are different, and only one of them is knowable here.
 *
 * @return list<array{item_id: int, item_name: string, unit: ?string, total: float, events: int}>
 */
function feedUsageByItem(PDO $conn, string $fromDate, string $toDate): array {
    $rows = tenantQuery(
        $conn,
        "SELECT e.item_id, i.item_name, i.unit,
                SUM(e.quantity) AS total, COUNT(*) AS events
           FROM feed_consumption_events e
           JOIN inventory_items i ON i.item_id = e.item_id AND i.farm_id = e.farm_id
          WHERE {scope} AND e.consumed_on BETWEEN ? AND ?
          GROUP BY e.item_id, i.item_name, i.unit
          ORDER BY total DESC",
        [$fromDate, $toDate],
        'e'
    );

    $usage = [];
    foreach ($rows as $row) {
        $usage[] = [
            'item_id'   => (int) $row['item_id'],
            'item_name' => (string) $row['item_name'],
            'unit'      => $row['unit'],
            'total'     => (float) $row['total'],
            'events'    => (int) $row['events'],
        ];
    }
    return $usage;
}

/**
 * Total feed consumed per day over a window, from the EVENT records.
 *
 * Kept separate from the legacy farm-level `feed_consumption` daily totals for
 * the same reason production is: they are different measurements and merging
 * them would double-count.
 *
 * @return array<string, float>
 */
function feedDailyTotals(PDO $conn, string $fromDate, string $toDate): array {
    $rows = tenantQuery(
        $conn,
        "SELECT e.consumed_on, SUM(e.quantity) AS total
           FROM feed_consumption_events e
          WHERE {scope} AND e.consumed_on BETWEEN ? AND ?
          GROUP BY e.consumed_on
          ORDER BY e.consumed_on",
        [$fromDate, $toDate],
        'e'
    );

    $totals = [];
    foreach ($rows as $row) {
        $totals[(string) $row['consumed_on']] = (float) $row['total'];
    }
    return $totals;
}

/**
 * What a ration would draw from stock if fed to N animals.
 *
 * A PROJECTION, and named so. It consumes nothing and writes nothing — it
 * exists to prefill a consumption form and to warn that the store cannot
 * cover a planned feeding. `sufficient` is false when the current level would
 * not cover it; `stock_known` is false when no level has ever been recorded,
 * which is not the same as a level of zero.
 *
 * @return list<array{item_id: int, item_name: string, unit: ?string,
 *                    required: float, stock: float, stock_known: bool, sufficient: bool}>
 */
function feedRationProjection(PDO $conn, int $rationId, float $animals = 1.0): array {
    $animals = max(0.0, $animals);
    $projection = [];

    foreach (feedRationItems($conn, $rationId) as $line) {
        $required = (float) $line['quantity'] * $animals;
        $stock = inventoryLevelFor($conn, (int) $line['item_id']);
        $projection[] = [
            'item_id'     => (int) $line['item_id'],
            'item_name'   => (string) $line['item_name'],
            'unit'        => $line['unit'],
            'required'    => $required,
            'stock'       => $stock ?? 0.0,
            'stock_known' => $stock !== null,
            'sufficient'  => $stock !== null && $stock >= $required,
        ];
    }
    return $projection;
}
