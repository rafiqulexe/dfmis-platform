<?php
require_once '../../includes/auth.php';
require_once '../../includes/errors.php';
require_once '../../includes/csrf.php';
require_once '../../includes/audit.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';
require_once '../../includes/repository.php';
require_once '../../includes/validation.php';

requireAuth('alerts.view');

$error_message = '';
$alerts = [];
$perPage = 50;
$page = 1;
$totalPages = 1;
$totalAlerts = 0;
$unreadCount = 0;

try {
    $conn = getDBConnection();

    $v = new DfmsValidator($_GET);
    $page = $v->integer('page', ['nullable' => true, 'min' => 1, 'max' => 100000]) ?? 1;

    $totalAlerts = (int) tenantScalar($conn, "SELECT COUNT(*) FROM alerts WHERE {scope}");
    $unreadCount = (int) tenantScalar($conn, "SELECT COUNT(*) FROM alerts WHERE {scope} AND is_read = 0");
    $totalPages = max(1, (int) ceil($totalAlerts / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $alerts = tenantQuery(
        $conn,
        "SELECT * FROM alerts WHERE {scope} ORDER BY is_read ASC, alert_time DESC
         LIMIT " . (int) $perPage . " OFFSET " . (int) $offset
    );
} catch (PDOException $e) {
    $error_message = safeError($e, "Database error");
}

$page_title = 'Alerts';
$page_breadcrumb = [['label' => 'Alerts']];
require_once '../../includes/header.php';
?>

<div class="py-6">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="sm:flex sm:items-center">
            <div class="sm:flex-auto">
                <h1 class="text-2xl font-semibold text-gray-900">Alerts</h1>
                <p class="mt-2 text-sm text-gray-700">A list of all alerts in the system</p>
            </div>
            <div class="mt-4 sm:mt-0 sm:ml-16 sm:flex-none flex items-center gap-3">
                <?php if ($unreadCount > 0 && hasPermission('alerts.acknowledge')): ?>
                    <form method="POST" action="<?php echo BASE_URL; ?>modules/alerts/read.php" class="inline">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="scope" value="all">
                        <button type="submit" class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">
                            Mark all read (<?php echo (int) $unreadCount; ?>)
                        </button>
                    </form>
                <?php endif; ?>
                <?php if (hasPermission('alerts.create')): ?>
                    <a href="create.php" class="inline-flex items-center justify-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 sm:w-auto">
                        Add Alert
                    </a>
                <?php endif; ?>
            </div>
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
                                    <tr class="<?php echo (int) $alert['is_read'] === 1 ? 'opacity-60' : ''; ?>">
                                        <td class="whitespace-nowrap py-4 pl-4 pr-3 text-sm text-gray-900">
                                            <?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($alert['alert_time']))); ?>
                                            <?php if ((int) $alert['is_read'] === 0): ?>
                                                <span class="ml-2 inline-flex rounded-full bg-blue-100 px-2 text-xs font-semibold leading-5 text-blue-800">New</span>
                                            <?php endif; ?>
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
                                            <?php if ((int) $alert['is_read'] === 0 && hasPermission('alerts.acknowledge')): ?>
                                                <form method="POST" action="<?php echo BASE_URL; ?>modules/alerts/read.php" class="inline">
                                                    <?php csrf_field(); ?>
                                                    <input type="hidden" name="scope" value="one">
                                                    <input type="hidden" name="alert_id" value="<?php echo (int) $alert['alert_id']; ?>">
                                                    <button type="submit" class="text-green-700 hover:text-green-900">Mark read</button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if (hasPermission('alerts.edit')): ?>
                                                <a href="edit.php?id=<?php echo (int) $alert['alert_id']; ?>" class="ml-4 text-blue-600 hover:text-blue-900">Edit</a>
                                            <?php endif; ?>
                                            <?php if (hasPermission('alerts.delete')): ?>
                                                <a href="delete.php?id=<?php echo (int) $alert['alert_id']; ?>" class="ml-4 text-red-600 hover:text-red-900" onclick="return confirm('Are you sure you want to delete this alert?')">Delete</a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php dfms_pagination($page, $totalPages, BASE_URL . 'modules/alerts/', $totalAlerts); ?>
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
<?php require_once '../../includes/footer.php'; ?>
