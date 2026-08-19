<?php
// ============================================================================
// UI COMPONENTS — reusable render helpers for the DFM design system.
//
// Pages compose these rather than hand-writing markup, so a change to a
// component's structure or classes lands everywhere at once. Every helper
// escapes its own output; callers pass plain values, not HTML.
//
// Helpers echo directly (dfms_*) or return a string (dfms_*_html) where the
// result needs to be embedded.
// ============================================================================

require_once __DIR__ . '/navigation.php';

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// ---- Page header -----------------------------------------------------------
// $actions is pre-rendered HTML (usually dfms_button_html calls).
function dfms_page_header($title, $subtitle = '', $actions = '', $eyebrow = '') {
    echo '<div class="dfms-page-header">';
    echo '<div>';
    if ($eyebrow !== '') {
        echo '<p class="eyebrow">' . e($eyebrow) . '</p>';
    }
    echo '<h1>' . e($title) . '</h1>';
    if ($subtitle !== '') {
        echo '<p>' . e($subtitle) . '</p>';
    }
    echo '</div>';
    if ($actions !== '') {
        echo '<div class="dfms-page-actions">' . $actions . '</div>';
    }
    echo '</div>';
}

// ---- Buttons ---------------------------------------------------------------
function dfms_button_html($label, $href = null, $variant = 'secondary', $options = []) {
    $classes = 'dfms-btn dfms-btn-' . $variant;
    if (!empty($options['size'])) {
        $classes .= ' dfms-btn-' . $options['size'];
    }
    if (!empty($options['class'])) {
        $classes .= ' ' . $options['class'];
    }

    $icon = !empty($options['icon']) ? dfms_icon($options['icon']) : '';
    $attrs = '';
    foreach ($options['attrs'] ?? [] as $key => $value) {
        $attrs .= ' ' . e($key) . '="' . e($value) . '"';
    }

    if ($href !== null) {
        return '<a href="' . e($href) . '" class="' . $classes . '"' . $attrs . '>' . $icon . e($label) . '</a>';
    }
    $type = $options['type'] ?? 'button';
    return '<button type="' . e($type) . '" class="' . $classes . '"' . $attrs . '>' . $icon . e($label) . '</button>';
}

// ---- Badge -----------------------------------------------------------------
function dfms_badge_html($label, $tone = 'neutral', $withDot = false) {
    $dot = $withDot ? '<span class="dfms-badge-dot"></span>' : '';
    return '<span class="dfms-badge dfms-badge-' . e($tone) . '">' . $dot . e($label) . '</span>';
}

// Maps a domain status to a badge tone in one place, so the same status
// never renders in two different colours across pages.
function dfms_status_tone($status) {
    switch ((string) $status) {
        case 'healthy':
        case 'active':
        case 'success':
        case 'lactating':
            return 'success';
        case 'at_risk':
        case 'pregnant':
        case 'warning':
            return 'warning';
        case 'sick':
        case 'critical':
        case 'expired':
            return 'danger';
        case 'treatment':
        case 'info':
            return 'info';
        default:
            return 'neutral';
    }
}

function dfms_status_badge_html($status) {
    $label = ucwords(str_replace('_', ' ', (string) $status));
    return dfms_badge_html($label, dfms_status_tone($status), true);
}

// ---- Alerts ----------------------------------------------------------------
function dfms_alert($message, $tone = 'info') {
    $iconMap = ['error' => 'bell', 'success' => 'sparkle', 'warning' => 'bell', 'info' => 'inbox'];
    $icon = dfms_icon($iconMap[$tone] ?? 'inbox');
    echo '<div class="dfms-alert dfms-alert-' . e($tone) . '" role="alert">' . $icon . '<div>' . e($message) . '</div></div>';
}

// Renders any queued flash messages. Called once by the shell so pages
// don't each repeat the same session plumbing.
function dfms_render_flashes() {
    $map = [
        'success_message' => 'success',
        'error_message'   => 'error',
    ];
    foreach ($map as $key => $tone) {
        if (!empty($_SESSION[$key])) {
            dfms_alert($_SESSION[$key], $tone);
            unset($_SESSION[$key]);
        }
    }
    foreach (['error' => 'error', 'success' => 'success'] as $flashKey => $tone) {
        if (function_exists('getFlash')) {
            $message = getFlash($flashKey);
            if ($message) {
                dfms_alert($message, $tone);
            }
        }
    }
}

// ---- Empty state -----------------------------------------------------------
function dfms_empty_state($title, $text = '', $actionHtml = '', $icon = 'inbox') {
    echo '<div class="dfms-empty-state">';
    echo '<div class="dfms-empty-icon">' . dfms_icon($icon) . '</div>';
    echo '<div class="dfms-empty-title">' . e($title) . '</div>';
    if ($text !== '') {
        echo '<p class="dfms-empty-text">' . e($text) . '</p>';
    }
    if ($actionHtml !== '') {
        echo $actionHtml;
    }
    echo '</div>';
}

