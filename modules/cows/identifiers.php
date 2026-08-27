<?php
// ============================================================================
// ANIMAL IDENTIFIERS — add / remove
//
// POST only. Rendering lives on the profile's Identity tab; this is the
// mutation endpoint.
//
// Uses cows.edit rather than a new animal.identifiers permission: attaching an
// ear tag is editing the animal, and a separate permission would be one more
// thing to get wrong in the matrix for no distinct decision.
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

requireAuth('cows.edit');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    dfms_abort(405, 'identifiers.php reached with ' . ($_SERVER['REQUEST_METHOD'] ?? '?'));
}

csrf_verify();

$conn = getDBConnection();

$v = new DfmsValidator($_POST);
$action = $v->enum('action', ['add', 'remove']);
$animalId = $v->id('animal_id');

if ($v->fails()) {
    dfms_abort(400, 'Malformed identifier request: ' . ($v->firstError() ?? ''));
}

// Resolves inside this tenant or 404s — a crafted animal_id cannot reach
// another farm's animal.
$animal = animalFindOr404($conn, $animalId, true);
$back = 'modules/cows/view.php?id=' . (int) $animal['cow_id'] . '&tab=identity';

if ($action === 'remove') {
    $identifierId = $v->id('identifier_id');
    if ($v->fails()) {
        redirectWith($back, 'error', $v->firstError());
    }
    try {
        $removed = animalRemoveIdentifier($conn, $identifierId);
        redirectWith($back, $removed ? 'success' : 'error',
            $removed ? 'Identifier removed.' : 'That identifier could not be found.');
    } catch (PDOException $e) {
        redirectWith($back, 'error', safeError($e, 'Could not remove the identifier'));
    }
}

// ---- add ------------------------------------------------------------------
$identifierType = $v->enum('identifier_type', array_keys(DFMS_IDENTIFIER_TYPES));
$identifierValue = $v->string('identifier_value', 1, 100);
$issuingAuthority = $v->string('issuing_authority', 0, 150, ['nullable' => true]);
// Backdating an identifier is legitimate; a future issue date is not.
$issuedOn = $v->date('issued_on', ['nullable' => true]);

if ($v->fails()) {
    redirectWith($back, 'error', $v->firstError());
}

try {
    animalAddIdentifier($conn, (int) $animal['cow_id'], [
        'identifier_type'   => $identifierType,
        'identifier_value'  => $identifierValue,
        'issuing_authority' => $issuingAuthority,
        'issued_on'         => $issuedOn,
        'status'            => 'active',
    ]);
    redirectWith($back, 'success', 'Identifier added.');
} catch (InvalidArgumentException $e) {
    redirectWith($back, 'error', $e->getMessage());
} catch (PDOException $e) {
    // 23000 = the per-farm uniqueness constraint. That means this identifier is
    // already recorded ON THIS FARM — it says nothing about any other tenant.
    if ($e->getCode() === '23000') {
        redirectWith($back, 'error', 'That identifier is already recorded on this farm.');
    }
    redirectWith($back, 'error', safeError($e, 'Could not add the identifier'));
}
