<?php
require_once '../../includes/auth.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';
requireLogin();

$error_message = '';
$cows = [];

try {
    $conn = getDBConnection();
    $stmt = $conn->query("SELECT * FROM cows ORDER BY unique_id");
    $cows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = safeError($e, "Database error");
}

require_once '../../includes/header.php';
?>

<div class="py-6">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="sm:flex sm:items-center">
            <div class="sm:flex-auto">
                <h1 class="text-2xl font-semibold text-gray-900">Cows</h1>
                <p class="mt-2 text-sm text-gray-700">A list of all cows in the dairy farm</p>
            </div>
            <div class="mt-4 sm:mt-0 sm:ml-16 sm:flex-none">
                <a href="add.php" class="inline-flex items-center justify-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 sm:w-auto">
                    Add Cow
                </a>
            </div>
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
                                    
                                    // Get latest health status
                                    $healthStatus = getLatestHealthStatus($conn, $cow['cow_id']);
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
                                                <?php echo htmlspecialchars($healthStatus); ?>
                                            </span>
                                        </td>
                                        <td class="relative whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-6">
                                            <a href="edit.php?id=<?php echo $cow['cow_id']; ?>" class="text-blue-600 hover:text-blue-900 mr-4">Edit</a>
                                            <a href="view.php?id=<?php echo $cow['cow_id']; ?>" class="text-green-600 hover:text-green-900 mr-4">Details</a>
                                            <a href="delete.php?id=<?php echo $cow['cow_id']; ?>" class="text-red-600 hover:text-red-900" onclick="return confirm('Are you sure you want to delete this cow?')">Delete</a>
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
// Helper function to get the latest health status
function getLatestHealthStatus($conn, $cowId) {
    try {
        $stmt = $conn->prepare("
            SELECT event_type 
            FROM health_events 
            WHERE cow_id = ? 
            ORDER BY event_date DESC 
            LIMIT 1
        ");
        $stmt->execute([$cowId]);
        $result = $stmt->fetch(PDO::FETCH_COLUMN);
        return $result ?: 'Healthy'; // Default to 'Healthy' if no events found
    } catch (PDOException $e) {
        return 'Unknown';
    }
}

// Helper function to get appropriate CSS classes for health status
function getHealthStatusClass($status) {
    switch (strtolower($status)) {
        case 'healthy':
            return 'bg-green-100 text-green-800';
        case 'sick':
            return 'bg-red-100 text-red-800';
        case 'vaccination':
            return 'bg-blue-100 text-blue-800';
        case 'treatment':
            return 'bg-yellow-100 text-yellow-800';
        default:
            return 'bg-gray-100 text-gray-800';
    }
}
?> 