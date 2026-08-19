<?php
require_once '../../includes/auth.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';
require_once '../../includes/repository.php';
requireLogin();
requirePermission('cows.view');

$cow_id = isset($_GET['id']) ? $_GET['id'] : null;
$error_message = '';
$cow = null;
$healthEvents = [];

if (!$cow_id) {
    $_SESSION['error_message'] = "No cow ID provided.";
    header('Location: ' . BASE_URL . 'modules/cows/');
    exit();
}

try {
    $conn = getDBConnection();

    // 404s if the cow belongs to another tenant — indistinguishable from
    // "does not exist", so this cannot be used to probe other farms.
    $cow = tenantFindOr404($conn, 'cows', 'cow_id', $cow_id, 'deleted_at IS NULL');

    $healthEvents = tenantQuery(
        $conn,
        "SELECT * FROM health_events WHERE {scope} AND cow_id = ? ORDER BY event_date DESC",
        [$cow['cow_id']]
    );
} catch (PDOException $e) {
    $error_message = safeError($e, "Database error");
}

$page_title = 'Animal Profile';
$page_breadcrumb = [['label' => 'Livestock', 'url' => 'index.php'], ['label' => 'Profile']];
require_once '../../includes/header.php';
?>

<div class="py-6">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="sm:flex sm:items-center">
            <div class="sm:flex-auto">
                <h1 class="text-2xl font-semibold text-gray-900">
                    <?php echo $cow ? htmlspecialchars($cow['unique_id']) : 'Cow Details'; ?>
                </h1>
                <p class="mt-2 text-sm text-gray-700">Cow profile and health history</p>
            </div>
            <div class="mt-4 sm:mt-0 sm:ml-16 sm:flex-none space-x-2">
                <?php if ($cow): ?>
                    <a href="edit.php?id=<?php echo $cow['cow_id']; ?>" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">Edit</a>
                <?php endif; ?>
                <a href="index.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">Back to List</a>
            </div>
        </div>

        <?php if ($error_message): ?>
            <div class="mt-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <?php if ($cow): ?>
            <div class="mt-8 bg-white shadow rounded-lg p-6">
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Unique ID</dt>
                        <dd class="text-sm text-gray-900"><?php echo htmlspecialchars($cow['unique_id']); ?></dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Date of Birth</dt>
                        <dd class="text-sm text-gray-900"><?php echo htmlspecialchars($cow['date_of_birth'] ?? '—'); ?></dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-sm font-medium text-gray-500">Notes</dt>
                        <dd class="text-sm text-gray-900"><?php echo nl2br(htmlspecialchars($cow['notes'] ?? '—')); ?></dd>
                    </div>
                </dl>
            </div>

            <div class="mt-8">
                <h2 class="text-lg font-medium text-gray-900">Health History</h2>
                <div class="mt-4 bg-white shadow rounded-lg divide-y divide-gray-200">
                    <?php if (empty($healthEvents)): ?>
                        <p class="p-6 text-sm text-gray-500">No health events recorded yet.</p>
                    <?php else: ?>
                        <?php foreach ($healthEvents as $event): ?>
                            <div class="p-4">
                                <div class="flex justify-between text-sm">
                                    <span class="font-medium text-gray-900"><?php echo htmlspecialchars($event['event_type']); ?></span>
                                    <span class="text-gray-500"><?php echo htmlspecialchars($event['event_date']); ?></span>
                                </div>
                                <?php if ($event['description']): ?>
                                    <p class="mt-1 text-sm text-gray-600"><?php echo htmlspecialchars($event['description']); ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
