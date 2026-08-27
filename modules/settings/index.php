<?php
// ============================================================================
// SETTINGS HUB
//
// Shows the RESOLVED configuration for the active farm and where each value
// came from, then links to the pages that can change each level.
//
// Showing the source is the point. "Asia/Dhaka" alone does not tell a farm
// manager whether that is this farm's own setting or one inherited from the
// organization — and that distinction determines which page they need.
// ============================================================================

require_once '../../includes/auth.php';
require_once '../../includes/errors.php';
require_once '../../includes/audit.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';
require_once '../../includes/repository.php';
require_once '../../includes/settings.php';
require_once '../../includes/subscription.php';
require_once '../../includes/farm_types.php';

requireAuth('settings.view');

$org = dfms_organization_row();
$farm = dfms_farm_row();
$subscription = dfms_current_subscription();

// Resolve each setting WITH its source, so the table can label inheritance.
$resolved = [];
foreach (['country_code', 'timezone', 'locale', 'currency', 'measurement_system'] as $key) {
    $resolved[$key] = dfms_resolve_setting($key);
}

$page_title = 'Settings';
$page_breadcrumb = [['label' => 'Settings']];
require_once '../../includes/header.php';

// Renders the source of a value as a badge, so inheritance is visible at a glance.
function settings_source_badge(string $source): string {
    $tones = ['farm' => 'success', 'organization' => 'info', 'platform' => 'neutral', 'default' => 'neutral'];
    $labels = [
        'farm'         => 'Farm override',
        'organization' => 'From organization',
        'platform'     => 'Platform default',
        'default'      => 'Platform default',
    ];
    return dfms_badge_html($labels[$source] ?? $source, $tones[$source] ?? 'neutral');
}

// Human-readable rendering of a resolved value.
function settings_display_value(string $key, $value): string {
    if ($value === null || $value === '') {
        return '<span class="dfms-text-muted">Not set</span>';
    }
    switch ($key) {
        case 'country_code':
            $name = dfms_country_name($value);
            return e($name ? "$name ($value)" : $value);
        case 'currency':
            $name = dfms_currency_name($value);
            return e($name ? "$value — $name" : $value);
        case 'locale':
            return e(DFMS_LOCALES[$value] ?? $value);
        case 'measurement_system':
            return e(ucfirst($value));
        default:
            return e($value);
    }
}
?>

<?php dfms_page_header(
    t('settings.title'),
    'Configuration in force for ' . ($farm['name'] ?? 'this farm') . ', and where each value comes from.'
); ?>

<!-- ================= Resolved configuration ================= -->
<section class="dfms-section">
    <div class="dfms-section-header">
        <div>
            <h2>Active configuration</h2>
            <p>Resolved through farm → organization → platform default.</p>
        </div>
    </div>

    <div class="dfms-card">
        <?php dfms_table_open([
            ['label' => 'Setting'],
            ['label' => 'Value'],
            ['label' => 'Source'],
        ]); ?>
        <?php
        $labels = [
            'country_code'       => t('settings.country'),
            'timezone'           => t('settings.timezone'),
            'locale'             => t('settings.locale'),
            'currency'           => t('settings.currency'),
            'measurement_system' => t('settings.measurement_system'),
        ];
        foreach ($resolved as $key => $entry):
        ?>
            <tr>
                <?php
                dfms_cell('Setting', e($labels[$key]));
                dfms_cell('Value', settings_display_value($key, $entry['value']));
                dfms_cell('Source', settings_source_badge($entry['source']));
                ?>
            </tr>
        <?php endforeach; ?>
        <?php dfms_table_close(); ?>
    </div>
</section>

<!-- ================= Live examples ================= -->
<section class="dfms-section">
    <div class="dfms-section-header">
        <div>
            <h2>How this renders</h2>
            <p>The same stored values, formatted with the configuration above.</p>
        </div>
    </div>
    <div class="dfms-card">
        <?php dfms_table_open([['label' => 'Kind'], ['label' => 'Stored (canonical)'], ['label' => 'Displayed']]); ?>
        <tr>
            <?php
            dfms_cell('Kind', 'Milk volume');
            dfms_cell('Stored (canonical)', '<code>108.50</code> litres');
            dfms_cell('Displayed', e(fmt_measure(108.5, 'volume')));
            ?>
        </tr>
        <tr>
            <?php
            dfms_cell('Kind', 'Feed mass');
            dfms_cell('Stored (canonical)', '<code>5.00</code> kilograms');
            dfms_cell('Displayed', e(fmt_measure(5, 'mass')));
            ?>
        </tr>
        <tr>
            <?php
            dfms_cell('Kind', 'Body temperature');
            dfms_cell('Stored (canonical)', '<code>38.60</code> °C');
            dfms_cell('Displayed', e(fmt_measure(38.6, 'temperature')));
            ?>
        </tr>
        <tr>
            <?php
            dfms_cell('Kind', 'Monetary amount');
            dfms_cell('Stored (canonical)', '<span class="dfms-text-muted">no monetary data yet — Phase 9</span>');
            dfms_cell('Displayed', e(fmt_money(1234.5)));
            ?>
        </tr>
        <tr>
            <?php
            dfms_cell('Kind', 'Today on this farm');
            dfms_cell('Stored (canonical)', '<span class="dfms-text-muted">computed, not stored</span>');
            dfms_cell('Displayed', e(farmToday()) . ' <span class="dfms-text-muted">(' . e(currentTimezone()) . ')</span>');
            ?>
        </tr>
        <?php dfms_table_close(); ?>
    </div>
