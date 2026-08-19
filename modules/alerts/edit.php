<?php
require_once '../../includes/auth.php';
require_once '../../config/database.php';
require_once '../../includes/csrf.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';
requireLogin();
requirePermission('alerts.edit');

$alert_id = isset($_GET['id']) ? $_GET['id'] : null;
$error_message = '';
$success_message = '';

// Fetch alert details if ID is provided
if ($alert_id) {
    try {
        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT * FROM alerts WHERE alert_id = ?");
        $stmt->execute([$alert_id]);
        $alert = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$alert) {
            $error_message = "Alert not found";
        }
    } catch (PDOException $e) {
        $error_message = safeError($e, "Database error");
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $alert_type = $_POST['alert_type'] ?? '';
    $description = $_POST['description'] ?? '';
    $alert_time = $_POST['alert_time'] ?? '';

    try {
        $conn = getDBConnection();
        $stmt = $conn->prepare("UPDATE alerts SET alert_type = ?, description = ?, alert_time = ? WHERE alert_id = ?");
        $stmt->execute([$alert_type, $description, $alert_time, $alert_id]);
        $success_message = "Alert updated successfully";

        // Redirect back to index after successful update
        header("Location: index.php");
        exit();
    } catch (PDOException $e) {
        $error_message = safeError($e, "Update failed");
    }
}

require_once '../../includes/header.php';
?>

<div class="py-6">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="sm:flex sm:items-center">
            <div class="sm:flex-auto">
                <h1 class="text-2xl font-semibold text-gray-900">Edit Alert</h1>
            </div>
            <div class="mt-4 sm:mt-0 sm:ml-16 sm:flex-none">
                <a href="index.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                    Back to List
                </a>
            </div>
        </div>

        <?php if ($error_message): ?>
            <div class="mt-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <?php if ($success_message): ?>
            <div class="mt-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative">
                <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>

        <?php if ($alert): ?>
            <div class="mt-8 bg-white shadow rounded-lg">
                <form method="POST" action="" class="space-y-6 p-6">
                    <?php csrf_field(); ?>
                    <div class="grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-2">
                        <div>
                            <label for="alert_time" class="block text-sm font-medium text-gray-700">Alert Time</label>
                            <input type="datetime-local" id="alert_time" name="alert_time" 
                                value="<?php echo date('Y-m-d\TH:i', strtotime($alert['alert_time'])); ?>" required
                                class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                        </div>

                        <div>
                            <label for="alert_type" class="block text-sm font-medium text-gray-700">Alert Type</label>
                            <select id="alert_type" name="alert_type" required
                                class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                <option value="Low Inventory" <?php echo $alert['alert_type'] === 'Low Inventory' ? 'selected' : ''; ?>>Low Inventory</option>
                                <option value="Health" <?php echo $alert['alert_type'] === 'Health' ? 'selected' : ''; ?>>Health</option>
                                <option value="Maintenance" <?php echo $alert['alert_type'] === 'Maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                                <option value="Other" <?php echo $alert['alert_type'] === 'Other' ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>

                        <div class="sm:col-span-2">
                            <label for="description" class="block text-sm font-medium text-gray-700">Description</label>
                            <textarea id="description" name="description" rows="3" required
                                class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                            ><?php echo htmlspecialchars($alert['description']); ?></textarea>
                        </div>
                    </div>

                    <div class="flex justify-end pt-5">
                        <button type="submit" 
                            class="ml-3 inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                            Update Alert
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div> 