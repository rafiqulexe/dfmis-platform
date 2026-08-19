<?php
// ============================================================================
// TENANT-SCOPED DATA ACCESS
//
// Every tenant-scoped read and every ID lookup goes through here. The point
// is that the *safe* call is also the *shortest* one, so new modules do not
// have to remember to append "AND farm_id = ?" — forgetting it is how tenant
// leaks happen, and it is exactly the bug this layer removes.
//
// Rule for all future modules:
//   - listing rows of a tenant table      -> tenantQuery()
//   - loading one row by an id from a URL -> tenantFind() / tenantFindOr404()
//   - updating/deleting one row by id     -> tenantExec() with scoped SQL
// Never interpolate a farm id from user input; it always comes from the
// tenant context.
// ============================================================================

require_once __DIR__ . '/tenant.php';

// Tables whose rows carry a farm_id. Anything not listed here is either
// system-wide (farms, organizations, admin_users) or reached only through a
// parent row that is itself scoped.
const DFMS_TENANT_TABLES = [
    'cows', 'health_events', 'feeding_schedules', 'feed_consumption',
    'milk_production', 'inventory_items', 'inventory_levels',
    'temperature_readings', 'alerts', 'audit_log',
];

// Run a SELECT with the active farm automatically applied.
// $sql must contain the literal token {scope}, e.g.
//   tenantQuery($conn, "SELECT * FROM cows WHERE {scope} ORDER BY unique_id")
function tenantQuery(PDO $conn, $sql, array $params = [], $alias = '') {
    $farmId = currentFarmId();
    if ($farmId === null) {
        return [];
    }
    $column = ($alias !== '' ? $alias . '.' : '') . 'farm_id';
    $sql = str_replace('{scope}', "$column = ?", $sql);
    $stmt = $conn->prepare($sql);
    $stmt->execute(array_merge([$farmId], $params));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Load exactly one row by primary key, scoped to the active farm.
// Returns null when the row does not exist OR belongs to another tenant —
// deliberately indistinguishable, so this cannot be used to probe for the
// existence of other tenants' records.
function tenantFind(PDO $conn, $table, $primaryKey, $id, $extraWhere = '') {
    if (!in_array($table, DFMS_TENANT_TABLES, true)) {
        throw new InvalidArgumentException("tenantFind() called on non-tenant table: $table");
    }
    $farmId = currentFarmId();
    if ($farmId === null || !is_numeric($id)) {
        return null;
    }
    $sql = "SELECT * FROM $table WHERE $primaryKey = ? AND farm_id = ?";
    if ($extraWhere !== '') {
        $sql .= " AND $extraWhere";
    }
    $stmt = $conn->prepare($sql);
    $stmt->execute([(int) $id, $farmId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row !== false ? $row : null;
}

// As tenantFind(), but ends the request with a 404 when the row is missing
// or out of tenant. Use for any page driven by an id in the URL.
function tenantFindOr404(PDO $conn, $table, $primaryKey, $id, $extraWhere = '') {
    $row = tenantFind($conn, $table, $primaryKey, $id, $extraWhere);
    if ($row === null) {
        http_response_code(404);
        exit('Not found.');
    }
    return $row;
}

// Run an INSERT/UPDATE/DELETE with the active farm applied to {scope}.
function tenantExec(PDO $conn, $sql, array $params = []) {
    $farmId = currentFarmId();
    if ($farmId === null) {
        return 0;
    }
    $sql = str_replace('{scope}', 'farm_id = ?', $sql);
    $stmt = $conn->prepare($sql);
    $stmt->execute(array_merge($params, [$farmId]));
    return $stmt->rowCount();
}
