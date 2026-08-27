<?php
// ============================================================================
// TENANT-AWARE FILE STORAGE
//
// Uploads (animal photos, medical images, documents) live OUTSIDE the web root
// and are served only through a PHP handler that re-checks tenancy. A file
// under a public directory is readable by anyone who can guess its URL, and
// sequential ids make guessing trivial — so nothing tenant-owned is ever
// placed somewhere the web server can serve directly.
//
// Layout:  storage/org_{organization_id}/farm_{farm_id}/{category}/{file}
// Filenames are random, so a URL cannot be derived from an entity id.
//
// PHASE 1 CHANGES
// The byte handling here was already sound. What was missing was ownership:
// nothing recorded WHICH tenant owned a file, so retrieval could not be
// access-controlled. Every stored file is now registered in `stored_files`,
// and modules/files/serve.php is the only way to read one back.
// ============================================================================

require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/repository.php';

const DFMS_STORAGE_ROOT = __DIR__ . '/../storage';

// Content-sniffed MIME -> canonical extension. The client's declared type and
// filename are never trusted.
const DFMS_ALLOWED_IMAGE_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

// Extensions that must never be produced, even if a MIME map were misedited.
const DFMS_FORBIDDEN_EXTENSIONS = [
    'php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar', 'inc',
    'htaccess', 'htm', 'html', 'js', 'exe', 'sh', 'bat', 'cmd', 'dll', 'svg',
];

const DFMS_MAX_UPLOAD_BYTES = 8388608; // 8 MB
const DFMS_ALLOWED_CATEGORIES = ['animal_photos', 'medical_images', 'documents'];

// Absolute directory for the active tenant and category, created on demand.
function tenantStorageDir($category) {
    $orgId = currentOrganizationId();
    $farmId = currentFarmId();
    if ($orgId === null || $farmId === null) {
        throw new RuntimeException('No tenant context for storage.');
    }
    if (!in_array($category, DFMS_ALLOWED_CATEGORIES, true)) {
        throw new InvalidArgumentException('Invalid storage category.');
    }
    $dir = DFMS_STORAGE_ROOT . "/org_{$orgId}/farm_{$farmId}/{$category}";
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create storage directory.');
    }
    return $dir;
}

/**
 * Validate and store an uploaded image, registering ownership.
 *
 * @param array  $file     one entry from $_FILES
 * @param string $category one of DFMS_ALLOWED_CATEGORIES
 * @param array  $link     optional ['entity_type' => 'cow', 'entity_id' => 12]
 * @return array           ['file_id' => int, 'stored_path' => string]
 * @throws RuntimeException with a user-safe message
 */
