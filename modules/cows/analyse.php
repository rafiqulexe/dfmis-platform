<?php
// ============================================================================
// REQUEST AN AI IMAGE ANALYSIS FOR ONE ANIMAL
//
// A THIN controller. It validates the shape of the request and hands off to
// aiImageRequestAnalysis() in includes/ai_vision.php, which owns every
// authorization, ownership, size and content check. Nothing security-relevant
// is decided in this file, and nothing here re-implements storage, tenancy or
// the AI gateway.
//
// The image is NEVER uploaded here. It must already be a stored file belonging
// to this farm and attached to this animal — uploading is still
// modules/cows/files.php, and the only route to a stored byte is still
// modules/files/serve.php. That keeps exactly one upload path in the codebase
// with exactly one set of MIME, size and containment checks on it.
//
// PERMISSION: ai.consult, the SAME permission the veterinary assistant uses.
// Requesting an analysis spends the organization metered AI allowance and
// returns clinical decision support, which is precisely the decision that
// permission already expresses — a new one would have had an identical role
// list and given the matrix a second place to be wrong.
// ============================================================================

require_once '../../includes/auth.php';
require_once '../../includes/errors.php';
require_once '../../includes/csrf.php';
require_once '../../includes/audit.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';
require_once '../../includes/repository.php';
require_once '../../includes/validation.php';
require_once '../../includes/settings.php';
require_once '../../includes/livestock.php';
require_once '../../includes/ai_vision.php';

requireAuth('ai.consult');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // A state-changing action that spends a metered allowance must never be
    // reachable by GET — a prefetch or a crawler would otherwise spend it.
    header('Allow: POST');
    dfms_abort(405, 'cows/analyse.php reached with ' . ($_SERVER['REQUEST_METHOD'] ?? '?'));
}

csrf_verify();

$conn = getDBConnection();

$v = new DfmsValidator($_POST);
$animalId = $v->id('animal_id');
$fileId = $v->id('file_id');
$reported = $v->string('reported_signs', 0, 500, ['nullable' => true]);

if ($v->fails()) {
    // A malformed id is indistinguishable from a foreign one, as everywhere.
    dfms_abort(400, 'Malformed analysis request: ' . ($v->firstError() ?? ''));
}

// 404s for another tenant animal, indistinguishable from "does not exist".
$animal = animalFindOr404($conn, $animalId, true);
$back = 'modules/cows/view.php?id=' . (int) $animal['cow_id'] . '&tab=analysis';

try {
    $result = aiImageRequestAnalysis($conn, [
        'animal_id'      => (int) $animal['cow_id'],
        'file_id'        => $fileId,
        'reported_signs' => $reported,
    ]);
} catch (Throwable $e) {
    // safeError() logs the detail and returns text with no path, no SQL and no
    // provider internals in it.
    redirectWith($back, 'error', safeError($e, 'The analysis could not be started'));
}

if (!$result['ok']) {
    // Always a mapped, user-safe message from aiImageFailureMessage(). A raw
    // upstream body, a credential or a vendor error string can never reach
    // here: the domain module only ever returns a machine token.
    redirectWith($back, 'error', (string) $result['message']);
}

redirectWith($back, 'success', 'Analysis complete. This is an AI observation, not a diagnosis.');
