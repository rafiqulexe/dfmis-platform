<?php
session_start();
require_once __DIR__ . '/../config/database.php';

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['admin_id']);
}

// NOTE: there is deliberately no global currentRole() here. A user's role is
// per-farm and lives in farm_users — use currentFarmRole() from tenant.php,
// or hasPermission()/requirePermission() from permissions.php.

// Verify login credentials against a hashed password
function verifyLogin($username, $password) {
    try {
        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT admin_id, username, password_hash, is_platform_admin FROM admin_users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            // Regenerate the session ID on privilege change to prevent session fixation
            session_regenerate_id(true);

            $_SESSION['admin_id'] = $user['admin_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['is_platform_admin'] = (int) $user['is_platform_admin'];
            // The active farm and the role on it are resolved from live
            // membership by the tenant context, never stored as authority here.
            unset($_SESSION['farm_id'], $_SESSION['role']);
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
