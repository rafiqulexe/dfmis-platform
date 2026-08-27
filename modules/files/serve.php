<?php
// ============================================================================
// FILE DELIVERY — the only route by which a stored file can be read.
//
// Storage sits outside the web root and storage/.htaccess denies direct
// access, so every byte a user receives passes through this gate:
//
//   1. authenticated
//   2. tenant context present
//   3. files.view permission on the active farm
//   4. the file record itself resolved through tenantFindOr404()
//   5. the resolved path confined to the active tenant's directory
//
// A file belonging to another farm is a 404, indistinguishable from one that
// does not exist.
// ============================================================================

require_once '../../includes/auth.php';
require_once '../../includes/errors.php';
require_once '../../includes/audit.php';
require_once '../../includes/permissions.php';
require_once '../../includes/repository.php';
require_once '../../includes/validation.php';
require_once '../../includes/storage.php';

requireAuth('files.view');

$fileId = dfms_route_id('id');
$conn = getDBConnection();

$record = tenantFindOr404($conn, 'stored_files', 'file_id', $fileId, 'deleted_at IS NULL');

$path = tenantFilePath($record['stored_path']);
if ($path === null || !is_file($path)) {
    // The row exists for this tenant but the bytes are gone — log it, because
    // that is a storage inconsistency worth knowing about, and 404 the user.
    dfms_abort(404, 'stored_files#' . $record['file_id'] . ' has no file on disk');
}

// Only ever serve a MIME type from the allow-list, taken from our own record
// rather than re-sniffed, and never something the browser might execute.
$mime = $record['mime_type'];
if (!in_array($mime, array_keys(DFMS_ALLOWED_IMAGE_TYPES), true)) {
    dfms_abort(404, 'stored_files#' . $record['file_id'] . " has unexpected mime '$mime'");
}

$size = filesize($path);
$etag = '"' . md5($record['stored_path'] . '|' . $record['created_at']) . '"';

// Conditional request — avoids re-sending an unchanged image.
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    header('ETag: ' . $etag);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . $size);
header('ETag: ' . $etag);
// Private: this is tenant data, so no shared cache may retain it.
header('Cache-Control: private, max-age=3600, must-revalidate');
header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $record['original_name']) . '"');
// Defence in depth if a malicious payload ever reached storage.
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'; img-src \'self\'; sandbox');

readfile($path);
