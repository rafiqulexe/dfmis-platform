<?php
// ============================================================================
// GET /api/v1/settings
//
// The configuration in force for the active farm, with the source of each
// value. Requires `settings.view`.
//
// Proves the architecture end to end: an API endpoint reusing the Phase 2A
// domain helpers verbatim, with no business logic of its own. If the
// inheritance rules change, this endpoint changes with them for free.
// ============================================================================

if (!defined('DFMS_API_DISPATCHED')) {
    http_response_code(404);
    exit;
}

$resolved = [];
foreach (['country_code', 'timezone', 'locale', 'currency', 'measurement_system'] as $key) {
    // Same resolver the settings UI uses — farm -> organization -> platform.
    $entry = dfms_resolve_setting($key);
    $resolved[$key] = [
        'value'  => $entry['value'],
        'source' => $entry['source'],
    ];
}

http_success([
    'settings' => $resolved,
    'today'    => farmToday(),
], 200, [
    'resolution_order' => ['farm', 'organization', 'platform'],
]);
