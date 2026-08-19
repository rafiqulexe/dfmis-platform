<?php
// ============================================================================
// AUDIT LOG — tenant-scoped record of who changed what.
// Writes carry the active farm; reads are farm-scoped like any other tenant
// data, with platform admins able to look across tenants.
// ============================================================================

require_once __DIR__ . '/tenant.php';

function auditLog($action, $entityType, $entityId = null, $oldValue = null, $newValue = null) {
    $farmId = currentFarmId();
    if ($farmId === null) {
        return;
    }
    try {
        $conn = getDBConnection();
        $stmt = $conn->prepare(
            "INSERT INTO audit_log (farm_id, admin_id, action, entity_type, entity_id, old_value, new_value)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $farmId,
            currentUserId(),
            $action,
            $entityType,
            $entityId !== null ? (int) $entityId : null,
            $oldValue !== null ? json_encode($oldValue) : null,
            $newValue !== null ? json_encode($newValue) : null,
        ]);
    } catch (PDOException $e) {
        // Auditing must never break the user's action; record and move on.
        error_log('Audit log write failed: ' . $e->getMessage());
    }
}
