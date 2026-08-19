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

// Database configuration — falls back to XAMPP/local defaults when no .env is present
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'dfms');

// AI provider config (used by the veterinary assistant / farm advisor — Phase 5).
// Empty until a key is supplied; features check this and degrade gracefully.
define('ANTHROPIC_API_KEY', getenv('ANTHROPIC_API_KEY') ?: '');

// Application URL configuration — auto-detected so the app works unmodified
// whether it's served from XAMPP htdocs, the PHP built-in dev server, or a real domain.
function detectBaseUrl()
{
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? '') == 443;
    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

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

// Create database connection
function getDBConnection()
{
    try {
        $conn = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME,
            DB_USER,
            DB_PASS
        );
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $conn;
    } catch (PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
        die("We're having trouble connecting to the database. Please try again shortly.");
    }
}

// Helper function for URLs
function url($path = '')
{
    return BASE_URL . '/' . ltrim($path, '/');
}