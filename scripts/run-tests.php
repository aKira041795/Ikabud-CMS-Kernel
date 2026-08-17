<?php

/**
 * Full test suite runner.
 * Discovers all tests/**\/*_test.php files recursively, runs each in a
 * sub-process with per-test timeout, captures output/exit-code, prints a
 * summary table, writes machine-readable JSON manifest, and reports slow tests.
 *
 * Usage:  php scripts/run-tests.php [--dir=tests/Unit]
 * Env:    TEST_TIMEOUT=120  (seconds, default 120)
 * Exit:   0 = all pass, 1 = one or more failures or errors.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$testDir = $root . '/tests';

// --- CLI flags ---
$subDir = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--dir=')) {
        $subDir = substr($arg, 6);
    }
}

/**
 * Recursively find all *_test.php files under a directory.
 */
function findTestFiles(string $dir): array {
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '_test.php')) {
            $files[] = $file->getPathname();
        }
    }
    return $files;
}

$searchPath = $subDir !== null
    ? (str_starts_with($subDir, '/') ? $subDir : $root . '/' . ltrim($subDir, '/'))
    : $testDir;

$files = findTestFiles($searchPath);
if ($files === false || count($files) === 0) {
    echo "No test files found in {$searchPath}\n";
    exit(1);
}

// Exclude the manual HTTP load-test tool from the default suite. load_test.php
// is a manual benchmark that fires real HTTP requests across all profiles
// (storefront/api/mixed/multitenant/checkout/concurrency ramp) and typically
// runs 125s+ — far beyond the per-test timeout — so it is not a CI unit test.
// It remains runnable directly:  php tests/load_test.php [profile] [concurrency]
// Or opt back into the suite explicitly: RUN_LOAD_TEST=1 php scripts/run-tests.php
if ($subDir === null && getenv('RUN_LOAD_TEST') === false) {
    $files = array_values(array_filter($files, fn(string $f): bool => basename($f) !== 'load_test.php'));
}
sort($files);

// Resolve display names relative to tests/
$displayName = fn(string $f): string => str_replace($testDir . '/', '', $f);

$pass    = 0;
$fail    = 0;
$error   = 0;
$skipped = 0;
$results = [];
$assertions = 0;

$width = max(array_map(
    fn(string $f) => strlen($displayName(basename($f, '.php'))),
    $files
));

$totalStart = microtime(true);
$timeout = (int) ($_ENV['TEST_TIMEOUT'] ?? $_SERVER['TEST_TIMEOUT'] ?? 120);
$slowThresholdMs = 5000; // 5 seconds

