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

// ---------------------------------------------------------------------------
// PDF DOCUMENTS (Phase 4)
//
// Laboratory reports and veterinary certificates arrive as PDFs, so the file
// system had to accept one non-image type. It is deliberately the ONLY one,
// and it is confined to the `documents` category — a PDF cannot be uploaded as
// an animal photo or a medical image.
//
// WHY THIS IS A CONTAINED CHANGE, NOT A WIDENING
//   - the type is content-sniffed with finfo AND magic-byte checked; the
//     client's declared type and filename are still never trusted
//   - the stored filename is still 32 random hex characters, so no URL can be
//     derived from an entity id
//   - the forbidden-extension list is unchanged and still applies
//   - serve.php sends a PDF as an ATTACHMENT with nosniff and a sandbox CSP,
//     so it is never rendered in the page's origin
//
// A PDF can carry JavaScript, which is exactly why it is never served inline.
// ---------------------------------------------------------------------------
const DFMS_ALLOWED_DOCUMENT_TYPES = [
    'application/pdf' => 'pdf',
];

// Categories that accept a document upload. `documents` only — see above.
const DFMS_DOCUMENT_CATEGORIES = ['documents'];

// Every extension the storage layer can ever produce, image or document.
// tenantFilePath() validates against this, so adding a type in one place
// cannot leave the read path unable to resolve it.
const DFMS_STORED_EXTENSIONS = ['jpg', 'png', 'webp', 'pdf'];

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
    // The extension list is built from DFMS_STORED_EXTENSIONS so the write and
    // read paths cannot disagree about what is storable.
    $extensions = implode('|', array_map(static function (string $extension): string {
        return preg_quote($extension, '/');
    }, DFMS_STORED_EXTENSIONS));
    if (!preg_match('/^[a-f0-9]{32}\.(' . $extensions . ')$/', $filename)) {
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

/**
 * Validate and store an uploaded PDF document (Phase 4).
 *
 * Deliberately a SEPARATE function from storeUploadedImage() rather than a
 * flag on it. The two have genuinely different validation — getimagesize()
 * cannot be applied to a PDF, and a magic-byte check is meaningless for a
 * JPEG — and merging them would produce one function with two mutually
 * exclusive halves and a boolean deciding which security checks run. That
 * shape is how an upload validator eventually gets called with the wrong flag.
 *
 * Everything AFTER validation is identical and deliberately so: the same
 * random filename, the same tenant directory, the same stored_files row, the
 * same orphan cleanup, the same audit event.
 *
 * @param array  $file     one entry from $_FILES
 * @param string $category must be one of DFMS_DOCUMENT_CATEGORIES
 * @param array  $link     optional ['entity_type' => 'lab_test', 'entity_id' => 12]
 * @return array           ['file_id' => int, 'stored_path' => string]
 * @throws RuntimeException with a user-safe message
 */
function storeUploadedDocument(array $file, $category, array $link = []) {
    if (!in_array($category, DFMS_DOCUMENT_CATEGORIES, true)) {
        throw new InvalidArgumentException('Invalid document category.');
    }

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

    // Real size on disk, not the client-reported one.
    $size = filesize($file['tmp_name']);
    if ($size === false || $size <= 0) {
        throw new RuntimeException('That file appears to be empty.');
    }
    if ($size > DFMS_MAX_UPLOAD_BYTES) {
        throw new RuntimeException('Document must be smaller than 8 MB.');
    }

    // Content sniffing. finfo is the primary opinion.
    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $sniffed = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            $mime = is_string($sniffed) ? $sniffed : '';
        }
    }
    if (!isset(DFMS_ALLOWED_DOCUMENT_TYPES[$mime])) {
        throw new RuntimeException('Only PDF documents are accepted.');
    }

    // Second, independent opinion: the file must actually begin with the PDF
    // magic bytes. finfo alone is a single point of failure, and a document
    // that satisfies finfo while starting with something else is not a PDF.
    $handle = fopen($file['tmp_name'], 'rb');
    $header = $handle ? fread($handle, 5) : false;
    if ($handle) {
        fclose($handle);
    }
    if ($header !== '%PDF-') {
        throw new RuntimeException('That file does not appear to be a valid PDF.');
    }

    $extension = DFMS_ALLOWED_DOCUMENT_TYPES[$mime];
    if (in_array(strtolower($extension), DFMS_FORBIDDEN_EXTENSIONS, true)) {
        throw new RuntimeException('That file type is not allowed.');
    }

    // Random name — a URL can never be derived from an entity id.
    $filename = bin2hex(random_bytes(16)) . '.' . $extension;
    $target = tenantStorageDir($category) . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        throw new RuntimeException('Could not save the uploaded document.');
    }
    @chmod($target, 0640);

    $storedPath = "{$category}/{$filename}";

    try {
        $conn = getDBConnection();
        $fileId = tenantInsert($conn, 'stored_files', [
            'category'      => $category,
            'document_type' => $link['document_type'] ?? null,
            'caption'       => $link['caption'] ?? null,
            'stored_path'   => $storedPath,
            'original_name' => mb_substr((string) ($file['name'] ?? ''), 0, 255, 'UTF-8'),
            'mime_type'     => $mime,
            'byte_size'     => $size,
            'entity_type'   => $link['entity_type'] ?? null,
            'entity_id'     => isset($link['entity_id']) ? (int) $link['entity_id'] : null,
            'uploaded_by'   => currentUserId(),
        ]);
    } catch (Throwable $e) {
        // Remove the orphaned bytes rather than leaving an unreferenced file.
        @unlink($target);
        throw new RuntimeException('Could not record the uploaded document.');
    }

    if (function_exists('auditLog')) {
        auditLog('file.upload', 'stored_file', $fileId, null, [
            'category'      => $category,
            'document_type' => $link['document_type'] ?? null,
            'mime_type'     => $mime,
            'byte_size'     => $size,
            'entity_type'   => $link['entity_type'] ?? null,
        ]);
    }

    return ['file_id' => $fileId, 'stored_path' => $storedPath];
}

/** Every MIME type the storage layer will ever serve back. */
function dfms_servable_mime_types(): array {
    return array_merge(
        array_keys(DFMS_ALLOWED_IMAGE_TYPES),
        array_keys(DFMS_ALLOWED_DOCUMENT_TYPES)
    );
}

/**
 * Must this MIME type be sent as a download rather than rendered inline?
 *
 * A PDF can carry JavaScript and an embedded viewer runs it, so a PDF is
 * ALWAYS an attachment. Images stay inline, which is what makes the photo
 * grid work.
 */
function dfms_mime_requires_download(string $mime): bool {
    return isset(DFMS_ALLOWED_DOCUMENT_TYPES[$mime]);
}
