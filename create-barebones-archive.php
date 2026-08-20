#!/usr/bin/env php
<?php

/**
 * Ikabud — Bare-Bones Kernel + DiSyL Deployment Archive Creator
 *
 * Creates a minimal production-ready ZIP archive containing ONLY the Kernel OS
 * and the DiSyL template engine. No application modules, no module templates,
 * no module migrations, and no module-owned web assets.
 *
 * Usage:
 *   php create-barebones-archive.php [output-filename.zip]
 *
 * Default output: application-kernel-barebones-YYYYMMDD-HHmmss.zip in the project root.
 *
 * What's included:
 *   - Core framework (kernel/ including kernel/DiSyL engine, src/, config/, bootstrap.php)
 *   - Public web root (public/index.php, lock.php installer, router.php, kernel JS under public/assets/js)
 *   - Kernel templates (templates/layouts, templates/pages)
 *   - Kernel migrations (migrations/, database/migrations, control-migrations/)
 *   - Composer dependencies (vendor/)
 *   - .htaccess files (root + public)
 *   - .env.example template
 *   - CLI tool (ikabud)
 *   - Bundled GUI companion module: modules/gui-settings/ + templates/modules/gui-settings/
 *
 * What's excluded:
 *   - modules/ (application modules — NOT included, except the bundled GUI companion gui-settings)
 *   - templates/modules/, templates/academic_similarity/, templates/project-audit-ledger/
 *   - Module-owned web assets (public/admin, public/assets/<module>, public/css, public/uploads,
 *     public/daily-ledger, public/moto-inventory)
 *   - Dev-only public utilities (debug-opcache.php, dev-cleanup.php, opcache-reset.php, _tmp_cache_flush.php)
 *   - scripts/ (one-off maintenance/dev utilities)
 *   - packages/ (optional module packages)
 *   - config/vhosts (dev-only Apache vhost configs)
 *   - database/seeds/ (seed data references module tables; the lock.php installer creates the admin)
 *   - .env (generated at install time)
 *   - storage/logs/*, storage/cache/*, storage/locks/*, storage/backups/*
 *   - storage/.installed (lock file)
 *   - Existing .zip archives at root
 *   - .git/, .github/, .vscode/, .windsurf/
 *   - android/ (mobile client — separate deploy)
 *   - docs/ (not needed for production)
 *   - tests/ (not needed for production)
 *   - This script itself
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('CLI only.');
}

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "Error: ZipArchive extension is required. Install php-zip.\n");
    exit(1);
}

$root = realpath(__DIR__);
if ($root === false) {
    fwrite(STDERR, "Error: Cannot resolve project root.\n");
    exit(1);
}

// ── Output path ─────────────────────────────────────────────────────────
$outputName = $argv[1] ?? ('application-kernel-barebones-' . date('Ymd-His') . '.zip');
// If relative, place in project root
if ($outputName[0] !== '/') {
    $outputPath = $root . '/' . $outputName;
} else {
    $outputPath = $outputName;
}

echo "╔══════════════════════════════════════════════════════╗\n";
echo "║  Ikabud — Bare-Bones Kernel + DiSyL Archive Creator  ║\n";
echo "╚══════════════════════════════════════════════════════╝\n\n";

// ── Directories to INCLUDE (relative to root) ───────────────────────────
$includeDirs = [
    'config',
    'control-migrations',
    'database',
    'kernel',
    'migrations',
    // Bundled GUI companion module — the only module shipped with the bare kernel
    'modules/gui-settings',
    // Its template must be walked directly too: the blanket 'templates/modules/'
    // exclusion prunes the parent dir, so the exception only works when the
    // walker starts inside the companion's own template folder.
    'templates/modules/gui-settings',
    'public',
    'src',
    'storage',
    'templates',
    'vendor',
];

// ── Root-level files to INCLUDE ─────────────────────────────────────────
$includeRootFiles = [
    '.htaccess',
    '.env.example',
    'bootstrap.php',
    'composer.json',
    'composer.lock',
    'ikabud',
];

// ── Paths/patterns to EXCLUDE (relative to root, prefix-matched) ────────
$excludePrefixes = [
    // Editor / dev tooling
    '.git/',
    '.github/',
    '.vscode/',
    '.windsurf/',
    // Mobile client
    'android/',
    // Documentation & tests (not needed in production)
    'docs/',
    'tests/',
    // Application modules — the bare-bones kernel ships WITHOUT modules
    // (the bundled GUI companion gui-settings bypasses this via $excludeExceptions)
    'modules/',
    // Optional module packages
    'packages/',
    // One-off maintenance/dev scripts
    'scripts/',
    // Dev-only Apache vhost configs
    'config/vhosts/',
    // Seed data references module-owned tables; the installer creates the admin user
    'database/seeds/',
    // Storage runtime artifacts
    'storage/.installed',
    'storage/logs/',
    'storage/cache/',
    'storage/locks/',
    'storage/backups/',
    'storage/module-exports/',
    // Module-owned public web assets
    'public/admin/',
    'public/assets/cms/',
    'public/assets/ecommerce/',
    'public/assets/guidance/',
    'public/assets/pal/',
    'public/assets/workbench/',
    'public/css/',
    'public/daily-ledger/',
    'public/moto-inventory/',
    'public/uploads/',
    // Dev-only public utilities
    'public/debug-opcache.php',
    'public/dev-cleanup.php',
    'public/opcache-reset.php',
    'public/_tmp_cache_flush.php',
    // Module-owned templates
    'templates/modules/',
    'templates/academic_similarity/',
    'templates/project-audit-ledger/',
    // This script itself
    'create-barebones-archive.php',
    'create-barebones-package.php',
];

// ── Paths allowed to bypass a blanket exclusion (bundled companions) ────
// The GUI companion module (gui-settings) is the only module shipped with the
// bare kernel, so it bypasses the blanket 'modules/' and 'templates/modules/'
// exclusions above.
$excludeExceptions = [
    'modules/gui-settings/',
    'templates/modules/gui-settings/',
];

// ── Filename patterns to skip everywhere ────────────────────────────────
$excludeFilePatterns = [
    '/\.zip$/i',            // zip archives
    '/\.gz$/i',             // gzip archives
    '/\.tar$/i',            // tar archives
    '/\.DS_Store$/i',       // macOS
    '/^Thumbs\.db$/i',     // Windows
];

// ── Build file list ─────────────────────────────────────────────────────
echo "Scanning project tree...\n";

$files = [];
$skipped = 0;

/**
 * Recursively collect files from a directory.
 */
