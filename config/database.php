<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'dfms');

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