<?php
// ============================================================================
// ORGANIZATION SETTINGS
//
// AUTHORIZATION: requireAuth() covers authentication, tenant context, farm and
// organization membership. requireOrganizationPermission('org.manage') then
// restricts writing to organization owners and admins. Both come from the
// Phase 1 architecture; nothing is re-implemented here.
//
// TENANT SCOPE: the organization id is read from the tenant context via
// currentOrganizationId(), never from the request. There is no path by which
// this page can write to another organization's row.
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
require_once '../../includes/subscription.php';

requireAuth('settings.view');
requireOrganizationPermission('org.manage');

$error_message = '';
$success_message = '';

// From the tenant context — NOT from $_GET or $_POST.
$organizationId = currentOrganizationId();
$org = dfms_organization_row($organizationId);
if ($org === null) {
    dfms_abort(404, 'Organization row missing for id ' . $organizationId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $v = new DfmsValidator($_POST);
    $legalName = $v->string('legal_name', 0, 200, ['nullable' => true]);
    $displayName = $v->string('name', 1, 150);

    // Organization values are DEFAULTS, not overrides — an empty one would
    // leave the whole organization on the platform default, which is a real
    // choice, so empty is allowed and stored as NULL.
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
            $values = $settings['values'];
            $values['legal_name'] = $legalName;

            dfms_update_organization_settings($organizationId, $values);

            // The display name lives on `name`, which predates this phase and
            // is not part of the settings hierarchy — updated separately and
            // audited by the same helper's diff.
            if ($displayName !== $org['name']) {
                $conn = getDBConnection();
                $stmt = $conn->prepare("UPDATE organizations SET name = ? WHERE organization_id = ?");
                $stmt->execute([$displayName, $organizationId]);
                auditLog('organization.rename', 'organization', $organizationId,
                    ['name' => $org['name']], ['name' => $displayName]);
                dfms_reset_settings_cache();
            }

            redirectWith('modules/settings/organization.php', 'success', t('state.saved'));
        } catch (PDOException $e) {
            $error_message = safeError($e, 'Could not save organization settings');
        }
    }
    // Re-read so the form shows what was actually stored.
    $org = dfms_organization_row($organizationId);
}

$page_title = 'Organization Settings';
$page_breadcrumb = [['label' => 'Settings', 'url' => 'index.php'], ['label' => 'Organization']];
require_once '../../includes/header.php';
?>

<?php dfms_page_header(
    'Organization settings',
    'Defaults for every farm in ' . $org['name'] . '. A farm may override any of these.'
); ?>

<?php if ($error_message): ?><?php dfms_alert($error_message, 'error'); ?><?php endif; ?>
<?php if ($success_message): ?><?php dfms_alert($success_message, 'success'); ?><?php endif; ?>

<section class="dfms-section">
    <div class="dfms-card">
        <form method="POST" action="">
            <?php csrf_field(); ?>

            <div class="dfms-field">
                <label for="name"><?php echo e(t('settings.display_name')); ?></label>
                <input id="name" name="name" type="text" maxlength="150" required
                       value="<?php echo e($org['name']); ?>">
                <small class="dfms-text-muted">Shown in the sidebar and page headers.</small>
            </div>

            <div class="dfms-field">
                <label for="legal_name"><?php echo e(t('settings.legal_name')); ?></label>
                <input id="legal_name" name="legal_name" type="text" maxlength="200"
                       value="<?php echo e($org['legal_name'] ?? ''); ?>">
                <small class="dfms-text-muted">
                    Registered entity name, if it differs from the display name. Used on
                    contracts and invoices in a later phase.
                </small>
            </div>

            <div class="dfms-field">
                <label for="country_code"><?php echo e(t('settings.country')); ?></label>
                <select id="country_code" name="country_code">
                    <option value="">— <?php echo e(t('settings.platform_default')); ?> (none) —</option>
                    <?php foreach (dfms_countries() as $code => $name): ?>
                        <option value="<?php echo e($code); ?>" <?php echo $org['country_code'] === $code ? 'selected' : ''; ?>>
                            <?php echo e($name); ?> (<?php echo e($code); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="dfms-text-muted">ISO 3166-1 alpha-2. Stored as a code, never as a name.</small>
            </div>

            <div class="dfms-field">
                <label for="timezone"><?php echo e(t('settings.timezone')); ?></label>
                <select id="timezone" name="timezone">
                    <option value="">— <?php echo e(t('settings.platform_default')); ?> (<?php echo e(DFMS_PLATFORM_DEFAULT_TIMEZONE); ?>) —</option>
                    <?php foreach (dfms_timezones() as $tz): ?>
                        <option value="<?php echo e($tz); ?>" <?php echo $org['default_timezone'] === $tz ? 'selected' : ''; ?>>
                            <?php echo e($tz); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="dfms-text-muted">
                    IANA identifier. Determines what "today" means for daily production records.
                </small>
            </div>

            <div class="dfms-field">
                <label for="locale"><?php echo e(t('settings.locale')); ?></label>
                <select id="locale" name="locale">
                    <option value="">— <?php echo e(t('settings.platform_default')); ?> (<?php echo e(DFMS_PLATFORM_DEFAULT_LOCALE); ?>) —</option>
                    <?php foreach (DFMS_LOCALES as $code => $label): ?>
                        <option value="<?php echo e($code); ?>" <?php echo $org['default_locale'] === $code ? 'selected' : ''; ?>>
                            <?php echo e($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="dfms-text-muted">
                    Interface language. Translation coverage is partial — untranslated text falls back to English.
                </small>
            </div>

            <div class="dfms-field">
                <label for="currency"><?php echo e(t('settings.currency')); ?></label>
                <select id="currency" name="currency">
                    <option value="">— <?php echo e(t('settings.platform_default')); ?> (<?php echo e(DFMS_PLATFORM_DEFAULT_CURRENCY); ?>) —</option>
                    <?php foreach (dfms_currencies() as $code => $meta): ?>
                        <option value="<?php echo e($code); ?>" <?php echo $org['default_currency'] === $code ? 'selected' : ''; ?>>
                            <?php echo e($code); ?> — <?php echo e($meta['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="dfms-text-muted">
                    ISO 4217. Decimal places follow the currency's minor unit. No monetary
                    records exist yet, so changing this cannot alter historical figures.
                </small>
            </div>

            <div class="dfms-field">
                <label for="measurement_system"><?php echo e(t('settings.measurement_system')); ?></label>
                <select id="measurement_system" name="measurement_system">
                    <option value="">— <?php echo e(t('settings.platform_default')); ?> (<?php echo e(DFMS_PLATFORM_DEFAULT_MEASUREMENT); ?>) —</option>
                    <?php foreach (DFMS_MEASUREMENT_SYSTEMS as $system): ?>
                        <option value="<?php echo e($system); ?>" <?php echo $org['measurement_system'] === $system ? 'selected' : ''; ?>>
                            <?php echo e(t('unit.' . $system)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="dfms-text-muted">
                    Display only. Values are always stored in metric, so switching this
                    converts what you see and never rewrites a stored measurement.
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
