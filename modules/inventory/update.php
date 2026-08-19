<?php
require_once '../../includes/auth.php';
require_once '../../config/database.php';
require_once '../../includes/csrf.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';
require_once '../../includes/repository.php';
requireLogin();
requirePermission('inventory.update');

$inventory_id = isset($_GET['id']) ? $_GET['id'] : null;
$error_message = '';
$success_message = '';
$inventory = null;

if (!$inventory_id) {
    $_SESSION['error_message'] = "No inventory record specified.";
    header('Location: ' . BASE_URL . 'modules/inventory/');
    exit();
}

$conn = getDBConnection();

// Scoped lookup first, then join for the display fields.
$inventory = tenantFindOr404($conn, 'inventory_levels', 'inventory_id', $inventory_id);
$detail = tenantQuery(
    $conn,
    "SELECT ii.item_name, ii.item_type, ii.unit FROM inventory_items ii WHERE {scope} AND ii.item_id = ?",
    [$inventory['item_id']],
    'ii'
);
if (empty($detail)) {
    http_response_code(404);
    exit('Not found.');
}
$inventory = array_merge($inventory, $detail[0]);

// Handle form submission
if ($inventory && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $new_quantity = $_POST['quantity'] ?? '';
    $adjustment_type = $_POST['adjustment_type'] ?? '';
    $adjustment_amount = $_POST['adjustment_amount'] ?? '';

    try {
        $conn->beginTransaction();

        // Get current quantity
        $stmt = $conn->prepare("SELECT quantity FROM inventory_levels WHERE inventory_id = ? AND farm_id = ? FOR UPDATE");
        $stmt->execute([$inventory['inventory_id'], currentFarmId()]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($adjustment_type === 'set') {
            // Directly set new quantity
            $new_quantity = floatval($new_quantity);
            if ($new_quantity < 0) {
                throw new Exception("Quantity cannot be negative");
            }
            $final_quantity = $new_quantity;
        } else {
            // Add or subtract from current quantity
            $adjustment_amount = floatval($adjustment_amount);
            $final_quantity = $adjustment_type === 'add' 
                ? $current['quantity'] + $adjustment_amount
                : $current['quantity'] - $adjustment_amount;
                
            if ($final_quantity < 0) {
                throw new Exception("Cannot reduce stock below 0");
            }
        }

        // Update inventory level
        tenantExec(
            $conn,
            "UPDATE inventory_levels SET quantity = ? WHERE inventory_id = ? AND {scope}",
            [$final_quantity, $inventory['inventory_id']]
        );

        // Check if stock is low and create alert if necessary
        if ($final_quantity <= 10) { // You can adjust this threshold
            $stmt = $conn->prepare("
                INSERT INTO alerts (alert_time, alert_type, severity, description, farm_id)
                VALUES (NOW(), 'Low Inventory', 'warning', ?, ?)
            ");
            $description = "Low stock alert for " . $inventory['item_name'] . " (" . $final_quantity . " " . $inventory['unit'] . " remaining)";
            $stmt->execute([$description, currentFarmId()]);
        }

        $conn->commit();
        $_SESSION['success_message'] = "Stock updated successfully";
        header('Location: ' . BASE_URL . 'modules/inventory/');
        exit();

    } catch (PDOException $e) {
        $conn->rollBack();
        $error_message = safeError($e, "Update failed");
    } catch (Exception $e) {
        // Our own validation messages (e.g. "Cannot reduce stock below 0") are safe to show as-is.
        $conn->rollBack();
        $error_message = $e->getMessage();
    }
}

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