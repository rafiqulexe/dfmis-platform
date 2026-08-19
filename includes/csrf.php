<?php
// CSRF protection helpers. auth.php must already be included (it starts the session).

// Returns the current CSRF token, generating one if none exists yet.
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Echoes a hidden input carrying the CSRF token. Put this inside every <form method="POST">.
function csrf_field() {
    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

// Call at the top of every POST handler before touching the database.
// Exits with 403 if the token is missing or wrong.
function csrf_verify() {
    $submitted = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $submitted)) {
        http_response_code(403);
        exit('Invalid or expired form submission. Please go back and try again.');
    }
}
