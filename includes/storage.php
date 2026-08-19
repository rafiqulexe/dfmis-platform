<?php
// ============================================================================
// TENANT-AWARE FILE STORAGE
//
// Uploads (cow photos, medical images, documents) live OUTSIDE the web root
// and are served only through a PHP handler that re-checks tenancy. A file
// under a public directory is readable by anyone who can guess its URL, and
// sequential ids make guessing trivial — so nothing tenant-owned is ever
// placed somewhere the web server can serve directly.
//
// Layout:  storage/org_{organization_id}/farm_{farm_id}/{category}/{file}
// Filenames are random, so a URL cannot be derived from a cow id.
// ============================================================================

require_once __DIR__ . '/tenant.php';

const DFMS_STORAGE_ROOT = __DIR__ . '/../storage';
const DFMS_ALLOWED_IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
const DFMS_MAX_UPLOAD_BYTES = 8388608; // 8 MB

// Absolute directory for the active tenant and category, created on demand.
function tenantStorageDir($category) {
    $orgId = currentOrganizationId();
    $farmId = currentFarmId();
    if ($orgId === null || $farmId === null) {
        throw new RuntimeException('No tenant context for storage.');
    }
    if (!preg_match('/^[a-z_]+$/', $category)) {
        throw new InvalidArgumentException('Invalid storage category.');
    }
    $dir = DFMS_STORAGE_ROOT . "/org_{$orgId}/farm_{$farmId}/{$category}";
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create storage directory.');
    }
    return $dir;
}

// Validate and store an uploaded image. Returns the tenant-relative path to
// persist in the database, or throws with a user-safe message.
function storeUploadedImage(array $file, $category) {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed. Please try again.');
    }
    if ($file['size'] > DFMS_MAX_UPLOAD_BYTES) {
        throw new RuntimeException('Image must be smaller than 8 MB.');
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Invalid upload.');
    }

    // Trust the file's actual content, never the client-supplied type or name.
    $info = @getimagesize($file['tmp_name']);
    $mime = $info['mime'] ?? '';
    if (!isset(DFMS_ALLOWED_IMAGE_TYPES[$mime])) {
        throw new RuntimeException('Only JPEG, PNG or WebP images are accepted.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . DFMS_ALLOWED_IMAGE_TYPES[$mime];
    $target = tenantStorageDir($category) . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        throw new RuntimeException('Could not save the uploaded image.');
    }

    return "{$category}/{$filename}";
}

// Resolve a stored path back to an absolute file, confined to the active
// tenant's directory. Returns null if the path escapes that directory.
function tenantFilePath($storedPath) {
    if ($storedPath === null || $storedPath === '' || str_contains($storedPath, "\0")) {
        return null;
    }
    $parts = explode('/', $storedPath, 2);
    if (count($parts) !== 2) {
        return null;
    }
    [$category, $filename] = $parts;
    if (!preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $filename)) {
        return null;
    }

    $base = tenantStorageDir($category);
    $full = realpath($base . '/' . $filename);
    // Containment check: defeats any traversal that survived the pattern above.
    if ($full === false || !str_starts_with($full, realpath($base))) {
        return null;
    }
    return $full;
}
