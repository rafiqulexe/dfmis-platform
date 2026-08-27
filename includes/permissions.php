<?php
// ============================================================================
// RBAC / AUTHORIZATION — one gate, checked server-side, for every protected
// action in the application.
//
// Permissions are checked against the user's role ON THE ACTIVE FARM
// (farm_users.role), not a global role on the user row. The same person can
// be a veterinarian at one farm and a viewer at another.
//
// PLATFORM ADMIN (see tenant.php for the full rule)
// Bypasses ROLE checks. Does NOT bypass TENANT boundaries — a platform admin
// still only sees farms they are a member of.
//
// THE FULL GATE
// requireAuth() performs every check the baseline audit asked for, in the
// order that fails cheapest first:
//
//   1. authenticated user          -> requireLogin()
//   2. tenant context present      -> requireTenantContext()
//   3. farm membership             -> implied by currentFarmId() being non-null
//   4. organization membership     -> the active farm's org is in allowedOrganizationIds()
//   5. role + permission           -> DFMS_PERMISSIONS matrix
//   6. resource ownership/scope    -> caller passes the row through
//                                     tenantFindOr404() (repository.php)
//
// Step 6 stays with the caller deliberately: only the caller knows which
// table and which id, and repository.php already makes the scoped lookup the
// shortest thing to write.
// ============================================================================

require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/auth.php';

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
    // Marking an alert read is a low-stakes acknowledgement, not an edit —
    // anyone who can see the alert may clear it from their own farm's queue.
    'alerts.acknowledge' => ['farm_manager', 'veterinarian', 'staff', 'accountant'],

    'audit.view'       => ['farm_manager'],

    'files.view'       => ['farm_manager', 'veterinarian', 'staff', 'accountant', 'viewer'],
    'files.upload'     => ['farm_manager', 'veterinarian', 'staff'],
    'files.delete'     => ['farm_manager'],
];

// Organization-level roles, used by organization-scoped actions. Farm-level
// permissions above are unaffected by these.
const DFMS_ORG_PERMISSIONS = [
    'org.manage'        => ['owner', 'admin'],
    'org.members.manage' => ['owner', 'admin'],
    'org.billing'       => ['owner'],
    'org.view'          => ['owner', 'admin', 'member'],
];

// Every permission that exists, for tests and for the audit reader.
function dfms_all_permissions() {
    return array_keys(DFMS_PERMISSIONS);
}

// ---------------------------------------------------------------------------
// Non-exiting checks, for conditionally rendering UI. Never a substitute for
// requireAuth()/requirePermission() on the action itself.
// ---------------------------------------------------------------------------

function hasPermission($permission) {
    // An unknown permission string is a programming error and must never be
    // granted by default.
    if (!array_key_exists($permission, DFMS_PERMISSIONS)) {
        error_log("hasPermission(): unknown permission '$permission' — denying.");
        return false;
    }
    if (!isLoggedIn()) {
        return false;
    }
    // Tenant boundary first: no active farm means no farm-scoped permission,
    // platform admin or not.
    if (currentFarmId() === null) {
        return false;
    }
    if (isPlatformAdmin()) {
        return true;
    }
    $allowed = DFMS_PERMISSIONS[$permission];
    return in_array(currentFarmRole(), $allowed, true);
}

function hasOrganizationPermission($permission) {
    if (!array_key_exists($permission, DFMS_ORG_PERMISSIONS)) {
        error_log("hasOrganizationPermission(): unknown permission '$permission' — denying.");
        return false;
    }
    if (!isLoggedIn() || currentOrganizationId() === null) {
        return false;
    }
    if (isPlatformAdmin()) {
        return true;
    }
    return in_array(currentOrganizationRole(), DFMS_ORG_PERMISSIONS[$permission], true);
}

// ---------------------------------------------------------------------------
// Hard gates.
// ---------------------------------------------------------------------------

// The single entry point every protected page should call. Replaces the
// three-line requireLogin() / requireTenantContext() / requirePermission()
// preamble and guarantees they happen in the right order.
function requireAuth($permission = null) {
    requireLogin();
    requireTenantContext();

    // Organization membership. currentOrganizationId() is derived from the
    // active farm, which is already membership-validated — this catches the
    // case where a farm's organization was changed underneath the user.
    $orgId = currentOrganizationId();
    if ($orgId === null || !in_array($orgId, allowedOrganizationIds(), true)) {
        auditSecurityEvent('authz.org_membership_denied', ['organization_id' => $orgId]);
        if (function_exists('dfms_abort')) {
            dfms_abort(403, 'Organization membership check failed for user ' . (currentUserId() ?? '?'));
        }
        http_response_code(403);
        exit('You do not have permission to perform this action.');
    }

    if ($permission !== null) {
        requirePermission($permission);
    }
    return true;
}

// Hard gate on a single permission. Kept as its own function because several
// pages check a second permission partway through.
function requirePermission($permission) {
    if (!hasPermission($permission)) {
        auditSecurityEvent('authz.permission_denied', [
            'permission' => $permission,
            'role'       => currentFarmRole(),
        ]);
        if (function_exists('dfms_abort')) {
            dfms_abort(403, "Permission '$permission' denied for user " . (currentUserId() ?? '?')
                . ' role ' . (currentFarmRole() ?? 'none'));
        }
        http_response_code(403);
        exit('You do not have permission to perform this action.');
    }
}

function requireOrganizationPermission($permission) {
    if (!hasOrganizationPermission($permission)) {
        auditSecurityEvent('authz.org_permission_denied', ['permission' => $permission]);
        if (function_exists('dfms_abort')) {
            dfms_abort(403, "Org permission '$permission' denied for user " . (currentUserId() ?? '?'));
        }
        http_response_code(403);
        exit('You do not have permission to perform this action.');
    }
}

// Records a denied authorization attempt. Defined here (rather than pulled
// from audit.php) so permissions.php has no hard dependency on the audit
// layer; audit.php overrides nothing, it simply provides the writer.
function auditSecurityEvent($action, array $metadata = []) {
    if (function_exists('auditLog')) {
        auditLog($action, 'security', null, null, $metadata, ['force' => true]);
    }
}
