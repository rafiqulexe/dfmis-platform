<?php
require_once '../../includes/auth.php';
require_once '../../includes/errors.php';
require_once '../../includes/csrf.php';
require_once '../../includes/audit.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';
require_once '../../includes/repository.php';
require_once '../../includes/validation.php';

requireAuth('inventory.update');

$error_message = '';
$success_message = '';

$inventory_id = dfms_route_id('id');
$conn = getDBConnection();

// Scoped lookup first, then join for the display fields.
$inventory = tenantFindOr404($conn, 'inventory_levels', 'inventory_id', $inventory_id);
$detail = tenantQuery(
    $conn,
    "SELECT ii.item_name, ii.item_type, ii.unit, ii.reorder_level FROM inventory_items ii WHERE {scope} AND ii.item_id = ?",
    [$inventory['item_id']],
    'ii'
);
if (empty($detail)) {
    dfms_abort(404, 'inventory_levels#' . $inventory['inventory_id'] . ' references an item outside the active farm');
}
$inventory = array_merge($inventory, $detail[0]);

// A user-facing validation failure. Distinct from a system fault so the
// message can be shown verbatim without leaking internals.
class StockValidationError extends RuntimeException {}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $v = new DfmsValidator($_POST);
    // Baseline audit C-7: anything that was not 'set' or 'add' silently
    // SUBTRACTED stock. Unknown input is now rejected outright.
    $adjustment_type = $v->enum('adjustment_type', ['set', 'add', 'subtract']);
    $new_quantity = $v->decimal('quantity', ['nullable' => true, 'min' => 0, 'max' => 9999999]);
    $adjustment_amount = $v->decimal('adjustment_amount', ['nullable' => true, 'min' => 0, 'max' => 9999999]);

    if (!$v->fails()) {
        if ($adjustment_type === 'set' && $new_quantity === null) {
            $v->required('quantity', 'Enter the new quantity.');
        }
        if ($adjustment_type !== 'set' && $adjustment_amount === null) {
            $v->required('adjustment_amount', 'Enter the amount to adjust by.');
        }
    }

    if ($v->fails()) {
        $error_message = $v->firstError();
    } else {
        try {
            $result = tenantTransaction($conn, function (PDO $conn) use (
                $inventory, $adjustment_type, $new_quantity, $adjustment_amount
            ) {
                // Row lock so two concurrent adjustments cannot both read the
                // same starting quantity and lose one of the writes.
                $stmt = $conn->prepare(
                    "SELECT quantity FROM inventory_levels
                      WHERE inventory_id = ? AND farm_id = ? FOR UPDATE"
                );
                $stmt->execute([$inventory['inventory_id'], currentFarmId()]);
                $current = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($current === false) {
                    throw new StockValidationError('That stock record is no longer available.');
                }

                $before = (float) $current['quantity'];

                if ($adjustment_type === 'set') {
                    $final = $new_quantity;
                } elseif ($adjustment_type === 'add') {
                    $final = $before + $adjustment_amount;
                } else {
                    $final = $before - $adjustment_amount;
                }

                if ($final < 0) {
                    throw new StockValidationError('Cannot reduce stock below 0.');
                }

                tenantExec(
                    $conn,
                    "UPDATE inventory_levels SET quantity = ? WHERE inventory_id = ? AND {scope}",
                    [$final, $inventory['inventory_id']]
                );

                // Baseline audit C-3: this used to insert a fresh alert on
                // EVERY save at or below threshold, with no dedupe — and C-2
                // meant none could ever be cleared. Now: raise one only when
                // none is outstanding, and resolve it when stock recovers.
                $threshold = (float) ($inventory['reorder_level'] ?? 10);
                $existing = tenantQuery(
                    $conn,
                    "SELECT alert_id FROM alerts
                      WHERE {scope} AND is_read = 0 AND alert_type = 'Low Inventory'
                        AND entity_type = 'inventory_item' AND entity_id = ?",
                    [$inventory['item_id']]
                );

                if ($final <= $threshold && empty($existing)) {
                    tenantInsert($conn, 'alerts', [
                        'alert_time'  => date('Y-m-d H:i:s'),
                        'alert_type'  => 'Low Inventory',
                        'severity'    => 'warning',
                        'description' => sprintf(
                            'Low stock: %s (%s %s remaining, reorder at %s)',
                            $inventory['item_name'],
                            rtrim(rtrim(number_format($final, 2, '.', ''), '0'), '.'),
                            $inventory['unit'],
                            rtrim(rtrim(number_format($threshold, 2, '.', ''), '0'), '.')
                        ),
                        'entity_type' => 'inventory_item',
                        'entity_id'   => (int) $inventory['item_id'],
                    ]);
                } elseif ($final > $threshold && !empty($existing)) {
                    // Stock recovered — clear the standing alert rather than
                    // leaving a stale warning in the queue.
                    tenantExec(
                        $conn,
                        "UPDATE alerts SET is_read = 1, read_at = NOW()
                          WHERE is_read = 0 AND alert_type = 'Low Inventory'
                            AND entity_type = 'inventory_item' AND entity_id = ? AND {scope}",
                        [$inventory['item_id']]
                    );
                }

                return ['before' => $before, 'after' => $final];
            });

            auditLog('inventory_level.adjust', 'inventory_level', (int) $inventory['inventory_id'],
                ['quantity' => $result['before']],
                [
                    'quantity'        => $result['after'],
                    'adjustment_type' => $adjustment_type,
                    'item_name'       => $inventory['item_name'],
                ]
            );

            redirectWith('modules/inventory/', 'success', 'Stock updated successfully.');
        } catch (StockValidationError $e) {
            // Our own validation messages are safe to show as-is.
            $error_message = $e->getMessage();
        } catch (PDOException $e) {
            $error_message = safeError($e, "Update failed");
        }
    }
}

