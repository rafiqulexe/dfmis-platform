<?php
// ============================================================================
// HTTP REQUEST / RESPONSE FOUNDATION
//
// A small set of shared helpers for request inspection and JSON responses.
// Introduced in Phase 2B for the API layer; existing HTML pages are NOT
// required to adopt it and none were changed to use it.
//
// WHAT THIS IS NOT
// Not a router, not a framework, not a controller base class, not a request
// object hierarchy. The application stays procedural. This file exists because
// the API needs ONE definition of "what does an error look like", and writing
// that inline in every endpoint is how inconsistent contracts happen.
//
// SECURITY — reuses Phase 1, duplicates nothing
// Authentication, authorization, tenant scope, validation and error
// containment are unchanged and still come from auth.php, permissions.php,
// tenant.php, repository.php, validation.php and errors.php. Nothing here
// re-implements any of them; the API entry point calls the same requireAuth()
// an HTML page does.
// ============================================================================

require_once __DIR__ . '/errors.php';

// ---------------------------------------------------------------------------
// Request inspection
// ---------------------------------------------------------------------------

function http_method(): string {
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

/** True when the client asked for JSON, by Accept header or Content-Type. */
function http_wants_json(): bool {
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    if (stripos($accept, 'application/json') !== false) {
        return true;
    }
    return stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
}

/**
 * Decode a JSON request body.
 *
 * Returns [] for an empty body — a GET or a bodyless POST is not an error.
 * Returns null when the body is present but not valid JSON, which the caller
 * must treat as a 400. The two cases are deliberately distinguishable.
 *
 * The body is read once and cached: php://input is not re-readable on every
 * SAPI, so a second read would silently yield an empty string.
 */
function http_json_body(): ?array {
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return $cache = [];
    }

    // Guard against a body large enough to exhaust memory before json_decode
    // even sees it. 1 MB is far beyond any legitimate API payload here.
    if (strlen($raw) > 1048576) {
        return $cache = null;
    }

    $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_array($decoded)) {
        return $cache = null;
    }
    return $cache = $decoded;
}

/**
 * Merged input for an endpoint: query string plus JSON body.
 *
 * Body wins on conflict, because it is the more explicit of the two. Feed the
 * result to DfmsValidator exactly as a page feeds it $_POST — the API does not
 * get its own validation rules.
 */
function http_input(): array {
    $body = http_json_body();
    return array_merge($_GET, is_array($body) ? $body : []);
}

// ---------------------------------------------------------------------------
// Responses
// ---------------------------------------------------------------------------

/**
 * Emit a JSON response and stop.
 *
 * Any partially rendered output is discarded first, so a stray notice or a
 * half-written template can never corrupt the JSON body.
 */
function http_json($payload, int $status = 200, array $headers = []): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        // API responses are per-tenant and must never be held by a shared cache.
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        // The correlation id is returned on EVERY response, not only errors, so
        // a client can quote it when reporting a wrong-looking success too.
        header('X-Request-Id: ' . dfms_request_id());
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * The single error shape for every API endpoint.
 *
 *   {"error": {"code": "...", "message": "...", "correlation_id": "..."}}
 *
 * `code` is a stable machine-readable token — clients branch on it, never on
 * the message. `message` is safe human text. NOTHING else is ever included:
 * no SQL, no stack trace, no filesystem path, no class name, no secret.
 *
 * $details is optional and is used only for validation field errors, which are
 * messages this application generated about the client's own input and are
 * therefore safe to return.
 */
function http_error(string $code, string $message, int $status = 400, array $details = []): void {
    $error = [
        'code'           => $code,
        'message'        => $message,
        'correlation_id' => dfms_request_id(),
    ];
    if ($details !== []) {
        $error['details'] = $details;
    }

    // Log server-side faults with their correlation id so the log line and the
    // client's reference match. 4xx is the client's problem and is not logged
    // as an application error.
    if ($status >= 500) {
        error_log('[' . dfms_request_id() . "] API $status $code: " . dfms_redact($message));
    }

    http_json(['error' => $error], $status);
}

/** Success envelope. Data is always under `data`, so the shape never varies. */
function http_success($data, int $status = 200, array $meta = []): void {
    $payload = ['data' => $data];
    if ($meta !== []) {
        $payload['meta'] = $meta;
    }
    http_json($payload, $status);
}

/**
 * Enforce the allowed HTTP methods for an endpoint.
 *
 * Responds 405 with a correct Allow header. OPTIONS is answered automatically
 * so a preflight never reaches endpoint logic.
 */
function http_require_method(array $allowed): void {
    $allowed = array_map('strtoupper', $allowed);
    $method = http_method();

    if ($method === 'OPTIONS') {
        http_json(['data' => ['allow' => $allowed]], 204, ['Allow' => implode(', ', $allowed)]);
    }

    if (!in_array($method, $allowed, true)) {
        http_error(
            'METHOD_NOT_ALLOWED',
            'This endpoint does not support ' . $method . '.',
            405,
            []
        );
    }
}

/**
 * Convert a DfmsValidator failure into the standard error response.
 *
 * Field messages come from the validator, which produces text about the
 * client's own input — safe to return, and far more useful than a bare
 * "validation failed".
 */
function http_validation_failed(array $errors): void {
    http_error('VALIDATION_ERROR', 'Validation failed.', 422, $errors);
}
