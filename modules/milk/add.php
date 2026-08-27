<?php
require_once '../../includes/auth.php';
require_once '../../includes/errors.php';
require_once '../../includes/csrf.php';
require_once '../../includes/audit.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';
require_once '../../includes/repository.php';
require_once '../../includes/validation.php';

requireAuth('milk.create');

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $v = new DfmsValidator($_POST);
    $production_date = $v->date('production_date');
    // Baseline audit C-6: this accepted any string while milk/edit.php
    // checked is_numeric. A herd cannot produce a negative or absurd yield.
    $total_milk_yield = $v->decimal('total_milk_yield', ['min' => 0, 'max' => 200000]);

    if ($v->fails()) {
        $error = $v->firstError();
    } else {
        try {
            $conn = getDBConnection();
            // Baseline audit T-5: the old check-then-insert was a race. The
            // database now enforces one record per farm per day, so we insert
            // and let the constraint decide.
            $id = tenantInsert($conn, 'milk_production', [
                'production_date'  => $production_date,
                'total_milk_yield' => $total_milk_yield,
            ]);
            auditCreate('milk_production', $id, [
                'production_date'  => $production_date,
                'total_milk_yield' => $total_milk_yield,
            ]);
            $success = 'Production record added successfully';
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $error = 'A production record already exists for this date.';
            } else {
                $error = safeError($e, 'Error adding production record');
            }
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