$page_title = 'Update Stock';
$page_breadcrumb = [['label' => 'Inventory', 'url' => 'index.php'], ['label' => 'Update Stock']];
require_once '../../includes/header.php';
?>

<div class="py-6">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="sm:flex sm:items-center">
            <div class="sm:flex-auto">
                <h1 class="text-2xl font-semibold text-gray-900">Update Stock Level</h1>
                <p class="mt-2 text-sm text-gray-700">
                    Update stock level for <?php echo htmlspecialchars($inventory['item_name']); ?> 
                    (Current: <?php echo htmlspecialchars($inventory['quantity']); ?> <?php echo htmlspecialchars($inventory['unit']); ?>)
                </p>
            </div>
            <div class="mt-4 sm:mt-0 sm:ml-16 sm:flex-none">
                <a href="<?php echo BASE_URL; ?>modules/inventory/" 
                   class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                    Back to List
                </a>
            </div>
        </div>

        <?php if ($error_message): ?>
            <div class="mt-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <?php if ($inventory): ?>
            <div class="mt-8 bg-white shadow rounded-lg">
                <form method="POST" action="" class="space-y-6 p-6">
                    <?php csrf_field(); ?>
                    <div class="grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label class="block text-sm font-medium text-gray-700">Adjustment Type</label>
                            <div class="mt-2 space-y-4">
                                <div class="flex items-center">
                                    <input type="radio" id="set" name="adjustment_type" value="set" 
                                           class="focus:ring-blue-500 h-4 w-4 text-blue-600 border-gray-300"
                                           onclick="toggleAdjustmentFields('set')">
                                    <label for="set" class="ml-3 block text-sm font-medium text-gray-700">
                                        Set new quantity
                                    </label>
                                </div>
                                <div class="flex items-center">
                                    <input type="radio" id="add" name="adjustment_type" value="add" 
                                           class="focus:ring-blue-500 h-4 w-4 text-blue-600 border-gray-300"
                                           onclick="toggleAdjustmentFields('add')">
                                    <label for="add" class="ml-3 block text-sm font-medium text-gray-700">
                                        Add to current stock
                                    </label>
                                </div>
                                <div class="flex items-center">
                                    <input type="radio" id="subtract" name="adjustment_type" value="subtract" 
                                           class="focus:ring-blue-500 h-4 w-4 text-blue-600 border-gray-300"
                                           onclick="toggleAdjustmentFields('subtract')">
                                    <label for="subtract" class="ml-3 block text-sm font-medium text-gray-700">
                                        Subtract from current stock
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div id="new_quantity_field" style="display: none;">
                            <label for="quantity" class="block text-sm font-medium text-gray-700">New Quantity</label>
                            <input type="number" step="0.01" min="0" id="quantity" name="quantity" 
                                class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                        </div>

                        <div id="adjustment_amount_field" style="display: none;">
                            <label for="adjustment_amount" class="block text-sm font-medium text-gray-700">Adjustment Amount</label>
                            <input type="number" step="0.01" min="0" id="adjustment_amount" name="adjustment_amount" 
                                class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                        </div>
                    </div>

                    <div class="flex justify-end pt-5">
                        <button type="submit" 
                            class="ml-3 inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                            Update Stock
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function toggleAdjustmentFields(type) {
    const newQuantityField = document.getElementById('new_quantity_field');
    const adjustmentAmountField = document.getElementById('adjustment_amount_field');
    
    if (type === 'set') {
        newQuantityField.style.display = 'block';
        adjustmentAmountField.style.display = 'none';
    } else {
        newQuantityField.style.display = 'none';
        adjustmentAmountField.style.display = 'block';
    }
}
</script>

<?php require_once '../../includes/footer.php'; ?> 