<?php

/**
 * Full test suite runner.
 * Discovers all tests/*_test.php files, runs each in a sub-process,
 * captures output/exit-code, and prints a summary table.
 *
 * Usage:  php scripts/run-tests.php
 * Exit:   0 = all pass, 1 = one or more failures or errors.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$testDir = $root . '/tests';

$files = glob($testDir . '/*_test.php');
if ($files === false || count($files) === 0) {
    echo "No test files found in {$testDir}\n";
    exit(1);
}
sort($files);

$pass    = 0;
$fail    = 0;
$error   = 0;
$results = [];

$width = max(array_map(
    fn(string $f) => strlen(basename($f, '.php')),
    $files
));

$totalStart = microtime(true);

foreach ($files as $file) {
    $name  = basename($file, '.php');
    $start = microtime(true);

    // Reset module registry state before each test so settings written by one
    // test (e.g. active_theme, low_stock_threshold) cannot pollute the next.
    @unlink($root . '/storage/modules.json');
    // Also clear any persistent per-tenant CMS settings cache entries so that
    // saveModuleSettings() calls inside a test are immediately visible to the
    // same test process via readCmsSettings() (important when CMS_SETTINGS_CACHE_TTL > 0).
    foreach (glob($root . '/storage/cache/cms_settings_t*', GLOB_ONLYDIR) as $cacheDir) {
        foreach (glob($cacheDir . '/*.cache') ?: [] as $cacheFile) {
            @unlink($cacheFile);
        }
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open(['php', $file], $descriptors, $pipes, $root);

    if (!is_resource($process)) {
        $results[] = ['name' => $name, 'status' => 'ERROR', 'ms' => 0, 'output' => '', 'since' => $start];
        $error++;
        continue;
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    $ms     = (int) round((microtime(true) - $start) * 1000);
    $output = trim((string)$stdout . ($stderr !== '' ? "\n[stderr] " . trim((string)$stderr) : ''));

    if ($exitCode === 0) {
        $status = 'PASS';
        $pass++;
    } else {
        $status = 'FAIL';
        $fail++;
    }

    $results[] = ['name' => $name, 'status' => $status, 'ms' => $ms, 'output' => $output];
}

$totalMs = (int) round((microtime(true) - $totalStart) * 1000);

// Summary table
echo "\n";
echo str_pad('Test', $width + 2) . str_pad('Status', 8) . "  Time\n";
echo str_repeat('-', $width + 2 + 8 + 8) . "\n";

foreach ($results as $r) {
    $label = match ($r['status']) {
        'PASS'  => "\033[32mPASS\033[0m",
        'FAIL'  => "\033[31mFAIL\033[0m",
        default => "\033[33mERROR\033[0m",
    };
    echo str_pad($r['name'], $width + 2) . str_pad($r['status'], 8) . "  {$r['ms']}ms\n";

    // Print failing output indented under the test name
    if ($r['status'] !== 'PASS' && $r['output'] !== '') {
        foreach (explode("\n", $r['output']) as $line) {
            echo "    {$line}\n";
        }
    }
}

echo str_repeat('-', $width + 2 + 8 + 8) . "\n";
echo "\nTotal: " . count($files) . " files — {$pass} passed";
if ($fail > 0) {
    echo ", \033[31m{$fail} failed\033[0m";
}
if ($error > 0) {
    echo ", \033[33m{$error} errors\033[0m";
}
echo "  ({$totalMs}ms)\n\n";

exit(($fail > 0 || $error > 0) ? 1 : 0);
