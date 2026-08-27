<?php
// ============================================================================
// SWITCH ACTIVE FARM
//
// Baseline audit D-2: switchFarm() existed, was correct, and had no caller —
// so a user who belonged to two farms was permanently pinned to whichever had
// the lowest id. The tenancy work was already done; this is the missing form.
//
// switchFarm() rejects any farm the user is not a member of, so a crafted
// farm_id cannot move someone into another tenant.
// ============================================================================

require_once '../../includes/auth.php';
require_once '../../includes/errors.php';
require_once '../../includes/csrf.php';
require_once '../../includes/audit.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';
require_once '../../includes/repository.php';
require_once '../../includes/validation.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    dfms_abort(405, 'farm/switch.php reached with ' . ($_SERVER['REQUEST_METHOD'] ?? '?'));
}

csrf_verify();

$v = new DfmsValidator($_POST);
$farmId = $v->id('farm_id');
if ($v->fails()) {
    redirectWith('dashboard.php', 'error', $v->firstError());
}

$previous = currentFarmId();

// Aborts with 403 if the user is not a member. Membership is re-read from the
// database, never taken from the session.
switchFarm($farmId);

// Reset per-request caches that were resolved against the old farm.
auditLog('farm.switch', 'farm', $farmId,
    ['farm_id' => $previous], ['farm_id' => $farmId]);

redirectWith('dashboard.php', 'success', 'Switched farm.');
