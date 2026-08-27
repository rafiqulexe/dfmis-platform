<?php
// ============================================================================
// APPLICATION SHELL — top half.
//
// Pages set $page_title / $page_breadcrumb before including this file; both
// are optional. Navigation, tenant context and the notification count are
// resolved here so no page repeats that plumbing.
// ============================================================================

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/repository.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/farm_types.php';
require_once __DIR__ . '/features.php';
require_once __DIR__ . '/navigation.php';
require_once __DIR__ . '/components.php';

/**
 * Resolves everything the shell needs to render.
 *
 * Baseline audit C-10: this logic used to run at file scope, so $conn, $stmt
 * and $row leaked into the scope of whichever page included the header —
 * silently replacing the connection handle a page was already using. Keeping
 * it in a function confines those locals.
 */
function dfms_shell_context(): array {
    $context = [
        'farm_name'     => '',
        'org_name'      => '',
        'unread_alerts' => 0,
        'farms'         => [],
    ];

    if (!isLoggedIn() || currentFarmId() === null) {
        return $context;
    }

    $conn = getDBConnection();

    $stmt = $conn->prepare(
        "SELECT f.name AS farm_name, o.name AS org_name
         FROM farms f JOIN organizations o ON o.organization_id = f.organization_id
         WHERE f.farm_id = ?"
    );
    $stmt->execute([currentFarmId()]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $context['farm_name'] = (string) $row['farm_name'];
        $context['org_name'] = (string) $row['org_name'];
    }

    // Unread alerts drive the topbar indicator. Scoped like all tenant reads.
    if (hasPermission('alerts.view')) {
        $context['unread_alerts'] = (int) tenantScalar(
            $conn,
            "SELECT COUNT(*) FROM alerts WHERE {scope} AND is_read = 0"
        );
    }

    // Farms this user may switch to. Only queried when there is more than one,
    // so the single-farm case costs nothing (baseline audit D-2).
    $allowed = allowedFarmIds();
    if (count($allowed) > 1) {
        $placeholders = implode(',', array_fill(0, count($allowed), '?'));
        $stmt = $conn->prepare("SELECT farm_id, name FROM farms WHERE farm_id IN ($placeholders) ORDER BY name");
        $stmt->execute($allowed);
        $context['farms'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    return $context;
}

$dfms_shell = dfms_shell_context();
$dfms_farm_name = $dfms_shell['farm_name'];
$dfms_org_name = $dfms_shell['org_name'];
$dfms_unread_alerts = $dfms_shell['unread_alerts'];
$dfms_switchable_farms = $dfms_shell['farms'];

$dfms_role_label = ucwords(str_replace('_', ' ', (string) currentFarmRole()));
$dfms_username = $_SESSION['username'] ?? '';
$dfms_initials = strtoupper(substr($dfms_username, 0, 2));
$dfms_active = dfms_active_nav_key();
$dfms_nav = isLoggedIn() ? dfms_visible_nav() : [];
$dfms_bottom = isLoggedIn() ? dfms_bottom_nav() : [];

$page_title = $page_title ?? 'Dashboard';
$page_breadcrumb = $page_breadcrumb ?? [];

/**
 * Renders one navigation entry. Items without a URL are part of the planned
 * IA but not yet built, so they render as disabled text rather than links —
 * the structure stays visible without producing a dead end.
 */
function dfms_render_nav_item(array $item, $activeKey, $unreadAlerts = 0) {
    $isActive = $item['key'] === $activeKey;
    $isSoon = ($item['status'] ?? '') === 'soon' || empty($item['url']);

    $classes = 'dfms-nav-item' . ($isActive ? ' active' : '') . ($isSoon ? ' is-disabled' : '');
    $icon = dfms_icon($item['icon']);
    $label = e($item['label']);

    $trailing = '';
    if ($isSoon) {
        $trailing = '<span class="dfms-nav-badge">Soon</span>';
    } elseif ($item['key'] === 'alerts' && $unreadAlerts > 0) {
        $trailing = '<span class="dfms-nav-count">' . e($unreadAlerts) . '</span>';
    }

    if ($isSoon) {
        echo '<span class="' . $classes . '" aria-disabled="true">' . $icon . $label . $trailing . '</span>';
        return;
    }

    echo '<a href="' . e(BASE_URL . $item['url']) . '" class="' . $classes . '"'
        . ($isActive ? ' aria-current="page"' : '') . '>' . $icon . $label . $trailing . '</a>';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($page_title); ?> · DFM</title>
    <!--
      Tailwind CDN remains only for module pages not yet migrated to the DFM
      design system. It is removed once the last page is converted; nothing
      in the shell or design system depends on it.
    -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@600;700;800&family=Inter:wght@400;500;600;700&family=Fraunces:ital,wght@1,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/style.css">
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>assets/images/favicon.svg">
    <meta name="theme-color" content="#1f6f4a">
</head>

<body class="dfms">
<?php if (isLoggedIn()): ?>
    <a class="dfms-skip-link" href="#dfms-main-content">Skip to main content</a>

    <div class="dfms-shell">
        <!-- ================= Sidebar ================= -->
        <aside class="dfms-sidebar">
            <div class="dfms-brand">
                <span class="dfms-brand-mark" aria-hidden="true">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 3v18M5 8c0 4 3 6 7 6M19 8c0 4-3 6-7 6"/>
                    </svg>
                </span>
                <span class="dfms-brand-text">
                    <span class="dfms-brand-name">DFM</span>
                    <span class="dfms-brand-sub"><?php echo e($dfms_org_name); ?></span>
                </span>
            </div>

            <nav class="dfms-nav" aria-label="Main navigation">
                <?php foreach ($dfms_nav as $section): ?>
                    <?php if (!empty($section['label'])): ?>
                        <div class="dfms-nav-section"><?php echo e($section['label']); ?></div>
                    <?php endif; ?>
                    <?php foreach ($section['items'] as $item): ?>
                        <?php dfms_render_nav_item($item, $dfms_active, $dfms_unread_alerts); ?>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </nav>

            <div class="dfms-sidebar-footer">
                <a href="<?php echo BASE_URL; ?>logout.php" class="dfms-nav-item">
                    <?php echo dfms_icon('logout'); ?>Sign out
                </a>
            </div>
        </aside>

        <div class="dfms-main">
            <!-- ================= Desktop top bar ================= -->
            <header class="dfms-topbar">
                <div class="dfms-topbar-lead">
                    <?php if (!empty($page_breadcrumb)): ?>
                        <?php dfms_breadcrumb($page_breadcrumb); ?>
                    <?php else: ?>
                        <span class="dfms-breadcrumb-current"><?php echo e($page_title); ?></span>
                    <?php endif; ?>
                </div>

                <div class="dfms-topbar-actions">
                    <button type="button" class="dfms-search-trigger" data-palette-open>
                        <?php echo dfms_icon('search'); ?>
                        <span>Search</span>
                        <span class="dfms-kbd">/</span>
                    </button>

                    <?php if (hasPermission('alerts.view')): ?>
                        <a href="<?php echo BASE_URL; ?>modules/alerts/" class="dfms-icon-btn dfms-tooltip"
                           data-tooltip="Alerts" aria-label="Alerts<?php echo $dfms_unread_alerts > 0 ? ' (' . e($dfms_unread_alerts) . ' unread)' : ''; ?>">
                            <?php echo dfms_icon('bell'); ?>
                            <?php if ($dfms_unread_alerts > 0): ?>
                                <span class="dfms-icon-btn-dot"><?php echo e(min($dfms_unread_alerts, 99)); ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endif; ?>

                    <?php if (count($dfms_switchable_farms) > 1): ?>
                        <!-- Farm switcher. switchFarm() re-checks membership
                             server-side, so a crafted farm_id cannot move a
                             user into another tenant. -->
                        <form method="POST" action="<?php echo BASE_URL; ?>modules/farm/switch.php" class="dfms-context-chip">
                            <?php csrf_field(); ?>
                            <span class="dfms-context-dot" aria-hidden="true"></span>
                            <label for="dfms-farm-switch" class="dfms-visually-hidden">Active farm</label>
                            <select id="dfms-farm-switch" name="farm_id" onchange="this.form.submit()"
                                    style="border:none;background:transparent;font:inherit;color:inherit;cursor:pointer;">
                                <?php foreach ($dfms_switchable_farms as $dfms_farm): ?>
                                    <option value="<?php echo (int) $dfms_farm['farm_id']; ?>"
                                        <?php echo (int) $dfms_farm['farm_id'] === currentFarmId() ? 'selected' : ''; ?>>
                                        <?php echo e($dfms_farm['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <noscript><button type="submit" class="dfms-btn dfms-btn-secondary">Switch</button></noscript>
                        </form>
                    <?php else: ?>
                        <span class="dfms-context-chip dfms-tooltip" data-tooltip="Active farm">
                            <span class="dfms-context-dot" aria-hidden="true"></span>
                            <span class="dfms-context-name"><?php echo e($dfms_farm_name); ?></span>
                        </span>
                    <?php endif; ?>

                    <div class="dfms-user-menu">
                        <button type="button" class="dfms-user-trigger" data-dropdown-toggle="user-menu"
                                aria-expanded="false" aria-haspopup="true">
                            <span class="dfms-avatar"><?php echo e($dfms_initials); ?></span>
                            <?php echo dfms_icon('chevron', 'dfms-user-caret'); ?>
                        </button>
                        <div class="dfms-dropdown" id="user-menu" role="menu">
                            <div class="dfms-dropdown-header">
                                <div class="dfms-dropdown-name"><?php echo e($dfms_username); ?></div>
                                <div class="dfms-dropdown-meta"><?php echo e($dfms_role_label); ?> · <?php echo e($dfms_farm_name); ?></div>
                            </div>
                            <a href="<?php echo BASE_URL; ?>logout.php" class="dfms-dropdown-item danger" role="menuitem">
                                <?php echo dfms_icon('logout'); ?>Sign out
                            </a>
                        </div>
                    </div>
                </div>
            </header>

            <!-- ================= Mobile top bar ================= -->
            <div class="dfms-mobile-topbar">
                <button type="button" class="dfms-icon-btn" data-drawer-open aria-label="Open menu" aria-expanded="false">
                    <?php echo dfms_icon('menu'); ?>
                </button>
                <strong style="font-family:var(--font-display); color:var(--text-strong); font-size:var(--text-base);">
                    <?php echo e($page_title); ?>
                </strong>
                <button type="button" class="dfms-icon-btn" data-palette-open aria-label="Search">
                    <?php echo dfms_icon('search'); ?>
                </button>
            </div>

            <main class="dfms-content" id="dfms-main-content">
                <?php dfms_render_flashes(); ?>
<?php endif; ?>
