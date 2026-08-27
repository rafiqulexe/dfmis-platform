<?php
// ============================================================================
// FARM SETTINGS — overrides
//
// Every field here is OPTIONAL. Leaving one blank means "inherit from the
// organization", which is the default state and the one most farms stay in.
//
// AUTHORIZATION: requireAuth('settings.farm') covers authentication, tenant
// context, farm and organization membership, and restricts writing to farm
// managers. TENANT SCOPE: the farm id comes from currentFarmId(), never the
// request — this page cannot write to another farm.
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
require_once '../../includes/farm_types.php';

requireAuth('settings.farm');

$error_message = '';

$farmId = currentFarmId();
$farm = dfms_farm_row($farmId);
if ($farm === null) {
    dfms_abort(404, 'Farm row missing for id ' . $farmId);
}
$org = dfms_organization_row((int) $farm['organization_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $v = new DfmsValidator($_POST);
    $farmName = $v->string('name', 1, 150);

    // allowEmpty = true: an empty value here explicitly CLEARS the override.
    // Without that, an override could be changed but never removed.
    $settings = dfms_validate_settings([
        'country_code'       => $_POST['country_code'] ?? '',
        'timezone'           => $_POST['timezone'] ?? '',
        'locale'             => $_POST['locale'] ?? '',
        'currency'           => $_POST['currency'] ?? '',
        'measurement_system' => $_POST['measurement_system'] ?? '',
    ], true);

    if ($v->fails()) {
        $error_message = $v->firstError();
    } elseif ($settings['errors']) {
        $error_message = reset($settings['errors']);
    } else {
        try {
            dfms_update_farm_settings($farmId, $settings['values']);

            if ($farmName !== $farm['name']) {
                $conn = getDBConnection();
                $stmt = $conn->prepare("UPDATE farms SET name = ? WHERE farm_id = ?");
                $stmt->execute([$farmName, $farmId]);
                auditLog('farm.rename', 'farm', $farmId, ['name' => $farm['name']], ['name' => $farmName]);
                dfms_reset_settings_cache();
            }

            redirectWith('modules/settings/farm.php', 'success', t('state.saved'));
        } catch (PDOException $e) {
            $error_message = safeError($e, 'Could not save farm settings');
        }
    }
    $farm = dfms_farm_row($farmId);
}

// What each field would resolve to if the override were removed — shown as the
// "inherit" option's label so the consequence of clearing is visible.
$inherited = [];
foreach (['country_code', 'timezone', 'locale', 'currency', 'measurement_system'] as $key) {
    $orgColumn = [
        'country_code' => 'country_code', 'timezone' => 'default_timezone',
        'locale' => 'default_locale', 'currency' => 'default_currency',
        'measurement_system' => 'measurement_system',
    ][$key];
    $orgValue = $org[$orgColumn] ?? null;
    $inherited[$key] = ($orgValue !== null && $orgValue !== '')
        ? $orgValue . ' (organization)'
        : (dfms_platform_defaults()[$key] ?? 'none') . ' (platform default)';
}

$page_title = 'Farm Settings';
$page_breadcrumb = [['label' => 'Settings', 'url' => 'index.php'], ['label' => 'Farm']];
require_once '../../includes/header.php';
?>

<?php dfms_page_header(
    'Farm settings',
    'Overrides for ' . $farm['name'] . '. Leave a field blank to inherit from ' . ($org['name'] ?? 'the organization') . '.'
); ?>

<?php if ($error_message): ?><?php dfms_alert($error_message, 'error'); ?><?php endif; ?>

<section class="dfms-section">
    <div class="dfms-card">
        <form method="POST" action="">
            <?php csrf_field(); ?>

            <div class="dfms-field">
                <label for="name">Farm name</label>
                <input id="name" name="name" type="text" maxlength="150" required
                       value="<?php echo e($farm['name']); ?>">
            </div>

            <div class="dfms-field">
                <label for="country_code"><?php echo e(t('settings.country')); ?></label>
                <select id="country_code" name="country_code">
                    <option value="">— Inherit: <?php echo e($inherited['country_code']); ?> —</option>
                    <?php foreach (dfms_countries() as $code => $name): ?>
                        <option value="<?php echo e($code); ?>" <?php echo $farm['country_code'] === $code ? 'selected' : ''; ?>>
                            <?php echo e($name); ?> (<?php echo e($code); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="dfms-text-muted">
                    Where this farm physically operates — may differ from where the organization is registered.
                </small>
            </div>

            <div class="dfms-field">
                <label for="timezone"><?php echo e(t('settings.timezone')); ?></label>
                <select id="timezone" name="timezone">
                    <option value="">— Inherit: <?php echo e($inherited['timezone']); ?> —</option>
                    <?php foreach (dfms_timezones() as $tz): ?>
                        <option value="<?php echo e($tz); ?>" <?php echo $farm['timezone'] === $tz ? 'selected' : ''; ?>>
                            <?php echo e($tz); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="dfms-text-muted">
                    The most consequential setting here: it decides which calendar day a
                    milking is recorded against.
                </small>
            </div>

            <div class="dfms-field">
                <label for="locale"><?php echo e(t('settings.locale')); ?></label>
                <select id="locale" name="locale">
                    <option value="">— Inherit: <?php echo e($inherited['locale']); ?> —</option>
                    <?php foreach (DFMS_LOCALES as $code => $label): ?>
                        <option value="<?php echo e($code); ?>" <?php echo $farm['locale'] === $code ? 'selected' : ''; ?>>
                            <?php echo e($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="dfms-field">
                <label for="currency"><?php echo e(t('settings.currency')); ?></label>
                <select id="currency" name="currency">
                    <option value="">— Inherit: <?php echo e($inherited['currency']); ?> —</option>
                    <?php foreach (dfms_currencies() as $code => $meta): ?>
                        <option value="<?php echo e($code); ?>" <?php echo $farm['currency'] === $code ? 'selected' : ''; ?>>
                            <?php echo e($code); ?> — <?php echo e($meta['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="dfms-text-muted">
                    Justified when a farm trades in a different currency from its parent
                    organization — a cross-border operation, for example.
                </small>
            </div>

            <div class="dfms-field">
                <label for="measurement_system"><?php echo e(t('settings.measurement_system')); ?></label>
                <select id="measurement_system" name="measurement_system">
                    <option value="">— Inherit: <?php echo e($inherited['measurement_system']); ?> —</option>
                    <?php foreach (DFMS_MEASUREMENT_SYSTEMS as $system): ?>
                        <option value="<?php echo e($system); ?>" <?php echo $farm['measurement_system'] === $system ? 'selected' : ''; ?>>
                            <?php echo e(t('unit.' . $system)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="dfms-text-muted">
                    Local staff usually want local units regardless of head office.
                </small>
            </div>

            <div style="display:flex;gap:.75rem;margin-top:1.5rem;">
                <button type="submit" class="dfms-btn dfms-btn-primary"><?php echo e(t('action.save')); ?></button>
                <a href="<?php echo BASE_URL; ?>modules/settings/" class="dfms-btn dfms-btn-secondary"><?php echo e(t('action.cancel')); ?></a>
            </div>
        </form>
    </div>
</section>

<?php require_once '../../includes/footer.php'; ?>
