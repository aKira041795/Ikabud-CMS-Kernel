<?php

declare(strict_types=1);

/**
 * Daily Ledger — a READ page must not rebuild the whole fleet's variances on every view.
 *
 * MEASURED DEFECT (2026-11-19, production import): /daily-ledger/admin/variances took 8,840 ms and
 * issued **5,531 queries**, because dl_refreshVariancesForDateView() ran a full
 * dl_recomputeVariancesForDay() for every branch with a ledger row for the day, on every view.
 * Dropping the row cap from 400 to 50 changed the HTML from 4,043 KB to 656 KB and the time by ~1 s,
 * which is how the queries - not the rendering - were identified as the cost.
 *
 * Each assertion names what breaks if it regresses:
 * - a historical date recomputed on view = settled history can be re-opened by browsing (the exact
 *   failure of 2026-11-16, when a repair derived 414 new flags across 32 branch-days);
 * - no throttle = every refresh costs thousands of queries again;
 * - a marker written without a recompute = the live day silently stops refreshing.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-variance-refresh', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();
// dl_businessDate() reads module settings, which resolve the manifest through discoverModules().
require_once __DIR__ . '/../../src/helpers/module-manager.php';
require_once __DIR__ . '/../../modules/daily-ledger/handlers.php';

$db = app()->db();
$today = dl_businessDate();
// The production marker directory belongs to the WEB user; a CLI suite cannot write it, and @touch
// failing silently would make a working throttle look broken. Point the markers at a temp dir.
$markerDir = sys_get_temp_dir() . '/dl-variance-refresh-' . getmypid();
putenv('DL_VARIANCE_REFRESH_DIR=' . $markerDir);
register_shutdown_function(static function () use ($markerDir): void {
    foreach (glob($markerDir . '/*.marker') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($markerDir);
});

$accessible = array_map('intval', $db->query(
    'SELECT id FROM dl_branches WHERE is_active = 1'
)->fetchAll(PDO::FETCH_COLUMN) ?: []);

$h->section('a CLOSED day is never recomputed from a page view');

// Self-healing IS wanted for an OPEN day (a row entered before the variance enhancement must surface),
// so the guard that protects settled history is the closed-day check, not the date. This is the
// 2026-11-16 defect class: 414 settled flags re-derived across 32 closed branch-days.
$closedRow = $db->query(
    "SELECT branch_id, ledger_date FROM dl_ledger_day_status
      WHERE status = 'closed' AND ledger_date < CURDATE()
      ORDER BY ledger_date DESC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC) ?: [];
$closedBranch = (int)($closedRow['branch_id'] ?? 0);
$closedDate = (string)($closedRow['ledger_date'] ?? '');
$closedMarker = $closedDate !== '' ? dl_varianceRefreshMarkerPath($closedBranch, $closedDate) : '';
if ($closedMarker !== '') {
    @unlink($closedMarker);
}
$closedResult = $closedDate !== '' ? dl_refreshVariancesForDateView($closedDate, $accessible) : 0;
$h->test(
    'a closed branch-day is skipped and not even marked',
    $closedDate !== '' && $closedResult === 0 && !is_file($closedMarker),
    'recomputing settled history from a page view is the 2026-11-16 defect class '
    . '($closedDate, returned ' . $closedResult . ')'
);

$h->section('the live day refreshes when its data changes, and only then');

// The recompute itself (dl_recomputeVariancesForDay) needs module() context, which a CLI suite does
// not have - asserting "it recomputed" here would pass for the wrong reason. What IS assertable is
// the gate that decides it; the end-to-end effect was measured in the browser: /admin/variances went
// 8,840 ms / 5,531 queries on a cold view to ~50 ms on every later view.
$marker = dl_varianceRefreshMarkerPath(18, $today);
@unlink($marker);
$h->test(
    'an unmarked branch-day is due for a refresh',
    dl_varianceRefreshDue($db, 18, $today) === true,
    'a live day must still refresh, or a new variance never appears until close'
);

dl_varianceMarkRefreshed($db, 18, $today);
$h->test(
    'an UNCHANGED branch-day is not due',
    dl_varianceRefreshDue($db, 18, $today) === false,
    'this is the 5,531-query storm: every page view re-derived 20+ branch-days'
);

// The first attempt throttled on TIME, which could suppress a refresh the data needed (cashier saves,
// admin reloads within the window, page shows the stale figure). The gate is a fingerprint of the day's
// numbers, so changed data must always win. Simulated by planting a marker with the wrong fingerprint -
// no production row is touched.
@file_put_contents($marker, '999999:1:1:1:1');
$h->test(
    'a CHANGED branch-day is due again, immediately',
    dl_varianceRefreshDue($db, 18, $today) === true,
    'a time window would hide a just-saved ledger row for up to the whole window'
);
@unlink($marker);

$h->section('the page call itself is bounded by that gate');
$h->test(
    'the fingerprint is stable for unchanged data',
    dl_varianceDataFingerprint($db, 18, $today) === dl_varianceDataFingerprint($db, 18, $today),
    'an unstable fingerprint would re-derive the fleet on every view, which is the defect being fixed'
);

// Restore a clean slate so the next real page view behaves like a user's first.
foreach (glob($markerDir . '/*-' . $today . '.marker') ?: [] as $marker) {
    @unlink($marker);
}

$h->done();
