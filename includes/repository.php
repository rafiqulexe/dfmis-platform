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
//   - inserting a tenant row              -> tenantInsert()
//   - updating/deleting one row by id     -> tenantExec() with scoped SQL
//   - multi-step business operations      -> tenantTransaction()
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
    'temperature_readings', 'alerts', 'audit_log', 'locations',
    // Phase 2B — uploaded files carry a farm_id and are read back through a
    // tenant-scoped handler.
    'stored_files',
    // Phase 3 — Animal Digital Twin satellites. Each carries farm_id and must
    // be reachable through the repository helpers; an unregistered table is
    // refused by tenantFind()/tenantInsert() by design.
    'animal_identifiers', 'animal_lifecycle_events', 'animal_transfers', 'animal_ownership',
    // Phase 4 — Veterinary & Animal Health. Every one carries farm_id and must
    // be registered here: tenantFind()/tenantInsert() refuse an unregistered
    // table by design, so forgetting a row here fails loudly at the first write
    // rather than quietly producing an unscoped one.
    'health_cases', 'health_status_events', 'health_observations', 'health_symptoms',
    'health_diagnoses', 'veterinary_consultations', 'health_treatments',
    'medication_administrations', 'vaccination_records', 'health_quarantines',
    'withdrawal_periods', 'laboratory_tests',
    // inventory_items was reachable only through joins before Phase 4;
    // medication administration resolves an item id arriving from a form, which
    // needs the scoped tenantFind() path.
    'inventory_items',
];

// Identifiers we will interpolate into SQL must be provably safe, because no
// placeholder exists for a table or column name. Anything that is not a bare
// identifier is a programming error and aborts rather than being escaped.
function dfms_assert_identifier($value, $what = 'identifier') {
    if (!is_string($value) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value)) {
        throw new InvalidArgumentException("Unsafe $what: " . var_export($value, true));
    }
    return $value;
}

