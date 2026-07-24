<?php

/**
 * Verifies that the test runner produces a machine-readable JSON manifest
 * after suite completion.
 */

declare(strict_types=1);

$manifestPath = __DIR__ . '/../test_results/manifest.json';

// This test is meant to be run AFTER the full suite. If no manifest exists,
// skip with a note.
if (!file_exists($manifestPath)) {
    echo "SKIP: test_results/manifest.json not found. Run the full suite first.\n";
    echo "Assertions: 0\n";
    exit(0);
}

$manifest = json_decode(file_get_contents($manifestPath), true);
$assertions = 0;

// Required top-level keys
$required = ['suite', 'timestamp', 'duration_ms', 'php_version', 'timeout_s',
             'files', 'passed', 'failed', 'errors', 'skipped', 'assertions', 'tests'];

foreach ($required as $key) {
    if (!array_key_exists($key, $manifest)) {
        echo "FAIL: manifest.json missing required key '{$key}'\n";
        exit(1);
    }
    $assertions++;
}

// Verify suite identity
if ($manifest['suite'] !== 'ikabud') {
    echo "FAIL: suite should be 'ikabud', got '{$manifest['suite']}'\n";
    exit(1);
}
$assertions++;

// Verify timestamp is parseable
$ts = strtotime((string) $manifest['timestamp']);
if ($ts === false || $ts <= 0) {
    echo "FAIL: timestamp '{$manifest['timestamp']}' is not parseable\n";
    exit(1);
}
$assertions++;

// Verify tests array is present and each entry has required fields
if (!is_array($manifest['tests'])) {
    echo "FAIL: tests should be an array\n";
    exit(1);
}
$assertions++;

$testKeys = ['file', 'name', 'status', 'ms', 'assertions', 'output'];
foreach ($manifest['tests'] as $test) {
    foreach ($testKeys as $key) {
        if (!array_key_exists($key, $test)) {
            echo "FAIL: test entry missing required key '{$key}'\n";
            exit(1);
        }
        $assertions++;
    }
}

// Verify aggregate counts match
$statusCounts = ['PASS' => 0, 'FAIL' => 0, 'ERROR' => 0, 'TIMEOUT' => 0];
foreach ($manifest['tests'] as $test) {
    $s = $test['status'];
    $statusCounts[$s] = ($statusCounts[$s] ?? 0) + 1;
}
if ($statusCounts['PASS'] !== $manifest['passed']) {
    echo "FAIL: passed count mismatch: aggregate={$manifest['passed']}, counted={$statusCounts['PASS']}\n";
    exit(1);
}
$assertions++;
if (($statusCounts['FAIL'] + $statusCounts['TIMEOUT']) !== $manifest['failed']) {
    echo "FAIL: failed count mismatch: aggregate={$manifest['failed']}, counted=" . ($statusCounts['FAIL'] + $statusCounts['TIMEOUT']) . "\n";
    exit(1);
}
$assertions++;

echo "PASS: manifest.json contains all required fields and consistent aggregates.\n";
echo "Assertions: {$assertions}\n";
exit(0);
