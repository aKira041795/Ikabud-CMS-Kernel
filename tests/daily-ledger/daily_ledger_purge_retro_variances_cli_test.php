<?php

declare(strict_types=1);

/**
 * Daily Ledger — retro-derived variance purge: proof semantics and cost
 *
 * This worker exists to undo what an EARLIER build of repair-closed-shift-status.php did:
 * it re-derived variances for days that had been closed months earlier (measured 2026-11-16,
 * tenant 207: 414 unreviewed flags across 32 branch-days). The repair no longer derives
 * anything; this removes what an old run already wrote, and it must never touch a real flag
 * the branch or the office created on a past day.
 *
 * Two things are asserted here:
 *
 *   PROOF   — the row is only eligible when a `finalize_shift` audit row carrying
 *             new_data LIKE '%repair_closed_day_sweep%' exists for the same branch-day and the
 *             same calendar day as the flag, and no human has touched the flag. Rows that look
 *             like junk but carry no such proof are counted and left alone.
 *
 *   COST    — the proof used to be a correlated EXISTS in the WHERE clause, evaluated once per
 *             candidate and again as NOT EXISTS, with two more passes for the shape/sample
 *             queries. Leading-wildcard LIKE cannot use an index, so the dry run took 497
 *             SECONDS on the imported live database (4,709 flags, 1,006 candidates). It is now
 *             two cheap queries and a prefix match in PHP. The timing assertion below is the
 *             guard against that shape creeping back: the ceiling is ~50x the measured 0.4 s,
 *             and ~25x inside the old cost.
 *
 * Integration mode — the CLI is executed against the real tenant (207) in DRY-RUN mode only.
 * Nothing is deleted: the only --apply exercised here is bounded and rolled back conceptually by
 * using --limit=0-ineligible (dry run), so the suite cannot damage an imported live copy.
 */

ob_start();

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('daily-ledger-purge-retro-variances', TestHarness::MODE_INTEGRATION, 'baronledger.test');

ob_end_clean();

$h->fingerprint('modules/daily-ledger/cli/purge-retro-derived-variances.php');
$h->fingerprint('modules/daily-ledger/cli/repair-closed-shift-status.php');

$base = $h->basePath();
$cliPath = $base . '/modules/daily-ledger/cli/purge-retro-derived-variances.php';
$repairPath = $base . '/modules/daily-ledger/cli/repair-closed-shift-status.php';
// Shape assertions must read CODE, not prose: both workers carry long doc blocks that describe
// the old correlated EXISTS and the repair's removed variance derivation by name.
$cliSrc = php_strip_whitespace($cliPath);
$repairSrc = php_strip_whitespace($repairPath);

// ─── Safety: what makes a row eligible ──────────────────────────────────
$h->section('Eligibility predicate');

$h->test('a flag is only eligible on a PAST business date', str_contains($cliSrc, 'vf.ledger_date < CURDATE()'));
$h->test('a flag is only eligible when created today', str_contains($cliSrc, 'vf.created_at >= CURDATE()'));
$h->test(
    'a human-reviewed flag is never eligible',
    str_contains($cliSrc, "vf.resolution_status = 'unreviewed'")
    && str_contains($cliSrc, 'vf.is_reviewed = 0')
    && str_contains($cliSrc, 'vf.reviewed_by IS NULL')
    && str_contains($cliSrc, 'vf.review_note IS NULL')
);
$h->test(
    'eligibility still requires the repair audit proof',
    str_contains($cliSrc, "new_data LIKE '%repair_closed_day_sweep%'")
    && str_contains($cliSrc, "action = 'finalize_shift'")
);
$h->test('the proof still matches the branch-day prefix', str_contains($cliSrc, '$provenPrefixes['));
$h->test('dry run is the default; deleting needs --apply', str_contains($cliSrc, "array_key_exists('apply'"));
$h->test('deletes go by primary key only (MySQL 5.7 cannot LIMIT a multi-table DELETE)',
    str_contains($cliSrc, 'DELETE FROM dl_variance_flags WHERE id IN ('));
$h->test('a concurrent purge is refused by flock', str_contains($cliSrc, 'LOCK_EX | LOCK_NB'));

// ─── Cost: the correlated EXISTS must not come back ─────────────────────
$h->section('Query shape (cost)');

$h->test('no correlated EXISTS remains in the eligibility path', !str_contains($cliSrc, 'EXISTS ('));
$h->test('candidates are read once, ordered', str_contains($cliSrc, 'ORDER BY vf.id ASC'));
$h->test('the audit proof is read as one set', substr_count($cliSrc, 'FROM audit_logs') === 1);
$h->test('shape and sample are computed from the rows already read (no extra proof passes)',
    substr_count($cliSrc, 'new_data LIKE') === 1);
$h->test('the repair worker derives no variances of its own',
    !str_contains($repairSrc, 'dl_recomputeVariancesForDay(') && !str_contains($repairSrc, 'dl_freezeVarianceFlags('));

// ─── Runtime: it runs, it is fast, and it changes nothing ───────────────
$h->section('Dry-run execution');

$started = microtime(true);
exec('php ' . escapeshellarg($cliPath) . ' --tenant=207 2>&1', $out, $exit);
$elapsed = microtime(true) - $started;
$output = implode("\n", $out);

$h->test('exits 0', $exit === 0);
$h->test('reports the three counters', str_contains($output, 'PROVEN from the repair:')
    && str_contains($output, 'unproven look-alikes')
    && str_contains($output, 'variance flags now'));
$h->test('says it did not write', str_contains($output, 'Dry run only'));
// 20 s ceiling: 50x the 0.4 s measured after the fix, 25x inside the 497 s the old shape took.
$h->test(
    'completes well inside a shared-hosting request budget (' . round($elapsed, 2) . 's)',
    $elapsed < 20.0
);

$h->done();
