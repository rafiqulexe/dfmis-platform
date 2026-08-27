<?php
// ============================================================================
// GET /api/v1/me
//
// The caller's own identity and resolved tenant context. Authenticated; no
// specific permission, because every authenticated user may read their own
// context.
//
// SCOPE: returns only what the caller already has, derived entirely from the
// tenant context. No id is read from the request, so there is no parameter
// that could point this at another user, farm or organization.
//
// EXCLUDES: password hash, session id, email, platform-admin flag, plan limits
// and any organization the caller is not a member of.
// ============================================================================

if (!defined('DFMS_API_DISPATCHED')) {
    http_response_code(404);
    exit;
}

$farm = dfms_farm_row();
$organization = dfms_organization_row();

// allowedFarmIds() is resolved live from farm_users every request, so this
// list can never include a farm whose membership was revoked.
$accessibleFarms = count(allowedFarmIds());

http_success([
    'user' => [
        'id'       => currentUserId(),
        'username' => $_SESSION['username'] ?? null,
    ],
    'farm' => [
        'id'   => currentFarmId(),
        'name' => $farm['name'] ?? null,
        'type' => $farm['farm_type'] ?? null,
        'role' => currentFarmRole(),
    ],
    'organization' => [
        'id'   => currentOrganizationId(),
        'name' => $organization['name'] ?? null,
        'role' => currentOrganizationRole(),
    ],
    'accessible_farm_count' => $accessibleFarms,
]);
