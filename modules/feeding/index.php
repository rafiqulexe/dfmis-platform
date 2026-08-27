<?php
require_once '../../includes/auth.php';
require_once '../../includes/errors.php';
require_once '../../includes/audit.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';
require_once '../../includes/repository.php';

requireAuth('feeding.view');

try {
    $conn = getDBConnection();
    $schedules = tenantQuery($conn, "SELECT * FROM feeding_schedules WHERE {scope} ORDER BY feed_time");
} catch (PDOException $e) {
    $schedules = [];
}

$page_title = 'Feeding';
$page_breadcrumb = [['label' => 'Feeding']];
require_once '../../includes/header.php';
?>

<div class="py-6">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="sm:flex sm:items-center">
            <div class="sm:flex-auto">
                <h1 class="text-2xl font-semibold text-gray-900">Feeding Schedules</h1>
                <p class="mt-2 text-sm text-gray-700">A list of all feeding schedules</p>
            </div>
            <?php if (hasPermission('feeding.create')): ?>
                <div class="mt-4 sm:mt-0 sm:ml-16 sm:flex-none">
                    <a href="add.php" class="inline-flex items-center justify-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700">
                        Add Schedule
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Schedules List -->
        <div class="mt-8 flex flex-col">
            <div class="-my-2 -mx-4 overflow-x-auto sm:-mx-6 lg:-mx-8">
                <div class="inline-block min-w-full py-2 align-middle md:px-6 lg:px-8">
                    <div class="overflow-hidden shadow ring-1 ring-black ring-opacity-5 md:rounded-lg">
                        <table class="min-w-full divide-y divide-gray-300">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900">Time</th>
                                    <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Feed Type</th>
                                    <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Amount (kg)</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white">
                                <?php foreach ($schedules as $schedule): ?>
                                <tr>
                                    <td class="whitespace-nowrap py-4 pl-4 pr-3 text-sm text-gray-900">
                                        <?php echo date('h:i A', strtotime($schedule['feed_time'])); ?>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500">
                                        <?php echo htmlspecialchars($schedule['feed_type']); ?>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500">
                                        <?php echo number_format($schedule['feed_amount'], 2); ?>
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

<?php require_once '../../includes/footer.php'; ?> 