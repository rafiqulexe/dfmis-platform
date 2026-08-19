<?php
// ============================================================================
// TENANT CONTEXT — the single source of truth for "who is asking, and what
// are they allowed to see". Every tenant-scoped query in the application
// derives its scope from here. Nothing outside this file should read
// $_SESSION to make an access decision.
//
// Hierarchy: Platform -> Organization -> Farm -> Barn -> Cow
// Business data carries farm_id only; organization is resolved through the
// farm, so there is exactly one tenant key per row and it cannot drift.
//
// SECURITY MODEL
// The client controls the session cookie, not its contents — but a session
// value could still be stale (membership revoked mid-session). Allowed farms
// are therefore resolved from the database per request, never trusted from
// the session, and cached only for the lifetime of that request.
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

// The farm the user is currently working in. Falls back to their first
// allowed farm. A session-stored farm is re-validated against live
// membership on every request, so revoking access takes effect immediately.
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

// Switch the active farm. Rejects any farm the user is not a member of, so
// a crafted request cannot move a user into another tenant.
function switchFarm($farmId) {
    $farmId = (int) $farmId;
    if (!in_array($farmId, allowedFarmIds(), true)) {
        http_response_code(403);
        exit('You do not have access to that farm.');
    }
    $_SESSION['farm_id'] = $farmId;
    return true;
}

// Hard gate: abort unless the given farm is one the user may access. Use
// whenever a farm id arrives from anywhere other than the tenant context.
function assertFarmAccess($farmId) {
    if (!in_array((int) $farmId, allowedFarmIds(), true)) {
        http_response_code(404);
        exit('Not found.');
    }
}
