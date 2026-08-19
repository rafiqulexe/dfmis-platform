<?php
// ============================================================================
// RBAC — permissions are checked against the user's role ON THE ACTIVE FARM
// (farm_users.role), not a global role on the user row. The same person can
// be a veterinarian at one farm and a viewer at another.
//
// Platform admins bypass these checks; they operate above the tenant layer.
// ============================================================================

require_once __DIR__ . '/tenant.php';

const DFMS_PERMISSIONS = [
    'cows.view'        => ['farm_manager', 'veterinarian', 'staff', 'accountant', 'viewer'],
    'cows.create'      => ['farm_manager', 'staff'],
    'cows.edit'        => ['farm_manager', 'staff'],
    'cows.delete'      => ['farm_manager'],

    'health.view'      => ['farm_manager', 'veterinarian', 'staff', 'viewer'],
    'health.create'    => ['farm_manager', 'veterinarian'],

    'feeding.view'     => ['farm_manager', 'veterinarian', 'staff', 'viewer'],
    'feeding.create'   => ['farm_manager', 'staff'],

    'milk.view'        => ['farm_manager', 'veterinarian', 'staff', 'accountant', 'viewer'],
    'milk.create'      => ['farm_manager', 'staff'],
    'milk.edit'        => ['farm_manager', 'staff'],

    'inventory.view'   => ['farm_manager', 'veterinarian', 'staff', 'accountant', 'viewer'],
    'inventory.create' => ['farm_manager', 'staff'],
    'inventory.update' => ['farm_manager', 'staff'],

    'alerts.view'      => ['farm_manager', 'veterinarian', 'staff', 'accountant', 'viewer'],
    'alerts.create'    => ['farm_manager', 'veterinarian', 'staff'],
    'alerts.edit'      => ['farm_manager', 'veterinarian'],
    'alerts.delete'    => ['farm_manager'],

    'audit.view'       => ['farm_manager'],
];

// Non-exiting check, for conditionally rendering UI. Never a substitute for
// requirePermission() on the action itself.
function hasPermission($permission) {
    if (isPlatformAdmin()) {
        return true;
    }
    $allowed = DFMS_PERMISSIONS[$permission] ?? [];
    return in_array(currentFarmRole(), $allowed, true);
}

// Hard gate. Call at the top of every action, after requireLogin().
function requirePermission($permission) {
    if (!hasPermission($permission)) {
        http_response_code(403);
        exit('You do not have permission to perform this action.');
    }
}
