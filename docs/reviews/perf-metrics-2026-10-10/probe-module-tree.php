<?php

declare(strict_types=1);

/**
 * Probe: what does ONE module's helpers.php actually pull in?
 *
 * probe-helper-load-budget.php normalises registration load by file size and
 * finds modules far off the median: `cms` costs 70.83 ms to load from a 1.8 KB
 * helpers.php (461x the median ms/KB) and `ecommerce` 61.67 ms from 3.6 KB
 * (201x), while `moodle-integration` costs 6.91 ms from 102.8 KB (0.8x). So the
 * cost is not in the file being loaded - it is in the tree the file drags in.
 *
 * This prints that tree for one module: every file newly included, by size,
 * which is where the bytes (and therefore the compile/execute cost) sit.
 *
 *   php probe-module-tree.php cms
 *   php probe-module-tree.php ecommerce
 *
 * One module per process on purpose: loadModuleHelpers() is require_once, so a
 * second call in the same process would measure nothing.
 *
 * Read-only.
 */

require __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../../../src/helpers/module-manager.php';

$moduleId = (string)($argv[1] ?? '');
if ($moduleId === '') {
    fwrite(STDERR, "usage: php probe-module-tree.php <module-id>\n");
    exit(2);
}

$target = null;
foreach (getEnabledModules() as $module) {
    if ((string)($module['id'] ?? '') === $moduleId) {
        $target = $module;
        break;
    }
}

if ($target === null) {
    fwrite(STDERR, "module not found or not enabled: {$moduleId}\n");
    exit(2);
}

$root = (string)($target['_path'] ?? '');
$before = get_included_files();
$startNs = hrtime(true);
loadModuleHelpers($target);
$ms = (hrtime(true) - $startNs) / 1_000_000;
$after = get_included_files();

$new = [];
$totalBytes = 0;
foreach (array_diff($after, $before) as $file) {
    $bytes = (int)@filesize($file);
    $totalBytes += $bytes;
    $new[] = ['file' => $file, 'bytes' => $bytes];
}

usort($new, static fn(array $a, array $b): int => $b['bytes'] <=> $a['bytes']);

$rel = static function (string $path) use ($root): string {
    $base = dirname($root);
    if ($base !== '' && str_starts_with($path, $base . '/')) {
        return substr($path, strlen($base) + 1);
    }
    return $path;
};

printf("module:        %s\n", $moduleId);
printf("helpers.php:   %d B\n", (int)@filesize($root . '/helpers.php'));
printf("tree:          %d files, %.1f KB\n", count($new), $totalBytes / 1024);
printf("load time:     %.2f ms  (%.3f ms/KB of tree)\n\n", $ms, $totalBytes > 0 ? $ms / ($totalBytes / 1024) : 0);

$cumulative = 0;
printf("  %-62s %9s %8s\n", 'file', 'KB', 'cum %');
foreach (array_slice($new, 0, 22) as $entry) {
    $cumulative += $entry['bytes'];
    printf(
        "  %-62s %9.1f %7.1f%%\n",
        substr($rel($entry['file']), 0, 62),
        $entry['bytes'] / 1024,
        $totalBytes > 0 ? 100 * $cumulative / $totalBytes : 0
    );
}
if (count($new) > 22) {
    echo "  ... " . (count($new) - 22) . " more files\n";
}
