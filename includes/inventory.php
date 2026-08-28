<?php
// ============================================================================
// INVENTORY STOCK MOVEMENT — the one place stock quantity changes.
//
// ---------------------------------------------------------------------------
// WHY THIS FILE EXISTS, AND WHY THERE IS NO inventory_transactions TABLE
//
// The obvious Phase 6 move is a movement ledger: a table with one row per
// stock change. It was rejected, because this codebase ALREADY has a stock
// ledger and adding a table would make a second one.
//
// The established pattern, set by Phase 4's healthAdministerMedication(), is:
//
//     SELECT ... FOR UPDATE      lock the level row, scoped by farm_id
//     check                      refuse a move that would go below zero
//     UPDATE inventory_levels    through tenantExec, so {scope} applies
//     auditLog('stock.adjust')   old quantity, new quantity, and the REASON
//
// audit_log is therefore the movement history: it is tenant-scoped, immutable
// in practice, already read by modules/audit/, and already records who and
// when. A parallel inventory_transactions table would hold the same facts,
// could disagree with inventory_levels the first time one write succeeded and
// the other did not, and the one this file consulted would be the one nobody
// reconciles.
//
// ---------------------------------------------------------------------------
// WHAT CHANGED IN PHASE 6
//
// That sequence existed ONCE, inline, in the middle of a 200-line medication
// function. Phase 6 needs it a second time for feed consumption. Copying it
// would mean two implementations of "may stock go negative" — so it was
// EXTRACTED here and health.php now calls it too. This is a de-duplication,
// not a new subsystem: the SQL, the lock, the zero-floor rule and the audit
// action are byte-for-byte the behaviour Phase 4 shipped.
//
// ---------------------------------------------------------------------------
// CALLING CONTRACT
//
// inventoryConsume() MUST be called inside an existing transaction. It does
// not open one, deliberately: a stock deduction is never the whole of what a
// caller is doing — medication administration also writes an administration
// row and withdrawal periods, feed consumption also writes a consumption
// event — and those must commit or roll back TOGETHER with the deduction.
// Opening a nested transaction here would silently break that guarantee.
// ============================================================================

require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/repository.php';
require_once __DIR__ . '/audit.php';

/**
 * A stock rule the caller broke — safe to display verbatim.
 *
 * Deliberately its own type rather than a shared one: a caller in the health
 * domain and a caller in the feed domain both need to turn this into their own
 * domain's error, and catching a generic RuntimeException would also swallow
 * genuine faults.
 */
class InventoryStockError extends RuntimeException {}

/** Item categories that count as feed for the feeding workflows. */
const DFMS_FEED_CATEGORIES = ['feed', 'supplement'];

/**
 * Take quantity out of an item's stock, atomically and audibly.
 *
 * @param int    $itemId   an inventory_items id ALREADY resolved through
 *                         tenantFind() by the caller — this function locks on
 *                         farm_id as well, but the caller is responsible for
 *                         proving the item is theirs before getting here.
 * @param float  $quantity how much to remove. Must be > 0; a "consumption" of
 *                         zero is a caller bug, not a no-op worth accepting.
 * @param string $reason   short machine-ish phrase recorded in the audit
 *                         trail, e.g. 'feed consumption'. Never user text.
 * @param array  $context  extra safe scalars for the audit payload (cow_id,
 *                         location_id…). Passes through dfms_audit_sanitize().
 * @param ?string $unit    the item's unit, for the refusal message only.
 *                         "3.00 remaining" is unactionable when the operator
 *                         cannot tell whether that is kilograms or millilitres,
 *                         so the caller passes what it already knows.
 *
 * @return array{inventory_id: int, before: float, after: float}
 * @throws InventoryStockError when there is no stock row, or the move would
 *                             take the level below zero.
 */
function inventoryConsume(
    PDO $conn,
    int $itemId,
    float $quantity,
    string $reason,
    array $context = [],
    ?string $unit = null
): array {
    if ($quantity <= 0) {
        throw new InventoryStockError('Enter a quantity greater than zero.');
    }

    // Locked and scoped by farm_id, so the lock can never be taken on another
    // tenant's stock row even if $itemId were somehow foreign.
    $stmt = $conn->prepare(
        "SELECT inventory_id, quantity FROM inventory_levels
          WHERE item_id = ? AND farm_id = ? FOR UPDATE"
    );
    $stmt->execute([$itemId, currentFarmId()]);
    $level = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($level === false) {
        throw new InventoryStockError('That item has no stock record on this farm.');
    }

    $before = (float) $level['quantity'];
    $after = $before - $quantity;

    // The zero floor is the domain rule Phase 1 established and Phase 4 kept:
    // stock is a physical count, and a negative one is a data error rather
    // than a debt. Refusing here — inside the caller's transaction — rolls
    // back whatever else that caller had already written.
    if ($after < 0) {
        throw new InventoryStockError(
            'Not enough stock: ' . fmt_number($before, 2)
            . ($unit !== null && $unit !== '' ? ' ' . $unit : '') . ' remaining.'
        );
    }

    tenantExec(
        $conn,
        "UPDATE inventory_levels SET quantity = ? WHERE inventory_id = ? AND {scope}",
        [$after, (int) $level['inventory_id']]
    );

    // The movement record. Written for EVERY deduction regardless of which
    // domain asked for it, which is what makes the inventory trail complete
    // without anyone opening the module that caused the change.
    auditLog('stock.adjust', 'inventory_level', (int) $level['inventory_id'],
        ['quantity' => $before],
        array_merge(['quantity' => $after, 'reason' => $reason], $context)
    );

    return [
        'inventory_id' => (int) $level['inventory_id'],
        'before'       => $before,
        'after'        => $after,
    ];
}