function storeUploadedImage(array $file, $category, array $link = []) {
    // PHP's own upload errors first — these are the common, explainable ones.
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error !== UPLOAD_ERR_OK) {
        $messages = [
            UPLOAD_ERR_INI_SIZE   => 'That file is larger than the server allows.',
            UPLOAD_ERR_FORM_SIZE  => 'That file is larger than this form allows.',
            UPLOAD_ERR_PARTIAL    => 'The upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE    => 'No file was selected.',
            UPLOAD_ERR_NO_TMP_DIR => 'Upload failed. Please try again.',
            UPLOAD_ERR_CANT_WRITE => 'Upload failed. Please try again.',
            UPLOAD_ERR_EXTENSION  => 'Upload failed. Please try again.',
        ];
        throw new RuntimeException($messages[$error] ?? 'Upload failed. Please try again.');
    }

    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Invalid upload.');
    }

    // Check the real size on disk, not the client-reported one.
    $size = filesize($file['tmp_name']);
    if ($size === false || $size <= 0) {
        throw new RuntimeException('That file appears to be empty.');
    }
    if ($size > DFMS_MAX_UPLOAD_BYTES) {
        throw new RuntimeException('Image must be smaller than 8 MB.');
    }

    // Trust the file's actual content, never the client-supplied type or name.
    $info = @getimagesize($file['tmp_name']);
    // getimagesize() returns false on failure; when it returns an array, 'mime'
    // is always present, so no inner null-coalesce is needed.
    $mime = is_array($info) ? $info['mime'] : '';
    if (!isset(DFMS_ALLOWED_IMAGE_TYPES[$mime])) {
        throw new RuntimeException('Only JPEG, PNG or WebP images are accepted.');
    }

    // Cross-check with finfo. A file crafted to satisfy getimagesize() while
    // also parsing as something else must not slip through on one opinion.
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $sniffed = $finfo ? finfo_file($finfo, $file['tmp_name']) : $mime;
        if ($finfo) {
            finfo_close($finfo);
        }
        if ($sniffed !== $mime) {
            throw new RuntimeException('That file does not appear to be a valid image.');
        }
    }

    $extension = DFMS_ALLOWED_IMAGE_TYPES[$mime];
    if (in_array(strtolower($extension), DFMS_FORBIDDEN_EXTENSIONS, true)) {
        throw new RuntimeException('That file type is not allowed.');
    }

    // Random name — a URL can never be derived from an entity id.
    $filename = bin2hex(random_bytes(16)) . '.' . $extension;
    $target = tenantStorageDir($category) . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        throw new RuntimeException('Could not save the uploaded image.');
    }
    @chmod($target, 0640);

    $storedPath = "{$category}/{$filename}";

    // Register ownership so retrieval can be access-controlled. If this fails,
    // remove the orphaned bytes rather than leaving an unreferenced file.
    try {
        $conn = getDBConnection();
        $fileId = tenantInsert($conn, 'stored_files', [
            'category'      => $category,
            'stored_path'   => $storedPath,
            'original_name' => mb_substr((string) ($file['name'] ?? ''), 0, 255, 'UTF-8'),
            'mime_type'     => $mime,
            'byte_size'     => $size,
            'entity_type'   => $link['entity_type'] ?? null,
            'entity_id'     => isset($link['entity_id']) ? (int) $link['entity_id'] : null,
            'uploaded_by'   => currentUserId(),
        ]);
    } catch (Throwable $e) {
        @unlink($target);
        throw new RuntimeException('Could not record the uploaded image.');
    }

    if (function_exists('auditLog')) {
        auditLog('file.upload', 'stored_file', $fileId, null, [
            'category'  => $category,
            'mime_type' => $mime,
            'byte_size' => $size,
        ]);
    }

    return ['file_id' => $fileId, 'stored_path' => $storedPath];
}

// Resolve a stored path back to an absolute file, confined to the active
// tenant's directory. Returns null if the path escapes that directory.
function tenantFilePath($storedPath) {
    if (!is_string($storedPath) || $storedPath === '' || str_contains($storedPath, "\0")) {
        return null;
    }
    $parts = explode('/', $storedPath, 2);
    if (count($parts) !== 2) {
        return null;
    }
    [$category, $filename] = $parts;
    if (!in_array($category, DFMS_ALLOWED_CATEGORIES, true)) {
        return null;
    }
    // Random 32-hex name with an approved extension — nothing else is valid,
    // which alone defeats traversal, but the containment check below stays.
    if (!preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $filename)) {
        return null;
    }

    $base = tenantStorageDir($category);
    $realBase = realpath($base);
    $full = realpath($base . '/' . $filename);
    if ($full === false || $realBase === false) {
        return null;
    }
    // Containment check: defeats any traversal that survived the pattern above.
    if (strpos($full, $realBase . DIRECTORY_SEPARATOR) !== 0 && $full !== $realBase) {
        return null;
    }
    return $full;
}

// Load a file record scoped to the active farm. Returns null for missing OR
// foreign — indistinguishable, as everywhere else.
function tenantFindFile(PDO $conn, $fileId) {
    return tenantFind($conn, 'stored_files', 'file_id', $fileId, 'deleted_at IS NULL');
}

/**
 * Soft-delete a file record and remove the bytes.
 *
 * Soft-delete keeps the audit trail intact (which row existed, who uploaded
 * it) while the bytes themselves are genuinely removed, since retaining a
 * deleted medical image is a privacy liability rather than an asset.
 */
function deleteStoredFile(PDO $conn, $fileId) {
    $record = tenantFindFile($conn, $fileId);
    if ($record === null) {
        return false;
    }

    tenantExec(
        $conn,
        "UPDATE stored_files SET deleted_at = NOW() WHERE file_id = ? AND {scope}",
        [$record['file_id']]
    );

    $path = tenantFilePath($record['stored_path']);
    if ($path !== null && is_file($path)) {
        @unlink($path);
    }

    if (function_exists('auditLog')) {
        auditLog('file.delete', 'stored_file', (int) $record['file_id'], [
            'category'      => $record['category'],
            'original_name' => $record['original_name'],
        ], null);
    }
    return true;
}
