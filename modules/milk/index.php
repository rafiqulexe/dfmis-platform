<?php
require_once '../../includes/auth.php';
require_once '../../includes/errors.php';
require_once '../../includes/audit.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';
require_once '../../includes/repository.php';
require_once '../../includes/validation.php';

requireAuth('milk.view');

$productions = [];
$perPage = 50;
$page = 1;
$totalPages = 1;
$totalRecords = 0;

try {
    $conn = getDBConnection();

    $v = new DfmsValidator($_GET);
    $page = $v->integer('page', ['nullable' => true, 'min' => 1, 'max' => 100000]) ?? 1;

    // Was a bare LIMIT 30 with no way to reach record 31 (baseline audit F-3).
    $totalRecords = (int) tenantScalar($conn, "SELECT COUNT(*) FROM milk_production WHERE {scope}");
    $totalPages = max(1, (int) ceil($totalRecords / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $productions = tenantQuery(
        $conn,
        "SELECT * FROM milk_production WHERE {scope} ORDER BY production_date DESC
         LIMIT " . (int) $perPage . " OFFSET " . (int) $offset
    );
} catch (PDOException $e) {
    $productions = [];
}

$page_title = 'Milk Production';
$page_breadcrumb = [['label' => 'Milk Production']];
require_once '../../includes/header.php';
?>

<style>
    .background-image {
        background-image: url('<?php echo BASE_URL; ?>assets/images/cow.png');
        background-size: cover;
        background-position: center;
        background-repeat: no-repeat;
        min-height: 100vh;
    }
</style>

<div class="background-image py-6">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="sm:flex sm:items-center">
            <div class="sm:flex-auto">
                <h1 class="text-2xl font-semibold text-green-900">Milk Production</h1>
                <p class="mt-2 text-sm text-gray-700">Daily milk production records</p>
            </div>
            <?php if (hasPermission('milk.create')): ?>
                <div class="mt-4 sm:mt-0 sm:ml-16 sm:flex-none">
                    <a href="add.php" class="inline-flex items-center justify-center rounded-md border border-transparent bg-green-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-green-700">
                        Add Production
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Production List -->
        <div class="mt-8 flex flex-col">
            <div class="-my-2 -mx-4 overflow-x-auto sm:-mx-6 lg:-mx-8">
                <div class="inline-block min-w-full py-2 align-middle md:px-6 lg:px-8">
                    <div class="overflow-hidden shadow ring-1 ring-black ring-opacity-5 md:rounded-lg">
                        <table class="min-w-full divide-y divide-gray-300">
                            <thead class="bg-green-50">
                                <tr>
                                    <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-green-900">Date</th>
                                    <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-green-900">Total Milk (L)</th>
                                    <th scope="col" class="relative py-3.5 pl-3 pr-4 sm:pr-6">
                                        <span class="sr-only">Edit</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white">
                                <?php foreach ($productions as $production): ?>
                                    <tr>
                                        <td class="whitespace-nowrap py-4 pl-4 pr-3 text-sm text-gray-900">
                                            <?php echo htmlspecialchars($production['production_date']); ?>
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-500">
                                            <?php echo number_format($production['total_milk_yield'], 2); ?>
                                        </td>
                                        <td class="relative whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-6">
                                            <?php if (hasPermission('milk.edit')): ?>
                                                <a href="edit.php?id=<?php echo $production['production_id']; ?>" class="text-green-600 hover:text-green-900">Edit</a>
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

<?php require_once '../../includes/footer.php'; ?>