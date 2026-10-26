<?php
// ============================================================================
// LINT — php -l across every PHP file in the project.
//
//   php tools/lint.php            lint everything
//   php tools/lint.php --quiet    only report failures
//
// This exists as a PHP script rather than a shell one-liner so `composer lint`
// works identically on Windows, macOS and Linux, and so CI and the local
// developer run exactly the same code path.
//
// It deliberately has NO dependencies — it must keep working even when
// vendor/ is absent, which is the situation a developer is in immediately
// after cloning.
// ============================================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

$root = dirname(__DIR__);

// Read through $_SERVER rather than the bare $argv. The CLI SAPI populates
// both whatever register_argc_argv says, so this is not a behaviour change —
// but $argv is only *guaranteed* when that directive is on, and it must stay
// off here because PHP 8.5 deprecates deriving argv from the query string on
// every web request. $_SERVER is a superglobal that always exists, so this
// reads correctly under either setting and in either SAPI.
$quiet = in_array('--quiet', $_SERVER['argv'] ?? [], true);

// Directories that contain PHP we did not write, or that are not part of the
// application. vendor/ is third-party and its lint status is not ours to fix.
$skip = ['/vendor/', '/.git/', '/node_modules/'];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

$checked = 0;
$failed = 0;
$failures = [];

foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }

    $path = str_replace('\\', '/', $file->getPathname());
    foreach ($skip as $fragment) {
        if (strpos($path, $fragment) !== false) {
            continue 2;
        }
    }

    $checked++;
    $relative = ltrim(str_replace(str_replace('\\', '/', $root), '', $path), '/');

    // PHP_BINARY is the interpreter running this script, so the lint uses the
    // same PHP version the application will run under.
    $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname());
    exec($command . ' 2>&1', $output, $status);

    if ($status !== 0) {
        $failed++;
        $failures[] = ['file' => $relative, 'output' => implode("\n", $output)];
        echo "  FAIL  $relative\n";
        foreach (array_slice($output, 0, 3) as $line) {
            echo "        $line\n";
        }
    } elseif (!$quiet) {
        echo "  ok    $relative\n";
    }
    $output = [];
}

echo "\n";
echo "================================\n";
echo " lint: $checked file(s) checked, $failed failing\n";
echo "================================\n";

exit($failed === 0 ? 0 : 1);
