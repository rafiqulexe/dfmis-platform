<?php
// ============================================================================
// MARK ALERT(S) READ
//
// Baseline audit C-2: alerts.is_read was queried in two places and written by
// none. The sidebar badge could only ever climb and the dashboard attention
// list permanently repeated handled items, which trains people to ignore both.
//
// POST only — this mutates state, so it must not be reachable by a GET a
// browser can prefetch or a crawler can follow.
// ============================================================================

require_once '../../includes/auth.php';
require_once '../../includes/errors.php';
require_once '../../includes/csrf.php';
require_once '../../includes/audit.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';
require_once '../../includes/repository.php';
require_once '../../includes/validation.php';

requireAuth('alerts.acknowledge');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    dfms_abort(405, 'alerts/read.php reached with ' . ($_SERVER['REQUEST_METHOD'] ?? '?'));
}

csrf_verify();

$conn = getDBConnection();
$v = new DfmsValidator($_POST);
$scope = $v->enum('scope', ['one', 'all'], ['default' => 'one']);

if ($v->fails()) {
    redirectWith('modules/alerts/', 'error', $v->firstError());
}

if ($scope === 'all') {
    // {scope} confines this to the active farm — a user can never clear
    // another tenant's queue.
    $count = tenantExec(
        $conn,
        "UPDATE alerts SET is_read = 1, read_at = NOW(), read_by = ?
          WHERE is_read = 0 AND {scope}",
        [currentUserId()]
    );
    if ($count > 0) {
        auditLog('alert.acknowledge_all', 'alert', null, null, ['count' => $count]);
    }
    redirectWith('modules/alerts/', 'success',
        $count === 0 ? 'No unread alerts to clear.' : "Marked {$count} alert" . ($count === 1 ? '' : 's') . ' as read.');
}

// Single alert. tenantFindOr404 is what makes a manipulated alert_id a 404
// rather than a cross-tenant write.
$alertId = $v->id('alert_id');
if ($v->fails()) {
    redirectWith('modules/alerts/', 'error', $v->firstError());
}

$alert = tenantFindOr404($conn, 'alerts', 'alert_id', $alertId);

if ((int) $alert['is_read'] === 1) {
    redirectWith('modules/alerts/', 'success', 'That alert was already marked read.');
}

tenantExec(
    $conn,
    "UPDATE alerts SET is_read = 1, read_at = NOW(), read_by = ? WHERE alert_id = ? AND {scope}",
    [currentUserId(), $alert['alert_id']]
);

auditLog('alert.acknowledge', 'alert', (int) $alert['alert_id'],
    ['is_read' => 0], ['is_read' => 1]);

redirectWith('modules/alerts/', 'success', 'Alert marked as read.');
