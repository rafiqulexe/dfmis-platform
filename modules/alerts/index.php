<?php
require_once '../../includes/auth.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';
require_once '../../includes/repository.php';
requireLogin();
requirePermission('alerts.view');

$error_message = '';
$alerts = [];

try {
    $conn = getDBConnection();
    $alerts = tenantQuery($conn, "SELECT * FROM alerts WHERE {scope} ORDER BY alert_time DESC");
} catch (PDOException $e) {
    $error_message = safeError($e, "Database error");
}

require_once '../../includes/header.php';
?>

<div class="py-6">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="sm:flex sm:items-center">
            <div class="sm:flex-auto">
                <h1 class="text-2xl font-semibold text-gray-900">Alerts</h1>
                <p class="mt-2 text-sm text-gray-700">A list of all alerts in the system</p>
            </div>
            <?php if (hasPermission('alerts.create')): ?>
                <div class="mt-4 sm:mt-0 sm:ml-16 sm:flex-none">
                    <a href="create.php" class="inline-flex items-center justify-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 sm:w-auto">
                        Add Alert
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($error_message): ?>
            <div class="mt-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <div class="mt-8 flex flex-col">
            <div class="-my-2 -mx-4 overflow-x-auto sm:-mx-6 lg:-mx-8">
                <div class="inline-block min-w-full py-2 align-middle md:px-6 lg:px-8">
                    <div class="overflow-hidden shadow ring-1 ring-black ring-opacity-5 md:rounded-lg">
                        <table class="min-w-full divide-y divide-gray-300">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900">Alert Time</th>
                                    <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Type</th>
                                    <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Description</th>
                                    <th scope="col" class="relative py-3.5 pl-3 pr-4 sm:pr-6">
                                        <span class="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white">
                                <?php foreach ($alerts as $alert): ?>
                                    <tr>
                                        <td class="whitespace-nowrap py-4 pl-4 pr-3 text-sm text-gray-900">
                                            <?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($alert['alert_time']))); ?>
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500">
                                            <span class="inline-flex rounded-full px-2 text-xs font-semibold leading-5 
                                                <?php echo getAlertTypeClass($alert['alert_type']); ?>">
                                                <?php echo htmlspecialchars($alert['alert_type']); ?>
                                            </span>
                                        </td>
                                        <td class="px-3 py-4 text-sm text-gray-500">
                                            <?php echo htmlspecialchars($alert['description']); ?>
                                        </td>
                                        <td class="relative whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-6">
                                            <?php if (hasPermission('alerts.edit')): ?>
                                                <a href="edit.php?id=<?php echo $alert['alert_id']; ?>" class="text-blue-600 hover:text-blue-900">Edit</a>
                                            <?php endif; ?>
                                            <?php if (hasPermission('alerts.delete')): ?>
                                                <a href="delete.php?id=<?php echo $alert['alert_id']; ?>" class="ml-4 text-red-600 hover:text-red-900" onclick="return confirm('Are you sure you want to delete this alert?')">Delete</a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
function getAlertTypeClass($type) {
    switch (strtolower($type)) {
        case 'low inventory':
            return 'bg-yellow-100 text-yellow-800';
        case 'health':
            return 'bg-red-100 text-red-800';
        case 'maintenance':
            return 'bg-blue-100 text-blue-800';
        default:
            return 'bg-gray-100 text-gray-800';
    }
}
?> 