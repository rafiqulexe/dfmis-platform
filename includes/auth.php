<?php
session_start();
require_once __DIR__ . '/../config/database.php';

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['admin_id']);
}

// Current user's role, or null if not logged in
function currentRole() {
    return $_SESSION['role'] ?? null;
}

// Verify login credentials against a hashed password
function verifyLogin($username, $password) {
    try {
        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT admin_id, username, password_hash, role, farm_id FROM admin_users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            // Regenerate the session ID on privilege change to prevent session fixation
            session_regenerate_id(true);

            $_SESSION['admin_id'] = $user['admin_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['farm_id'] = $user['farm_id'] ?? 1;
            return true;
        }

        return false;
    } catch (PDOException $e) {
        error_log('Login error: ' . $e->getMessage());
        return false;
    }
}

// Simple redirect for unauthorized access
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . 'index.php');
        exit();
    }
}

// Restrict a page to one or more roles. Call after requireLogin().
function requireRole($allowedRoles) {
    if (!in_array(currentRole(), (array) $allowedRoles, true)) {
        http_response_code(403);
        exit('You do not have permission to access this page.');
    }
}

// Improved logout function
function logout() {
    // Clear all session variables
    $_SESSION = array();

    // Destroy the session cookie
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }

    // Destroy the session
    session_destroy();

    // Redirect to login page using BASE_URL
    $redirectUrl = rtrim(BASE_URL, '/') . '/index.php';
    header('Location: ' . $redirectUrl);
    exit();
}