foreach ($files as $file) {
    $relName = $displayName($file);
    $name    = basename($file, '.php');
    $start   = microtime(true);

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
    // Clear the per-tenant bakeshop brand settings cache between tests so
    // saveModuleSettings('bakeshop', ...) + bakeshopBrandSettings() inside a
    // test are not shadowed by a previous test's cached branding (the cache
    // instance is keyed by tenant and persists on disk across subprocesses).
    foreach (glob($root . '/storage/cache/bakeshop_brand_settings_*', GLOB_ONLYDIR) as $cacheDir) {
        foreach (glob($cacheDir . '/*.cache') ?: [] as $cacheFile) {
            @unlink($cacheFile);
        }
    }
    // Clear the per-tenant CMS customizer persistent caches between tests.
    // cms_theme_test / cms_customizer_*_test upsert customizer sections via
    // direct DB writes and then read them back through cmsCustomizerSectionRecord(),
    // which serves from the persistent cache (cms_customizer_{scope}_t{tid},
    // TTL 300s) when present. A stale entry written by an earlier test in the
    // same CI run makes the section read return old defaults, so the canonical
    // entity presentation / theme settings assertions fail. Wipe those cache
    // files (including .tag_*.idx tag indexes) before each test so reads always
    // hit the DB.
    foreach (glob($root . '/storage/cache/cms_customizer_*', GLOB_ONLYDIR) as $cacheDir) {
        foreach (glob($cacheDir . '/*') ?: [] as $cacheFile) {
            if (is_file($cacheFile)) {
                @unlink($cacheFile);
            }
        }
    }
    // Clear the CMS derived public-context cache (cms_t{tid}) between tests.
    // cmsPublicContext() caches a derived context (theme + entity presentation +
    // theme settings merged) keyed on theme/scope factors with TTL 120s. A stale
    // entry from an earlier test makes cms_theme_test's post-upsert
    // cmsPublicContext() return the previous defaults. Tag invalidation is not
    // relied on here; wipe the cache dir so the next test rebuilds fresh.
    foreach (glob($root . '/storage/cache/cms_t*', GLOB_ONLYDIR) as $cacheDir) {
        foreach (glob($cacheDir . '/*') ?: [] as $cacheFile) {
            if (is_file($cacheFile)) {
                @unlink($cacheFile);
            }
        }
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $env = null;
    if (in_array($relName, ['pal_reconciliation_test.php', 'pal_service_integration_test.php'], true)
        && getenv('PAL_TENANT_ID') === false
    ) {
        $env = ['PAL_TENANT_ID' => '502'];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_scalar($value)) {
                $env[(string)$key] = (string)$value;
            }
        }
    }

    $process = proc_open(['php', $file], $descriptors, $pipes, $root, $env);

    if (!is_resource($process)) {
        $ms = (int) round((microtime(true) - $start) * 1000);
        $results[] = [
            'file'       => $relName,
            'name'       => $name,
            'status'     => 'ERROR',
            'ms'         => $ms,
            'assertions' => 0,
            'output'     => '',
        ];
        $error++;
        echo "[ERROR] {$relName} ({$ms}ms)\n";
        continue;
    }

    fclose($pipes[0]);

    // Per-test timeout via stream_select / non-blocking read with deadline
    $stdout = '';
    $stderr = '';
    $deadline = microtime(true) + $timeout;
    $timedOut = false;

    while (true) {
        $now = microtime(true);
        $remaining = max(0, (int)(($deadline - $now) * 1_000_000));
        $read = [];
        foreach ([1, 2] as $pipeIndex) {
            if (isset($pipes[$pipeIndex]) && is_resource($pipes[$pipeIndex])) {
                $read[] = $pipes[$pipeIndex];
            }
        }
        if ($read === []) {
            break;
        }
        $write = null;
        $except = null;
        $waitSeconds = intdiv($remaining, 1_000_000);
        $waitMicroseconds = $remaining % 1_000_000;

        $changed = stream_select($read, $write, $except, $waitSeconds, $waitMicroseconds);

        if ($changed === false) {
            break; // stream_select error
        }

        if ($changed === 0) {
            // Timeout reached
            $timedOut = true;
            break;
        }

        // Read available data
        if (isset($pipes[1]) && in_array($pipes[1], $read, true)) {
            $chunk = @fread($pipes[1], 8192);
            if ($chunk === false || $chunk === '') {
                // stdout closed
                fclose($pipes[1]);
                unset($pipes[1]);
            } else {
                $stdout .= $chunk;
            }
        }
        if (isset($pipes[2]) && in_array($pipes[2], $read, true)) {
            $chunk = @fread($pipes[2], 8192);
            if ($chunk === false || $chunk === '') {
                fclose($pipes[2]);
                unset($pipes[2]);
            } else {
                $stderr .= $chunk;
            }
        }

        // All pipes closed → process finished
        if (!isset($pipes[1]) && !isset($pipes[2])) {
            break;
        }
    }

    // Close remaining pipes
    foreach ([1, 2] as $idx) {
        if (isset($pipes[$idx])) {
            @fclose($pipes[$idx]);
        }
    }

    if ($timedOut) {
        // Terminate the process
        proc_terminate($process, defined('SIGTERM') ? SIGTERM : 15);
        // Give it 5s grace, then SIGKILL
        usleep(5000000);
        $status = proc_get_status($process);
        if ($status !== false && $status['running']) {
            proc_terminate($process, defined('SIGKILL') ? SIGKILL : 9);
        }
        proc_close($process);

        $ms = (int) round((microtime(true) - $start) * 1000);
        $output = trim((string)$stdout) . "\n[TIMEOUT after {$timeout}s]";
        $results[] = [
            'file'       => $relName,
            'name'       => $name,
            'status'     => 'TIMEOUT',
            'ms'         => $ms,
            'assertions' => 0,
            'output'     => $output,
        ];
        $fail++;
        echo "[TIMEOUT] {$relName} ({$ms}ms)\n";
        continue;
    }

    // Process finished normally
    $exitCode = proc_close($process);
    $ms       = (int) round((microtime(true) - $start) * 1000);
    $output   = trim((string)$stdout . ($stderr !== '' ? "\n[stderr] " . trim((string)$stderr) : ''));

    // Extract assertion count from output if present
    $testAssertions = 0;
    if (preg_match('/Assertions:\s*(\d+)/', $output, $m)) {
        $testAssertions = (int) $m[1];
        $assertions += $testAssertions;
    }

    if ($exitCode === 0) {
        $status = 'PASS';
        $pass++;
    } else {
        $status = 'FAIL';
        $fail++;
    }

    $results[] = [
        'file'       => $relName,
        'name'       => $name,
        'status'     => $status,
        'ms'         => $ms,
        'assertions' => $testAssertions,
        'output'     => $output,
    ];
    echo "[{$status}] {$relName} ({$ms}ms)\n";
    if (function_exists('flush')) {
        flush();
    }
}

$totalMs = (int) round((microtime(true) - $totalStart) * 1000);

// Summary table
echo "\n";
echo str_pad('Test', $width + 2) . str_pad('Status', 8) . "  Time\n";
echo str_repeat('-', $width + 2 + 8 + 8) . "\n";

foreach ($results as $r) {
    $label = match ($r['status']) {
        'PASS'    => "\033[32mPASS\033[0m",
        'FAIL'    => "\033[31mFAIL\033[0m",
        'TIMEOUT' => "\033[31mTIMEOUT\033[0m",
        default   => "\033[33mERROR\033[0m",
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
echo "  ({$totalMs}ms)\n";

// Slow-test report
$slowTests = array_filter($results, fn($r) => $r['ms'] >= $slowThresholdMs);
if (count($slowTests) > 0) {
    echo "\n\033[33mSlow tests (>={$slowThresholdMs}ms):\033[0m\n";
    foreach ($slowTests as $r) {
        echo "  {$r['name']} — {$r['ms']}ms\n";
    }
}

echo "\n";

// --- Machine-readable JSON manifest ---
$manifestDir = $root . '/test_results';
if (!is_dir($manifestDir)) {
    @mkdir($manifestDir, 0755, true);
}
$manifest = [
    'suite'         => 'ikabud',
    'timestamp'     => date('c'),
    'duration_ms'   => $totalMs,
    'php_version'   => PHP_VERSION,
    'timeout_s'     => $timeout,
    'slow_threshold_ms' => $slowThresholdMs,
    'files'         => count($files),
    'passed'        => $pass,
    'failed'        => $fail,
    'errors'        => $error,
    'skipped'       => $skipped,
    'assertions'    => $assertions,
    'tests'         => $results,
];
file_put_contents(
    $manifestDir . '/manifest.json',
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
);

exit(($fail > 0 || $error > 0) ? 1 : 0);
