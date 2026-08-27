<?php
// ============================================================================
// FEATURE CONFIGURATION
//
// Every flag listed here gates a module that DOES NOT EXIST YET. Switching one
// on changes nothing today — it exists so a later phase can roll itself out
// gradually instead of appearing for everyone at once.
//
// AUTHORIZATION
//   farm scope         -> requireAuth('settings.features')     (farm managers)
//   organization scope -> requireOrganizationPermission('org.manage')
// Checked per scope at the point of write, not once for the page, because the
// two scopes have genuinely different authority requirements.
// ============================================================================

require_once '../../includes/auth.php';
require_once '../../includes/errors.php';
require_once '../../includes/csrf.php';
require_once '../../includes/audit.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';
require_once '../../includes/repository.php';
require_once '../../includes/validation.php';
require_once '../../includes/settings.php';
require_once '../../includes/features.php';

requireAuth('settings.view');

// The page needs at least one of the two authorities to be useful at all.
$canFarm = hasPermission('settings.features');
$canOrg = hasOrganizationPermission('org.manage');
if (!$canFarm && !$canOrg) {
    requirePermission('settings.features');   // aborts 403, audited
}

$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $v = new DfmsValidator($_POST);
    $flagKey = $v->string('flag_key', 1, 64);
    $scope = $v->enum('scope', ['organization', 'farm']);
    // 'inherit' clears the override so the flag falls through to the next level.
    $state = $v->enum('state', ['on', 'off', 'inherit']);

    if ($v->fails()) {
        $error_message = $v->firstError();
    } elseif (!dfms_is_registered_feature($flagKey)) {
        // An unregistered key is a tampered request, not a user mistake.
        dfms_abort(400, "Unregistered feature key '$flagKey'");
    } else {
        // Per-scope authorization — the decisive check.
        if ($scope === 'farm' && !$canFarm) {
            requirePermission('settings.features');
        }
        if ($scope === 'organization' && !$canOrg) {
            requireOrganizationPermission('org.manage');
        }

        try {
            $enabled = $state === 'inherit' ? null : ($state === 'on');
            // dfms_set_feature() takes the scope id from the tenant context,
            // never from the request, so this cannot target another tenant.
            dfms_set_feature($flagKey, $scope, $enabled);
            redirectWith('modules/settings/features.php', 'success', t('state.saved'));
        } catch (InvalidArgumentException $e) {
            $error_message = 'That feature or scope is not recognised.';
        } catch (Throwable $e) {
            $error_message = safeError($e, 'Could not update the feature');
        }
    }
}

$page_title = 'Feature Configuration';
$page_breadcrumb = [['label' => 'Settings', 'url' => 'index.php'], ['label' => 'Features']];
require_once '../../includes/header.php';
?>

<?php dfms_page_header(
    'Features',
    'Controlled rollout for modules arriving in later phases.'
); ?>

<?php if ($error_message): ?><?php dfms_alert($error_message, 'error'); ?><?php endif; ?>

<?php dfms_alert(
    'Every flag below gates functionality that is not built yet. Turning one on does '
  . 'not make a feature appear — it records the intent so the phase that builds it can '
  . 'roll out safely. Flags never bypass authentication, authorization or tenant isolation.',
    'info'
); ?>

<section class="dfms-section">
    <div class="dfms-card">
        <?php dfms_table_open([
            ['label' => 'Feature'],
            ['label' => 'Effective'],
            ['label' => 'Source'],
            ['label' => 'Set for this farm', 'align' => 'right'],
        ]); ?>
        <?php foreach (dfms_feature_registry() as $key => $meta): ?>
            <?php $state = dfms_feature_state($key); ?>
            <tr>
                <?php
                dfms_cell('Feature',
                    '<strong>' . e($meta['label']) . '</strong><br>'
                    . '<span class="dfms-text-muted" style="font-size:.85rem;">' . e($meta['description']) . '</span>'
                );
                dfms_cell('Effective', $state['enabled']
                    ? dfms_badge_html('Enabled', 'success', true)
                    : dfms_badge_html('Disabled', 'neutral', true));
                dfms_cell('Source', e(ucfirst($state['source'])));
                ?>
                <td class="dfms-cell-actions">
                    <?php if ($canFarm): ?>
                        <form method="POST" action="" style="display:inline-flex;gap:.4rem;align-items:center;">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="flag_key" value="<?php echo e($key); ?>">
                            <input type="hidden" name="scope" value="farm">
                            <label class="dfms-visually-hidden" for="state-<?php echo e($key); ?>">State for <?php echo e($meta['label']); ?></label>
                            <select id="state-<?php echo e($key); ?>" name="state">
                                <option value="inherit" <?php echo $state['source'] !== 'farm' ? 'selected' : ''; ?>>Inherit</option>
                                <option value="on"  <?php echo ($state['source'] === 'farm' && $state['enabled']) ? 'selected' : ''; ?>>On</option>
                                <option value="off" <?php echo ($state['source'] === 'farm' && !$state['enabled']) ? 'selected' : ''; ?>>Off</option>
                            </select>
                            <button type="submit" class="dfms-btn dfms-btn-secondary dfms-btn-sm">Apply</button>
                        </form>
                    <?php else: ?>
                        <span class="dfms-text-muted">Farm manager only</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php dfms_table_close(); ?>
    </div>
</section>

<?php if ($canOrg): ?>
<section class="dfms-section">
    <div class="dfms-section-header">
        <div>
            <h2>Organization scope</h2>
            <p>Applies to every farm in this organization unless a farm overrides it.</p>
        </div>
    </div>
    <div class="dfms-card">
        <?php dfms_table_open([['label' => 'Feature'], ['label' => 'Set for the organization', 'align' => 'right']]); ?>
        <?php foreach (dfms_feature_registry() as $key => $meta): ?>
            <tr>
                <?php dfms_cell('Feature', e($meta['label'])); ?>
                <td class="dfms-cell-actions">
                    <form method="POST" action="" style="display:inline-flex;gap:.4rem;align-items:center;">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="flag_key" value="<?php echo e($key); ?>">
                        <input type="hidden" name="scope" value="organization">
                        <label class="dfms-visually-hidden" for="org-state-<?php echo e($key); ?>">Organization state for <?php echo e($meta['label']); ?></label>
                        <select id="org-state-<?php echo e($key); ?>" name="state">
                            <option value="inherit">Inherit</option>
                            <option value="on">On</option>
                            <option value="off">Off</option>
                        </select>
                        <button type="submit" class="dfms-btn dfms-btn-secondary dfms-btn-sm">Apply</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php dfms_table_close(); ?>
    </div>
</section>
<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>