// Run a SELECT with the active farm automatically applied.
// $sql must contain the literal token {scope}, e.g.
//   tenantQuery($conn, "SELECT * FROM cows WHERE {scope} ORDER BY unique_id")
function tenantQuery(PDO $conn, $sql, array $params = [], $alias = '') {
    $farmId = currentFarmId();
    if ($farmId === null) {
        return [];
    }
    if (strpos($sql, '{scope}') === false) {
        throw new InvalidArgumentException('tenantQuery() requires a {scope} token — use a plain prepare() for non-tenant tables.');
    }
    if ($alias !== '') {
        dfms_assert_identifier($alias, 'table alias');
    }
    $column = ($alias !== '' ? $alias . '.' : '') . 'farm_id';
    $sql = str_replace('{scope}', "$column = ?", $sql);
    $stmt = $conn->prepare($sql);
    $stmt->execute(array_merge([$farmId], $params));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Single-row variant of tenantQuery(). Returns null rather than an empty array.
function tenantQueryOne(PDO $conn, $sql, array $params = [], $alias = '') {
    $rows = tenantQuery($conn, $sql, $params, $alias);
    return $rows ? $rows[0] : null;
}

// Scalar variant, for COUNT/SUM/AVG. Returns $default when there is no
// tenant context so callers never receive null unexpectedly.
function tenantScalar(PDO $conn, $sql, array $params = [], $default = 0, $alias = '') {
    $farmId = currentFarmId();
    if ($farmId === null) {
        return $default;
    }
    if ($alias !== '') {
        dfms_assert_identifier($alias, 'table alias');
    }
    $column = ($alias !== '' ? $alias . '.' : '') . 'farm_id';
    $sql = str_replace('{scope}', "$column = ?", $sql);
    $stmt = $conn->prepare($sql);
    $stmt->execute(array_merge([$farmId], $params));
    $value = $stmt->fetchColumn();
    return $value === false || $value === null ? $default : $value;
}

// Load exactly one row by primary key, scoped to the active farm.
// Returns null when the row does not exist OR belongs to another tenant —
// deliberately indistinguishable, so this cannot be used to probe for the
// existence of other tenants' records.
function tenantFind(PDO $conn, $table, $primaryKey, $id, $extraWhere = '') {
    if (!in_array($table, DFMS_TENANT_TABLES, true)) {
        throw new InvalidArgumentException("tenantFind() called on non-tenant table: $table");
    }
    dfms_assert_identifier($table, 'table');
    dfms_assert_identifier($primaryKey, 'primary key');

    $farmId = currentFarmId();
    // Reject anything that is not a positive integer id BEFORE it reaches the
    // query. "1 OR 1=1", "1.5", "-3" and "" all stop here.
    if ($farmId === null || !is_scalar($id) || !preg_match('/^\d+$/', (string) $id) || (int) $id < 1) {
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
        if (function_exists('auditSecurityEvent')) {
            auditSecurityEvent('tenant.resource_denied', [
                'table' => $table,
                'requested_id' => is_scalar($id) ? (string) $id : '[non-scalar]',
            ]);
        }
        if (function_exists('dfms_abort')) {
            dfms_abort(404, "tenantFindOr404 miss: $table#" . (is_scalar($id) ? $id : '?')
                . ' for user ' . (currentUserId() ?? '?'));
        }
        http_response_code(404);
        exit('Not found.');
    }
    return $row;
}

// Insert a row into a tenant table with farm_id applied automatically.
// Callers cannot supply farm_id — the tenant key is never client-controlled.
// Returns the new primary key.
function tenantInsert(PDO $conn, $table, array $data) {
    if (!in_array($table, DFMS_TENANT_TABLES, true)) {
        throw new InvalidArgumentException("tenantInsert() called on non-tenant table: $table");
    }
    dfms_assert_identifier($table, 'table');

    $farmId = currentFarmId();
    if ($farmId === null) {
        throw new RuntimeException('tenantInsert() called with no tenant context.');
    }

    // A caller-supplied farm_id is always ignored, never honoured.
    unset($data['farm_id']);

    $columns = array_keys($data);
    foreach ($columns as $column) {
        dfms_assert_identifier($column, 'column');
    }
    $columns[] = 'farm_id';
    $values = array_values($data);
    $values[] = $farmId;

    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $columnList = implode(', ', $columns);

    $stmt = $conn->prepare("INSERT INTO $table ($columnList) VALUES ($placeholders)");
    $stmt->execute($values);
    return (int) $conn->lastInsertId();
}

// Run an INSERT/UPDATE/DELETE with the active farm applied to {scope}.
function tenantExec(PDO $conn, $sql, array $params = []) {
    $farmId = currentFarmId();
    if ($farmId === null) {
        return 0;
    }
    if (strpos($sql, '{scope}') === false) {
        throw new InvalidArgumentException('tenantExec() requires a {scope} token.');
    }
    $sql = str_replace('{scope}', 'farm_id = ?', $sql);
    $stmt = $conn->prepare($sql);
    $stmt->execute(array_merge($params, [$farmId]));
    return $stmt->rowCount();
}

// ---------------------------------------------------------------------------
// Transactions
//
// Baseline audit C-5: catch blocks called rollBack() unconditionally, and PDO
// throws "There is no active transaction" when none is open — inside the
// handler, so it escaped as a fatal error. This wrapper makes the correct
// shape the default and the incorrect one unwritable.
// ---------------------------------------------------------------------------

/**
 * Run $work inside a transaction, committing on success and rolling back on
 * any throwable. Nested calls join the outer transaction rather than opening
 * a second one (PDO does not support real nesting on MySQL).
 *
 * @param callable $work  fn(PDO $conn): mixed
 * @return mixed          whatever $work returns
 */
function tenantTransaction(PDO $conn, callable $work) {
    $owner = !$conn->inTransaction();
    if ($owner) {
        $conn->beginTransaction();
    }
    try {
        $result = $work($conn);
        if ($owner && $conn->inTransaction()) {
            $conn->commit();
        }
        return $result;
    } catch (Throwable $e) {
        if ($owner && $conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
}
