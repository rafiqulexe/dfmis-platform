<?php
// Minimal .env loader — no Composer dependency. Existing environment
// variables (e.g. set by the host) always win over the file.
function loadEnvFile($path)
{
    if (!file_exists($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}
loadEnvFile(__DIR__ . '/../.env');

// ---------------------------------------------------------------------------
// Environment
// ---------------------------------------------------------------------------
// APP_ENV drives error display. Anything other than an explicit "local" or
// "development" is treated as production, so a missing or misspelled value
// fails safe (errors hidden) rather than unsafe (stack traces to the browser).
define('APP_ENV', strtolower(getenv('APP_ENV') ?: 'production'));
define('APP_IS_DEV', in_array(APP_ENV, ['local', 'development', 'dev'], true));

// Baseline audit S-6: stock php.ini ships display_errors=On, and an uncaught
// TypeError leaked the absolute server path to the browser. Errors are always
// logged; they are only ever *displayed* in an explicitly non-production env.
ini_set('display_errors', APP_IS_DEV ? '1' : '0');
ini_set('display_startup_errors', APP_IS_DEV ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// ---------------------------------------------------------------------------
// Clock discipline — PHP side. See the DB side in getDBConnection() below.
//
// The application runs entirely in UTC and converts to the farm's timezone
// only at the moment of display (includes/i18n.php, includes/settings.php).
//
// WHY THIS IS NEEDED: before Phase 2 this process inherited the host's
// timezone (Europe/Berlin, +02:00) while MySQL's SYSTEM zone was +06:00 — so
// alerts.alert_time, a DATETIME with no zone of its own, was written by PHP
// date() on one clock and by the seeder's NOW() on another. Two clocks, one
// column. Pinning both ends to UTC makes every new write unambiguous.
//
// Historical rows are NOT rewritten; see docs/DFMIS_INTERNATIONAL_ARCHITECTURE.md.
date_default_timezone_set('UTC');

// ---------------------------------------------------------------------------
// Database configuration
// ---------------------------------------------------------------------------
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'dfms');
define('DB_CHARSET', getenv('DB_CHARSET') ?: 'utf8mb4');

// AI provider config (used by the veterinary assistant / farm advisor — Phase 5).
// Empty until a key is supplied; features check this and degrade gracefully.
define('ANTHROPIC_API_KEY', getenv('ANTHROPIC_API_KEY') ?: '');

// ---------------------------------------------------------------------------
// Application URL
// ---------------------------------------------------------------------------
// Baseline audit S-1: BASE_URL was built from an unvalidated Host header and
// feeds six redirects, so an attacker-controlled Host produced off-site
// redirects and poisonable absolute URLs.
//
// Resolution order:
//   1. APP_URL from the environment — explicit, always wins, no guessing.
//   2. Host header, but ONLY if it appears in APP_ALLOWED_HOSTS.
//   3. The first entry of APP_ALLOWED_HOSTS.
//   4. localhost.
// A Host that is not allow-listed is never reflected back into a URL.

function dfms_allowed_hosts()
{
    $raw = getenv('APP_ALLOWED_HOSTS') ?: '';
    $hosts = array_filter(array_map('trim', explode(',', $raw)));
    if (!$hosts) {
        // Development default. Production installs are expected to set
        // APP_URL or APP_ALLOWED_HOSTS explicitly.
        $hosts = ['localhost', 'localhost:8000', 'localhost:8010', '127.0.0.1', '127.0.0.1:8000'];
    }
    return array_map('strtolower', $hosts);
}

function detectBaseUrl()
{
    // 1. An explicit APP_URL removes all ambiguity — preferred in production.
    $configured = getenv('APP_URL');
    if ($configured) {
        return rtrim($configured, '/') . '/';
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? '') == 443
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $scheme = $isHttps ? 'https' : 'http';

    // 2/3. Only ever reflect a host we recognise.
    $allowed = dfms_allowed_hosts();
    $candidate = strtolower($_SERVER['HTTP_HOST'] ?? '');
    $host = in_array($candidate, $allowed, true) ? $candidate : ($allowed[0] ?? 'localhost');

    // Project root is the parent of this config/ directory on disk. Comparing it
    // against DOCUMENT_ROOT gives the URL path prefix the app is mounted under
    // (empty for the built-in server, "/Dairy_Farm_Management_Project" under XAMPP htdocs).
    $projectRoot = str_replace('\\', '/', dirname(__DIR__));
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'], '/')) : '';

    $path = '';
    if ($docRoot !== '' && strpos($projectRoot, $docRoot) === 0) {
        $path = substr($projectRoot, strlen($docRoot));
    }

    return $scheme . '://' . $host . rtrim($path, '/') . '/';
}

define('BASE_URL', detectBaseUrl());

// ---------------------------------------------------------------------------
// Database connection
// ---------------------------------------------------------------------------
// Baseline audit S-2: the DSN omitted a charset and left emulated prepares on.
// utf8mb4 is also a prerequisite for storing non-Latin names (Phase 12).
//
// Baseline audit C-10: this opened a brand-new connection on every call —
// roughly six per dashboard request. The handle is now created once per
// request and reused, which also means a transaction opened by one caller is
// visible to the next.
function getDBConnection()
{
    static $conn = null;
    if ($conn instanceof PDO) {
        return $conn;
    }
    try {
        $conn = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                // Real server-side prepares. Removes the multibyte escaping
                // class of bypass entirely.
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]
        );

        // Clock discipline — database side. TIMESTAMP columns are converted by
        // the engine using the SESSION time zone, so leaving it at SYSTEM made
        // every timestamp read depend on the host's regional settings. Pinning
        // it to UTC makes NOW(), CURDATE() and every TIMESTAMP read/write
        // deterministic and identical to the PHP side.
        //
        // The stored instant never changes — TIMESTAMP is UTC internally
        // either way. What changes is that it is now *read back* as UTC, which
        // is what the display layer expects before converting to farm time.
        $conn->exec("SET time_zone = '+00:00'");

        return $conn;
    } catch (PDOException $e) {
        error_log('Database connection failed: ' . $e->getMessage());

        // On the command line, exit with a NON-ZERO status. `exit("string")`
        // prints the string and exits 0, which would make a migration run or a
        // CI job report success after failing to reach the database at all.
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "Database connection failed. Check DB_HOST / DB_NAME / credentials.\n");
            exit(1);
        }

        // No detail to the user — the message must not reveal host, user or
        // schema names.
        if (function_exists('dfms_abort')) {
            dfms_abort(500, 'Database connection failed');
        }
        http_response_code(500);
        exit("We're having trouble connecting to the database. Please try again shortly.");
    }
}

// Builds an absolute application URL. BASE_URL already carries a trailing
// slash (baseline audit D-4 fixed the double separator this used to emit).
function url($path = '')
{
    return BASE_URL . ltrim($path, '/');
}
