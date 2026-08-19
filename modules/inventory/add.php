<?php
require_once '../../includes/auth.php';
require_once '../../includes/csrf.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';
require_once '../../includes/tenant.php';
requireLogin();
requirePermission('inventory.create');

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $item_name = trim($_POST['item_name'] ?? '');
    $item_type = $_POST['item_type'] ?? '';
    $unit = $_POST['unit'] ?? '';
    $initial_quantity = $_POST['initial_quantity'] ?? 0;

    if (empty($item_name) || empty($item_type) || empty($unit)) {
        $error = 'Please fill in all required fields';
    } else {
        try {
            $conn = getDBConnection();
            $conn->beginTransaction();

            // Add item
            $stmt = $conn->prepare("INSERT INTO inventory_items (item_name, item_type, unit, farm_id) VALUES (?, ?, ?, ?)");
            $stmt->execute([$item_name, $item_type, $unit, currentFarmId()]);
            $item_id = $conn->lastInsertId();

            // Set initial quantity
            if ($initial_quantity > 0) {
                $stmt = $conn->prepare("INSERT INTO inventory_levels (item_id, quantity, farm_id) VALUES (?, ?, ?)");
                $stmt->execute([$item_id, $initial_quantity, currentFarmId()]);
            }

            $conn->commit();
            $success = 'Item added successfully';
        } catch (PDOException $e) {
            $conn->rollBack();
            $error = safeError($e, 'Error adding item');
        }
    }
}

require_once '../../includes/header.php';
?>

<div class="py-6">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="sm:flex sm:items-center">
            <div class="sm:flex-auto">
                <h1 class="text-2xl font-semibold text-gray-900">Add Inventory Item</h1>
                <p class="mt-2 text-sm text-gray-700">Add a new item to inventory</p>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="mt-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="mt-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="mt-8 space-y-6">
            <?php csrf_field(); ?>
            <div class="bg-white shadow px-4 py-5 sm:rounded-lg sm:p-6">
                <div class="grid grid-cols-1 gap-6">
                    <div>
                        <label for="item_name" class="block text-sm font-medium text-gray-700">Item Name</label>
                        <input type="text" name="item_name" id="item_name" required
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    </div>

                    <div>
                        <label for="item_type" class="block text-sm font-medium text-gray-700">Item Type</label>
                        <select name="item_type" id="item_type" required
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                            <option value="">Select type</option>
                            <option value="Feed">Feed</option>
                            <option value="Medicine">Medicine</option>
                            <option value="Equipment">Equipment</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>

                    <div>
                        <label for="unit" class="block text-sm font-medium text-gray-700">Unit</label>
                        <input type="text" name="unit" id="unit" required
                               placeholder="e.g., kg, liters, pieces"
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    </div>

                    <div>
                        <label for="initial_quantity" class="block text-sm font-medium text-gray-700">Initial Quantity</label>
                        <input type="number" step="0.01" name="initial_quantity" id="initial_quantity"
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    </div>
                </div>
            </div>

            <div class="flex justify-end">
                <a href="index.php" class="bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Cancel
                </a>
                <button type="submit" class="ml-3 inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                    Save
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?> 