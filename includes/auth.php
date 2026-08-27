<?php
// ============================================================================
// AUTHENTICATION — session lifecycle and credential verification.
//
// Baseline audit S-3/S-4: the session cookie shipped with PHP defaults (no
// HttpOnly, no SameSite, no Secure) and logout cleared it at a hardcoded
// path that may not match the cookie the app actually set.
// ============================================================================

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/errors.php';

// ---------------------------------------------------------------------------
// Session bootstrap. Cookie params MUST be set before session_start().
// ---------------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    $dfms_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? '') == 443
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

    // Cookie path derives from BASE_URL so a subdirectory mount (XAMPP
    // htdocs) sets and clears the same cookie rather than two different ones.
    $dfms_path = parse_url(BASE_URL, PHP_URL_PATH) ?: '/';
    if ($dfms_path === '' || $dfms_path[strlen($dfms_path) - 1] !== '/') {
        $dfms_path .= '/';
    }

    session_set_cookie_params([
        'lifetime' => 0,          // session cookie — cleared when browser closes
        'path'     => $dfms_path,
        'domain'   => '',
        'secure'   => $dfms_https, // only sent over HTTPS when available
        'httponly' => true,        // never readable by JavaScript
        'samesite' => 'Lax',       // second layer under the CSRF tokens
    ]);
    session_name('DFMSSESSID');
    session_start();
}

// Absolute + idle session expiry. A farm office terminal left unlocked is a
// realistic threat; an unbounded session is not acceptable for it.
const DFMS_SESSION_IDLE_SECONDS = 28800;   // 8 hours — one working shift
const DFMS_SESSION_ABSOLUTE_SECONDS = 86400; // 24 hours

function dfms_enforce_session_lifetime() {
    if (empty($_SESSION['admin_id'])) {
        return;
    }
    $now = time();
    $started = $_SESSION['auth_started_at'] ?? $now;
    $seen = $_SESSION['auth_last_seen_at'] ?? $now;

    if (($now - $started) > DFMS_SESSION_ABSOLUTE_SECONDS
        || ($now - $seen) > DFMS_SESSION_IDLE_SECONDS) {
        dfms_clear_session();
        return;
    }
    $_SESSION['auth_last_seen_at'] = $now;
}
dfms_enforce_session_lifetime();

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['admin_id']);
}

// NOTE: there is deliberately no global currentRole() here. A user's role is
// per-farm and lives in farm_users — use currentFarmRole() from tenant.php,
// or hasPermission()/requirePermission() from permissions.php.

// ---------------------------------------------------------------------------
// Login throttling. Keyed on username AND client IP so neither a single
// account nor a single source can be hammered.
// ---------------------------------------------------------------------------
const DFMS_LOGIN_MAX_ATTEMPTS = 8;
const DFMS_LOGIN_WINDOW_SECONDS = 900; // 15 minutes

function dfms_login_throttled($username) {
    try {
        $conn = getDBConnection();
        $stmt = $conn->prepare(
            "SELECT COUNT(*) FROM login_attempts
              WHERE succeeded = 0
                AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
                AND (username = ? OR ip_address = ?)"
        );
        $stmt->execute([DFMS_LOGIN_WINDOW_SECONDS, $username, dfms_client_ip_safe()]);
        return ((int) $stmt->fetchColumn()) >= DFMS_LOGIN_MAX_ATTEMPTS;
    } catch (Throwable $e) {
        // If the throttle table is unavailable, fail OPEN for availability —
        // but record it loudly, because a silent loss of throttling matters.
        error_log('Login throttle check failed: ' . $e->getMessage());
        return false;
    }
}

function dfms_record_login_attempt($username, $succeeded) {
    try {
        $conn = getDBConnection();
        $stmt = $conn->prepare(
            "INSERT INTO login_attempts (username, ip_address, succeeded, user_agent)
             VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([
            mb_substr((string) $username, 0, 50, 'UTF-8'),
            dfms_client_ip_safe(),
            $succeeded ? 1 : 0,
            mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255, 'UTF-8'),
        ]);
    } catch (Throwable $e) {
        error_log('Login attempt record failed: ' . $e->getMessage());
    }
}

// audit.php owns dfms_client_ip(), but auth.php must work before audit.php is
// loaded (login happens before tenant context exists).
function dfms_client_ip_safe() {
    if (function_exists('dfms_client_ip')) {
        return dfms_client_ip();
    }
    $remote = $_SERVER['REMOTE_ADDR'] ?? null;
    return $remote && filter_var($remote, FILTER_VALIDATE_IP) ? $remote : null;
}

/**
 * Verify credentials.
 *
 * @return true|string  true on success, or a machine-readable failure reason
 *                      ('invalid_credentials' | 'throttled').
 */
function verifyLogin($username, $password) {
    if (dfms_login_throttled($username)) {
        error_log("Login throttled for '$username' from " . (dfms_client_ip_safe() ?? 'unknown'));
        return 'throttled';
    }

    try {
        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT admin_id, username, password_hash, is_platform_admin FROM admin_users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Always run a hash comparison, even when the user does not exist, so
        // response timing does not reveal which usernames are registered.
        $hash = $user['password_hash'] ?? '$2y$10$usesomesillystringforeverusesomesillystringfooo';
        $valid = password_verify($password, $hash);

        if ($user && $valid) {
            // Regenerate the session ID on privilege change to prevent session fixation
            session_regenerate_id(true);

            $_SESSION['admin_id'] = (int) $user['admin_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['is_platform_admin'] = (int) $user['is_platform_admin'];
            $_SESSION['auth_started_at'] = time();
            $_SESSION['auth_last_seen_at'] = time();
            // The active farm and the role on it are resolved from live
            // membership by the tenant context, never stored as authority here.
            unset($_SESSION['farm_id'], $_SESSION['role']);

            dfms_record_login_attempt($username, true);
            if (function_exists('auditLog')) {
                auditLog('auth.login', 'security', (int) $user['admin_id'], null,
                    ['username' => $user['username']], ['force' => true]);
            }
            return true;
        }

        dfms_record_login_attempt($username, false);
        if (function_exists('auditLog')) {
            auditLog('auth.login_failed', 'security', null, null,
                ['username' => mb_substr((string) $username, 0, 50, 'UTF-8')], ['force' => true]);
        }
        return 'invalid_credentials';
    } catch (PDOException $e) {
        error_log('Login error: ' . $e->getMessage());
        return 'invalid_credentials';
    }
}

// Simple redirect for unauthorized access
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . 'index.php');
        exit();
    }
}

// Clears session state and the session cookie, using the cookie's ACTUAL
// parameters rather than a hardcoded path (baseline audit S-4).
function dfms_clear_session() {
    $_SESSION = [];

    if (ini_get('session.use_cookies') && isset($_COOKIE[session_name()])) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

// Improved logout function
function logout() {
    if (function_exists('auditLog') && isLoggedIn()) {
        auditLog('auth.logout', 'security', currentUserId(), null, null, ['force' => true]);
    }

    dfms_clear_session();

    // Redirect to login page using BASE_URL
    $redirectUrl = rtrim(BASE_URL, '/') . '/index.php';
    header('Location: ' . $redirectUrl);
    exit();
}
