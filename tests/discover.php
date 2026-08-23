<?php

declare(strict_types=1);

/**
 * Test Discovery Runner
 *
 * Scans tests subdirectories for test files and runs each.
 * Usage: php tests/discover.php [--module=NAME] [--list] [--failed-only]
 */

$projectRoot = dirname(__DIR__);
$testDir = $projectRoot . '/tests';
$resultsDir = $projectRoot . '/test_results';

$options = getopt('', ['module:', 'list', 'failed-only', 'help', 'filter:']);

if (isset($options['help'])) {
    echo "Usage: php tests/discover.php [options]\n";
    echo "  --module=NAME     Run only tests in tests/NAME/\n";
    echo "  --filter=SUBSTR   Run only tests whose basename contains SUBSTR (e.g. --filter=bakeshop)\n";
    echo "  --list            List all discovered tests\n";
    echo "  --failed-only     Re-run only previously failed tests\n";
    echo "  --help            Show this help\n";
    exit(0);
}

// Discover test files
$discovered = [];
$moduleFilter = $options['module'] ?? null;
$failedOnly = isset($options['failed-only']);

// Scan flat tests/ root as the 'core' suite group (module suites like
// bakeshop/wms/cms live flat at tests root). Grouped under core/ so the
// subdir suites remain distinct and --filter can target any basename.
// Skipped when --module=NAME is active so that flag keeps its subdir-only
// semantics (run only tests in tests/NAME/).
if (!$moduleFilter) {
    foreach (glob($testDir . '/*_test.php') ?: [] as $file) {
        $base = basename($file);
        if (str_contains($base, '_seed_') || str_contains($base, '_interactive') || str_contains($base, '_helper')) {
            continue;
        }
        $rel = str_replace($projectRoot . '/', '', $file);
        $key = 'core/' . basename($file, '.php');
        $discovered[$key] = ['file' => $file, 'rel' => $rel];
    }
}

// Dynamically scan tests/ subdirectories for *_test.php files
$skipDirs = ['harness', 'browser', 'ai', 'test_results', 'bench'];
$testSubdirs = glob($testDir . '/*', GLOB_ONLYDIR) ?: [];
foreach ($testSubdirs as $subdir) {
    $dir = basename($subdir);
    if ($moduleFilter && $dir !== $moduleFilter) continue;
    if (in_array($dir, $skipDirs, true)) continue;
    $path = $testDir . '/' . $dir;
    if (!is_dir($path)) continue;
    $files = glob($path . '/*_test.php');
    foreach ($files as $file) {
        $base = basename($file);
        if (str_contains($base, '_seed_') || str_contains($base, '_interactive')) {
            continue;
        }
        $rel = str_replace($projectRoot . '/', '', $file);
        $key = $dir . '/' . basename($file, '.php');
        $discovered[$key] = ['file' => $file, 'rel' => $rel];
    }
}

// Apply name filter (matches test basenames across core + subdir suites)
$nameFilter = $options['filter'] ?? null;
if ($nameFilter !== null && $nameFilter !== '') {
    foreach ($discovered as $key => $info) {
        if (!str_contains(basename($info['file']), $nameFilter)) {
            unset($discovered[$key]);
        }
    }
}

if (empty($discovered)) {
    echo "No test files discovered.\n";
    if ($moduleFilter) echo "  Module filter: {$moduleFilter}\n";
    exit(0);
}

// List-only mode
if (isset($options['list'])) {
    echo "Discovered " . count($discovered) . " test files:\n";
    foreach ($discovered as $key => $info) {
        echo "  {$info['rel']}\n";
    }
    exit(0);
}

// Previous failures filter
$prevSummaryFile = $resultsDir . '/discover-summary.json';
$prevFailed = [];
if ($failedOnly && file_exists($prevSummaryFile)) {
    $prevData = json_decode(file_get_contents($prevSummaryFile), true);
    foreach ($prevData['suites'] ?? [] as $key => $info) {
        if ($info['failed'] > 0) {
            $prevFailed[$key] = true;
        }
    }
    if (empty($prevFailed)) {
        echo "No previous failures found.\n";
        exit(0);
    }
    $discovered = array_intersect_key($discovered, $prevFailed);
    echo "Re-running " . count($discovered) . " previously failed test(s)\n";
}

// Run each test
$results = [];
$allPassed = 0;
$allFailed = 0;
$startTime = microtime(true);

$line = str_repeat('=', 40);
echo "\n{$line}\n";
echo "  DISCOVER - Running " . count($discovered) . " test suite(s)\n";
echo "  Started: " . date('Y-m-d H:i:s') . "\n";
echo "{$line}\n\n";

foreach ($discovered as $key => $info) {
    echo "-- {$info['rel']} --\n";

    $cmd = 'php ' . escapeshellarg($info['file']) . ' 2>&1';
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($cmd, $descriptors, $pipes, $projectRoot);
    if (!is_resource($process)) {
        echo "  FAILED to start process\n";
        $results[$key] = ['passed' => 0, 'failed' => 1, 'time' => 0];
        $allFailed++;
        continue;
    }

    $output = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    $passed = 0;
    $failed = 0;
    if (preg_match('/(\d+)\/(\d+)\s+passed/', $output, $m)) {
        $passed = (int)$m[1];
        $total = (int)$m[2];
        $failed = $total - $passed;
    } elseif ($exitCode !== 0) {
        $failed = 1;
    }

    $results[$key] = ['passed' => $passed, 'failed' => $failed, 'time' => 0];

    if ($failed > 0) {
        $allFailed++;
        echo "  FAILED ({$passed}/" . ($passed + $failed) . ")\n";
    } else {
        $allPassed++;
        echo "  PASSED ({$passed}/" . ($passed + $failed) . ")\n";
    }

    if ($failed > 0) {
        $lines = explode("\n", trim($output . $stderr));
        $tail = array_slice($lines, -6);
        foreach ($tail as $line) {
            if (trim($line) !== '') echo "    " . trim($line) . "\n";
        }
    }
    echo "\n";
}

// Summary
$elapsed = round((microtime(true) - $startTime) * 1000);
$totalSuites = count($discovered);

echo "{$line}\n";
echo "  DISCOVER SUMMARY\n";
echo "  {$allPassed}/{$totalSuites} suites passed";
if ($allFailed > 0) echo ", {$allFailed} failed";
echo "\n";
echo "  Time: " . round($elapsed / 1000, 2) . "s\n";
echo "  Results: test_results/discover-summary.json\n";
echo "{$line}\n\n";

$summary = [
    'runner' => 'discover',
    'started' => date('Y-m-d H:i:s'),
    'finished' => date('Y-m-d H:i:s'),
    'elapsed_ms' => round($elapsed, 1),
    'suites' => $results,
    'summary' => [
        'passed' => $allPassed,
        'failed' => $allFailed,
        'total' => $totalSuites,
    ],
];

file_put_contents(
    $resultsDir . '/discover-summary.json',
    json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

exit($allFailed > 0 ? 1 : 0);
