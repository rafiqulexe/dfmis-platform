<?php
require_once '../../includes/auth.php';
require_once '../../includes/csrf.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';
require_once '../../includes/repository.php';
requireLogin();
requirePermission('milk.create');

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $production_date = $_POST['production_date'] ?? '';
    $total_milk_yield = $_POST['total_milk_yield'] ?? '';

    if (empty($production_date) || empty($total_milk_yield)) {
        $error = 'Please fill in all required fields';
    } else {
        try {
            $conn = getDBConnection();

            // Check if entry already exists for this date
            $stmt = $conn->prepare("SELECT production_id FROM milk_production WHERE production_date = ? AND farm_id = ?");
            $stmt->execute([$production_date, currentFarmId()]);

            if ($stmt->fetch()) {
                $error = 'A production record already exists for this date';
            } else {
                $stmt = $conn->prepare("INSERT INTO milk_production (production_date, total_milk_yield, farm_id) VALUES (?, ?, ?)");
                $stmt->execute([$production_date, $total_milk_yield, currentFarmId()]);
                $success = 'Production record added successfully';
            }
        } catch (PDOException $e) {
            $error = safeError($e, 'Error adding production record');
        }
    }
}

$page_title = 'Record Production';
$page_breadcrumb = [['label' => 'Milk Production', 'url' => 'index.php'], ['label' => 'Record']];
require_once '../../includes/header.php';
?>

<div class="py-6">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="sm:flex sm:items-center">
            <div class="sm:flex-auto">
                <h1 class="text-2xl font-semibold text-gray-900">Add Milk Production</h1>
                <p class="mt-2 text-sm text-gray-700">Record daily milk production</p>
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
                        <label for="production_date" class="block text-sm font-medium text-gray-700">Production Date</label>
                        <input type="date" name="production_date" id="production_date" required
                               value="<?php echo date('Y-m-d'); ?>"
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    </div>

                    <div>
                        <label for="total_milk_yield" class="block text-sm font-medium text-gray-700">Total Milk Yield (L)</label>
                        <input type="number" step="0.01" name="total_milk_yield" id="total_milk_yield" required
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