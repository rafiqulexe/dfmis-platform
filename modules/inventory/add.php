<?php
require_once '../../includes/auth.php';
require_once '../../includes/errors.php';
require_once '../../includes/csrf.php';
require_once '../../includes/audit.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';
require_once '../../includes/repository.php';
require_once '../../includes/validation.php';

requireAuth('inventory.create');

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $v = new DfmsValidator($_POST);
    $item_name = $v->string('item_name', 1, 100);
    $item_type = $v->string('item_type', 1, 50);
    $unit = $v->string('unit', 1, 50);
    $initial_quantity = $v->decimal('initial_quantity', ['nullable' => true, 'min' => 0, 'max' => 9999999]) ?? 0.0;
    // Per-item reorder level replaces the threshold that was hardcoded as 10
    // in three places and was unit-blind (baseline audit C-9).
    $reorder_level = $v->decimal('reorder_level', ['nullable' => true, 'min' => 0, 'max' => 9999999]) ?? 10.0;

    if ($v->fails()) {
        $error = $v->firstError();
    } else {
        try {
            $conn = getDBConnection();

            // Baseline audit C-5: the old catch called rollBack()
            // unconditionally, which throws when no transaction is open —
            // inside the handler, so it escaped as a fatal. tenantTransaction()
            // makes the correct shape the only shape.
            $itemId = tenantTransaction($conn, function (PDO $conn) use ($item_name, $item_type, $unit, $initial_quantity, $reorder_level) {
                $itemId = tenantInsert($conn, 'inventory_items', [
                    'item_name'     => $item_name,
                    'item_type'     => $item_type,
                    'unit'          => $unit,
                    'reorder_level' => $reorder_level,
                ]);

                // Always create a level row, even at zero, so an item always
                // has a stock record to adjust rather than a missing join.
                tenantInsert($conn, 'inventory_levels', [
                    'item_id'  => $itemId,
                    'quantity' => $initial_quantity,
                ]);

                return $itemId;
            });

            auditCreate('inventory_item', $itemId, [
                'item_name'        => $item_name,
                'item_type'        => $item_type,
                'unit'             => $unit,
                'initial_quantity' => $initial_quantity,
                'reorder_level'    => $reorder_level,
            ]);
            $success = 'Item added successfully';
        } catch (PDOException $e) {
            $error = safeError($e, 'Error adding item');
        }
    }
}

$page_title = 'Add Item';
$page_breadcrumb = [['label' => 'Inventory', 'url' => 'index.php'], ['label' => 'Add Item']];
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