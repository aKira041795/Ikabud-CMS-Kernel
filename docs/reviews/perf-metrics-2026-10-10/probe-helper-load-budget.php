<?php

declare(strict_types=1);

/**
 * Probe: of the ~7.65 ms spent loading 348 module helper files during route
 * registration, how much is structurally unavoidable?
 *
 * The Phase 1 premise was "routes are declarative, so registration shouldn't
 * need all the helper files". Reading loadModuleRoutes() shows that premise is
 * only half right: the loop calls loadModuleHelpers() for EVERY enabled module
 * because capability registration needs the callables that live in helpers.php.
 * A module that exposes no capability supplies no callable, so its helpers.php
 * is loaded at registration for no registration-time reason - it is only needed
 * if a request actually dispatches into that module.
 *
 * So the size of the available lever is not "7.65 ms" but "the share of 7.65 ms
 * attributable to modules with zero exposed capabilities". That is what this
 * probe measures, per module, using the real enabled set and the real loader.
 *
 *   php probe-helper-load-budget.php
 *
 * Read-only: boots, loads helpers in the same order the real path does, reports.
 * Writes a TSV beside itself for inspection.
 */

require __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../../../src/helpers/module-manager.php';

/** @return float milliseconds */
function msSince(int $startNs): float
{
    return (hrtime(true) - $startNs) / 1_000_000;
}

function exposeCount(array $module): int
{
    $exposes = $module['capabilities']['exposes'] ?? null;
    if (is_array($exposes)) {
        return count($exposes);
    }

    // Fall back to the manifest on disk when the loaded array does not carry it.
    $path = (string)($module['_path'] ?? '');
    $manifest = $path !== '' ? $path . '/module.json' : '';
    if ($manifest === '' || !is_file($manifest)) {
        return 0;
    }
    $decoded = json_decode((string)file_get_contents($manifest), true);
    $exposes = $decoded['capabilities']['exposes'] ?? null;

    return is_array($exposes) ? count($exposes) : 0;
}

$modules = getEnabledModules();

$rows = [];
$withCaps = ['count' => 0, 'files' => 0, 'bytes' => 0, 'ns' => 0];
$withoutCaps = ['count' => 0, 'files' => 0, 'bytes' => 0, 'ns' => 0];
$noHelper = 0;

foreach ($modules as $module) {
    $moduleId = (string)($module['id'] ?? '');
    $helpersFile = (string)($module['_path'] ?? '') . '/helpers.php';
    $exposes = exposeCount($module);

    if (!is_file($helpersFile)) {
        $noHelper++;
        continue;
    }

    $bytes = (int)@filesize($helpersFile);
    $startNs = hrtime(true);
    loadModuleHelpers($module);
    $ns = hrtime(true) - $startNs;

    $bucket = $exposes > 0 ? 'withCaps' : 'withoutCaps';
    ${$bucket}['count']++;
    ${$bucket}['files']++;
    ${$bucket}['bytes'] += $bytes;
    ${$bucket}['ns'] += $ns;

    $rows[] = [$moduleId, $exposes, $bytes, round($ns / 1_000_000, 3)];
}

// Worst offenders first: where the bytes actually are.
usort($rows, static fn(array $a, array $b): int => $b[2] <=> $a[2]);

$tsv = "module\texposes\tbytes\tload_ms\n";
foreach ($rows as $row) {
    $tsv .= implode("\t", $row) . "\n";
}
file_put_contents(__DIR__ . '/helper-load-budget.tsv', $tsv);

$totalNs = $withCaps['ns'] + $withoutCaps['ns'];
$totalMs = $totalNs / 1_000_000;

printf("enabled modules:            %d (%d declare no helpers.php)\n", count($modules), $noHelper);
printf("helper files loaded:        %d\n", $withCaps['count'] + $withoutCaps['count']);
printf("total bytes:                %.1f KB\n", ($withCaps['bytes'] + $withoutCaps['bytes']) / 1024);
printf("total load time:            %.2f ms\n\n", $totalMs);

printf("%-14s %7s %9s %10s %9s\n", 'bucket', 'modules', 'MB', 'ms', 'share');
printf("%-14s %7d %9.2f %10.2f %8.1f%%\n", 'exposes caps', $withCaps['count'], $withCaps['bytes'] / 1048576, $withCaps['ns'] / 1_000_000, $totalNs > 0 ? 100 * $withCaps['ns'] / $totalNs : 0);
printf("%-14s %7d %9.2f %10.2f %8.1f%%\n", 'no caps', $withoutCaps['count'], $withoutCaps['bytes'] / 1048576, $withoutCaps['ns'] / 1_000_000, $totalNs > 0 ? 100 * $withoutCaps['ns'] / $totalNs : 0);

echo "\ntop 12 by bytes:\n";
printf("  %-28s %8s %10s %9s\n", 'module', 'exposes', 'KB', 'load_ms');
foreach (array_slice($rows, 0, 12) as $row) {
    printf("  %-28s %8d %10.1f %9.3f\n", $row[0], $row[1], $row[2] / 1024, $row[3]);
}

echo "\nWrote helper-load-budget.tsv\n";
