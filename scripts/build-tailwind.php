#!/usr/bin/env php
<?php
/**
 * Build Tailwind CSS bundle for ARK Workbench.
 *
 * 1. Runs Tailwind CLI to generate utility classes
 * 2. Appends explicit variant utilities (responsive, hover, focus)
 *    that Tailwind cannot extract from .disyl template files
 *
 * Usage: php scripts/build-tailwind.php
 *        composer run build:tailwind
 */

$rootDir = dirname(__DIR__);
$srcFile = $rootDir . '/public/assets/workbench/workbench-tailwind.src.css';
$outFile = $rootDir . '/public/assets/workbench/workbench-tailwind.css';
$profileSrcFile = $rootDir . '/storage/application-profiles/ark-workbench/assets/workbench-tailwind.src.css';
$profileOutFile = $rootDir . '/storage/application-profiles/ark-workbench/assets/workbench-tailwind.css';

echo "Building Tailwind CSS bundle...\n";

if (!copy($srcFile, $profileSrcFile)) {
    fwrite(STDERR, "ERROR: Unable to synchronize Tailwind source into the Workbench profile\n");
    exit(1);
}

// Step 1: Run Tailwind CLI
if (file_exists($outFile) && !unlink($outFile)) {
    fwrite(STDERR, "ERROR: Unable to remove stale Tailwind output\n");
    exit(1);
}

$cmd = sprintf(
    'cd %s && npx tailwindcss -c tailwind.config.js -i %s -o %s --minify 2>&1',
    escapeshellarg($rootDir),
    escapeshellarg($srcFile),
    escapeshellarg($outFile)
);

$output = [];
$exitCode = 0;
exec($cmd, $output, $exitCode);
echo implode("\n", $output) . "\n";

if ($exitCode !== 0 || !file_exists($outFile) || filesize($outFile) === 0) {
    fwrite(STDERR, "ERROR: Tailwind build failed\n");
    exit(1);
}

// Step 2: Append explicit variant utilities
$variants = <<<'ENDCSS'

/* ── Responsive variants (explicitly declared for .disyl compatibility) ── */
@media (min-width: 640px) {
  .sm\:grid-cols-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .sm\:grid-cols-4 { grid-template-columns: repeat(4, minmax(0, 1fr)); }
}
@media (min-width: 768px) {
  .md\:grid-cols-6 { grid-template-columns: repeat(6, minmax(0, 1fr)); }
  .md\:pb-0 { padding-bottom: 0px; }
  .md\:hidden { display: none; }
}
@media (min-width: 1024px) {
  .lg\:grid-cols-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .lg\:grid-cols-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
  .lg\:grid-cols-5 { grid-template-columns: repeat(5, minmax(0, 1fr)); }
  .lg\:hidden { display: none; }
}

/* ── Hover variants ── */
.hover\:bg-gray-50:hover { background-color: #f9fafb; }
.hover\:bg-gray-100:hover { background-color: #f3f4f6; }
.hover\:bg-gray-200:hover { background-color: #e5e7eb; }
.hover\:bg-blue-50:hover { background-color: #eff6ff; }
.hover\:bg-blue-700:hover { background-color: #1d4ed8; }
.hover\:bg-red-700:hover { background-color: #b91c1c; }
.hover\:bg-slate-700:hover { background-color: #334155; }
.hover\:shadow-md:hover { box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -2px rgba(0,0,0,0.1); }
.hover\:shadow:hover { box-shadow: 0 1px 3px 0 rgba(0,0,0,0.1), 0 1px 2px -1px rgba(0,0,0,0.1); }
.hover\:text-blue-600:hover { color: #2563eb; }
.hover\:text-blue-700:hover { color: #1d4ed8; }
.hover\:text-gray-900:hover { color: #111827; }
.hover\:text-white:hover { color: #fff; }
.hover\:text-red-900:hover { color: #7f1d1d; }

/* ── Focus variants ── */
.focus\:border-blue-500:focus { border-color: #3b82f6; }
.focus\:ring-1:focus { box-shadow: 0 0 0 1px rgba(59,130,246,0.5); }
.focus\:ring-blue-500:focus { --tw-ring-color: #3b82f6; }
ENDCSS;

file_put_contents($outFile, file_get_contents($outFile) . $variants);

if (!copy($outFile, $profileOutFile)) {
    fwrite(STDERR, "ERROR: Unable to publish Tailwind output into the Workbench profile\n");
    exit(1);
}

$size = filesize($outFile);
echo "Done: public and profile Tailwind assets ({$size} bytes each)\n";