function collectFiles(string $baseDir, string $relativePrefix, array &$files, int &$skipped, string $root, array $excludePrefixes, array $excludeFilePatterns, array $excludeExceptions = []): void
{
    $items = @scandir($baseDir);
    if ($items === false) return;

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;

        $fullPath = $baseDir . '/' . $item;
        $relativePath = $relativePrefix === '' ? $item : ($relativePrefix . '/' . $item);

        // Check prefix exclusions. A bundled module companion may bypass a
        // blanket exclusion (e.g. modules/ is excluded except gui-settings).
        $excluded = false;
        foreach ($excludePrefixes as $prefix) {
            if (!str_starts_with($relativePath, $prefix) && !str_starts_with($relativePath . '/', $prefix)) {
                continue;
            }
            $allowedByException = false;
            foreach ($excludeExceptions as $exception) {
                if ($relativePath === rtrim($exception, '/') || str_starts_with($relativePath, $exception)) {
                    $allowedByException = true;
                    break;
                }
            }
            if ($allowedByException) {
                break;
            }
            $excluded = true;
            break;
        }
        if ($excluded) {
            $skipped++;
            continue;
        }

        if (is_dir($fullPath)) {
            // Recurse into directory
            collectFiles($fullPath, $relativePath, $files, $skipped, $root, $excludePrefixes, $excludeFilePatterns, $excludeExceptions);
        } elseif (is_file($fullPath)) {
            // Check filename pattern exclusions
            $basename = basename($fullPath);
            $patternExcluded = false;
            foreach ($excludeFilePatterns as $pattern) {
                if (preg_match($pattern, $basename)) {
                    $patternExcluded = true;
                    break;
                }
            }
            if ($patternExcluded) {
                $skipped++;
                continue;
            }

            $files[] = [
                'full' => $fullPath,
                'relative' => $relativePath,
            ];
        }
    }
}

