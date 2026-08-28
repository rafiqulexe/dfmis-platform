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

    // Phase 4 — Veterinary & Animal Health.
    //
    // Only TWO new permissions, because most health actions are already the
    // same decision as an existing one:
    //
    //   reading anything clinical      -> health.view (unchanged)
    //   opening a case, recording a
    //   diagnosis, a treatment, a
    //   consultation, a lab test or a
    //   quarantine                     -> health.create (unchanged)
    //
    // Creating health.manage as a separate permission would have produced a
    // role list identical to health.create's — two names for one decision, and
    // two places to get the matrix wrong.
    //
    // These two exist because they ARE genuinely different decisions:
    //   observe   Taking a temperature or noting that an animal is off its feed
    //             is routine stockmanship, not a clinical judgement. Staff do
    //             it every day and locking them out means it goes unrecorded —
    //             which is worse than recording it. Deliberately WIDER than
    //             health.create.
    //   medicate  Administering a medicine consumes controlled stock and
    //             creates a food-safety withdrawal restriction. It is the
    //             narrowest health action in the system and is NOT granted to
    //             staff, accountants or viewers.
    'health.observe'   => ['farm_manager', 'veterinarian', 'staff'],
    'health.medicate'  => ['farm_manager', 'veterinarian'],

    'feeding.view'     => ['farm_manager', 'veterinarian', 'staff', 'viewer'],
    'feeding.create'   => ['farm_manager', 'staff'],

    'milk.view'        => ['farm_manager', 'veterinarian', 'staff', 'accountant', 'viewer'],
    'milk.create'      => ['farm_manager', 'staff'],
    'milk.edit'        => ['farm_manager', 'staff'],

    // Phase 6 — Production & Feeding.
    //
    // Exactly ONE new permission was added for this whole phase, and this is
    // it. Everything else reuses what already existed:
    //
    //   recording / correcting a per-animal yield  -> milk.create / milk.edit
    //   viewing production and analytics           -> milk.view
    //   rations and feed consumption               -> feeding.create / .view
    //
    // Feed consumption deducts stock, which looks like it should also demand
    // inventory.update — but that permission's role list is IDENTICAL to
    // feeding.create's (farm_manager, staff), so requiring both would grant
    // nothing extra while implying a distinction the matrix does not make.
    //
    // milk.delete exists because DELETING is genuinely a different decision
    // from correcting, and this codebase already says so everywhere else:
    // cows.delete, alerts.delete and files.delete are each farm-manager-only
    // and separately named. Correcting a mis-keyed figure is routine
    // stockmanship; permanently erasing the record that an animal produced
    // milk on a date is not, and staff should not be able to do it.
    'milk.delete'      => ['farm_manager'],

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

    // Phase 2 — international configuration.
    // Anyone who works on the farm may SEE which timezone, currency and units
    // are in force; misreading those is a real operational hazard. Changing
    // them is a farm manager decision, because a unit or timezone change
    // alters how every existing record is presented to everyone.
    // Phase 3 — Animal Digital Twin.
    //
    // Only TWO new permissions. Viewing, creating, editing and archiving an
    // animal are already covered by cows.view/create/edit/delete, and files by
    // files.view/upload/delete — creating animal.* duplicates of those would be
    // permission bloat with two places to get the matrix wrong.
    //
    // These two exist because they are genuinely different decisions:
    //   lifecycle — declaring an animal sold, dead or retired is a herd
    //               management call, and a vet is the right person to record a
    //               death. It also covers ownership, which is the same kind of
    //               "what is this animal's standing" judgement.
    //   transfer  — moves an animal's farm_id, its TENANT KEY. Restricted to
    //               farm managers, who are the only role that can be trusted
    //               with a cross-farm data movement.
    'animal.lifecycle' => ['farm_manager', 'veterinarian'],
    'animal.transfer'  => ['farm_manager'],

    // Phase 5D — AI Veterinarian Assistant.
    //
    // TWO new permissions, and only because no existing one expresses either
    // decision. health.view is far too wide for the first (it includes viewer
    // and grants read of the whole clinical record), and there is no existing
    // permission at all for curating what the assistant is allowed to quote.
    //
    //   ai.consult   Asking the assistant a question. It SPENDS the
    //                organization's metered AI allowance and returns clinical
    //                decision support, so it is granted to the roles that
    //                actually work with animals — not to viewer or accountant,
    //                who have no clinical decision to support and would consume
    //                a shared budget.
    //   ai.knowledge Curating the knowledge base. Deliberately NARROWER than
    //                consulting: whatever is written here is quoted back to
    //                everyone who asks, so editing it shapes the advice the
    //                whole farm receives. That is a farm-manager decision.
    'ai.consult'       => ['farm_manager', 'veterinarian', 'staff'],
    'ai.knowledge'     => ['farm_manager'],

    'settings.view'    => ['farm_manager', 'veterinarian', 'staff', 'accountant', 'viewer'],
    'settings.farm'    => ['farm_manager'],
    // Feature rollout at FARM scope. Organization scope is governed by
    // DFMS_ORG_PERMISSIONS['org.manage'] instead — see modules/settings/features.php.
    'settings.features' => ['farm_manager'],
    'notifications.manage' => ['farm_manager', 'veterinarian', 'staff', 'accountant', 'viewer'],
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
