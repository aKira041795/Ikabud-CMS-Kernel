<?php

declare(strict_types=1);

/**
 * Daily Ledger — the fleet-wide day-status preload must equal the per-branch queries it replaced.
 *
 * MEASURED DEFECT IT FIXES (2026-11-20, tenant 207, KERNEL_DB_PROFILE on): a dashboard view that
 * triggered the auto-close pass issued 342 statements / 3.36 s of DB time, and two shapes ran once per
 * branch just to learn that most branches had nothing to do:
 *   27x  SELECT status FROM dl_ledger_day_status WHERE branch_id = ? AND ledger_date = ?
 *   27x  SELECT ledger_date ... status='open' AND ledger_date < ? AND reopened_at IS NULL ... LIMIT 7
 * dl_preloadDayStatusState() answers both for the whole fleet in ONE statement. Each statement was
 * sub-millisecond in MySQL (20 runs in 0.32 s including client start), so the cost was the round trips.
 *
 * THE RISK THIS TEST COVERS: an optimisation that changes an ANSWER is worse than the slowness. The
 * preload applies the per-branch "oldest 7 stale open days" cap in PHP, because MySQL 5.7 has no
 * window functions. That is only safe if the PHP cap reproduces the SQL exactly - same rows, same
 * order, same limit - and if a status the preload did not load still falls back to a query instead of
 * being reported as 'open'.
 *
 * Read-only by design: it compares the preload against the statements it replaced on REAL rows, so it
 * cannot influence the outcome by writing fixtures of its own.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-day-status-preload', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

require_once __DIR__ . '/../../src/helpers/module-manager.php';
require_once __DIR__ . '/../../modules/daily-ledger/handlers.php';

$passed = 0;
$failed = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$passed, &$failed, $h): void {
    if ($ok) {
        $passed++;
        $h->test($label, true);
        return;
    }
    $failed++;
    $h->test($label, false, $detail);
};

$ctx = module('daily-ledger');
if (!$ctx) {
    $h->test('module context is available', false, 'module() returned null in CLI');
    $h->done();
    exit(1);
}
// module() returns null in a bare CLI run until the request context is established; without this the
// whole equality check would compare against an empty map and prove nothing.
kernel_request_context_set('_activeModuleContext', $ctx);
app()->setActiveModule('daily-ledger');
\Ikabud\Kernel\Database\KernelPDO::setActiveModule('daily-ledger');
$h->test('module context is available', true);
$ctx = module();
$db = $ctx->db();

// A date far enough back that yesterday-relative comparisons are stable whatever today is: the pass
// asks "what is the status of <date>, and which earlier days are still open", which is exactly the
// question this test pins.
$probeDate = '2026-11-19';

$branchIds = array_map('intval', array_column(
    $db->query('SELECT id FROM dl_branches WHERE is_active = 1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) ?: [],
    'id'
));
$check('active branches were discovered', $branchIds !== [], 'none found');

// ── The preload itself ───────────────────────────────────────────────
unset($GLOBALS['_dl_day_status'], $GLOBALS['_dl_day_status_scope'], $GLOBALS['_dl_day_status_stale']);
$loaded = dl_preloadDayStatusState($branchIds, $probeDate, 7);
$check('the preload loaded at least the probed state', is_int($loaded) && $loaded >= 0, 'returned ' . var_export($loaded, true));
$check('the preload published its scope', ($GLOBALS['_dl_day_status_scope'] ?? null) === $probeDate);
$check('the preload published a stale map', is_array($GLOBALS['_dl_day_status_stale'] ?? null));

// ── Equivalence: status for the probed date ──────────────────────────
$statusStmt = $db->prepare('SELECT status FROM dl_ledger_day_status WHERE branch_id = :b AND ledger_date = :d');
$mismatchStatus = [];
$rowsChecked = 0;
foreach ($branchIds as $bid) {
    $statusStmt->execute([':b' => $bid, ':d' => $probeDate]);
    $direct = $statusStmt->fetchColumn();
    $expected = $direct === false ? 'open' : (string)$direct;
    $fromPreload = dl_getDayStatus($bid, $probeDate);
    $rowsChecked++;
    if ($fromPreload !== $expected) {
        $mismatchStatus[] = 'branch ' . $bid . ' preload=' . $fromPreload . ' direct=' . $expected;
    }
}
$check(
    'dl_getDayStatus() answers identically for every branch after the preload',
    $mismatchStatus === [],
    implode(' | ', $mismatchStatus)
);
$check('every branch was compared', $rowsChecked === count($branchIds), $rowsChecked . ' of ' . count($branchIds));

// ── Equivalence: the stale-open-days list, including the per-branch cap ──
$staleStmt = $db->prepare(
    "SELECT ledger_date
       FROM dl_ledger_day_status
      WHERE branch_id = :b
        AND status = 'open'
        AND ledger_date < :yesterday
        AND reopened_at IS NULL
      ORDER BY ledger_date ASC
      LIMIT 7"
);
$mismatchStale = [];
foreach ($branchIds as $bid) {
    $staleStmt->execute([':b' => $bid, ':yesterday' => $probeDate]);
    $direct = array_map('strval', $staleStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    $fromPreload = array_map('strval', $GLOBALS['_dl_day_status_stale'][$bid] ?? []);
    if ($direct !== $fromPreload) {
        $mismatchStale[] = 'branch ' . $bid . ' preload=[' . implode(',', $fromPreload) . '] direct=[' . implode(',', $direct) . ']';
    }
}
$check(
    'the preloaded stale list equals the LIMIT 7 query, row for row and in order',
    $mismatchStale === [],
    implode(' | ', $mismatchStale)
);

// The cap must actually be honoured when a branch has more than 7 stale open days.
$capHolder = null;
foreach ($branchIds as $bid) {
    $staleStmt->execute([':b' => $bid, ':yesterday' => $probeDate]);
    // Same query with a wide cap, to count what EXISTS rather than what the pass takes.
    $allStmt = $db->prepare(
        "SELECT ledger_date FROM dl_ledger_day_status
          WHERE branch_id = :b AND status = 'open' AND ledger_date < :yesterday AND reopened_at IS NULL
          ORDER BY ledger_date ASC"
    );
    $allStmt->execute([':b' => $bid, ':yesterday' => $probeDate]);
    $all = $allStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if (count($all) > 7) {
        $capHolder = ['branch' => $bid, 'all' => array_map('strval', $all)];
        break;
    }
}
if ($capHolder === null) {
    $h->skip('the 7-day cap is exercised', 'no branch currently has more than 7 stale open days');
} else {
    $preloaded = array_map('strval', $GLOBALS['_dl_day_status_stale'][$capHolder['branch']] ?? []);
    $check(
        'a branch with a backlog yields exactly the OLDEST 7',
        count($preloaded) === 7 && $preloaded === array_slice($capHolder['all'], 0, 7),
        'preload=[' . implode(',', $preloaded) . ']'
    );
}

// ── The cap, tested purely (no fixtures written into the ledger) ─────
// The preload cannot express "oldest 7 per branch" in SQL on MySQL 5.7, so the cap is applied in PHP.
// That is the one place the optimisation could silently change an answer, so it is pinned with
// synthetic rows rather than by hoping a branch happens to have a backlog.
$synthetic = [];
for ($d = 1; $d <= 12; $d++) {
    $date = sprintf('2026-10-%02d', $d);
    // The 5th is closed and the 6th was reopened: both must be excluded from a sweep. They replace
    // their date's row rather than being appended, because the function's contract is "rows arrive in
    // (branch, date ASC) order" - appending them out of order would test a sequence production never
    // produces (and would let the cap fill before the exclusions were ever seen).
    $synthetic[] = [
        'branch_id' => 90,
        'ledger_date' => $date,
        'status' => $d === 5 ? 'closed' : 'open',
        'reopened_at' => $d === 6 ? '2026-10-07 09:00:00' : null,
    ];
}
$synthetic[] = ['branch_id' => 90, 'ledger_date' => $probeDate, 'status' => 'open', 'reopened_at' => null]; // not < yesterday: excluded
$synthetic[] = ['branch_id' => 91, 'ledger_date' => '2026-10-02', 'status' => 'open', 'reopened_at' => null];

$capped = dl_staleDatesFromRows($synthetic, $probeDate, 7);
$check(
    'the cap yields exactly the OLDEST 7, in order',
    ($capped[90] ?? []) === ['2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04', '2026-10-07', '2026-10-08', '2026-10-09'],
    'got [' . implode(',', $capped[90] ?? []) . ']'
);
$check(
    'a closed or reopened row is never a sweep candidate',
    !in_array('2026-10-05', $capped[90] ?? [], true) && !in_array('2026-10-06', $capped[90] ?? [], true)
);
$check('the probed (yesterday) date itself is not a stale candidate', !in_array($probeDate, $capped[90] ?? [], true));
$check('branches are capped independently', ($capped[91] ?? []) === ['2026-10-02']);
$check('cap 0 disables the sweep selection', dl_staleDatesFromRows($synthetic, $probeDate, 0) === []);

// ── Invalidation: a write must not be answered by the preload ────────
$anyBranch = $branchIds[0];
$before = dl_getDayStatus($anyBranch, $probeDate);
// Simulate the write without touching the database: invert what the map holds, then forget it.
$inverted = $before === 'closed' ? 'open' : 'closed';
$GLOBALS['_dl_day_status'][$anyBranch . '|' . $probeDate] = $inverted;
$check('the map is authoritative while it holds a key', dl_getDayStatus($anyBranch, $probeDate) === $inverted);
dl_forgetDayStatus($anyBranch, $probeDate);
$check(
    'after dl_forgetDayStatus() the reader falls back to the real row',
    dl_getDayStatus($anyBranch, $probeDate) === $before,
    'expected ' . $before
);

// A date OUTSIDE the preload scope must never be answered from the map.
$otherDate = '2026-10-15';
$directOther = $statusStmt;
$directOther->execute([':b' => $anyBranch, ':d' => $otherDate]);
$expectOther = $directOther->fetchColumn();
$expectOther = $expectOther === false ? 'open' : (string)$expectOther;
$check(
    'a date outside the loaded scope still queries instead of guessing',
    dl_getDayStatus($anyBranch, $otherDate) === $expectOther,
    'got ' . dl_getDayStatus($anyBranch, $otherDate) . ' expected ' . $expectOther
);

// ── The pass must consume the preload rather than re-querying ────────
$passSource = (string)file_get_contents(__DIR__ . '/../../modules/daily-ledger/handlers.php');
$check(
    'the fleet walk preloads day-status state before iterating',
    str_contains($passSource, 'dl_preloadDayStatusState(array_keys($uniqueBranchIds)')
);
$check(
    'the per-branch sweep consumes the preloaded list',
    str_contains($passSource, "\$GLOBALS['_dl_day_status_stale'][\$branchId]")
);
$check(
    'both day-status writers invalidate the preload',
    substr_count($passSource, 'dl_forgetDayStatus(') >= 3
);

$h->done();
echo "\n" . str_repeat('─', 60) . "\n";
echo ($failed === 0 ? 'PASS' : 'FAIL') . ": {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