// Collect directories
foreach ($includeDirs as $dir) {
    $dirPath = $root . '/' . $dir;
    if (is_dir($dirPath)) {
        collectFiles($dirPath, $dir, $files, $skipped, $root, $excludePrefixes, $excludeFilePatterns, $excludeExceptions);
    } else {
        echo "  [skip] Directory not found: {$dir}/\n";
    }
}

// Collect root-level files
foreach ($includeRootFiles as $file) {
    $filePath = $root . '/' . $file;
    if (is_file($filePath)) {
        $files[] = [
            'full' => $filePath,
            'relative' => $file,
        ];
    } else {
        echo "  [skip] Root file not found: {$file}\n";
    }
}

$fileCount = count($files);
echo "  Found {$fileCount} files to archive ({$skipped} excluded).\n\n";

if ($fileCount === 0) {
    fwrite(STDERR, "Error: No files to archive.\n");
    exit(1);
}

// ── Create ZIP ──────────────────────────────────────────────────────────
echo "Creating archive: " . basename($outputPath) . "\n";

$zip = new ZipArchive();
$result = $zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
if ($result !== true) {
    fwrite(STDERR, "Error: Could not create ZIP archive (code: {$result}).\n");
    exit(1);
}

$progress = 0;
$lastPercent = -1;
foreach ($files as $entry) {
    $zip->addFile($entry['full'], $entry['relative']);
    $progress++;
    $percent = (int) (($progress / $fileCount) * 100);
    if ($percent !== $lastPercent && $percent % 10 === 0) {
        echo "  [{$percent}%] {$progress}/{$fileCount} files...\n";
        $lastPercent = $percent;
    }
}

// Add an empty .gitkeep for storage dirs that must exist after extraction
$emptyDirs = [
    'storage/cache/disyl/.gitkeep',
    'storage/logs/.gitkeep',
    'storage/locks/.gitkeep',
    'storage/backups/.gitkeep',
];
foreach ($emptyDirs as $placeholder) {
    $zip->addFromString($placeholder, '');
}

$zip->close();

$sizeBytes = filesize($outputPath);
$sizeMB = round($sizeBytes / 1048576, 2);

echo "\n";
echo "✓ Archive created successfully!\n";
echo "  Path: {$outputPath}\n";
echo "  Size: {$sizeMB} MB ({$sizeBytes} bytes)\n";
echo "  Files: {$fileCount}\n";

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  BARE-BONES KERNEL DEPLOYMENT STEPS                         ║\n";
echo "╠══════════════════════════════════════════════════════════════╣\n";
echo "║                                                             ║\n";
echo "║  1. Log into cPanel → MySQL Databases                      ║\n";
echo "║     → Create a new database + user, grant ALL privileges   ║\n";
echo "║                                                             ║\n";
echo "║  2. Upload ZIP via cPanel File Manager to public_html/     ║\n";
echo "║     → Extract the archive in place                         ║\n";
echo "║                                                             ║\n";
echo "║  3. Navigate to https://yourdomain.com/lock.php            ║\n";
echo "║     → Enter DB credentials + admin account details         ║\n";
echo "║     → Installer runs schema, creates admin, generates .env ║\n";
echo "║                                                             ║\n";
echo "║  4. After verifying the kernel host works:                 ║\n";
echo "║     → DELETE public/lock.php (security requirement)        ║\n";
echo "║                                                             ║\n";
echo "║  5. A GUI companion module (gui-settings) is bundled.      ║\n";
echo "║     Enable it in Admin -> Modules. Add other modules      ║\n";
echo "║     by copying them into modules/ + php ikabud migrate    ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
