<?php
// Shared utility functions used across modules.

require_once __DIR__ . '/errors.php';

// Logs the real exception server-side and returns a safe, generic message
// for display to the user. Never echo $e->getMessage() directly to the browser.
//
// Baseline audit S-6: this contract was right, but stock php.ini displayed
// uncaught throwables anyway. errors.php now enforces it globally; this
// remains the handler for *expected*, recoverable failures on a page that
// should keep rendering.
function safeError(Throwable $e, $context) {
    dfms_log_throwable($e, $context);
    return $context . '. Please try again, or quote reference '
        . dfms_request_id() . ' if the problem continues.';
}

// Session-based flash messages, replacing the ad-hoc $_SESSION['error_message'] pattern.
function setFlash($type, $message) {
    $_SESSION['flash'][$type] = $message;
}

// Returns and clears the flash message of a given type ('error' or 'success'), or null.
function getFlash($type) {
    if (empty($_SESSION['flash'][$type])) {
        return null;
    }
    $message = $_SESSION['flash'][$type];
    unset($_SESSION['flash'][$type]);
    return $message;
}

// Redirect to an application-relative path. Only ever builds a URL from
// BASE_URL, so a caller cannot be tricked into emitting an off-site Location
// header (baseline audit S-1).
function redirectTo($path = '') {
    $target = BASE_URL . ltrim((string) $path, '/');
    header('Location: ' . $target);
    exit();
}

// Redirect back to a known-safe internal path carrying a flash message.
function redirectWith($path, $type, $message) {
    setFlash($type, $message);
    redirectTo($path);
}
