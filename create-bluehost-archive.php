#!/usr/bin/env php
<?php

/**
 * Ikabud — Bluehost Deployment Archive Creator
 *
 * Creates a production-ready ZIP archive for uploading to Bluehost (cPanel).
 *
 * Usage:
 *   php create-bluehost-archive.php [output-filename.zip]
 *
 * Default output: application-kernel-os-YYYYMMDD-HHmmss.zip in the project root.
 *
 * What's included:
 *   - Core framework (kernel/, src/, config/, bootstrap.php)
 *   - Public web root (public/ including lock.php installer)
 *   - All modules (modules/)
 *   - Templates (templates/)
 *   - Database migrations + seeds (database/, migrations/, control-migrations/)
 *   - Composer dependencies (vendor/)
 *   - .htaccess files (root + public)
 *   - .env.example template
 *   - CLI tool (ikabud)
 *   - Scripts (scripts/)
 *
 * What's excluded:
 *   - .env (generated at install time)
 *   - storage/logs/*, storage/cache/*, storage/locks/*, storage/backups/*
 *   - storage/.installed (lock file)
 *   - Existing .zip archives at root
 *   - .git/, .github/, .vscode/, .windsurf/
 *   - android/ (mobile client — separate deploy)
 *   - docs/ (not needed for production)
 *   - tests/ (not needed for production)
 *   - Legacy backups (contact-form.bak_*, contact-form/ at root)
 *   - Node artifacts (node_modules/, builder-ui dev files)
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
$outputName = $argv[1] ?? ('application-kernel-os-' . date('Ymd-His') . '.zip');
// If relative, place in project root
if ($outputName[0] !== '/') {
    $outputPath = $root . '/' . $outputName;
} else {
    $outputPath = $outputName;
}

echo "╔══════════════════════════════════════════════════╗\n";
echo "║  Ikabud — Bluehost Deployment Archive Creator    ║\n";
echo "╚══════════════════════════════════════════════════╝\n\n";

// ── Directories to INCLUDE (relative to root) ───────────────────────────
$includeDirs = [
    'config',
    'control-migrations',
    'database',
    'kernel',
    'migrations',
    'modules',
    'public',
    'scripts',
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
    // Legacy / backup duplicates
    'contact-form/',
    'contact-form.bak',
    // Storage runtime artifacts
    'storage/.installed',
    'storage/logs/',
    'storage/cache/',
    'storage/locks/',
    'storage/backups/',
    'storage/module-exports/',
    // Node.js build artifacts inside modules
    'modules/cms/builder-ui/node_modules/',
    // This script itself
    'create-bluehost-archive.php',
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
function collectFiles(string $baseDir, string $relativePrefix, array &$files, int &$skipped, string $root, array $excludePrefixes, array $excludeFilePatterns): void
{
    $items = @scandir($baseDir);
    if ($items === false) return;

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;

        $fullPath = $baseDir . '/' . $item;
        $relativePath = $relativePrefix === '' ? $item : ($relativePrefix . '/' . $item);

        // Check prefix exclusions
        $excluded = false;
        foreach ($excludePrefixes as $prefix) {
            if (str_starts_with($relativePath, $prefix) || str_starts_with($relativePath . '/', $prefix)) {
                $excluded = true;
                break;
            }
        }
        if ($excluded) {
            $skipped++;
            continue;
        }

        if (is_dir($fullPath)) {
            // Recurse into directory
            collectFiles($fullPath, $relativePath, $files, $skipped, $root, $excludePrefixes, $excludeFilePatterns);
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
        collectFiles($dirPath, $dir, $files, $skipped, $root, $excludePrefixes, $excludeFilePatterns);
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
echo "║  BLUEHOST DEPLOYMENT STEPS                                  ║\n";
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
echo "║     → Installer runs schema, seeds data, generates .env    ║\n";
echo "║                                                             ║\n";
echo "║  4. After verifying the app works:                         ║\n";
echo "║     → DELETE public/lock.php (security requirement)        ║\n";
echo "║                                                             ║\n";
echo "║  5. Run incremental migrations via phpMyAdmin if needed:   ║\n";
echo "║     → database/migrations/005–009 (kernel features)        ║\n";
echo "║     → modules/*/database/migrations/ (module schemas)      ║\n";
echo "║                                                             ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
