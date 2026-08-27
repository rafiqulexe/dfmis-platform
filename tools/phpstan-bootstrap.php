<?php
// ============================================================================
// PHPStan bootstrap.
//
// Loaded before analysis so PHPStan knows about constants that are defined at
// runtime by config/database.php. Without this it reports every use of
// BASE_URL, APP_ENV and friends as an unknown constant, drowning real findings.
//
// This file is NEVER loaded by the application itself.
// ============================================================================

if (!defined('BASE_URL')) {
    define('BASE_URL', 'http://localhost/');
}
if (!defined('APP_ENV')) {
    define('APP_ENV', 'testing');
}
if (!defined('APP_IS_DEV')) {
    define('APP_IS_DEV', false);
}
foreach (['DB_HOST' => 'localhost', 'DB_USER' => 'root', 'DB_PASS' => '', 'DB_NAME' => 'dfms', 'DB_CHARSET' => 'utf8mb4'] as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}
if (!defined('ANTHROPIC_API_KEY')) {
    define('ANTHROPIC_API_KEY', '');
}
