<?php
require_once '../../includes/auth.php';
require_once '../../config/database.php';
require_once '../../includes/csrf.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';
require_once '../../includes/tenant.php';
requireLogin();
requirePermission('alerts.create');

$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $alert_type = $_POST['alert_type'] ?? '';
    $description = $_POST['description'] ?? '';

    try {
        $conn = getDBConnection();
        $stmt = $conn->prepare("INSERT INTO alerts (alert_time, alert_type, description, farm_id) VALUES (NOW(), ?, ?, ?)");
        $stmt->execute([$alert_type, $description, currentFarmId()]);
        $success_message = "Alert created successfully";

        // Redirect back to index after successful creation
        header("Location: index.php");
        exit();
    } catch (PDOException $e) {
        $error_message = safeError($e, "Creation failed");
    }
}

require_once '../../includes/header.php';
?>

<div class="py-6">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="sm:flex sm:items-center">
            <div class="sm:flex-auto">
                <h1 class="text-2xl font-semibold text-gray-900">Create New Alert</h1>
            </div>
            <div class="mt-4 sm:mt-0 sm:ml-16 sm:flex-none">
                <a href="index.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                    Back to List
                </a>
            </div>
        </div>

        <div class="mt-8 bg-white shadow rounded-lg">
            <form method="POST" action="" class="space-y-6 p-6">
                <?php csrf_field(); ?>
                <div class="grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-2">
                    <div>
                        <label for="alert_type" class="block text-sm font-medium text-gray-700">Alert Type</label>
                        <select id="alert_type" name="alert_type" required
                            class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                            <option value="Low Inventory">Low Inventory</option>
                            <option value="Health">Health</option>
                            <option value="Maintenance">Maintenance</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>

                    <div class="sm:col-span-2">
                        <label for="description" class="block text-sm font-medium text-gray-700">Description</label>
                        <textarea id="description" name="description" rows="3" required
                            class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm"></textarea>
                    </div>
                </div>

                <div class="flex justify-end pt-5">
                    <button type="submit" 
                        class="ml-3 inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        Create Alert
                    </button>
                </div>
            </form>
        </div>
    </div>
</div> 