// ---- Table -----------------------------------------------------------------
// $columns: list of ['label' => string, 'align' => 'right'|null].
// Rows are emitted by the caller between the open/close helpers, using
// dfms_cell() so the mobile stacked layout gets its data-label.
function dfms_table_open(array $columns, $stackOnMobile = true) {
    echo '<div class="dfms-table-wrap"><div class="dfms-table-scroll">';
    echo '<table class="dfms-table' . ($stackOnMobile ? ' dfms-table-stack' : '') . '">';
    echo '<thead><tr>';
    foreach ($columns as $column) {
        $class = !empty($column['align']) && $column['align'] === 'right' ? ' class="dfms-cell-actions"' : '';
        $label = $column['label'] === '' ? '<span class="dfms-visually-hidden">Actions</span>' : e($column['label']);
        echo '<th scope="col"' . $class . '>' . $label . '</th>';
    }
    echo '</tr></thead><tbody>';
}

function dfms_table_close() {
    echo '</tbody></table></div></div>';
}

// $content is trusted HTML (badges, links); pass plain text through e() first.
function dfms_cell($label, $content, $modifier = '') {
    $class = trim($modifier);
    echo '<td data-label="' . e($label) . '"' . ($class ? ' class="' . e($class) . '"' : '') . '>' . $content . '</td>';
}

// ---- Pagination ------------------------------------------------------------
function dfms_pagination($currentPage, $totalPages, $baseUrl, $totalItems = null) {
    if ($totalPages < 1) {
        return;
    }
    $currentPage = max(1, min((int) $currentPage, $totalPages));
    $sep = strpos($baseUrl, '?') === false ? '?' : '&';

    echo '<nav class="dfms-pagination" aria-label="Pagination">';
    echo '<div>';
    echo $totalItems !== null
        ? 'Showing page ' . e($currentPage) . ' of ' . e($totalPages) . ' · ' . e($totalItems) . ' total'
        : 'Page ' . e($currentPage) . ' of ' . e($totalPages);
    echo '</div>';

    echo '<div class="dfms-pagination-pages">';
    $prevDisabled = $currentPage <= 1 ? ' aria-disabled="true" tabindex="-1"' : '';
    echo '<a class="dfms-page-btn' . ($currentPage <= 1 ? ' is-disabled' : '') . '" href="'
        . e($baseUrl . $sep . 'page=' . max(1, $currentPage - 1)) . '"' . $prevDisabled . ' aria-label="Previous page">&lsaquo;</a>';

    // A compact window around the current page keeps this usable at any size.
    $start = max(1, $currentPage - 2);
    $end = min($totalPages, $start + 4);
    $start = max(1, $end - 4);
    for ($page = $start; $page <= $end; $page++) {
        $active = $page === $currentPage ? ' active' : '';
        $aria = $page === $currentPage ? ' aria-current="page"' : '';
        echo '<a class="dfms-page-btn' . $active . '" href="' . e($baseUrl . $sep . 'page=' . $page) . '"' . $aria . '>' . e($page) . '</a>';
    }

    $nextDisabled = $currentPage >= $totalPages ? ' aria-disabled="true" tabindex="-1"' : '';
    echo '<a class="dfms-page-btn' . ($currentPage >= $totalPages ? ' is-disabled' : '') . '" href="'
        . e($baseUrl . $sep . 'page=' . min($totalPages, $currentPage + 1)) . '"' . $nextDisabled . ' aria-label="Next page">&rsaquo;</a>';
    echo '</div></nav>';
}

// ---- Metric ----------------------------------------------------------------
function dfms_metric_html($label, $value, $unit = '', $footHtml = '') {
    $html = '<div class="dfms-metric">';
    $html .= '<div class="dfms-metric-label">' . e($label) . '</div>';
    $html .= '<div class="dfms-metric-value">' . e($value);
    if ($unit !== '') {
        $html .= '<span class="dfms-metric-unit">' . e($unit) . '</span>';
    }
    $html .= '</div>';
    if ($footHtml !== '') {
        $html .= '<div class="dfms-metric-foot">' . $footHtml . '</div>';
    }
    return $html . '</div>';
}

// ---- Breadcrumb ------------------------------------------------------------
// $trail: list of ['label' => string, 'url' => string|null].
function dfms_breadcrumb(array $trail) {
    if (!$trail) {
        return;
    }
    echo '<nav class="dfms-breadcrumb" aria-label="Breadcrumb">';
    $last = count($trail) - 1;
    foreach ($trail as $index => $crumb) {
        if ($index > 0) {
            echo '<span class="dfms-breadcrumb-sep" aria-hidden="true">/</span>';
        }
        if ($index === $last || empty($crumb['url'])) {
            echo '<span class="dfms-breadcrumb-current">' . e($crumb['label']) . '</span>';
        } else {
            echo '<a href="' . e($crumb['url']) . '">' . e($crumb['label']) . '</a>';
        }
    }
    echo '</nav>';
}
