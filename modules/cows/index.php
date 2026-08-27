<?php
// ============================================================================
// LIVESTOCK LIST — search, filter, paginate
//
// Every query runs through animalSearch(), which is tenant-scoped. There is no
// code path here that reads animals across farms, including the identifier
// search: its subquery is constrained to the same farm, so searching an RFID
// can never confirm that the tag exists on another tenant's animal.
// ============================================================================

require_once '../../includes/auth.php';
require_once '../../includes/errors.php';
require_once '../../includes/audit.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';
require_once '../../includes/repository.php';
require_once '../../includes/validation.php';
require_once '../../includes/settings.php';
require_once '../../includes/livestock.php';

requireAuth('cows.view');

$error_message = '';
$result = ['items' => [], 'total' => 0, 'pages' => 1, 'page' => 1];
$locations = [];
$breeds = [];

$v = new DfmsValidator($_GET);
$filters = [
    'search'           => $v->string('search', 0, 100, ['nullable' => true]) ?? '',
    'species'          => $v->enum('species', array_keys(DFMS_SPECIES), ['nullable' => true]),
    'sex'              => $v->enum('sex', ['female', 'male'], ['nullable' => true]),
    'lifecycle_status' => $v->enum('lifecycle_status', array_keys(DFMS_LIFECYCLE_STATUSES), ['nullable' => true]),
    'location_id'      => $v->id('location_id', ['nullable' => true]),
    'breed'            => $v->string('breed', 0, 100, ['nullable' => true]),
    'include_archived' => isset($_GET['include_archived']),
];
$page = $v->integer('page', ['nullable' => true, 'min' => 1, 'max' => 100000]) ?? 1;

// A bad filter value is a bad request, not a reason to show the wrong animals.
if ($v->fails()) {
    $error_message = $v->firstError();
    $filters = ['include_archived' => false];
    $page = 1;
}

try {
    $conn = getDBConnection();
    $result = animalSearch($conn, $filters, $page, 25);
    $locations = farmLocations($conn);
    $breeds = farmBreeds($conn);
} catch (PDOException $e) {
    $error_message = safeError($e, 'Could not load the herd');
}

// Preserves the active filters across pagination links.
$queryBase = array_filter([
    'search'           => $filters['search'] ?? '',
    'species'          => $filters['species'] ?? '',
    'sex'              => $filters['sex'] ?? '',
    'lifecycle_status' => $filters['lifecycle_status'] ?? '',
    'location_id'      => $filters['location_id'] ?? '',
    'breed'            => $filters['breed'] ?? '',
    'include_archived' => !empty($filters['include_archived']) ? '1' : '',
], fn($value) => $value !== '' && $value !== null);
$paginationUrl = BASE_URL . 'modules/cows/' . ($queryBase ? '?' . http_build_query($queryBase) : '');

$page_title = 'Livestock';
$page_breadcrumb = [['label' => 'Livestock']];
require_once '../../includes/header.php';
?>

