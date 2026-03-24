#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "ZipArchive extension is required.\n");
    exit(1);
}

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "Failed to resolve project root.\n");
    exit(1);
}

$sourceDir = $root . '/packages/cms-wordpress-importer';
if (!is_dir($sourceDir)) {
    fwrite(STDERR, "Extension source directory not found: {$sourceDir}\n");
    exit(1);
}

$outputDir = $root . '/storage/cms-extension-packages';
if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
    fwrite(STDERR, "Failed to create output directory: {$outputDir}\n");
    exit(1);
}

$outputPath = $outputDir . '/wordpress-importer.zip';
if (is_file($outputPath) && !unlink($outputPath)) {
    fwrite(STDERR, "Failed to remove existing archive: {$outputPath}\n");
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Failed to create archive: {$outputPath}\n");
    exit(1);
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($iterator as $item) {
    $fullPath = $item->getPathname();
    $relativePath = substr($fullPath, strlen($sourceDir) + 1);
    if ($item->isDir()) {
        $zip->addEmptyDir($relativePath);
        continue;
    }
    $zip->addFile($fullPath, $relativePath);
}

$zip->close();

fwrite(STDOUT, "Created {$outputPath}\n");