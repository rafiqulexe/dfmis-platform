<?php
// ============================================================================
// NAVIGATION — single source of truth for the application's information
// architecture. The sidebar, mobile drawer, bottom nav and command palette
// all read from here, so the IA is defined once.
//
// Each item declares the permission required to see it. Navigation is
// filtered server-side by the same hasPermission() the endpoints enforce,
// so a hidden item is never merely cosmetic.
//
// Items marked 'status' => 'soon' are part of the planned IA but not yet
// built. They render disabled rather than as links, so the structure is
// visible without creating dead ends.
// ============================================================================

require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/farm_types.php';
// A nav item may be gated on a feature flag as well as a capability and a
// permission (see dfms_nav_item_visible), so this is a hard dependency rather
// than something the including page happens to have loaded first.
require_once __DIR__ . '/features.php';

// Each item may declare a `capability`. An item whose capability the active
// farm does not have is hidden — that is how a future poultry farm stops
// seeing "Milk Production" without a single `if ($type === 'dairy')` anywhere
// outside includes/farm_types.php.
//
// Capability is INDEPENDENT of permission. Capability asks "does this farm do
// this?"; permission asks "may this user?". Both must pass.
function dfms_nav_sections() {
    return [
        [
            'label' => null,
            'items' => [
                ['key' => 'dashboard', 'label' => 'Dashboard', 'url' => 'dashboard.php', 'icon' => 'grid', 'permission' => null],
            ],
        ],
        [
            'label' => 'Herd',
            'items' => [
                ['key' => 'livestock',    'label' => 'Livestock',       'url' => 'modules/cows/',   'icon' => 'livestock', 'permission' => 'cows.view',    'capability' => 'animal_registry'],
                ['key' => 'milk',         'label' => 'Milk Production', 'url' => 'modules/milk/',   'icon' => 'droplet',   'permission' => 'milk.view',    'capability' => 'milk_production'],
                ['key' => 'health',       'label' => 'Health',          'url' => 'modules/health/', 'icon' => 'heart',     'permission' => 'health.view',  'capability' => 'health_events'],
                ['key' => 'reproduction', 'label' => 'Reproduction',    'url' => null,              'icon' => 'sprout',    'permission' => 'health.view',  'capability' => 'reproduction', 'status' => 'soon'],
                ['key' => 'feeding',      'label' => 'Feeding',         'url' => 'modules/feeding/','icon' => 'wheat',     'permission' => 'feeding.view', 'capability' => 'feeding'],
            ],
        ],
        [
            'label' => 'Operations',
            'items' => [
                ['key' => 'inventory', 'label' => 'Inventory', 'url' => 'modules/inventory/', 'icon' => 'box',   'permission' => 'inventory.view'],
                ['key' => 'alerts',    'label' => 'Alerts',    'url' => 'modules/alerts/',    'icon' => 'bell',  'permission' => 'alerts.view'],
                ['key' => 'reports',   'label' => 'Reports',   'url' => null,                 'icon' => 'chart', 'permission' => 'milk.view', 'status' => 'soon'],
            ],
        ],
        [
            'label' => 'Intelligence',
            'items' => [
                // Phase 5D — built, so it is a real link rather than a 'soon'
                // placeholder. Gated on BOTH the ai.consult permission and the
                // ai_vet_assistant feature flag, so a farm that does not want
                // an AI surface in its clinical workflow simply switches it off.
                ['key' => 'ai', 'label' => 'AI Assistant', 'url' => 'modules/ai/', 'icon' => 'sparkle',
                 'permission' => 'ai.consult', 'feature' => 'ai_vet_assistant'],
                ['key' => 'ai_knowledge', 'label' => 'Knowledge Base', 'url' => 'modules/ai/knowledge.php', 'icon' => 'inbox',
                 'permission' => 'ai.knowledge', 'feature' => 'ai_vet_assistant'],
            ],
        ],
        [
            'label' => 'Workspace',
            'items' => [
                // The audit log now has both a writer and a reader (baseline
                // audit D-1); `audit.view` is restricted to farm managers.
                ['key' => 'audit',    'label' => 'Audit Log', 'url' => 'modules/audit/',    'icon' => 'inbox',    'permission' => 'audit.view'],
                ['key' => 'settings', 'label' => 'Settings',  'url' => 'modules/settings/', 'icon' => 'settings', 'permission' => 'settings.view'],
            ],
        ],
    ];
}

