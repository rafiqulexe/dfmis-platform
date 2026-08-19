<?php
// Shared utility functions used across modules.

// Logs the real exception server-side and returns a safe, generic message
// for display to the user. Never echo $e->getMessage() directly to the browser.
function safeError(PDOException $e, $context) {
    error_log($context . ': ' . $e->getMessage());
    return $context . '. Please try again or contact support if the problem continues.';
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