<style>
    .animal-filters {
        display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 1rem; align-items: end;
    }
    .animal-filters .dfms-field { margin-bottom: 0; }
    .animal-row-name { font-weight: 600; }
    .animal-row-sub { font-size: .82rem; color: var(--text-muted, #59685f); }
</style>

<?php dfms_page_header(
    'Livestock',
    $result['total'] . ' animal' . ($result['total'] === 1 ? '' : 's') . ' on this farm.',
    hasPermission('cows.create')
        ? dfms_button_html('Add animal', BASE_URL . 'modules/cows/add.php', 'primary', ['icon' => 'plus'])
        : ''
); ?>

<?php if ($error_message): ?><?php dfms_alert($error_message, 'error'); ?><?php endif; ?>

<!-- ===================== Filters ===================== -->
<section class="dfms-section">
    <div class="dfms-card">
        <form method="GET" action="" role="search">
            <div class="animal-filters">
                <div class="dfms-field">
                    <label for="search">Search</label>
                    <input id="search" name="search" type="search" autocomplete="off" spellcheck="false" maxlength="100"
                           value="<?php echo e($filters['search'] ?? ''); ?>"
                           placeholder="Tag, name, breed or identifier…"
                           aria-describedby="search-help">
                    <small id="search-help" class="dfms-text-muted">Includes RFID and registration numbers.</small>
                </div>

                <div class="dfms-field">
                    <label for="species">Species</label>
                    <select id="species" name="species">
                        <option value="">All</option>
                        <?php foreach (DFMS_SPECIES as $key => $meta): ?>
                            <option value="<?php echo e($key); ?>" <?php echo ($filters['species'] ?? '') === $key ? 'selected' : ''; ?>>
                                <?php echo e($meta['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="dfms-field">
                    <label for="sex">Sex</label>
                    <select id="sex" name="sex">
                        <option value="">All</option>
                        <option value="female" <?php echo ($filters['sex'] ?? '') === 'female' ? 'selected' : ''; ?>>Female</option>
                        <option value="male" <?php echo ($filters['sex'] ?? '') === 'male' ? 'selected' : ''; ?>>Male</option>
                    </select>
                </div>

                <div class="dfms-field">
                    <label for="lifecycle_status">Status</label>
                    <select id="lifecycle_status" name="lifecycle_status">
                        <option value="">All</option>
                        <?php foreach (DFMS_LIFECYCLE_STATUSES as $key => $meta): ?>
                            <option value="<?php echo e($key); ?>" <?php echo ($filters['lifecycle_status'] ?? '') === $key ? 'selected' : ''; ?>>
                                <?php echo e($meta['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($breeds): ?>
                    <div class="dfms-field">
                        <label for="breed">Breed</label>
                        <select id="breed" name="breed">
                            <option value="">All</option>
                            <?php foreach ($breeds as $breed): ?>
                                <option value="<?php echo e($breed); ?>" <?php echo ($filters['breed'] ?? '') === $breed ? 'selected' : ''; ?>>
                                    <?php echo e($breed); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <?php if ($locations): ?>
                    <div class="dfms-field">
                        <label for="location_id">Location</label>
                        <select id="location_id" name="location_id">
                            <option value="">All</option>
                            <?php foreach ($locations as $location): ?>
                                <option value="<?php echo (int) $location['location_id']; ?>"
                                    <?php echo (int) ($filters['location_id'] ?? 0) === (int) $location['location_id'] ? 'selected' : ''; ?>>
                                    <?php echo e($location['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="dfms-field">
                    <label for="include_archived" style="display:flex;align-items:center;gap:.45rem;">
                        <input id="include_archived" name="include_archived" type="checkbox" value="1"
                               <?php echo !empty($filters['include_archived']) ? 'checked' : ''; ?>>
                        Include archived
                    </label>
                </div>

                <div class="dfms-field" style="display:flex;gap:.5rem;">
                    <button type="submit" class="dfms-btn dfms-btn-primary">Apply</button>
                    <a href="<?php echo BASE_URL; ?>modules/cows/" class="dfms-btn dfms-btn-secondary">Reset</a>
                </div>
            </div>
        </form>
    </div>
</section>

<!-- ===================== Results ===================== -->
<section class="dfms-section">
    <div class="dfms-card">
        <?php if (empty($result['items'])): ?>
            <?php dfms_empty_state(
                ($filters['search'] ?? '') !== '' ? 'No animals match that search' : 'No animals yet',
                ($filters['search'] ?? '') !== ''
                    ? 'Try a different tag, name, breed or identifier.'
                    : 'Add the first animal to start building the herd record.',
                hasPermission('cows.create')
                    ? dfms_button_html('Add animal', BASE_URL . 'modules/cows/add.php', 'primary')
                    : ''
            ); ?>
        <?php else: ?>
            <?php dfms_table_open([
                ['label' => 'Tag'],
                ['label' => 'Animal'],
                ['label' => 'Species / breed'],
                ['label' => 'Age'],
                ['label' => 'Location'],
                ['label' => 'Status'],
                ['label' => '', 'align' => 'right'],
            ]); ?>
            <?php foreach ($result['items'] as $animal): ?>
                <tr>
                    <?php
                    $profileUrl = BASE_URL . 'modules/cows/view.php?id=' . (int) $animal['cow_id'];
                    dfms_cell('Tag', '<a href="' . e($profileUrl) . '"><code>' . e($animal['unique_id']) . '</code></a>');
                    dfms_cell('Animal',
                        '<span class="animal-row-name">' . e($animal['name'] ?: '—') . '</span><br>'
                        . '<span class="animal-row-sub">' . e(dfms_animal_sex_label($animal['species'], $animal['sex'])) . '</span>'
                    );
                    dfms_cell('Species / breed',
                        e(dfms_species_label($animal['species']))
                        . '<br><span class="animal-row-sub">' . e($animal['breed'] ?: '—') . '</span>'
                    );
                    dfms_cell('Age', e(animalAgeLabel($animal['date_of_birth'])));
                    dfms_cell('Location', e($animal['location_name'] ?? 'Unassigned'));
                    dfms_cell('Status', dfms_status_badge_html($animal['lifecycle_status']));
                    ?>
                    <td class="dfms-cell-actions">
                        <a href="<?php echo e($profileUrl); ?>" class="dfms-btn dfms-btn-secondary dfms-btn-sm">Profile</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php dfms_table_close(); ?>

            <?php dfms_pagination($result['page'], $result['pages'], $paginationUrl, $result['total']); ?>
        <?php endif; ?>
    </div>
</section>

<?php require_once '../../includes/footer.php'; ?>