// Should this nav item be shown at all on the active farm?
//
// Three independent questions, cheapest first:
//   capability — does this farm's business model include this? (farm type)
//   feature    — has this farm/organization switched this feature on?
//   permission — may THIS user do it?
//
// A feature flag is not an authorization check. It answers "is this surface
// enabled here", which is why it sits beside capability rather than replacing
// the permission test below — every endpoint still enforces its own permission.
function dfms_nav_item_visible(array $item): bool {
    if (!empty($item['capability']) && !farmHasCapability($item['capability'])) {
        return false;
    }
    if (!empty($item['feature']) && !dfms_feature_enabled($item['feature'])) {
        return false;
    }
    return $item['permission'] === null || hasPermission($item['permission']);
}

// Sections with items the current user may not see removed, and sections
// that end up empty dropped entirely.
function dfms_visible_nav() {
    $visible = [];
    foreach (dfms_nav_sections() as $section) {
        $items = array_values(array_filter($section['items'], 'dfms_nav_item_visible'));
        if ($items) {
            $section['items'] = $items;
            $visible[] = $section;
        }
    }
    return $visible;
}

// Compact nav for the mobile bottom bar: the destinations a farm worker
// reaches for most, filtered by the same permissions.
function dfms_bottom_nav() {
    $candidates = [
        ['key' => 'dashboard', 'label' => 'Home',      'url' => 'dashboard.php',    'icon' => 'grid',      'permission' => null],
        ['key' => 'livestock', 'label' => 'Livestock', 'url' => 'modules/cows/',    'icon' => 'livestock', 'permission' => 'cows.view', 'capability' => 'animal_registry'],
        ['key' => 'milk',      'label' => 'Milk',      'url' => 'modules/milk/',    'icon' => 'droplet',   'permission' => 'milk.view', 'capability' => 'milk_production'],
        ['key' => 'health',    'label' => 'Health',    'url' => 'modules/health/',  'icon' => 'heart',     'permission' => 'health.view', 'capability' => 'health_events'],
        ['key' => 'alerts',    'label' => 'Alerts',    'url' => 'modules/alerts/',  'icon' => 'bell',      'permission' => 'alerts.view'],
    ];
    $items = array_values(array_filter($candidates, 'dfms_nav_item_visible'));
    return array_slice($items, 0, 5);
}

// Which nav key the current request belongs to. Compared against the
// request path rather than a page-set variable so every page highlights
// correctly without extra bookkeeping.
function dfms_active_nav_key() {
    $path = $_SERVER['PHP_SELF'] ?? '';
    foreach (dfms_nav_sections() as $section) {
        foreach ($section['items'] as $item) {
            if (!empty($item['url']) && $item['url'] !== 'dashboard.php' && strpos($path, trim($item['url'], '/')) !== false) {
                return $item['key'];
            }
        }
    }
    return strpos($path, 'dashboard.php') !== false ? 'dashboard' : '';
}