/**
 * The current stock level for one item, or null when none is recorded.
 *
 * A missing level row is NOT zero. It means nobody has ever counted this item,
 * which is a different fact from having counted it and found none, and the two
 * must not be flattened — a caller deciding whether to warn about low stock
 * needs to know which it is looking at.
 */
function inventoryLevelFor(PDO $conn, int $itemId): ?float {
    $rows = tenantQuery(
        $conn,
        "SELECT l.quantity FROM inventory_levels l WHERE {scope} AND l.item_id = ?",
        [$itemId],
        'l'
    );
    return $rows === [] ? null : (float) $rows[0]['quantity'];
}

/**
 * Items on this farm usable as feed, with their current level.
 *
 * Reads the Phase 4 `category` column, falling back to the older free-text
 * `item_type` for rows created before categories existed — the same
 * belt-and-braces filter healthMedicationItems() uses for medicines, so a farm
 * that has not re-categorised its catalogue still sees its feed.
 *
 * @return list<array<string, mixed>>
 */
function inventoryFeedItems(PDO $conn): array {
    $placeholders = implode(',', array_fill(0, count(DFMS_FEED_CATEGORIES), '?'));
    return tenantQuery(
        $conn,
        "SELECT i.item_id, i.item_name, i.unit, i.category, i.reorder_level,
                COALESCE(l.quantity, 0) AS quantity,
                (l.inventory_id IS NULL) AS stock_unknown
           FROM inventory_items i
           LEFT JOIN inventory_levels l ON l.item_id = i.item_id AND l.farm_id = i.farm_id
          WHERE {scope}
            AND (i.category IN ($placeholders)
                 OR (i.category IS NULL AND i.item_type IN ('Feed','Fodder','Supplement')))
          ORDER BY i.item_name",
        DFMS_FEED_CATEGORIES,
        'i'
    );
}

/**
 * Is this item one the feeding workflows will accept?
 *
 * Checked in the domain rather than only in a <select>, because the item id
 * arrives from a form and a posted id that is real, on this farm, but is a
 * syringe rather than a feed would otherwise be consumed as feed.
 */
function inventoryIsFeedItem(array $item): bool {
    if (in_array((string) ($item['category'] ?? ''), DFMS_FEED_CATEGORIES, true)) {
        return true;
    }
    return ($item['category'] ?? null) === null
        && in_array((string) ($item['item_type'] ?? ''), ['Feed', 'Fodder', 'Supplement'], true);
}

/**
 * Raise or clear the standing low-stock alert for one item.
 *
 * The SAME dedupe-and-auto-resolve rule modules/inventory/update.php has used
 * since Phase 1 — one unread 'Low Inventory' alert per item at most, cleared
 * when the level recovers. Extracted so a deduction made from the feed module
 * keeps the alert queue as accurate as one made from the inventory module,
 * rather than leaving the alert to whichever page happened to be used.
 *
 * @return array{created: bool, resolved: bool}
 */
function inventorySyncLowStockAlert(PDO $conn, array $item, float $level): array {
    $threshold = (float) ($item['reorder_level'] ?? 10);
    $existing = tenantQuery(
        $conn,
        "SELECT a.alert_id FROM alerts a
          WHERE {scope} AND a.is_read = 0 AND a.alert_type = 'Low Inventory'
            AND a.entity_type = 'inventory_item' AND a.entity_id = ?",
        [(int) $item['item_id']],
        'a'
    );

    if ($level <= $threshold && $existing === []) {
        tenantInsert($conn, 'alerts', [
            'alert_time'  => date('Y-m-d H:i:s'),
            'alert_type'  => 'Low Inventory',
            'severity'    => 'warning',
            'description' => sprintf(
                'Low stock: %s (%s %s remaining, reorder at %s)',
                (string) $item['item_name'],
                rtrim(rtrim(number_format($level, 2, '.', ''), '0'), '.'),
                (string) ($item['unit'] ?? ''),
                rtrim(rtrim(number_format($threshold, 2, '.', ''), '0'), '.')
            ),
            'entity_type' => 'inventory_item',
            'entity_id'   => (int) $item['item_id'],
        ]);
        return ['created' => true, 'resolved' => false];
    }

    if ($level > $threshold && $existing !== []) {
        tenantExec(
            $conn,
            "UPDATE alerts SET is_read = 1, read_at = NOW()
              WHERE is_read = 0 AND alert_type = 'Low Inventory'
                AND entity_type = 'inventory_item' AND entity_id = ? AND {scope}",
            [(int) $item['item_id']]
        );
        return ['created' => false, 'resolved' => true];
    }

    return ['created' => false, 'resolved' => false];
}
