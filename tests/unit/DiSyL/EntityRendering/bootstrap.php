<?php
declare(strict_types=1);

/**
 * Shared bootstrap for entity rendering test suites.
 * Loads autoloader, defines path constants, and sets up kernel autoloading.
 */

$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
$basePath = dirname(__DIR__, 4);

require_once $basePath . '/vendor/autoload.php';

if (!defined('BASE_PATH')) { define('BASE_PATH', $basePath); }
if (!defined('KERNEL_PATH')) { define('KERNEL_PATH', $basePath . '/kernel'); }
if (!defined('STORAGE_PATH')) { define('STORAGE_PATH', $basePath . '/storage'); }

spl_autoload_register(static function (string $class): void {
    $kernelPrefix = 'Ikabud\\Kernel\\';
    if (strncmp($class, $kernelPrefix, strlen($kernelPrefix)) !== 0) return;
    $relative = substr($class, strlen($kernelPrefix));
    $path = KERNEL_PATH . '/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($path)) { require_once $path; }
});

/**
 * Simple test assertion helper.
 */
function test_ok(string $label, bool $ok, string $detail = ''): void {
    global $test_pass, $test_fail;
    if (!isset($test_pass)) { $test_pass = 0; $test_fail = 0; }
    if ($ok) { $test_pass++; echo "  \xE2\x9C\x93 {$label}\n"; }
    else { $test_fail++; echo "  \xE2\x9C\x97 {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

function test_summary(string $suite): void {
    global $test_pass, $test_fail;
    $p = $test_pass ?? 0;
    $f = $test_fail ?? 0;
    echo "\n── [{$suite}] {$p} passed, {$f} failed ──\n";
    $test_pass = 0; $test_fail = 0;
}