</section>

<!-- ================= Farm profile ================= -->
<section class="dfms-section">
    <div class="dfms-section-header">
        <div>
            <h2>Farm profile</h2>
            <p>Type determines which modules this farm can use.</p>
        </div>
    </div>
    <div class="dfms-card">
        <?php dfms_table_open([['label' => 'Property'], ['label' => 'Value']]); ?>
        <tr>
            <?php
            dfms_cell('Property', 'Farm type');
            $typeLabel = dfms_farm_types()[$farm['farm_type'] ?? '']['label'] ?? ($farm['farm_type'] ?? '—');
            dfms_cell('Value', e($typeLabel) . ($farm['farm_subtype'] ? ' · ' . e(ucfirst($farm['farm_subtype'])) : ''));
            ?>
        </tr>
        <tr>
            <?php
            dfms_cell('Property', 'Capabilities');
            $badges = '';
            foreach (currentFarmCapabilities() as $capability) {
                $built = dfms_capability_is_implemented($capability);
                $badges .= dfms_badge_html(
                    str_replace('_', ' ', $capability) . ($built ? '' : ' (planned)'),
                    $built ? 'success' : 'neutral'
                ) . ' ';
            }
            dfms_cell('Value', $badges);
            ?>
        </tr>
        <?php dfms_table_close(); ?>
    </div>
</section>

<!-- ================= Subscription (read-only display) ================= -->
<?php if ($subscription !== null): ?>
<section class="dfms-section">
    <div class="dfms-section-header">
        <div>
            <h2>Subscription</h2>
            <p>Plan and status for <?php echo e($org['name'] ?? 'this organization'); ?>.</p>
        </div>
    </div>
    <div class="dfms-card">
        <?php dfms_table_open([['label' => 'Property'], ['label' => 'Value']]); ?>
        <tr>
            <?php
            dfms_cell('Property', 'Plan');
            dfms_cell('Value', dfms_badge_html($subscription['plan_label'], 'info') . ' ' . e($subscription['plan_description']));
            ?>
        </tr>
        <tr>
            <?php
            dfms_cell('Property', 'Status');
            dfms_cell('Value', dfms_badge_html($subscription['status_label'], $subscription['grants_access'] ? 'success' : 'danger'));
            ?>
        </tr>
        <tr>
            <?php
            dfms_cell('Property', 'Plan limits');
            $limitText = [];
            foreach ($subscription['limits'] as $resource => $limit) {
                $limitText[] = str_replace('_', ' ', $resource) . ': ' . ($limit < 0 ? 'unlimited' : $limit);
            }
            dfms_cell('Value', e(implode(' · ', $limitText)));
            ?>
        </tr>
        <?php dfms_table_close(); ?>
        <p class="dfms-text-muted" style="margin-top:1rem;font-size:.9rem;">
            Limits are recorded but <strong>not enforced</strong>. No existing access has been
            restricted; billing and enforcement are later phases.
        </p>
    </div>
</section>
<?php endif; ?>

<!-- ================= Where to change things ================= -->
<section class="dfms-section">
    <div class="dfms-section-header">
        <div>
            <h2>Manage</h2>
        </div>
    </div>
    <div class="dfms-quick-actions">
        <?php if (hasOrganizationPermission('org.manage')): ?>
            <a class="dfms-quick-action" href="<?php echo BASE_URL; ?>modules/settings/organization.php">
                <span class="dfms-quick-action-icon"><?php echo dfms_icon('settings'); ?></span>
                <span>
                    <span class="dfms-quick-action-title">Organization defaults</span><br>
                    <span class="dfms-quick-action-sub">Country, timezone, language, currency, units</span>
                </span>
            </a>
        <?php endif; ?>

        <?php if (hasPermission('settings.farm')): ?>
            <a class="dfms-quick-action" href="<?php echo BASE_URL; ?>modules/settings/farm.php">
                <span class="dfms-quick-action-icon"><?php echo dfms_icon('grid'); ?></span>
                <span>
                    <span class="dfms-quick-action-title">Farm overrides</span><br>
                    <span class="dfms-quick-action-sub">Differ from the organization where needed</span>
                </span>
            </a>
        <?php endif; ?>

        <?php if (hasPermission('settings.features') || hasOrganizationPermission('org.manage')): ?>
            <a class="dfms-quick-action" href="<?php echo BASE_URL; ?>modules/settings/features.php">
                <span class="dfms-quick-action-icon"><?php echo dfms_icon('sparkle'); ?></span>
                <span>
                    <span class="dfms-quick-action-title">Features</span><br>
                    <span class="dfms-quick-action-sub">Controlled rollout of future modules</span>
                </span>
            </a>
        <?php endif; ?>

        <?php if (hasPermission('notifications.manage')): ?>
            <a class="dfms-quick-action" href="<?php echo BASE_URL; ?>modules/settings/notifications.php">
                <span class="dfms-quick-action-icon"><?php echo dfms_icon('bell'); ?></span>
                <span>
                    <span class="dfms-quick-action-title">Notification preferences</span><br>
                    <span class="dfms-quick-action-sub">How you want to be told about events</span>
                </span>
            </a>
        <?php endif; ?>
    </div>
</section>

<?php require_once '../../includes/footer.php'; ?>
