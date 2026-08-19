<?php
require_once '../../includes/auth.php';
require_once '../../includes/csrf.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';
require_once '../../includes/repository.php';
requireLogin();
requirePermission('feeding.create');

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $feed_time = $_POST['feed_time'] ?? '';
    $feed_type = $_POST['feed_type'] ?? '';
    $feed_amount = $_POST['feed_amount'] ?? '';

    if (empty($feed_time) || empty($feed_type) || empty($feed_amount)) {
        $error = 'Please fill in all required fields';
    } else {
        try {
            $conn = getDBConnection();
            $stmt = $conn->prepare("INSERT INTO feeding_schedules (feed_time, feed_type, feed_amount, farm_id) VALUES (?, ?, ?, ?)");
            $stmt->execute([$feed_time, $feed_type, $feed_amount, currentFarmId()]);
            $success = 'Feeding schedule added successfully';
        } catch (PDOException $e) {
            $error = safeError($e, 'Error adding schedule');
        }
    }
}

require_once '../../includes/header.php';
?>

<div class="py-6">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="sm:flex sm:items-center">
            <div class="sm:flex-auto">
                <h1 class="text-2xl font-semibold text-gray-900">Add Feeding Schedule</h1>
                <p class="mt-2 text-sm text-gray-700">Create a new feeding schedule</p>
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
                        <label for="feed_time" class="block text-sm font-medium text-gray-700">Feed Time</label>
                        <input type="time" name="feed_time" id="feed_time" required
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    </div>

                    <div>
                        <label for="feed_type" class="block text-sm font-medium text-gray-700">Feed Type</label>
                        <select name="feed_type" id="feed_type" required
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                            <option value="">Select feed type</option>
                            <option value="Hay">Hay</option>
                            <option value="Grain">Grain</option>
                            <option value="Silage">Silage</option>
                            <option value="Mixed">Mixed Feed</option>
                        </select>
                    </div>

                    <div>
                        <label for="feed_amount" class="block text-sm font-medium text-gray-700">Amount (kg)</label>
                        <input type="number" step="0.01" name="feed_amount" id="feed_amount" required
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