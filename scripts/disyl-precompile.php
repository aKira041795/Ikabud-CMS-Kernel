<?php
/**
 * DiSyL Template Pre-compilation (Warm Compile)
 *
 * Compiles all .disyl templates into PHP classes so that production never
 * sees a first-request compile penalty.  Run after deploy, cache flush,
 * or tenant activation.
 *
 * Usage:
 *   php scripts/disyl-precompile.php [options]
 *
 * Options:
 *   --dir=<path>    Template root directory (default: auto-detect from modules + templates)
 *   --cleanup       Remove stale compiled files from older compiler versions
 *   --stats         Print cache statistics after compilation
 *   --dry-run       Show what would be compiled without writing files
 *   --verbose       Show each file as it's compiled
 */

require_once __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\DiSyL\Compiler\TemplateCache;
use Ikabud\Kernel\DiSyL\Compiler\TemplateCompiler;
use Ikabud\Kernel\DiSyL\v4\Parser;

$args = getopt('', ['dir:', 'cleanup', 'stats', 'dry-run', 'verbose', 'help']);

if (isset($args['help'])) {
    echo file_get_contents(__FILE__);
    exit(0);
}

$verbose = isset($args['verbose']);
$dryRun  = isset($args['dry-run']);
$cleanup = isset($args['cleanup']);
$showStats = isset($args['stats']) || $cleanup;

$root = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);

// Determine template directories to scan
$templateDirs = [];
if (isset($args['dir'])) {
    $templateDirs[] = realpath($args['dir']) ?: $args['dir'];
} else {
    // Auto-detect: templates/ dir + all module template dirs
    $candidates = [
        $root . '/templates',
    ];
    // Scan modules for template directories
    $modulesDir = $root . '/modules';
    if (is_dir($modulesDir)) {
        $modules = scandir($modulesDir);
        foreach ($modules as $m) {
            if ($m === '.' || $m === '..') continue;
            $modTemplates = $modulesDir . '/' . $m . '/templates';
            if (is_dir($modTemplates)) {
                $candidates[] = $modTemplates;
            }
        }
    }
    foreach ($candidates as $c) {
        if (is_dir($c)) {
            $templateDirs[] = $c;
        }
    }
}

if (empty($templateDirs)) {
    echo "No template directories found. Use --dir=<path> to specify.\n";
    exit(1);
}

// Collect all .disyl files
$files = [];
foreach ($templateDirs as $dir) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $file) {
        if ($file->getExtension() === 'disyl') {
            $files[] = $file->getRealPath();
        }
    }
}

echo "DiSyL Pre-compilation\n";
echo "=====================\n";
echo "Compiler version: " . TemplateCompiler::COMPILER_VERSION . "\n";
echo "Template dirs:    " . count($templateDirs) . "\n";
echo "Templates found:  " . count($files) . "\n";

if ($dryRun) {
    echo "\n[DRY RUN] Would compile:\n";
    foreach ($files as $f) {
        echo "  " . str_replace($root . '/', '', $f) . "\n";
    }
    exit(0);
}

echo "\n";

// Set up cache
$cacheDir = $root . '/storage/cache/compiled';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}
$cache = new TemplateCache($cacheDir, false);

// Run cleanup first if requested
if ($cleanup) {
    echo "Cleaning stale compiled files...\n";
    $removed = $cache->cleanup();
    echo "  Removed: {$removed} stale file(s)\n\n";
}

// Compile all templates
$compiled = 0;
$cached = 0;
$errors = 0;
$startTime = microtime(true);

foreach ($files as $file) {
    $relative = str_replace($root . '/', '', $file);
    try {
        $cache->get($file);
        $compiled++;
        if ($verbose) {
            echo "  ✓ {$relative}\n";
        }
    } catch (\Throwable $e) {
        $errors++;
        echo "  ✗ {$relative}: {$e->getMessage()}\n";
    }
}

$elapsed = round((microtime(true) - $startTime) * 1000, 1);

echo "\nResults:\n";
echo "  Compiled: {$compiled}\n";
echo "  Errors:   {$errors}\n";
echo "  Time:     {$elapsed}ms\n";

if ($showStats) {
    echo "\nCache statistics:\n";
    $stats = $cache->getStats();
    foreach ($stats as $key => $value) {
        if ($key === 'total_size') {
            $value = round($value / 1024, 1) . ' KB';
        }
        echo "  {$key}: {$value}\n";
    }
}

exit($errors > 0 ? 1 : 0);
