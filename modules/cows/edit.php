<?php
require_once '../../includes/auth.php';
require_once '../../config/database.php';
require_once '../../includes/csrf.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';
require_once '../../includes/repository.php';
requireLogin();
requirePermission('cows.edit');

$cow_id = isset($_GET['id']) ? $_GET['id'] : null;
$error_message = '';
$success_message = '';
$cow = null;

$conn = getDBConnection();

// 404s for another tenant's cow, so an id from the URL can never be edited
// across a farm boundary.
if ($cow_id) {
    $cow = tenantFindOr404($conn, 'cows', 'cow_id', $cow_id, 'deleted_at IS NULL');
}

// Handle form submission
if ($cow && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $unique_id = $_POST['unique_id'] ?? '';
    $dob = $_POST['dob'] ?? '';
    $notes = $_POST['notes'] ?? '';

    try {
        tenantExec(
            $conn,
            "UPDATE cows SET unique_id = ?, date_of_birth = ?, notes = ? WHERE cow_id = ? AND {scope}",
            [$unique_id, $dob, $notes, $cow['cow_id']]
        );
        $success_message = "Cow details updated successfully";
        $cow = tenantFindOr404($conn, 'cows', 'cow_id', $cow['cow_id'], 'deleted_at IS NULL');
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
                <h1 class="text-2xl font-semibold text-gray-900">Edit Cow Details</h1>
                <p class="mt-2 text-sm text-gray-700">Update information for this cow</p>
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

        <?php if ($cow): ?>
            <div class="mt-8 bg-white shadow rounded-lg">
                <form method="POST" action="" class="space-y-6 p-6">
                    <?php csrf_field(); ?>
                    <div class="grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-2">
                        <div>
                            <label for="unique_id" class="block text-sm font-medium text-gray-700">Unique ID</label>
                            <input type="text" id="unique_id" name="unique_id" 
                                value="<?php echo htmlspecialchars($cow['unique_id']); ?>" required
                                class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                        </div>

                        <div>
                            <label for="dob" class="block text-sm font-medium text-gray-700">Date of Birth</label>
                            <input type="date" id="dob" name="dob" 
                                value="<?php echo htmlspecialchars($cow['date_of_birth']); ?>" required
                                class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                        </div>

                        <div class="sm:col-span-2">
                            <label for="notes" class="block text-sm font-medium text-gray-700">Notes</label>
                            <textarea id="notes" name="notes" rows="3"
                                class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                            ><?php echo htmlspecialchars($cow['notes']); ?></textarea>
                        </div>
                    </div>

                    <div class="flex justify-end pt-5">
                        <button type="submit" 
                            class="ml-3 inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                            Update Cow
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>