<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/permissions.php';

// Farm shown in the top bar. With one farm today this is a label; once a
// user belongs to several it becomes the farm switcher's current value.
$dfms_farm_name = '';
if (isLoggedIn() && currentFarmId() !== null) {
    $stmt = getDBConnection()->prepare("SELECT name FROM farms WHERE farm_id = ?");
    $stmt->execute([currentFarmId()]);
    $dfms_farm_name = (string) $stmt->fetchColumn();
}
$dfms_role_label = ucwords(str_replace('_', ' ', (string) currentFarmRole()));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DFMS - Dairy Farm Management System</title>
    <!-- Tailwind CDN kept temporarily for pages not yet migrated to the new design system -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/style.css">
    <link rel="icon" type="image/x-icon" href="<?php echo BASE_URL; ?>assets/images/favicon.ico">
    <meta name="theme-color" content="#16a34a">
</head>

<body class="dfms">
    <?php if (isLoggedIn()):
        $current_page = $_SERVER['PHP_SELF'];
        $primary_nav = [
            'dashboard.php' => 'Dashboard',
            'modules/cows/' => 'Cows',
            'modules/health/' => 'Health',
        ];
        $secondary_nav = [
            'modules/feeding/' => 'Feeding',
            'modules/milk/' => 'Milk Production',
            'modules/inventory/' => 'Inventory',
            'modules/alerts/' => 'Alerts',
        ];
        $bottom_nav = [
            'dashboard.php' => 'Home',
            'modules/cows/' => 'Cows',
            'modules/health/' => 'Health',
            'modules/alerts/' => 'Alerts',
        ];

        function dfms_is_active($current, $url) {
            return strpos($current, $url) !== false;
        }
    ?>
    <div class="dfms-shell">
        <aside class="dfms-sidebar">
            <div class="dfms-sidebar-brand"><?php echo htmlspecialchars($dfms_farm_name); ?></div>
            <nav class="dfms-nav">
                <?php foreach ($primary_nav as $url => $label): ?>
                    <a href="<?php echo BASE_URL . $url; ?>" class="<?php echo dfms_is_active($current_page, $url) ? 'active' : ''; ?>"><?php echo htmlspecialchars($label); ?></a>
                <?php endforeach; ?>
                <div class="dfms-nav-section-label">More</div>
                <?php foreach ($secondary_nav as $url => $label): ?>
                    <a href="<?php echo BASE_URL . $url; ?>" class="<?php echo dfms_is_active($current_page, $url) ? 'active' : ''; ?>"><?php echo htmlspecialchars($label); ?></a>
                <?php endforeach; ?>
            </nav>
            <div class="dfms-sidebar-footer">
                <a href="<?php echo BASE_URL; ?>logout.php">Logout</a>
            </div>
        </aside>

        <div class="dfms-main">
            <header class="dfms-topbar">
                <div></div>
                <div class="dfms-topbar-user">
                    <span><?php echo htmlspecialchars($dfms_farm_name); ?> · <?php echo htmlspecialchars($_SESSION['username']); ?> (<?php echo htmlspecialchars($dfms_role_label); ?>)</span>
                    <a href="<?php echo BASE_URL; ?>logout.php" class="dfms-btn dfms-btn-secondary">Logout</a>
                </div>
            </header>

            <div class="dfms-mobile-topbar">
                <button type="button" data-drawer-open aria-label="Open menu">&#9776;</button>
                <strong><?php echo htmlspecialchars($dfms_farm_name); ?></strong>
                <a href="<?php echo BASE_URL; ?>modules/alerts/" aria-label="Alerts" style="color:#fff;">&#128276;</a>
            </div>

            <main class="dfms-content">
    <?php endif; ?>
