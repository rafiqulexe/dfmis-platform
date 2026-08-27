<?php
require_once '../../includes/auth.php';
require_once '../../includes/errors.php';
require_once '../../includes/csrf.php';
require_once '../../includes/audit.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';
require_once '../../includes/repository.php';
require_once '../../includes/validation.php';

requireAuth('alerts.create');

$error_message = '';
$success_message = '';

// Matches the alerts.severity ENUM. Previously never set, so every manually
// created alert silently took the 'info' default and was then filtered out of
// the dashboard's attention list, which only shows warning/critical.
const ALERT_SEVERITIES = ['info', 'warning', 'critical'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $v = new DfmsValidator($_POST);
    $alert_type = $v->string('alert_type', 1, 50);
    $severity = $v->enum('severity', ALERT_SEVERITIES, ['default' => 'info']);
    $description = $v->text('description', 2000, ['nullable' => true]);

    if ($v->fails()) {
        $error_message = $v->firstError();
    } else {
        try {
            $conn = getDBConnection();
            $alertId = tenantInsert($conn, 'alerts', [
                'alert_time'  => date('Y-m-d H:i:s'),
                'alert_type'  => $alert_type,
                'severity'    => $severity,
                'description' => $description,
            ]);
            auditCreate('alert', $alertId, [
                'alert_type' => $alert_type,
                'severity'   => $severity,
            ]);

            redirectWith('modules/alerts/', 'success', 'Alert created successfully.');
        } catch (PDOException $e) {
            $error_message = safeError($e, "Creation failed");
        }
    }
}

$page_title = 'New Alert';
$page_breadcrumb = [['label' => 'Alerts', 'url' => 'index.php'], ['label' => 'New Alert']];
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
<?php require_once '../../includes/footer.php'; ?>
