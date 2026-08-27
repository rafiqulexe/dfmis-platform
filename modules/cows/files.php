<?php
// ============================================================================
// ANIMAL DOCUMENTS AND PHOTOS — upload
//
// A THIN wrapper over the Phase 1 storage layer. It adds the animal link and
// the document type; it does NOT re-implement MIME sniffing, size limits,
// filename generation, path containment or access control. Building a second
// file path would be a security regression, and the existing one is already
// tested.
//
// Reading a file back is modules/files/serve.php — unchanged, and still the
// only route to a stored byte.
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
require_once '../../includes/storage.php';
require_once '../../includes/livestock.php';

requireAuth('files.upload');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    dfms_abort(405, 'cows/files.php reached with ' . ($_SERVER['REQUEST_METHOD'] ?? '?'));
}

csrf_verify();

$conn = getDBConnection();

$v = new DfmsValidator($_POST);
$animalId = $v->id('animal_id');
// Only the two categories that belong to an animal. medical_images is a
// storage category but is not offered here.
$category = $v->enum('category', ['animal_photos', 'documents']);
$caption = $v->string('caption', 0, 255, ['nullable' => true]);

if ($v->fails()) {
    dfms_abort(400, 'Malformed upload request: ' . ($v->firstError() ?? ''));
}

$animal = animalFindOr404($conn, $animalId, true);
$tab = $category === 'animal_photos' ? 'photos' : 'documents';
$back = 'modules/cows/view.php?id=' . (int) $animal['cow_id'] . '&tab=' . $tab;

// The type vocabulary differs by category: a photo has a purpose, a document
// has a kind. Both are validated against their own closed list.
$allowedTypes = $category === 'animal_photos'
    ? array_keys(DFMS_PHOTO_CATEGORIES)
    : array_keys(DFMS_DOCUMENT_TYPES);
$documentType = $v->enum('document_type', $allowedTypes);

if ($v->fails()) {
    redirectWith($back, 'error', $v->firstError());
}

if (!isset($_FILES['upload'])) {
    redirectWith($back, 'error', 'No file was selected.');
}

try {
    // storeUploadedImage() performs every byte-level check: upload-error
    // mapping, is_uploaded_file, real on-disk size against the 8 MB cap,
    // content-sniffed MIME cross-checked with finfo, a random filename, storage
    // outside the web root, and ownership registration.
    $stored = storeUploadedImage($_FILES['upload'], $category, [
        'entity_type' => 'animal',
        'entity_id'   => (int) $animal['cow_id'],
    ]);

    // Attach the animal-specific metadata to the row storeUploadedImage created.
    animalTagFile($conn, (int) $stored['file_id'], (int) $animal['cow_id'], [
        'document_type' => $documentType,
        'caption'       => $caption,
        'captured_on'   => farmToday(),
    ]);

    auditLog('animal.file_upload', 'animal', (int) $animal['cow_id'], null, [
        'file_id'       => (int) $stored['file_id'],
        'category'      => $category,
        'document_type' => $documentType,
    ]);

    redirectWith($back, 'success',
        $category === 'animal_photos' ? 'Photo uploaded.' : 'Document uploaded.');
} catch (RuntimeException $e) {
    // storeUploadedImage() raises user-safe messages ("Only JPEG, PNG or WebP
    // images are accepted", "Image must be smaller than 8 MB").
    redirectWith($back, 'error', $e->getMessage());
} catch (Throwable $e) {
    redirectWith($back, 'error', safeError($e, 'The upload could not be completed'));
}
