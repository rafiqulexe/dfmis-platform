<?php
// ============================================================================
// ERROR HANDLING — one place where every uncaught failure is turned into a
// safe response.
//
// The rule this file exists to enforce: a user NEVER sees a SQL fragment, a
// stack trace, a filesystem path, a class name or a credential. Those go to
// the error log. The user gets a generic message and a correlation id they
// can quote to support.
//
// Baseline audit S-6: display_errors defaults to On in stock php.ini, and a
// TypeError in the dashboard leaked the full server path to the browser.
// ============================================================================

require_once __DIR__ . '/../config/database.php';

// Correlation id for this request. Logged alongside the real error and shown
// to the user, so a support conversation can find the log line without the
// user ever seeing what went wrong.
function dfms_request_id() {
    static $id = null;
    if ($id === null) {
        $id = bin2hex(random_bytes(8));
    }
    return $id;
}

// Redacts values that must never reach a log file, let alone a browser.
function dfms_redact($text) {
    $patterns = [
        '/(x-api-key|api[_-]?key|authorization|password|passwd|secret|token)\s*[:=]\s*\S+/i',
        '/sk-[A-Za-z0-9_\-]{16,}/',
    ];
    return preg_replace($patterns, '$1=[REDACTED]', (string) $text);
}

// Writes the real detail to the log, never to output.
function dfms_log_throwable(Throwable $e, $context = 'Unhandled') {
    error_log(sprintf(
        '[%s] %s: %s in %s:%d',
        dfms_request_id(),
        $context . ' ' . get_class($e),
        dfms_redact($e->getMessage()),
        $e->getFile(),
        $e->getLine()
    ));
    // Trace is logged separately so a log-forwarding rule can drop it.
    error_log('[' . dfms_request_id() . '] trace: ' . dfms_redact($e->getTraceAsString()));
}

// The generic page a user sees when something has gone wrong. Deliberately
// contains no detail beyond the correlation id.
function dfms_render_error_page($status = 500) {
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
    }
    $id = htmlspecialchars(dfms_request_id(), ENT_QUOTES, 'UTF-8');
    $messages = [
        400 => 'That request could not be understood.',
        403 => 'You do not have permission to do that.',
        404 => 'Not found.',
        413 => 'That file is too large.',
        500 => 'Something went wrong on our side. The problem has been recorded.',
    ];
    $message = htmlspecialchars($messages[$status] ?? $messages[500], ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>Error · DFM</title>'
       . '<style>body{font-family:system-ui,sans-serif;background:#f2f5f2;color:#141c18;'
       . 'display:grid;place-items:center;min-height:100vh;margin:0;padding:2rem}'
       . 'div{max-width:32rem;text-align:center}h1{font-size:1.25rem;margin:0 0 .5rem}'
       . 'p{color:#59685f;margin:0 0 1rem}code{font-family:ui-monospace,monospace;font-size:.8rem;'
       . 'background:#e7ece8;padding:.2rem .45rem}a{color:#1f6f4a}</style></head><body><div>'
       . '<h1>' . $message . '</h1>'
       . '<p>Reference <code>' . $id . '</code> — quote this if you contact support.</p>'
       . '<p><a href="' . htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') . 'dashboard.php">Back to dashboard</a></p>'
       . '</div></body></html>';
}

// ---------------------------------------------------------------------------
// Response mode.
//
// Phase 2B: the API needs aborts to render JSON rather than an HTML page. The
// authorization DECISION stays in exactly one place (permissions.php); only
// how a refusal is PRESENTED varies. Duplicating the decision per surface is
// what this deliberately avoids.
//
// api/index.php sets 'json' once at the top of an API request. Everything else
// keeps the default and is unaffected.
// ---------------------------------------------------------------------------
function dfms_response_mode(?string $set = null): string {
    static $mode = 'html';
    if ($set !== null) {
        $mode = $set === 'json' ? 'json' : 'html';
    }
    return $mode;
}

function dfms_set_response_mode(string $mode): void {
    dfms_response_mode($mode);
}

// Maps an HTTP status to the API's machine-readable error code, so an abort
// reaching an API client carries the same contract as a deliberate http_error().
function dfms_status_error_code(int $status): string {
    return [
        400 => 'BAD_REQUEST',
        401 => 'UNAUTHENTICATED',
        403 => 'FORBIDDEN',
        404 => 'NOT_FOUND',
        405 => 'METHOD_NOT_ALLOWED',
        413 => 'PAYLOAD_TOO_LARGE',
        422 => 'VALIDATION_ERROR',
    ][$status] ?? 'INTERNAL_ERROR';
}

// Ends the request with a safe status page. Use instead of exit('...') so
// every abort path looks the same and is logged consistently.
function dfms_abort($status, $logMessage = null) {
    if ($logMessage !== null) {
        error_log('[' . dfms_request_id() . '] abort ' . $status . ': ' . dfms_redact($logMessage));
    }
    // Discard any partially rendered page so the user never sees half a
    // layout with an error stapled underneath it.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (dfms_response_mode() === 'json') {
        $messages = [
            400 => 'That request could not be understood.',
            401 => 'Authentication required.',
            403 => 'You do not have permission to do that.',
            404 => 'Not found.',
            405 => 'Method not allowed.',
            413 => 'That payload is too large.',
        ];
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, private');
            header('X-Content-Type-Options: nosniff');
            header('X-Request-Id: ' . dfms_request_id());
        }
        echo json_encode(['error' => [
            'code'           => dfms_status_error_code((int) $status),
            'message'        => $messages[$status] ?? 'An unexpected error occurred.',
            'correlation_id' => dfms_request_id(),
        ]], JSON_UNESCAPED_SLASHES);
        exit;
    }

    dfms_render_error_page($status);
    exit;
}

// ---------------------------------------------------------------------------
// Global handlers. Registered once, on include.
// ---------------------------------------------------------------------------

set_exception_handler(function (Throwable $e) {
    dfms_log_throwable($e);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    dfms_render_error_page(500);
});

// Convert warnings/notices into exceptions so they cannot silently corrupt a
// page, but only for error levels that indicate a real defect.
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    if (in_array($severity, [E_WARNING, E_NOTICE, E_USER_WARNING, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED], true)) {
        error_log(sprintf('[%s] %s in %s:%d', dfms_request_id(), dfms_redact($message), $file, $line));
        return true;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Fatals (memory, timeout) bypass both handlers above.
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    error_log(sprintf(
        '[%s] FATAL %s in %s:%d',
        dfms_request_id(),
        dfms_redact($error['message']),
        $error['file'],
        $error['line']
    ));
    if (!headers_sent()) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        dfms_render_error_page(500);
    }
});
