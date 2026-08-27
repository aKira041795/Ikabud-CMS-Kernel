#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Builds a production-safe Daily Ledger deployment package (zip).
 *
 * Includes the entire modules/daily-ledger tree (minus docs/dev-only files)
 * and the templates/modules/daily-ledger tree, preserving relative paths so
 * it can be extracted directly over the live Bluehost application root.
 *
 * Usage: php create-daily-ledger-deploy-package.php [output.zip]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "Error: ZipArchive extension is required.\n");
    exit(1);
}

$root = realpath(__DIR__);
if ($root === false) {
    fwrite(STDERR, "Error: Cannot resolve project root.\n");
    exit(1);
}

$timestamp = date('Ymd-His');
$outputName = $argv[1] ?? ('daily-ledger-deploy-' . $timestamp . '.zip');
$outputPath = str_starts_with($outputName, '/') ? $outputName : ($root . '/' . $outputName);

// Root-relative path -> absolute path, with per-entry include/exclude predicates.
$roots = [
    'modules/daily-ledger' => [
        'exclude' => static fn (string $rel): bool =>
            str_starts_with($rel, 'tests/') || str_ends_with($rel, '/.gitkeep') || $rel === 'README.md',
    ],
    'templates/modules/daily-ledger' => [
        'exclude' => static fn (string $rel): bool => false,
    ],
];

$zip = new ZipArchive();
if ($zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Error: Cannot create {$outputPath}\n");
    exit(1);
}

$added = 0;
foreach ($roots as $relRoot => $opts) {
    $absRoot = $root . '/' . $relRoot;
    if (!is_dir($absRoot)) {
        fwrite(STDERR, "Warning: missing {$relRoot}\n");
        continue;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($absRoot, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        if (!$file->isFile()) {
            continue;
        }
        $rel = substr($file->getPathname(), strlen($absRoot) + 1);
        $rel = str_replace('\\', '/', $rel);
        if (($opts['exclude'])($rel)) {
            continue;
        }
        $entry = $relRoot . '/' . $rel;
        $zip->addFile($file->getPathname(), $entry);
        $added++;
    }
}

if ($zip->close() !== true) {
    fwrite(STDERR, "Error: Failed to finalize zip.\n");
    exit(1);
}

echo "Daily Ledger deploy package built: {$outputPath}\n";
echo "Files: {$added}\n\n";
echo "Upload + extract this zip over the live application root (preserves paths):\n";
echo "  modules/daily-ledger/...\n";
echo "  templates/modules/daily-ledger/...\n";
