<?php

/**
 * Release Manifest Generator
 *
 * Scans the repository and emits a structured release-manifest.json capturing
 * kernel version, DiSyL version, module/route/migration/template/test counts,
 * PHP version, supported DB profiles, and commit hash.
 *
 * Usage:  php scripts/generate-release-manifest.php [--output=release-manifest.json]
 * Exit:   0 on success, 1 on fatal error (missing critical paths).
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$output = $root . '/release-manifest.json';

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--output=')) {
        $output = $root . '/' . ltrim(substr($arg, 9), '/');
    }
}

// --- Kernel version from App.php ---
$kernelVersion = 'unknown';
$appPhp = $root . '/kernel/App.php';
if (file_exists($appPhp)) {
    $contents = file_get_contents($appPhp);
    if (preg_match("/KERNEL_VERSION\s*=\s*'([^']+)'/", (string)$contents, $m)) {
        $kernelVersion = $m[1];
    }
}

// --- DiSyL version ---
$disylVersion = 'unknown';
// Try multiple version sources
$grammarFile = $root . '/kernel/DiSyL/Grammar.php';
if (file_exists($grammarFile)) {
    $contents = file_get_contents($grammarFile);
    if (preg_match("/SCHEMA_VERSION\s*=\s*'([^']+)'/", (string)$contents, $m)) {
        $disylVersion = $m[1];
    }
}
if ($disylVersion === 'unknown') {
    $compilerFile = $root . '/kernel/DiSyL/Compiler/TemplateCompiler.php';
    if (file_exists($compilerFile)) {
        $contents = file_get_contents($compilerFile);
        if (preg_match("/COMPILER_VERSION\s*=\s*(\d+)/", (string)$contents, $m)) {
            $disylVersion = 'compiler-v' . $m[1];
        }
    }
}

// --- PHP version from composer.json ---
$phpVersion = 'unknown';
$composerJson = $root . '/composer.json';
if (file_exists($composerJson)) {
    $data = json_decode((string)file_get_contents($composerJson), true);
    $phpVersion = $data['require']['php'] ?? 'unknown';
}

// --- Module count ---
$moduleCount = 0;
$moduleDirs = glob($root . '/modules/*/module.json');
$moduleCount = is_array($moduleDirs) ? count($moduleDirs) : 0;

// --- Route count ---
$routeCount = 0;
$routeFiles = array_merge(
    glob($root . '/modules/*/routes.php') ?: [],
    glob($root . '/modules/*/*/routes.php') ?: [],
);
foreach ($routeFiles as $rf) {
    $contents = (string)file_get_contents($rf);
    // Count route definitions: 'GET' => '...', 'POST' => '...'
    $routeCount += preg_match_all("/'(GET|POST|PUT|DELETE|PATCH)'\s*=>/", $contents);
}

// --- Migration count ---
$migrationCount = 0;
$migrationFiles = array_merge(
    glob($root . '/migrations/*.sql') ?: [],
    glob($root . '/control-migrations/*.sql') ?: [],
    glob($root . '/modules/*/migrations/*.sql') ?: [],
    glob($root . '/modules/*/*/migrations/*.sql') ?: [],
);
$migrationCount = is_array($migrationFiles) ? count($migrationFiles) : 0;

// --- Template count ---
$templateCount = 0;
$templateFiles = findFilesRecursive($root . '/templates', '.disyl');
$templateCount = count($templateFiles);

// --- Test count ---
// Helper: recursively find files matching a suffix under a directory.
function findFilesRecursive(string $dir, string $suffix): array {
    $files = [];
    if (!is_dir($dir)) return $files;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), $suffix)) {
            $files[] = $file->getPathname();
        }
    }
    return $files;
}

// --- Test count ---
$testCount = 0;
$testFiles = findFilesRecursive($root . '/tests', '_test.php');
$testCount = count($testFiles);

// --- Test assertion count (from last manifest if available) ---
$assertionCount = null;
$lastManifest = $root . '/test_results/manifest.json';
if (file_exists($lastManifest)) {
    $data = json_decode((string)file_get_contents($lastManifest), true);
    $assertionCount = $data['assertions'] ?? null;
}

// --- Git commit hash ---
$commitHash = 'unknown';
$headFile = $root . '/.git/HEAD';
if (file_exists($headFile)) {
    $head = trim((string)file_get_contents($headFile));
    if (str_starts_with($head, 'ref: ')) {
        $refPath = $root . '/.git/' . substr($head, 5);
        if (file_exists($refPath)) {
            $commitHash = trim((string)file_get_contents($refPath));
        }
    } else {
        $commitHash = $head; // detached HEAD
    }
}

// --- DB profiles supported ---
$dbProfiles = ['compatibility', 'enterprise'];

// --- Assembled manifest ---
$manifest = [
    'schema'               => 'ikabud-release-manifest/1',
    'generated_at'         => date('c'),
    'commit'               => $commitHash,
    'kernel_version'       => $kernelVersion,
    'disyl_version'        => $disylVersion,
    'php_version'          => $phpVersion,
    'db_profiles'          => $dbProfiles,
    'modules'              => $moduleCount,
    'routes'               => $routeCount,
    'migrations'           => $migrationCount,
    'templates'            => $templateCount,
    'tests'                => $testCount,
    'assertions'           => $assertionCount,
];

file_put_contents(
    $output,
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
);

echo "Release manifest written to {$output}\n";
echo "  Kernel:    {$kernelVersion}\n";
echo "  DiSyL:     {$disylVersion}\n";
echo "  PHP:       {$phpVersion}\n";
echo "  Modules:   {$moduleCount}\n";
echo "  Routes:    {$routeCount}\n";
echo "  Migrations: {$migrationCount}\n";
echo "  Templates: {$templateCount}\n";
echo "  Tests:     {$testCount}\n";
echo "  DB profiles: " . implode(', ', $dbProfiles) . "\n";

exit(0);
