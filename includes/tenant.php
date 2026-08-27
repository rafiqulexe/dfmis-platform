<?php
// ============================================================================
// TENANT CONTEXT — the single source of truth for "who is asking, and what
// are they allowed to see". Every tenant-scoped query in the application
// derives its scope from here. Nothing outside this file should read
// $_SESSION to make an access decision.
//
// Hierarchy: Platform -> Organization -> Farm -> Location -> Entity
// Business data carries farm_id only; organization is resolved through the
// farm, so there is exactly one tenant key per row and it cannot drift.
//
// SECURITY MODEL
// The client controls the session cookie, not its contents — but a session
// value could still be stale (membership revoked mid-session). Allowed farms
// are therefore resolved from the database per request, never trusted from
// the session, and cached only for the lifetime of that request.
//
// PLATFORM ADMIN — resolved contradiction (baseline audit T-6)
// The previous documentation claimed platform admins "operate above the
// tenant layer" and can "look across tenants". The code never implemented
// that, and this file now makes the real rule explicit:
//
//   A platform admin bypasses ROLE checks on farms they belong to.
//   A platform admin does NOT bypass TENANT boundaries.
//
// Cross-tenant data access is deliberately not implemented. When a platform
// admin console is built (Phase 2) it must go through a separate, audited
// code path — not by widening the ordinary request-scoped helpers below,
// which every module depends on being airtight.
// ============================================================================

require_once __DIR__ . '/../config/database.php';

function currentUserId() {
    return $_SESSION['admin_id'] ?? null;
}

function isPlatformAdmin() {
    return !empty($_SESSION['is_platform_admin']);
}