// Inline SVG icon set. Kept in one place so icon style stays consistent
// (single stroke weight, same 24px grid) and no icon-font request is needed.
function dfms_icon($name, $class = '') {
    $icons = [
        'grid'      => '<path d="M4 5.5A1.5 1.5 0 015.5 4h3A1.5 1.5 0 0110 5.5v3A1.5 1.5 0 018.5 10h-3A1.5 1.5 0 014 8.5v-3zM14 5.5A1.5 1.5 0 0115.5 4h3A1.5 1.5 0 0120 5.5v3A1.5 1.5 0 0118.5 10h-3A1.5 1.5 0 0114 8.5v-3zM4 15.5A1.5 1.5 0 015.5 14h3A1.5 1.5 0 0110 15.5v3A1.5 1.5 0 018.5 20h-3A1.5 1.5 0 014 18.5v-3zM14 15.5A1.5 1.5 0 0115.5 14h3a1.5 1.5 0 011.5 1.5v3a1.5 1.5 0 01-1.5 1.5h-3a1.5 1.5 0 01-1.5-1.5v-3z"/>',
        'livestock' => '<path d="M4 8c0-1 .6-2 1.5-2S7 6.5 7 7.5M20 8c0-1-.6-2-1.5-2S17 6.5 17 7.5"/><path d="M7 7.5C7 6 9 5 12 5s5 1 5 2.5c0 1-.3 1.8-.3 2.8 0 2.2 1.3 3 1.3 5.2 0 2-1.5 3.5-3 3.5h-6c-1.5 0-3-1.5-3-3.5 0-2.2 1.3-3 1.3-5.2 0-1-.3-1.8-.3-2.8z"/><path d="M10.5 11h.01M13.5 11h.01"/>',
        'droplet'   => '<path d="M12 3.5s5.5 5.4 5.5 9.3A5.5 5.5 0 0112 18.3a5.5 5.5 0 01-5.5-5.5c0-3.9 5.5-9.3 5.5-9.3z"/>',
        'heart'     => '<path d="M12 20s-6.5-4.3-8.4-8A4.7 4.7 0 0112 6.5 4.7 4.7 0 0120.4 12c-1.9 3.7-8.4 8-8.4 8z"/>',
        'sprout'    => '<path d="M12 21v-8"/><path d="M12 13c0-3.3 2.7-6 6-6 0 3.3-2.7 6-6 6zM12 13c0-2.8-2.2-5-5-5 0 2.8 2.2 5 5 5z"/>',
        'wheat'     => '<path d="M12 21V9"/><path d="M12 9c-2 0-3.5-1.6-3.5-3.5S10 2 12 2s3.5 1.6 3.5 3.5S14 9 12 9zM12 13c-2.4 0-4.5-1.4-4.5-3M12 13c2.4 0 4.5-1.4 4.5-3M12 17c-2.4 0-4.5-1.4-4.5-3M12 17c2.4 0 4.5-1.4 4.5-3"/>',
        'box'       => '<path d="M20.5 7.3l-8-4.2a1 1 0 00-.9 0l-8 4.2a1 1 0 00-.6.9v7.6a1 1 0 00.6.9l8 4.2a1 1 0 00.9 0l8-4.2a1 1 0 00.6-.9V8.2a1 1 0 00-.6-.9z"/><path d="M3.6 7.6L12 12l8.4-4.4M12 21v-9"/>',
        'bell'      => '<path d="M18 8.5a6 6 0 10-12 0c0 6-2 7.5-2 7.5h16s-2-1.5-2-7.5z"/><path d="M13.7 20a2 2 0 01-3.4 0"/>',
        'chart'     => '<path d="M4 20V10M10 20V4M16 20v-6M22 20H2"/>',
        'sparkle'   => '<path d="M12 3l1.9 5.3L19 10l-5.1 1.7L12 17l-1.9-5.3L5 10l5.1-1.7L12 3zM18.5 15.5l.8 2.2 2.2.8-2.2.8-.8 2.2-.8-2.2-2.2-.8 2.2-.8.8-2.2z"/>',
        'settings'  => '<circle cx="12" cy="12" r="3"/><path d="M19.4 14.5a1.6 1.6 0 00.3 1.8l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.6 1.6 0 00-1.8-.3 1.6 1.6 0 00-1 1.5v.2a2 2 0 11-4 0v-.1a1.6 1.6 0 00-1-1.5 1.6 1.6 0 00-1.8.3l-.1.1a2 2 0 11-2.8-2.8l.1-.1a1.6 1.6 0 00.3-1.8 1.6 1.6 0 00-1.5-1H3a2 2 0 110-4h.1a1.6 1.6 0 001.5-1 1.6 1.6 0 00-.3-1.8l-.1-.1a2 2 0 112.8-2.8l.1.1a1.6 1.6 0 001.8.3h.1a1.6 1.6 0 001-1.5V3a2 2 0 114 0v.1a1.6 1.6 0 001 1.5 1.6 1.6 0 001.8-.3l.1-.1a2 2 0 112.8 2.8l-.1.1a1.6 1.6 0 00-.3 1.8v.1a1.6 1.6 0 001.5 1H21a2 2 0 110 4h-.1a1.6 1.6 0 00-1.5 1z"/>',
        'search'    => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
        'menu'      => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'close'     => '<path d="M6 6l12 12M18 6L6 18"/>',
        'logout'    => '<path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/>',
        'user'      => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0116 0"/>',
        'chevron'   => '<path d="M6 9l6 6 6-6"/>',
        'plus'      => '<path d="M12 5v14M5 12h14"/>',
        'inbox'     => '<path d="M4 13h4l2 3h4l2-3h4"/><path d="M5.5 5h13l2.5 8v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5l2.5-8z"/>',
    ];
    $path = $icons[$name] ?? $icons['grid'];
    return '<svg class="' . htmlspecialchars($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
        . 'stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . $path . '</svg>';
}
