<?php
require_once '../../includes/auth.php';
require_once '../../includes/errors.php';
require_once '../../includes/csrf.php';
require_once '../../includes/audit.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';
require_once '../../includes/repository.php';
require_once '../../includes/validation.php';

requireAuth('cows.create');

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $v = new DfmsValidator($_POST);
    // A tag is the animal's only human-readable identifier; it must be
    // present and printable. Uniqueness is now enforced PER FARM by the
    // database (migration 005), so the duplicate below is a same-farm clash.
    $unique_id = $v->string('unique_id', 1, 50);
    $date_of_birth = $v->date('date_of_birth', ['nullable' => true]);
    $notes = $v->text('notes', 5000, ['nullable' => true]);

    if ($v->fails()) {
        $error = $v->firstError();
    } else {
        try {
            $conn = getDBConnection();
            // farm_id is applied by tenantInsert() from the tenant context and
            // can never be supplied by the client.
            $cowId = tenantInsert($conn, 'cows', [
                'unique_id'     => $unique_id,
                'date_of_birth' => $date_of_birth,
                'notes'         => $notes,
            ]);
            auditCreate('cow', $cowId, [
                'unique_id'     => $unique_id,
                'date_of_birth' => $date_of_birth,
            ]);
            $success = 'Cow added successfully';
        } catch (PDOException $e) {
            // 23000 = integrity constraint. On this table that means the tag
            // is already used ON THIS FARM — a user error, not a system fault.
            if ($e->getCode() === '23000') {
                $error = 'An animal with that tag already exists on this farm.';
            } else {
                $error = safeError($e, 'Error adding cow');
            }
        }
    }
}

$page_title = 'Add Animal';
$page_breadcrumb = [['label' => 'Livestock', 'url' => 'index.php'], ['label' => 'Add Animal']];
require_once '../../includes/header.php';
?>

<div class="py-6">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="sm:flex sm:items-center">
            <div class="sm:flex-auto">
                <h1 class="text-2xl font-semibold text-gray-900">Add New Cow</h1>
                <p class="mt-2 text-sm text-gray-700">Add a new cow to the system</p>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="mt-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="mt-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="mt-8 space-y-6">
            <?php csrf_field(); ?>
            <div class="bg-white shadow px-4 py-5 sm:rounded-lg sm:p-6">
                <div class="grid grid-cols-1 gap-6">
                    <div>
                        <label for="unique_id" class="block text-sm font-medium text-gray-700">Unique ID</label>
                        <input type="text" name="unique_id" id="unique_id" required
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    </div>

                    <div>
                        <label for="date_of_birth" class="block text-sm font-medium text-gray-700">Date of Birth</label>
                        <input type="date" name="date_of_birth" id="date_of_birth"
                               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    </div>

                    <div>
                        <label for="notes" class="block text-sm font-medium text-gray-700">Notes</label>
                        <textarea name="notes" id="notes" rows="3"
                                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"></textarea>
                    </div>
                </div>
            </div>

            <div class="flex justify-end">
                <a href="index.php" class="bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    Cancel
                </a>
                <button type="submit" class="ml-3 inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    Save
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?> 