// Farms this user may access, resolved live from farm_users. Cached per
// request only. Returns a list of int farm ids.
function allowedFarmIds() {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $userId = currentUserId();
    if (!$userId) {
        return $cache = [];
    }
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT farm_id FROM farm_users WHERE admin_id = ?");
    $stmt->execute([$userId]);
    return $cache = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

// Organizations this user may access, resolved live from BOTH membership
// tables. A user can reach an organization either by being an explicit
// organization member or by being a member of one of its farms.
function allowedOrganizationIds() {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $userId = currentUserId();
    if (!$userId) {
        return $cache = [];
    }
    $conn = getDBConnection();
    $stmt = $conn->prepare(
        "SELECT organization_id FROM organization_users WHERE admin_id = ?
         UNION
         SELECT f.organization_id FROM farms f
           JOIN farm_users fu ON fu.farm_id = f.farm_id
          WHERE fu.admin_id = ?"
    );
    $stmt->execute([$userId, $userId]);
    return $cache = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

// The farm the user is currently working in. Falls back to their first
// allowed farm. A session-stored farm is re-validated against live
// membership on every request, so revoking access takes effect immediately.
// Returns null when the user belongs to no farm — callers MUST handle that
// (see requireTenantContext()).
function currentFarmId() {
    $allowed = allowedFarmIds();
    if (empty($allowed)) {
        return null;
    }
    $sessionFarm = isset($_SESSION['farm_id']) ? (int) $_SESSION['farm_id'] : null;
    if ($sessionFarm !== null && in_array($sessionFarm, $allowed, true)) {
        return $sessionFarm;
    }
    $_SESSION['farm_id'] = $allowed[0];
    return $allowed[0];
}

// Organization owning the active farm — always derived, never stored on
// business rows.
function currentOrganizationId() {
    static $cache = [];
    $farmId = currentFarmId();
    if ($farmId === null) {
        return null;
    }
    if (isset($cache[$farmId])) {
        return $cache[$farmId];
    }
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT organization_id FROM farms WHERE farm_id = ?");
    $stmt->execute([$farmId]);
    $orgId = $stmt->fetchColumn();
    return $cache[$farmId] = $orgId !== false ? (int) $orgId : null;
}

// The user's role *on the active farm*. This is the authoritative role for
// permission checks — not any global role stored on the user row.
function currentFarmRole() {
    static $cache = [];
    $userId = currentUserId();
    $farmId = currentFarmId();
    if (!$userId || $farmId === null) {
        return null;
    }
    $key = "$userId:$farmId";
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT role FROM farm_users WHERE admin_id = ? AND farm_id = ?");
    $stmt->execute([$userId, $farmId]);
    $role = $stmt->fetchColumn();
    return $cache[$key] = $role !== false ? $role : null;
}

// The user's role in the organization owning the active farm, or null if
// they are only a farm-level member. Used by organization-scoped actions.
function currentOrganizationRole() {
    static $cache = [];
    $userId = currentUserId();
    $orgId = currentOrganizationId();
    if (!$userId || $orgId === null) {
        return null;
    }
    $key = "$userId:$orgId";
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT role FROM organization_users WHERE admin_id = ? AND organization_id = ?");
    $stmt->execute([$userId, $orgId]);
    $role = $stmt->fetchColumn();
    return $cache[$key] = $role !== false ? $role : null;
}

// Switch the active farm. Rejects any farm the user is not a member of, so
// a crafted request cannot move a user into another tenant.
function switchFarm($farmId) {
    $farmId = (int) $farmId;
    if (!in_array($farmId, allowedFarmIds(), true)) {
        if (function_exists('dfms_abort')) {
            dfms_abort(403, "switchFarm denied: user " . (currentUserId() ?? '?') . " -> farm $farmId");
        }
        http_response_code(403);
        exit('You do not have access to that farm.');
    }
    $_SESSION['farm_id'] = $farmId;
    return true;
}

// Hard gate: abort unless the given farm is one the user may access. Use
// whenever a farm id arrives from anywhere other than the tenant context.
// 404 rather than 403 — a 403 would confirm the farm exists.
function assertFarmAccess($farmId) {
    if (!in_array((int) $farmId, allowedFarmIds(), true)) {
        if (function_exists('dfms_abort')) {
            dfms_abort(404, "assertFarmAccess denied: user " . (currentUserId() ?? '?') . " -> farm $farmId");
        }
        http_response_code(404);
        exit('Not found.');
    }
}

// Hard gate for an organization id arriving from outside the tenant context.
function assertOrganizationAccess($organizationId) {
    if (!in_array((int) $organizationId, allowedOrganizationIds(), true)) {
        if (function_exists('dfms_abort')) {
            dfms_abort(404, "assertOrganizationAccess denied: user " . (currentUserId() ?? '?') . " -> org $organizationId");
        }
        http_response_code(404);
        exit('Not found.');
    }
}

// Hard gate for MISSING tenant context.
//
// Baseline audit C-1: a logged-in user with no farm_users row produced a
// null farm id, which was then passed to a non-nullable int parameter and
// crashed the dashboard with a TypeError that leaked the server path.
//
// Any page that reads tenant data must call this after requireLogin(). It
// renders an explicit "no farm assigned" state rather than failing.
function requireTenantContext() {
    if (currentFarmId() !== null) {
        return true;
    }
    if (!headers_sent()) {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
    }
    $logoutUrl = htmlspecialchars(BASE_URL . 'logout.php', ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>No farm assigned · DFM</title>'
       . '<style>body{font-family:system-ui,sans-serif;background:#f2f5f2;color:#141c18;'
       . 'display:grid;place-items:center;min-height:100vh;margin:0;padding:2rem}'
       . 'div{max-width:34rem;text-align:center}h1{font-size:1.3rem;margin:0 0 .6rem}'
       . 'p{color:#59685f;margin:0 0 1rem;line-height:1.6}a{color:#1f6f4a}</style>'
       . '</head><body><div>'
       . '<h1>No farm assigned yet</h1>'
       . '<p>Your account is active, but it has not been added to a farm. '
       . 'An administrator needs to grant you access before you can see any data.</p>'
       . '<p><a href="' . $logoutUrl . '">Sign out</a></p>'
       . '</div></body></html>';
    exit;
}
