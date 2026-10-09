#!/usr/bin/env php
<?php
/**
 * Build the application Tailwind bundle used by DiSyL templates.
 *
 * Usage: php scripts/build-tailwind-app.php
 *        npm run build:tailwind:app
 */

declare(strict_types=1);

$rootDir = dirname(__DIR__);
$outDir = $rootDir . '/public/assets/tailwind';
$outFile = $outDir . '/app.css';
$sourceFile = tempnam(sys_get_temp_dir(), 'tailwind-app-');

if ($sourceFile === false) {
    fwrite(STDERR, "ERROR: Unable to create temporary Tailwind input\n");
    exit(1);
}

if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    @unlink($sourceFile);
    fwrite(STDERR, "ERROR: Unable to create {$outDir}\n");
    exit(1);
}

file_put_contents($sourceFile, "@tailwind base;\n@tailwind components;\n@tailwind utilities;\n");

$command = sprintf(
    'cd %s && npx tailwindcss -c tailwind.app.config.js -i %s -o %s --minify 2>&1',
    escapeshellarg($rootDir),
    escapeshellarg($sourceFile),
    escapeshellarg($outFile)
);

$output = [];
$exitCode = 0;
exec($command, $output, $exitCode);
@unlink($sourceFile);

echo implode("\n", $output) . "\n";
if ($exitCode !== 0 || !is_file($outFile) || filesize($outFile) === 0) {
    fwrite(STDERR, "ERROR: Tailwind application build failed\n");
    exit(1);
}

echo "Done: public/assets/tailwind/app.css (" . filesize($outFile) . " bytes)\n";
