<?php
require_once '../../includes/auth.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';
require_once '../../includes/repository.php';
requireLogin();
requirePermission('cows.view');

$error_message = '';
$cows = [];

try {
    $conn = getDBConnection();
    $cows = tenantQuery($conn, "SELECT * FROM cows WHERE {scope} AND deleted_at IS NULL ORDER BY unique_id");
} catch (PDOException $e) {
    $error_message = safeError($e, "Database error");
}

$page_title = 'Livestock';
$page_breadcrumb = [['label' => 'Livestock']];
require_once '../../includes/header.php';
?>

<div class="py-6">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="sm:flex sm:items-center">
            <div class="sm:flex-auto">
                <h1 class="text-2xl font-semibold text-gray-900">Cows</h1>
                <p class="mt-2 text-sm text-gray-700">A list of all cows in the dairy farm</p>
            </div>
            <?php if (hasPermission('cows.create')): ?>
                <div class="mt-4 sm:mt-0 sm:ml-16 sm:flex-none">
                    <a href="add.php" class="inline-flex items-center justify-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 sm:w-auto">
                        Add Cow
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="mt-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative">
                <?php 
                echo htmlspecialchars($_SESSION['error_message']);
                unset($_SESSION['error_message']);
                ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="mt-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative">
                <?php 
                echo htmlspecialchars($_SESSION['success_message']);
                unset($_SESSION['success_message']);
                ?>
            </div>
        <?php endif; ?>

        <div class="mt-8 flex flex-col">
            <div class="-my-2 -mx-4 overflow-x-auto sm:-mx-6 lg:-mx-8">
                <div class="inline-block min-w-full py-2 align-middle md:px-6 lg:px-8">
                    <div class="overflow-hidden shadow ring-1 ring-black ring-opacity-5 md:rounded-lg">
                        <table class="min-w-full divide-y divide-gray-300">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900">Unique ID</th>
                                    <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Date of Birth</th>
                                    <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Age</th>
                                    <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Notes</th>
                                    <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900">Health Status</th>
                                    <th scope="col" class="relative py-3.5 pl-3 pr-4 sm:pr-6">
                                        <span class="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white">
                                <?php foreach ($cows as $cow):
                                    // Calculate age
                                    $dob = new DateTime($cow['date_of_birth']);
                                    $now = new DateTime();
                                    $age = $now->diff($dob);

                                    $healthStatus = $cow['health_status'];
                                ?>
                                    <tr>
                                        <td class="whitespace-nowrap py-4 pl-4 pr-3 text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($cow['unique_id']); ?>
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500">
                                            <?php echo htmlspecialchars(date('Y-m-d', strtotime($cow['date_of_birth']))); ?>
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500">
                                            <?php echo $age->y . ' years, ' . $age->m . ' months'; ?>
                                        </td>
                                        <td class="px-3 py-4 text-sm text-gray-500">
                                            <?php echo htmlspecialchars($cow['notes']); ?>
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-4 text-sm">
                                            <span class="inline-flex rounded-full px-2 text-xs font-semibold leading-5 <?php echo getHealthStatusClass($healthStatus); ?>">
                                                <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $healthStatus))); ?>
                                            </span>
                                        </td>
                                        <td class="relative whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-6">
                                            <?php if (hasPermission('cows.edit')): ?>
                                                <a href="edit.php?id=<?php echo $cow['cow_id']; ?>" class="text-blue-600 hover:text-blue-900 mr-4">Edit</a>
                                            <?php endif; ?>
                                            <a href="view.php?id=<?php echo $cow['cow_id']; ?>" class="text-green-600 hover:text-green-900 mr-4">Details</a>
                                            <?php if (hasPermission('cows.delete')): ?>
                                                <a href="delete.php?id=<?php echo $cow['cow_id']; ?>" class="text-red-600 hover:text-red-900" onclick="return confirm('Are you sure you want to delete this cow?')">Delete</a>
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
// Maps the cows.health_status enum to its badge styling.
function getHealthStatusClass($status) {
    switch ($status) {
        case 'healthy':
            return 'bg-green-100 text-green-800';
        case 'sick':
            return 'bg-red-100 text-red-800';
        case 'at_risk':
            return 'bg-yellow-100 text-yellow-800';
        case 'treatment':
            return 'bg-blue-100 text-blue-800';
        default:
            return 'bg-gray-100 text-gray-800';
    }
}
?> 
<?php require_once '../../includes/footer.php'; ?>
