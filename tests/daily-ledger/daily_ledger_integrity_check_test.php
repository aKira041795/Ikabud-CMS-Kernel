<?php

declare(strict_types=1);

/**
 * Daily Ledger — the integrity check runs, reports every class it claims to, and writes nothing but its
 * own file.
 *
 * WHY IT EXISTS: every defect this week was found by a person noticing something odd. The checks were
 * one-off probes. This locks the combined check so it can be scheduled, and so a future change that breaks
 * it fails loudly here instead of silently.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-integrity-check-cli', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$base = dirname(__DIR__, 2);
$reportPath = sys_get_temp_dir() . '/dl-integrity-' . getmypid() . '.md';
$cmd = sprintf('php %s --quiet --out=%s 2>&1', escapeshellarg($base . '/tools/daily-ledger-integrity-check.php'),
    escapeshellarg($reportPath));
exec($cmd, $output, $exitCode);

$report = is_file($reportPath) ? (string)file_get_contents($reportPath) : '';

$h->section('the check runs and reports every class');

$h->test(
    'it exits with a signal a scheduler can act on',
    in_array($exitCode, [0, 1], true),
    'exit 0 = clean, 1 = needs attention, 2 = could not run (got ' . $exitCode . ')'
);
$h->test(
    'the report covers negative balances',
    strpos($report, 'negative_balances') !== false,
    'the symptom that took seven weeks to be believed must be on every run'
);
$h->test(
    'the report covers count coverage',
    strpos($report, 'count_coverage') !== false,
    'a variance can only come from a counted line, so coverage belongs beside every variance figure'
);
$h->test(
    'the report covers projection drift for each hub',
    strpos($report, 'reconcile_hub_') !== false,
    'the app understating dispatch against the delivery records is invisible without this'
);
$h->test(
    'the report covers deviation events in the last day',
    strpos($report, 'deviation_events_24h') !== false,
    'if a guard is bypassed, the audit action it should have written is the early warning'
);

$h->section('it is read-only');

$before = (int)(app()->db()->query('SELECT COUNT(*) FROM dl_commissary_product_ledger')->fetchColumn() ?: 0);
exec($cmd, $out2, $exit2);
$after = (int)(app()->db()->query('SELECT COUNT(*) FROM dl_commissary_product_ledger')->fetchColumn() ?: 0);
$h->test(
    'running it twice changes nothing in the ledger',
    $before === $after,
    'a monitoring tool that mutates data is a liability (' . $before . ' -> ' . $after . ')'
);
$h->test(
    'the second run is deterministic in its verdict',
    $exit2 === $exitCode || ($exitCode === 1 && $exit2 === 1),
    'a check that flips verdicts run-to-run cannot be scheduled'
);

@unlink($reportPath);
$h->done();
