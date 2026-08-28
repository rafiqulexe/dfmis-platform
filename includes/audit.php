<?php
// ============================================================================
// AUDIT LOG — tenant-scoped record of who changed what.
//
// Baseline audit D-1: this file existed, was documented as working, and had
// ZERO call sites. Nothing recorded who archived an animal or edited a
// production figure. It is now wired into every mutating handler.
//
// EVERY EVENT RECORDS
//   actor         admin_id
//   organization  resolved from the active farm
//   farm          the active farm
//   action        dotted verb, e.g. 'cow.archive'
//   entity        entity_type + entity_id
//   timestamp     created_at (DB default)
//   metadata      old_value / new_value JSON
//
// NEVER RECORDS
//   passwords, hashes, API keys, tokens, session ids, CSRF tokens.
// Values are filtered through a deny-list before serialization, so a caller
// that carelessly passes $_POST cannot leak a credential into the log.
//
// Writes must never break the user's action — a failure is logged and
// swallowed.
// ============================================================================

require_once __DIR__ . '/tenant.php';

// Keys whose values are replaced with [REDACTED] wherever they appear in an
// audit payload, at any nesting depth.
const DFMS_AUDIT_DENY_KEYS = [
    'password', 'password_hash', 'passwd', 'pass', 'new_password', 'old_password',
    'confirm_password', 'csrf_token', 'api_key', 'anthropic_api_key', 'token',
    'secret', 'authorization', 'session_id', 'remember_token', 'private_key',
    // One entry per provider credential NAME. A key never reaches an audit
    // payload by design — nothing copies one into a metadata array — but this
    // list is the backstop for a future caller that passes a request array
    // through wholesale, and a backstop that knows only one vendor's variable
    // name stops working the day a second is configured.
    'gemini_api_key', 'google_api_key', 'x_goog_api_key',
];

// Recursively strips credential-bearing keys and truncates oversized values.
function dfms_audit_sanitize($value, $depth = 0) {
    if ($depth > 6) {
        return '[TRUNCATED:depth]';
    }
    if (is_array($value)) {
        $clean = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array(strtolower($key), DFMS_AUDIT_DENY_KEYS, true)) {
                $clean[$key] = '[REDACTED]';
                continue;
            }
            $clean[$key] = dfms_audit_sanitize($item, $depth + 1);
        }
        return $clean;
    }
    if (is_string($value)) {
        // A bcrypt hash or an API key pasted into a free-text field.
        if (preg_match('/^\$2[aby]\$\d{2}\$/', $value) || preg_match('/^sk-[A-Za-z0-9_\-]{16,}$/', $value)) {
            return '[REDACTED]';
        }
        if (mb_strlen($value, 'UTF-8') > 2000) {
            return mb_substr($value, 0, 2000, 'UTF-8') . '…[TRUNCATED]';
        }
    }
    return $value;
}

/**
 * Record a sensitive mutation.
 *
 * @param string     $action     dotted verb, e.g. 'cow.create', 'stock.adjust'
 * @param string     $entityType 'cow', 'alert', 'inventory_level', 'security'…
 * @param int|null   $entityId
 * @param mixed      $oldValue   state before (null for creates)
 * @param mixed      $newValue   state after (null for deletes)
 * @param array      $options    ['force' => true] to log even without a farm
 *                               context — used for auth/authz events.
 */
function auditLog($action, $entityType, $entityId = null, $oldValue = null, $newValue = null, array $options = []) {
    $farmId = currentFarmId();
    $force = !empty($options['force']);

    // Ordinary business events require a tenant. Security events (failed
    // login, denied authorization) are worth recording even when there is no
    // farm context yet — that is exactly when they matter most.
    if ($farmId === null && !$force) {
        return;
    }

    try {
        $conn = getDBConnection();
        $stmt = $conn->prepare(
            "INSERT INTO audit_log
                (farm_id, organization_id, admin_id, action, entity_type, entity_id,
                 old_value, new_value, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $old = $oldValue !== null ? json_encode(dfms_audit_sanitize($oldValue), JSON_UNESCAPED_UNICODE) : null;
        $new = $newValue !== null ? json_encode(dfms_audit_sanitize($newValue), JSON_UNESCAPED_UNICODE) : null;

        $stmt->execute([
            $farmId,
            currentOrganizationId(),
            currentUserId(),
            $action,
            $entityType,
            $entityId !== null ? (int) $entityId : null,
            $old,
            $new,
            dfms_client_ip(),
            mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255, 'UTF-8'),
        ]);
    } catch (Throwable $e) {
        // Auditing must never break the user's action; record and move on.
        error_log('Audit log write failed: ' . $e->getMessage());
    }
}

// Client IP, preferring a proxy header only when the app is explicitly told
// it sits behind a trusted proxy. Otherwise an attacker could forge the field.
function dfms_client_ip() {
    if (getenv('APP_TRUST_PROXY') === '1' && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($parts[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    $remote = $_SERVER['REMOTE_ADDR'] ?? null;
    return $remote && filter_var($remote, FILTER_VALIDATE_IP) ? $remote : null;
}

// Convenience wrappers so call sites read as intent rather than plumbing.
function auditCreate($entityType, $entityId, $newValue) {
    auditLog($entityType . '.create', $entityType, $entityId, null, $newValue);
}

function auditUpdate($entityType, $entityId, $oldValue, $newValue) {
    // Record only what actually changed — a diff is more useful in an
    // investigation than two full row dumps, and much smaller.
    $changedOld = [];
    $changedNew = [];
    foreach ((array) $newValue as $key => $value) {
        $before = (array) $oldValue;
        if (!array_key_exists($key, $before) || (string) $before[$key] !== (string) $value) {
            $changedOld[$key] = $before[$key] ?? null;
            $changedNew[$key] = $value;
        }
    }
    if (!$changedNew) {
        return; // Nothing actually changed; do not write a noise row.
    }
    auditLog($entityType . '.update', $entityType, $entityId, $changedOld, $changedNew);
}

function auditDelete($entityType, $entityId, $oldValue) {
    auditLog($entityType . '.delete', $entityType, $entityId, $oldValue, null);
}
