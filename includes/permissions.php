<?php
// Centralized role-based permission matrix. One place to check "can this
// role do X" instead of scattering ad-hoc role checks across modules.
// auth.php (requireRole, currentRole) must already be loaded.

const DFMS_PERMISSIONS = [
    'cows.create'     => ['admin', 'staff'],
    'cows.edit'       => ['admin', 'staff'],
    'cows.delete'     => ['admin'],

    'health.create'   => ['admin', 'veterinarian'],

    'feeding.create'  => ['admin', 'staff'],

    'milk.create'     => ['admin', 'staff'],
    'milk.edit'       => ['admin', 'staff'],

    'inventory.create' => ['admin', 'staff'],
    'inventory.update' => ['admin', 'staff'],

    'alerts.create'   => ['admin', 'veterinarian', 'staff'],
    'alerts.edit'     => ['admin', 'veterinarian'],
    'alerts.delete'   => ['admin'],
];

// Call at the top of a module/action, after requireLogin(). Exits with 403
// if the current role lacks the permission.
function requirePermission($permission) {
    $allowed = DFMS_PERMISSIONS[$permission] ?? [];
    requireRole($allowed);
}

// Non-exiting check, for conditionally showing UI (e.g. hiding an "Add"
// button). Never a substitute for requirePermission() on the action itself.
function hasPermission($permission) {
    $allowed = DFMS_PERMISSIONS[$permission] ?? [];
    return in_array(currentRole(), $allowed, true);
}
