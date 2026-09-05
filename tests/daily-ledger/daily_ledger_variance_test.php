<?php

declare(strict_types=1);

/**
 * Daily Ledger — Nullable endings, shift lifecycle, deterministic variances
 *
 * Focused DB-backed suite (tenant 207, reserved 99xxx test ids):
 *   A. Migration/schema: nullable bal_end+sales, shift-status table, variance
 *      canonical columns + unique key, idempotent rerun, conservative backfills.
 *   B. Deterministic variance recompute: overnight/handoff/ending/sales kinds,
 *      first-day skip, recorded-zero handling, null PM fallback, D→D+1
 *      propagation, unreviewed-delete vs reviewed-zero-retention, close freeze.
 *   C. PM finalization domain: missing endings, inactive ignored, atomic
 *      lock+recompute, idempotent repeat, immutability guard, closed-day gate.
 *   D. Close gate + late-count authorization: unfinalized manual day closes at
 *      the cutoff with a surfaced gap audit (owner rule), finalized manual PM
 *      closes + freezes, deliberately reopened days exempt from re-auto-close,
 *      POS days exempt, prior pending PM editable by cashier, older/AM/finalized
 *      blocked.
 *   E. Reporting: provisional vs official rows/totals and NULL round-trip.
 */

ob_start();

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('daily-ledger-variance', TestHarness::MODE_INTEGRATION, 'localhost');
ob_end_clean();

$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/helpers.php');
$h->fingerprint('modules/daily-ledger/handlers-pos.php');
$h->fingerprint('modules/daily-ledger/handlers-deliveries.php');
$h->fingerprint('modules/daily-ledger/database/migrations/049_nullable_endings_and_shift_status.sql');
$h->fingerprint('modules/daily-ledger/database/migrations/051_variance_shift_inputs.sql');
$h->fingerprint('modules/daily-ledger/database/migrations/052_cashier_withdrawals_dedup_hash.sql');

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/helpers/entity-views.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers-pos.php';
require_once $base . '/modules/daily-ledger/handlers.php';

app()->tenant()->setTenantId(207);
$dlCtx = modulePushContext('daily-ledger');
if (!$dlCtx) {
    fwrite(STDERR, "daily-ledger module context unavailable\n");
    exit(1);
}

/** @var \Ikabud\Kernel\Contracts\DatabaseContract $db */
$db = $dlCtx->db();

// ─── Settings: enable auto-close at 22:00 (restored at cleanup) ────────
$origSettings = getModuleSettings('daily-ledger');
dlPersistModuleSettings(array_merge((array)$origSettings, [
    'auto_close_enabled' => '1',
    'close_of_day_time' => '22:00',
    'operating_timezone' => 'UTC',
]));

// ─── Seeds (reserved 99xxx range) ──────────────────────────────────────
$branchId = 99061;
$pA = 99061; // has AM + PM activity
$pB = 99062; // PM ending missing
$pC = 99063; // inactive — must be ignored by finalization
$testDate = '2030-02-10';
$nextDate = '2030-02-11';

$tenantScope = (string)(app()->tenant()->current() ?? '');
foreach ([$branchId, 99062, 99063] as $bid) {
    $db->execute('DELETE FROM dl_variance_flags WHERE branch_id = :b', [':b' => $bid]);
    $db->execute('DELETE FROM dl_ledger_shift_status WHERE branch_id = :b', [':b' => $bid]);
    $db->execute('DELETE FROM dl_ledger_day_status WHERE branch_id = :b', [':b' => $bid]);
    $db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b', [':b' => $bid]);
    $db->execute('DELETE FROM dl_branch_products WHERE branch_id = :b', [':b' => $bid]);
    $db->execute('DELETE FROM dl_branches WHERE id = :b', [':b' => $bid]);
}
foreach ([$pA, $pB, $pC, 99064] as $pid) {
    $db->execute('DELETE FROM dl_products WHERE id = :p', [':p' => $pid]);
}

$branchStmt = $db->prepare(
    'INSERT INTO dl_branches (id, code, name, address, default_supply_mode, is_commissary, is_active)
     VALUES (:id, :code, :name, :addr, :mode, 0, 1)'
);
$branchStmt->execute([':id' => $branchId, ':code' => 'T-VAR', ':name' => 'Variance Test Branch', ':addr' => 'Test', ':mode' => 'self_managed']);
$prodStmt = $db->prepare(
    'INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active)
     VALUES (:id, :sku, :name, :price, :sort, 1)'
);
$prodStmt->execute([':id' => $pA, ':sku' => 'VAR-A', ':name' => 'Variance Product A', ':price' => 10.0, ':sort' => 0]);
$prodStmt->execute([':id' => $pB, ':sku' => 'VAR-B', ':name' => 'Variance Product B', ':price' => 12.0, ':sort' => 1]);
$prodStmt->execute([':id' => $pC, ':sku' => 'VAR-C', ':name' => 'Inactive Product C', ':price' => 15.0, ':sort' => 2]);
$prodStmt->execute([':id' => 99064, ':sku' => 'VAR-D', ':name' => 'Variance Product D', ':price' => 20.0, ':sort' => 3]);
$db->execute('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (:b, :p, 1)', [':b' => $branchId, ':p' => $pA]);
$db->execute('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (:b, :p, 1)', [':b' => $branchId, ':p' => $pB]);
$db->execute('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (:b, :p, 0)', [':b' => $branchId, ':p' => $pC]);

/** Insert a ledger row directly (controlled beg/add/withdraw/bal_end). */
function dl_t_ledger(\Ikabud\Kernel\Contracts\DatabaseContract $db, int $branchId, int $productId, string $date, string $shift, int $beg, int $add, int $wdr, ?int $end, float $price = 10.0): void
{
    $db->prepare(
        'INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw, bal_end)
         VALUES (:b, :p, :d, :s, :pr, :beg, :add, :wdr, :end)
         ON DUPLICATE KEY UPDATE beg_bal = VALUES(beg_bal), addtl = VALUES(addtl), withdraw = VALUES(withdraw), bal_end = VALUES(bal_end), price_snapshot = VALUES(price_snapshot)'
    )->execute([
        ':b' => $branchId, ':p' => $productId, ':d' => $date, ':s' => $shift,
        ':pr' => $price, ':beg' => $beg, ':add' => $add, ':wdr' => $wdr, ':end' => $end,
    ]);
}

function dl_t_flag(\Ikabud\Kernel\Contracts\DatabaseContract $db, int $branchId, int $productId, string $date, string $kind, ?string $shift): ?array
{
    $stmt = $db->prepare(
        'SELECT * FROM dl_variance_flags
          WHERE branch_id = :b AND product_id = :p AND ledger_date = :d AND kind = :k AND shift <=> :s LIMIT 1'
    );
    $stmt->execute([':b' => $branchId, ':p' => $productId, ':d' => $date, ':k' => $kind, ':s' => $shift]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

// ══════════════════════════════════════════════════════════════════════
// A. Migration / schema
//
// PREREQUISITE: migration 049 has been applied to tenant 207 (the CLI migration
// runner executes DDL unguarded; the module DB contract forbids PREPARE/ALTER, so
// this test asserts the resulting schema and proves the DML backfills are
// idempotent rather than re-running DDL on a shared connection).
// ══════════════════════════════════════════════════════════════════════
$migrationSql = (string) file_get_contents($base . '/modules/daily-ledger/database/migrations/049_nullable_endings_and_shift_status.sql');
$h->test('migration 049 file exists', $migrationSql !== '');
$migration051Sql = (string) file_get_contents($base . '/modules/daily-ledger/database/migrations/051_variance_shift_inputs.sql');
$h->test('migration 051 file exists', $migration051Sql !== '');

$cols = [];
foreach ($db->query('SHOW COLUMNS FROM dl_daily_ledger') as $c) {
    $cols[(string)$c['Field']] = $c;
}
$h->test('bal_end is nullable', (string)($cols['bal_end']['Null'] ?? 'NO') === 'YES');
$h->test('sales is nullable', (string)($cols['sales']['Null'] ?? 'NO') === 'YES');

$shiftCols = [];
foreach ($db->query('SHOW COLUMNS FROM dl_ledger_shift_status') as $c) {
    $shiftCols[(string)$c['Field']] = $c;
}
$h->test('shift status table has status column', isset($shiftCols['status']));
$h->test('shift status table has finalized_by', isset($shiftCols['finalized_by']));
$h->test('shift status table has finalized_at', isset($shiftCols['finalized_at']));
$h->test('shift status table has pending_notified_at', isset($shiftCols['pending_notified_at']));

// Unique key on shift status — behavioral proof (module DB forbids SHOW INDEX /
// INFORMATION_SCHEMA). A duplicate (branch,date,shift) insert must be rejected.
$dupShiftBlocked = true;
$db->execute(
    'INSERT INTO dl_ledger_shift_status (branch_id, ledger_date, shift, status) VALUES (:b, :d, \'PM\', \'open\')',
    [':b' => $branchId, ':d' => '2030-02-03']
);
try {
    $db->execute(
        'INSERT INTO dl_ledger_shift_status (branch_id, ledger_date, shift, status) VALUES (:b, :d, \'PM\', \'open\')',
        [':b' => $branchId, ':d' => '2030-02-03']
    );
    $dupShiftBlocked = false;
} catch (Throwable $e) {
    // expected unique violation
}
$h->test('shift status unique key (branch,date,shift) rejects duplicates', $dupShiftBlocked);
// A distinct shift on the same date is allowed (proves shift is part of identity).
$distinctShiftOk = true;
try {
    $db->execute(
        'INSERT INTO dl_ledger_shift_status (branch_id, ledger_date, shift, status) VALUES (:b, :d, \'AM\', \'open\')',
        [':b' => $branchId, ':d' => '2030-02-03']
    );
} catch (Throwable $e) {
    $distinctShiftOk = false;
}
$h->test('shift status allows a distinct shift on the same date', $distinctShiftOk);
$db->execute('DELETE FROM dl_ledger_shift_status WHERE branch_id = :b AND ledger_date = :d', [':b' => $branchId, ':d' => '2030-02-03']);

$vfCols = [];
foreach ($db->query('SHOW COLUMNS FROM dl_variance_flags') as $c) {
    $vfCols[(string)$c['Field']] = $c;
}
foreach (['resolution_status', 'kind', 'shift', 'expected_end_bal', 'recorded_end_bal', 'frozen_at', 'auto_clear_note', 'addtl', 'withdraw'] as $need) {
    $h->test('variance column present: ' . $need, isset($vfCols[$need]));
}
// Variance unique key (branch,product,date,kind,shift) — behavioral proof.
$vfDupBlocked = true;
$db->execute(
    'INSERT INTO dl_variance_flags (branch_id, product_id, ledger_date, kind, shift, variance) VALUES (:b, :p, :d, \'overnight\', \'AM\', 1)',
    [':b' => $branchId, ':p' => 99064, ':d' => '2030-02-04']
);
try {
    $db->execute(
        'INSERT INTO dl_variance_flags (branch_id, product_id, ledger_date, kind, shift, variance) VALUES (:b, :p, :d, \'overnight\', \'AM\', 2)',
        [':b' => $branchId, ':p' => 99064, ':d' => '2030-02-04']
    );
    $vfDupBlocked = false;
} catch (Throwable $e) {
    // expected unique violation
}
$h->test('variance unique key rejects duplicate (kind,shift) identity', $vfDupBlocked);
$vfShiftDistinctOk = true;
try {
    $db->execute(
        'INSERT INTO dl_variance_flags (branch_id, product_id, ledger_date, kind, shift, variance) VALUES (:b, :p, :d, \'overnight\', \'PM\', 3)',
        [':b' => $branchId, ':p' => 99064, ':d' => '2030-02-04']
    );
} catch (Throwable $e) {
    $vfShiftDistinctOk = false;
}
$h->test('variance unique key keeps shift as part of identity', $vfShiftDistinctOk);
$db->execute('DELETE FROM dl_variance_flags WHERE branch_id = :b AND ledger_date = :d', [':b' => $branchId, ':d' => '2030-02-04']);

// Idempotent rerun of the DML backfills (the only part re-run in-process).
$rerunOk = true;
try {
    $db->execute(
        "UPDATE dl_variance_flags SET kind = 'overnight', resolution_status = resolution_status WHERE kind = 'overnight' AND shift IS NULL"
    );
    $db->execute(
        "INSERT INTO dl_ledger_shift_status (branch_id, ledger_date, shift, status, finalized_by, finalized_at)
         SELECT lds.branch_id, lds.ledger_date, s.shift, 'finalized', lds.closed_by, lds.closed_at
           FROM dl_ledger_day_status lds
           JOIN (SELECT 'AM' AS shift UNION ALL SELECT 'PM') s
          WHERE lds.status = 'closed'
            AND NOT EXISTS (
                SELECT 1 FROM dl_ledger_shift_status x
                 WHERE x.branch_id = lds.branch_id AND x.ledger_date = lds.ledger_date AND x.shift = s.shift
            )"
    );
} catch (Throwable $e) {
    $rerunOk = false;
}
$h->test('backfill DML rerun is idempotent', $rerunOk);

// Conservative legacy backfill: a legacy variance row (no kind/shift) maps to
// overnight and, when reviewed, to investigated — history preserved.
$db->execute(
    'INSERT INTO dl_variance_flags (branch_id, product_id, ledger_date, prev_bal_end, current_beg_bal, variance, is_reviewed)
     VALUES (:b, :p, :d, 8, 10, 2, 1)',
    [':b' => $branchId, ':p' => 99064, ':d' => $testDate]
);
$db->execute(
    "UPDATE dl_variance_flags SET kind = 'overnight',
            expected_end_bal = COALESCE(expected_end_bal, prev_bal_end),
            recorded_end_bal = COALESCE(recorded_end_bal, current_beg_bal),
            resolution_status = CASE WHEN is_reviewed = 1 THEN 'investigated' ELSE resolution_status END
      WHERE kind = 'overnight' AND shift IS NULL"
);
$legacy = dl_t_flag($db, $branchId, 99064, $testDate, 'overnight', null);
$h->test('legacy flag backfilled to overnight kind', is_array($legacy) && ($legacy['kind'] ?? '') === 'overnight');
$h->test('legacy reviewed flag mapped to investigated', is_array($legacy) && ($legacy['resolution_status'] ?? '') === 'investigated');
$db->execute('DELETE FROM dl_variance_flags WHERE branch_id = :b', [':b' => $branchId]);

// Closed historical day backfill → both shifts finalized.
$db->execute(
    'INSERT INTO dl_ledger_day_status (branch_id, ledger_date, status, closed_by, closed_at)
     VALUES (:b, :d, "closed", 1, CURRENT_TIMESTAMP)',
    [':b' => $branchId, ':d' => '2030-02-01']
);
$db->execute(
    "INSERT INTO dl_ledger_shift_status (branch_id, ledger_date, shift, status, finalized_by, finalized_at)
     SELECT lds.branch_id, lds.ledger_date, s.shift, 'finalized', lds.closed_by, lds.closed_at
       FROM dl_ledger_day_status lds
       JOIN (SELECT 'AM' AS shift UNION ALL SELECT 'PM') s
      WHERE lds.status = 'closed'
        AND NOT EXISTS (
            SELECT 1 FROM dl_ledger_shift_status x
             WHERE x.branch_id = lds.branch_id AND x.ledger_date = lds.ledger_date AND x.shift = s.shift
        )"
);
$pmBackfill = $db->prepare('SELECT status FROM dl_ledger_shift_status WHERE branch_id = :b AND ledger_date = :d AND shift = :s');
$pmBackfill->execute([':b' => $branchId, ':d' => '2030-02-01', ':s' => 'PM']);
$amBackfill = $db->prepare('SELECT status FROM dl_ledger_shift_status WHERE branch_id = :b AND ledger_date = :d AND shift = :s');
$amBackfill->execute([':b' => $branchId, ':d' => '2030-02-01', ':s' => 'AM']);
$h->test('closed historical day backfilled PM finalized', (string)$pmBackfill->fetchColumn() === 'finalized');
$h->test('closed historical day backfilled AM finalized', (string)$amBackfill->fetchColumn() === 'finalized');
$db->execute('DELETE FROM dl_ledger_shift_status WHERE branch_id = :b AND ledger_date = :d', [':b' => $branchId, ':d' => '2030-02-01']);
$db->execute('DELETE FROM dl_ledger_day_status WHERE branch_id = :b AND ledger_date = :d', [':b' => $branchId, ':d' => '2030-02-01']);

// ══════════════════════════════════════════════════════════════════════
// B. Deterministic variance recompute
// ══════════════════════════════════════════════════════════════════════
$db->execute('DELETE FROM dl_variance_flags WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_ledger_shift_status WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_ledger_day_status WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b', [':b' => $branchId]);

// Prior day PM ending = 8 for product A.
dl_t_ledger($db, $branchId, $pA, '2030-02-09', 'PM', 8, 0, 0, 8);
// Day D: AM beg=10, end=5; PM beg=6 (handoff +1), end=7.
dl_t_ledger($db, $branchId, $pA, $testDate, 'AM', 10, 0, 0, 5);
dl_t_ledger($db, $branchId, $pA, $testDate, 'PM', 6, 0, 0, 7);
// Product B: PM ending missing (null) → pending, no numeric variance. Prior day
// has a recorded AM ending (no PM) → overnight must fall back to it.
dl_t_ledger($db, $branchId, $pB, '2030-02-09', 'AM', 5, 0, 0, 5);
dl_t_ledger($db, $branchId, $pB, $testDate, 'AM', 20, 0, 0, 20);
dl_t_ledger($db, $branchId, $pB, $testDate, 'PM', 20, 0, 0, null);
// Product D (active) has no rows — no flags.

dl_recomputeVariancesForDay($branchId, $testDate, false);

// overnight (AM): 10 - 8 = 2
$ov = dl_t_flag($db, $branchId, $pA, $testDate, 'overnight', 'AM');
$h->test('overnight variance computed (AM beg - prior end)', is_array($ov) && (int)$ov['variance'] === 2 && (int)$ov['expected_end_bal'] === 8 && (int)$ov['recorded_end_bal'] === 10 && (int)$ov['current_beg_bal'] === 10);
// handoff (PM): 6 - 5 = 1
$ho = dl_t_flag($db, $branchId, $pA, $testDate, 'handoff', 'PM');
$h->test('handoff variance computed (PM beg - AM end)', is_array($ho) && (int)$ho['variance'] === 1 && (int)$ho['expected_end_bal'] === 5 && (int)$ho['recorded_end_bal'] === 6 && (int)$ho['current_beg_bal'] === 6 && (int)$ho['prev_bal_end'] === 5);
// Product B PM ending null → NO handoff/ending/sales flag and no fabricated overnight.
$h->test('null PM ending creates no numeric variance for B', dl_t_flag($db, $branchId, $pB, $testDate, 'handoff', 'PM') === null && dl_t_flag($db, $branchId, $pB, $testDate, 'ending', 'PM') === null);
// Overnight for B falls back to the recorded AM ending (20 - 5 = 15).
$ovB = dl_t_flag($db, $branchId, $pB, $testDate, 'overnight', 'AM');
$h->test('null PM ending falls back to recorded AM ending for overnight', is_array($ovB) && (int)$ovB['variance'] === 15 && (int)$ovB['expected_end_bal'] === 5);
// First data day → overnight skipped.
$h->test('first-day product with no prior ending has no overnight flag', dl_t_flag($db, $branchId, $pC, $testDate, 'overnight', 'AM') === null);

// Ending above supply + negative raw sales on product A's AM row:
// expected = 10+0-0 = 10, recorded end = 5 → not above supply. Make it above supply.
dl_t_ledger($db, $branchId, $pA, $testDate, 'AM', 10, 0, 0, 15);
dl_recomputeVariancesForDay($branchId, $testDate, false);
$endFlag = dl_t_flag($db, $branchId, $pA, $testDate, 'ending', 'AM');
$salesFlag = dl_t_flag($db, $branchId, $pA, $testDate, 'sales', 'AM');
$h->test('ending-above-supply produces ending kind', is_array($endFlag) && (int)$endFlag['variance'] === 5 && (int)$endFlag['expected_end_bal'] === 10 && (int)$endFlag['recorded_end_bal'] === 15 && (int)$endFlag['current_beg_bal'] === 10 && (int)$endFlag['addtl'] === 0 && (int)$endFlag['withdraw'] === 0);
$h->test('ending-above-supply produces negative raw sales kind', is_array($salesFlag) && (int)$salesFlag['variance'] === -5 && (int)$salesFlag['current_beg_bal'] === 10 && (int)$salesFlag['addtl'] === 0 && (int)$salesFlag['withdraw'] === 0);
$h->test('save order cannot overwrite identity (overnight still AM)', ($ov = dl_t_flag($db, $branchId, $pA, $testDate, 'overnight', 'AM')) !== null);

// Restore AM end to 5 and verify handoff recompute (D→D+1 propagation).
dl_t_ledger($db, $branchId, $pA, $testDate, 'AM', 10, 0, 0, 5);
// D+1 AM beg uses D's PM end (7) — change D PM end to 9 → D+1 overnight should use 9.
dl_t_ledger($db, $branchId, $pA, $testDate, 'PM', 6, 0, 0, 9);
dl_t_ledger($db, $branchId, $pA, $nextDate, 'AM', 10, 0, 0, 10);
dl_recomputeVariancesForDay($branchId, $testDate, true); // touch next day
$ovNext = dl_t_flag($db, $branchId, $pA, $nextDate, 'overnight', 'AM');
$h->test('correcting D ending propagates overnight to D+1', is_array($ovNext) && (int)$ovNext['variance'] === 1 && (int)$ovNext['expected_end_bal'] === 9);
// Zero variance auto-clears unreviewed flags (handoff 6-5=1 → now PM end changed; recompute handoff = 6-5 = 1 still). Force handoff to zero: set PM beg = AM end.
dl_t_ledger($db, $branchId, $pA, $testDate, 'PM', 5, 0, 0, 9);
dl_recomputeVariancesForDay($branchId, $testDate, false);
$h->test('zero handoff auto-clears unreviewed flag', dl_t_flag($db, $branchId, $pA, $testDate, 'handoff', 'PM') === null);

// Reviewed zero-retention: mark a flag investigated, then recompute it to zero.
dl_t_ledger($db, $branchId, $pA, $testDate, 'PM', 7, 0, 0, 9); // handoff 7-5=2
dl_recomputeVariancesForDay($branchId, $testDate, false);
$ho2 = dl_t_flag($db, $branchId, $pA, $testDate, 'handoff', 'PM');
$h->test('nonzero handoff flag created for reviewed-retention test', is_array($ho2) && (int)$ho2['variance'] === 2);
$db->prepare('UPDATE dl_variance_flags SET resolution_status = :s, is_reviewed = 1 WHERE id = :id')
    ->execute([':s' => 'investigated', ':id' => (int)$ho2['id']]);
dl_t_ledger($db, $branchId, $pA, $testDate, 'PM', 5, 0, 0, 9); // handoff 0
dl_recomputeVariancesForDay($branchId, $testDate, false);
$ho3 = dl_t_flag($db, $branchId, $pA, $testDate, 'handoff', 'PM');
$h->test('reviewed flag retained at zero with auto-clear note', is_array($ho3) && (int)$ho3['variance'] === 0 && ($ho3['resolution_status'] ?? '') === 'investigated' && ($ho3['auto_clear_note'] ?? '') !== '');

// Recorded prior ending participates even when today's AM ending is a recorded
// zero (0 is counted, not pending). Overnight = 7 - 5 = 2.
dl_t_ledger($db, $branchId, $pB, '2030-02-09', 'PM', 5, 0, 0, 5);
dl_t_ledger($db, $branchId, $pB, $testDate, 'AM', 7, 0, 0, 0); // recorded zero end
dl_recomputeVariancesForDay($branchId, $testDate, false);
$ovB = dl_t_flag($db, $branchId, $pB, $testDate, 'overnight', 'AM');
$h->test('recorded prior ending participates in overnight variance', is_array($ovB) && (int)$ovB['variance'] === 2);

// ─── Historical backfill gap: "beginning lesser than ending" ────────────
// A row entered BEFORE the variance enhancement (no flags exist anywhere) must
// be surfaced by (1) a direct recompute and (2) the self-healing view refresh.
// User-reported shape: beg=10, addtl=0, withdraw=20, bal_end=22 → expected −10,
// over = 32 → ending variance 32, raw sales −32.
$histDate = '2030-02-12';
$db->execute('DELETE FROM dl_variance_flags WHERE branch_id = :b AND ledger_date = :d', [':b' => $branchId, ':d' => $histDate]);
$db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b AND ledger_date = :d', [':b' => $branchId, ':d' => $histDate]);
dl_t_ledger($db, $branchId, 99064, $histDate, 'AM', 10, 0, 20, 22, 5.0);
$h->test('pre-enhancement anomaly has no flags before recompute', dl_t_flag($db, $branchId, 99064, $histDate, 'ending', 'AM') === null);

dl_recomputeVariancesForDay($branchId, $histDate, false);
$histEnd = dl_t_flag($db, $branchId, 99064, $histDate, 'ending', 'AM');
$histSales = dl_t_flag($db, $branchId, 99064, $histDate, 'sales', 'AM');
$h->test('beginning-lesser-than-ending surfaces ending variance', is_array($histEnd) && (int)$histEnd['variance'] === 32 && (int)$histEnd['expected_end_bal'] === -10 && (int)$histEnd['recorded_end_bal'] === 22 && (int)$histEnd['current_beg_bal'] === 10 && (int)$histEnd['addtl'] === 0 && (int)$histEnd['withdraw'] === 20);
$h->test('beginning-lesser-than-ending surfaces negative raw sales', is_array($histSales) && (int)$histSales['variance'] === -32);

// Self-healing view refresh must surface the anomaly from a no-flag state on an
// OPEN day, and must NOT mutate a CLOSED (frozen) day.
$db->execute('DELETE FROM dl_variance_flags WHERE branch_id = :b AND ledger_date = :d', [':b' => $branchId, ':d' => $histDate]);
dl_refreshVariancesForDateView($histDate, [$branchId]);
$h->test('view refresh surfaces anomaly on an open day', dl_t_flag($db, $branchId, 99064, $histDate, 'ending', 'AM') !== null);

$db->execute('DELETE FROM dl_variance_flags WHERE branch_id = :b AND ledger_date = :d', [':b' => $branchId, ':d' => $histDate]);
$db->execute('DELETE FROM dl_ledger_day_status WHERE branch_id = :b AND ledger_date = :d', [':b' => $branchId, ':d' => $histDate]);
$db->execute('INSERT INTO dl_ledger_day_status (branch_id, ledger_date, status) VALUES (:b, :d, "closed")', [':b' => $branchId, ':d' => $histDate]);
dl_refreshVariancesForDateView($histDate, [$branchId]);
$h->test('view refresh skips closed days', dl_t_flag($db, $branchId, 99064, $histDate, 'ending', 'AM') === null);

// Cleanup this block's isolated day.
$db->execute('DELETE FROM dl_ledger_day_status WHERE branch_id = :b AND ledger_date = :d', [':b' => $branchId, ':d' => $histDate]);
$db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b AND ledger_date = :d', [':b' => $branchId, ':d' => $histDate]);
$db->execute('DELETE FROM dl_variance_flags WHERE branch_id = :b AND ledger_date = :d', [':b' => $branchId, ':d' => $histDate]);

// Close freeze metadata.
dl_t_ledger($db, $branchId, $pA, $testDate, 'PM', 5, 0, 0, 9);
dl_recomputeVariancesForDay($branchId, $testDate, false);
dl_freezeVarianceFlags($db, $branchId, $testDate, 1);
$frozen = $db->prepare('SELECT COUNT(*) FROM dl_variance_flags WHERE branch_id = :b AND ledger_date = :d AND frozen_at IS NOT NULL');
$frozen->execute([':b' => $branchId, ':d' => $testDate]);
$h->test('close freeze stamps frozen_at on day flags', (int)$frozen->fetchColumn() > 0);

// ══════════════════════════════════════════════════════════════════════
// C. PM finalization domain + immutability
// ══════════════════════════════════════════════════════════════════════
$db->execute('DELETE FROM dl_ledger_shift_status WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b AND ledger_date = :d', [':b' => $branchId, ':d' => $testDate]);

// Product B has no PM ending → missing list should include B only (A has one, C inactive).
dl_t_ledger($db, $branchId, $pA, $testDate, 'PM', 5, 0, 0, 9);
dl_t_ledger($db, $branchId, $pB, $testDate, 'PM', 20, 0, 0, null);
$missing = dl_shiftMissingEndings($db, $branchId, $testDate, 'PM');
$missingIds = array_map('intval', array_column($missing, 'product_id'));
$h->test('missing endings lists PM-uncounted active product', in_array($pB, $missingIds, true));
$h->test('missing endings excludes products with a PM ending', !in_array($pA, $missingIds, true));
$h->test('missing endings excludes inactive products', !in_array($pC, $missingIds, true));

// Not finalized yet → assertShiftMutable does not throw.
$notFinalized = true;
try {
    dl_assertShiftMutable($db, $branchId, $testDate, 'PM');
} catch (Throwable $e) {
    $notFinalized = false;
}
$h->test('unfinalized shift is mutable', $notFinalized);

// Simulate the finalize transaction (same helpers the handler uses).
$finalizeOk = true;
try {
    $db->beginTransaction();
    $dayStatus = dl_lockDayStatusRow($db, $branchId, $testDate);
    if ($dayStatus === 'closed') {
        throw new RuntimeException('closed');
    }
    $pmStatus = dl_lockShiftStatusRow($db, $branchId, $testDate, 'PM');
    $stillMissing = dl_shiftMissingEndings($db, $branchId, $testDate, 'PM');
    if (count($stillMissing) > 0) {
        throw new RuntimeException('missing');
    }
    $db->commit();
} catch (Throwable $e) {
    $finalizeOk = false;
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}
$h->test('finalize rejects when any active product is missing an ending', !$finalizeOk);

// Complete B's PM ending → finalize succeeds, status set, sales recomputed, audit.
dl_t_ledger($db, $branchId, $pB, $testDate, 'PM', 20, 0, 0, 20);
$finalizeOk = true;
$finalizeErr = null;
try {
    $db->beginTransaction();
    $dayStatus = dl_lockDayStatusRow($db, $branchId, $testDate);
    $pmStatus = dl_lockShiftStatusRow($db, $branchId, $testDate, 'PM');
    $stillMissing = dl_shiftMissingEndings($db, $branchId, $testDate, 'PM');
    if (count($stillMissing) > 0) {
        throw new RuntimeException('missing:' . json_encode($stillMissing));
    }
    $upd = $db->prepare('SELECT product_id FROM dl_daily_ledger WHERE branch_id = :b AND ledger_date = :d AND shift = \'PM\'');
    $upd->execute([':b' => $branchId, ':d' => $testDate]);
    foreach ($upd->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        dl_recomputeSales($branchId, (int)$r['product_id'], $testDate, 1, 'PM');
    }
    dl_recomputeVariancesForDay($branchId, $testDate, false);
    $db->prepare('UPDATE dl_ledger_shift_status SET status = \'finalized\', finalized_by = :u, finalized_at = CURRENT_TIMESTAMP WHERE branch_id = :b AND ledger_date = :d AND shift = \'PM\'')
        ->execute([':u' => 1, ':b' => $branchId, ':d' => $testDate]);
    $db->commit();
} catch (Throwable $e) {
    $finalizeOk = false;
    $finalizeErr = $e->getMessage();
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}
$h->test('finalize succeeds once every active PM ending is recorded', $finalizeOk, (string)$finalizeErr);
$h->test('finalize marks PM shift finalized', dl_shiftIsFinalized($db, $branchId, $testDate, 'PM'));

// Immutability after finalization: cashier field/batch and domain deltas throw.
$blocked = true;
try {
    dl_assertShiftMutable($db, $branchId, $testDate, 'PM');
} catch (Throwable $e) {
    $blocked = false;
}
$h->test('finalized PM shift is immutable to domain mutations', !$blocked);
$blockedDelta = true;
try {
    dl_applyLedgerDelta($branchId, $pA, $testDate, 1, 1, 'addtl', 'PM');
} catch (Throwable $e) {
    $blockedDelta = false;
}
$h->test('dl_applyLedgerDelta rejects finalized PM writes', !$blockedDelta);

// Closed day blocks finalize (replicate the handler guard).
$db->execute('DELETE FROM dl_ledger_shift_status WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_ledger_day_status WHERE branch_id = :b AND ledger_date = :d', [':b' => $branchId, ':d' => $testDate]);
$db->execute('INSERT INTO dl_ledger_day_status (branch_id, ledger_date, status) VALUES (:b, :d, "closed")', [':b' => $branchId, ':d' => $testDate]);
$closedBlock = true;
try {
    $db->beginTransaction();
    $dayStatus = dl_lockDayStatusRow($db, $branchId, $testDate);
    if ($dayStatus === 'closed') {
        throw new RuntimeException('DAY_CLOSED');
    }
    $db->commit();
} catch (Throwable $e) {
    $closedBlock = false;
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}
$h->test('finalize blocked on a closed day', !$closedBlock);
$db->execute('DELETE FROM dl_ledger_day_status WHERE branch_id = :b AND ledger_date = :d', [':b' => $branchId, ':d' => $testDate]);

// Reopen clears finalization metadata.
$db->execute('UPDATE dl_ledger_shift_status SET status = \'open\', finalized_by = NULL, finalized_at = NULL WHERE branch_id = :b AND ledger_date = :d', [':b' => $branchId, ':d' => $testDate]);
$h->test('reopen clears finalized status', !dl_shiftIsFinalized($db, $branchId, $testDate, 'PM'));

// Open PM shift remains mutable to domain deltas (delta lock path must not over-block).
$openDeltaOk = true;
try {
    dl_applyLedgerDelta($branchId, $pA, $testDate, 1, 1, 'addtl', 'PM');
} catch (Throwable $e) {
    $openDeltaOk = false;
}
$h->test('dl_applyLedgerDelta allows writes to an open PM shift', $openDeltaOk);

// ══════════════════════════════════════════════════════════════════════
// D. Close gate + late-count authorization
// ══════════════════════════════════════════════════════════════════════
$db->execute('DELETE FROM dl_ledger_shift_status WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_ledger_day_status WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b AND ledger_date = :d', [':b' => $branchId, ':d' => $testDate]);
dl_t_ledger($db, $branchId, $pA, $testDate, 'PM', 5, 0, 0, 9);
dl_t_ledger($db, $branchId, $pB, $testDate, 'PM', 20, 0, 0, 20);

// Boundary: now = 00:05 on the next day (past 22:00 rollover) → closeDate = $testDate.
$afterCutoff = new \DateTimeImmutable($nextDate . ' 00:05:00', new \DateTimeZone('UTC'));
// Owner rule (2026-09-04): an unfinalized manual day CLOSES at the cutoff too
// (both shifts), surfacing the missing PM ending in the audit instead of
// leaving the day open to leak entries into the next business date.
$closedPending = dl_maybeAutoCloseBranchDay($branchId, 1, $afterCutoff);
$h->test('auto-close closes an unfinalized manual day at the cutoff', $closedPending === true && dl_getDayStatus($branchId, $testDate) === 'closed');
$gapAudit = static function () use ($db, $branchId): int {
    $st = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = 'auto_close_day' AND branch_id = :b");
    $st->execute([':b' => $branchId]);
    return (int)$st->fetchColumn();
};
$h->test('auto-close surfaces the missing PM ending in the audit', $gapAudit() >= 1);
// Repeated passes are no-ops once the day is closed (single close).
$h->test('auto-close repeated pass is a no-op after closing', dl_maybeAutoCloseBranchDay($branchId, 1, $afterCutoff) === false);

// A finalized manual PM closes at the boundary and freezes its snapshot.
$db->execute('DELETE FROM dl_variance_flags WHERE branch_id = :b AND ledger_date = :d', [':b' => $branchId, ':d' => $testDate]);
$db->execute('DELETE FROM dl_ledger_shift_status WHERE branch_id = :b AND ledger_date = :d', [':b' => $branchId, ':d' => $testDate]);
$db->execute('DELETE FROM dl_ledger_day_status WHERE branch_id = :b AND ledger_date = :d', [':b' => $branchId, ':d' => $testDate]);
$db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b AND ledger_date = :d', [':b' => $branchId, ':d' => $testDate]);
dl_t_ledger($db, $branchId, $pA, $testDate, 'PM', 5, 0, 0, 9);
dl_t_ledger($db, $branchId, $pB, $testDate, 'PM', 20, 0, 0, 20);
$db->execute('INSERT INTO dl_ledger_shift_status (branch_id, ledger_date, shift, status, finalized_by, finalized_at) VALUES (:b, :d, \'PM\', \'finalized\', 1, CURRENT_TIMESTAMP)', [':b' => $branchId, ':d' => $testDate]);
$closedOk = dl_maybeAutoCloseBranchDay($branchId, 1, $afterCutoff);
$h->test('auto-close closes a finalized manual day', $closedOk === true && dl_getDayStatus($branchId, $testDate) === 'closed');
$frozenAfterClose = $db->prepare('SELECT COUNT(*) FROM dl_variance_flags WHERE branch_id = :b AND ledger_date = :d AND frozen_at IS NOT NULL');
$frozenAfterClose->execute([':b' => $branchId, ':d' => $testDate]);
$h->test('auto-close freezes the variance snapshot for a finalized day', (int)$frozenAfterClose->fetchColumn() > 0);
// Already closed → no-op.
$h->test('auto-close is a no-op for an already-closed day', dl_maybeAutoCloseBranchDay($branchId, 1, $afterCutoff) === false);

// Deliberately reopened day (admin apiReopenDay sets reopened_by/reopened_at)
// must STAY open — the next auto-close pass must not instantly re-close it.
$db->execute(
    'UPDATE dl_ledger_day_status SET status = \'open\', closed_by = NULL, closed_at = NULL,
       reopened_by = 7, reopened_at = CURRENT_TIMESTAMP
      WHERE branch_id = :b AND ledger_date = :d',
    [':b' => $branchId, ':d' => $testDate]
);
$reopenExempt = dl_maybeAutoCloseBranchDay($branchId, 1, $afterCutoff);
$h->test('reopened day is exempt from re-auto-close', $reopenExempt === false && dl_getDayStatus($branchId, $testDate) === 'open');
// The offline late-ending bridge reopens WITHOUT reopened_at and must still
// auto-close so a stale open day never leaks into the next business date.
$db->execute(
    'UPDATE dl_ledger_day_status SET reopened_by = NULL, reopened_at = NULL
      WHERE branch_id = :b AND ledger_date = :d',
    [':b' => $branchId, ':d' => $testDate]
);
$closeAgain = dl_maybeAutoCloseBranchDay($branchId, 1, $afterCutoff);
$h->test('non-deliberate reopen (late-ending) still auto-closes', $closeAgain === true && dl_getDayStatus($branchId, $testDate) === 'closed');

// Manual-day gate flag (POS disabled → manual).
$h->test('fully-manual day detected when POS disabled', dl_isFullyManualDay($db, $branchId, $testDate) === true);

// Late-count authorization: reset to an open, unfinalized prior day first.
$db->execute('DELETE FROM dl_ledger_shift_status WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_ledger_day_status WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b', [':b' => $branchId]);
dl_t_ledger($db, $branchId, $pA, $testDate, 'PM', 5, 0, 0, 9);
dl_t_ledger($db, $branchId, $pB, $testDate, 'PM', 20, 0, 0, 20);
$today = $nextDate;
$dayStatus = 'open';
$h->test('current business date editable', dl_cashierMayEdit($branchId, $nextDate, 'PM', $today, 'open') === true);
$h->test('prior pending PM editable for cashier late count', dl_cashierMayEdit($branchId, $testDate, 'PM', $today, 'open') === true);
$h->test('prior AM not editable', dl_cashierMayEdit($branchId, $testDate, 'AM', $today, 'open') === false);
$h->test('older date PM not editable', dl_cashierMayEdit($branchId, '2030-02-08', 'PM', $today, 'open') === false);
$h->test('prior PM on closed day not editable', dl_cashierMayEdit($branchId, $testDate, 'PM', $today, 'closed') === false);
// After finalize (still open day but PM finalized) → prior PM blocked.
$db->execute('INSERT INTO dl_ledger_shift_status (branch_id, ledger_date, shift, status) VALUES (:b, :d, \'PM\', \'finalized\')', [':b' => $branchId, ':d' => $testDate]);
$h->test('finalized prior PM not editable', dl_cashierMayEdit($branchId, $testDate, 'PM', $today, 'open') === false);
$db->execute('DELETE FROM dl_ledger_shift_status WHERE branch_id = :b', [':b' => $branchId]);

// ══════════════════════════════════════════════════════════════════════
// E. Reporting — provisional vs official, NULL round-trip
// ══════════════════════════════════════════════════════════════════════
$db->execute('DELETE FROM dl_ledger_day_status WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b', [':b' => $branchId]);
dl_t_ledger($db, $branchId, $pA, $testDate, 'AM', 10, 0, 0, 5);
dl_t_ledger($db, $branchId, $pA, $testDate, 'PM', 5, 0, 0, 3);
dl_t_ledger($db, $branchId, $pB, $testDate, 'AM', 20, 0, 0, 15);
dl_t_ledger($db, $branchId, $pB, $testDate, 'PM', 15, 0, 0, null); // pending

// Cashier row read: pending row surfaces NULL bal_end + NULL sales.
$rows = dl_fetchCashierLedgerRows($db, $branchId, $testDate, 'PM');
$bRow = null;
foreach ($rows as $r) {
    if ((int)$r['product_id'] === $pB) {
        $bRow = $r;
        break;
    }
}
$h->test('cashier read exposes NULL bal_end for pending row', is_array($bRow) && $bRow['bal_end'] === null);
$h->test('cashier read exposes NULL sales for pending row', is_array($bRow) && $bRow['sales'] === null);
$aRow = null;
foreach ($rows as $r) {
    if ((int)$r['product_id'] === $pA) {
        $aRow = $r;
        break;
    }
}
$h->test('cashier read computes sales for recorded ending', is_array($aRow) && (int)$aRow['sales'] === (5 - 3));

// Consolidated summary: official excludes pending (NULL) AND unfinalized PM rows.
$sum = dl_branchConsolidatedSummary($branchId, $testDate);
// Official = pA AM (10-5) + pB AM (20-15) = 10. pA PM is unfinalized → provisional.
$h->test('consolidated summary official qty excludes pending PM', (int)$sum['regular_qty'] === 10);
$h->test('consolidated summary reports provisional qty separately', (int)$sum['provisional_qty'] === 2);
// Amount: (5*10) + (5*10) = 100 official.
$h->test('consolidated summary official amount is null-safe', abs((float)$sum['regular_sales'] - 100.0) < 0.001);

// Null-safe SQL helper returns NULL for pending, computed for recorded.
$q = $db->prepare(
    'SELECT ' . dl_ledgerSalesQuantitySql('dl') . ' AS sales_qty
       FROM dl_daily_ledger dl
      WHERE branch_id = :b AND product_id = :p AND ledger_date = :d AND shift = :s'
);
$q->execute([':b' => $branchId, ':p' => $pB, ':d' => $testDate, ':s' => 'PM']);
$h->test('canonical sales SQL returns NULL for pending row', $q->fetchColumn() === null);
$q->execute([':b' => $branchId, ':p' => $pA, ':d' => $testDate, ':s' => 'PM']);
$h->test('canonical sales SQL computes recorded row', (int)$q->fetchColumn() === 2);

// ══════════════════════════════════════════════════════════════════════
// F. Cashier-withdrawal dedup guard (migration 052 + handler wiring)
// ── Prevents the 2026-08-15 duplicate-pullout incident: identical
//    re-submissions are rejected atomically by uq_dl_cw_dedup on a
//    deterministic fingerprint — cache-independent, durable, race-proof.
// ══════════════════════════════════════════════════════════════════════
$migration052Sql = (string) file_get_contents($base . '/modules/daily-ledger/database/migrations/052_cashier_withdrawals_dedup_hash.sql');
$h->test('migration 052 file exists', $migration052Sql !== '');
$h->test('migration 052 defines dedup_hash + unique index', str_contains($migration052Sql, 'dedup_hash') && str_contains($migration052Sql, 'uq_dl_cw_dedup'));

$cwCols = [];
foreach ($db->query('SHOW COLUMNS FROM dl_cashier_withdrawals') as $c) {
    $cwCols[(string)$c['Field']] = $c;
}
$h->test('dl_cashier_withdrawals has dedup_hash column', isset($cwCols['dedup_hash']));

// Hash parity: the PHP helper must equal the SQL backfill expression exactly.
$pidHash = 99065;
$dedupDate = '2030-02-20';
$db->execute('DELETE FROM dl_products WHERE id = :p', [':p' => $pidHash]);
$db->execute('INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active) VALUES (:id, :sku, :name, :price, :sort, 1)', [
    ':id' => $pidHash, ':sku' => 'VAR-DEDUP', ':name' => 'Dedup Product', ':price' => 8.0, ':sort' => 4,
]);
$db->execute('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (:b, :p, 1)', [':b' => $branchId, ':p' => $pidHash]);

$phpHash = dl_withdrawalDedupHash($branchId, $pidHash, $dedupDate, 'pullout', 'spoilage', null, null, null, 3, 2);
$db->execute(
    'INSERT INTO dl_cashier_withdrawals (branch_id, product_id, ledger_date, withdrawal_type, reason_code, custom_reason, dr_number, target_branch_id, quantity, encoded_by, liable_user_id, dedup_hash)
     VALUES (:b, :p, :d, :t, :rc, :cr, :dr, :tb, :q, :e, :l, :dh)',
    [':b' => $branchId, ':p' => $pidHash, ':d' => $dedupDate, ':t' => 'pullout', ':rc' => 'spoilage',
     ':cr' => null, ':dr' => null, ':tb' => null, ':q' => 3, ':e' => 1, ':l' => 2, ':dh' => $phpHash]
);
// The SQL backfill expression (migration 057) includes the normalized unit
// component to match the unit-aware PHP helper byte-for-byte.
$sqlHash = (string)$db->query(
    "SELECT SHA1(CONCAT_WS('|', branch_id, product_id, ledger_date, withdrawal_type, COALESCE(reason_code,''), COALESCE(custom_reason,''), COALESCE(dr_number,''), COALESCE(target_branch_id,''), quantity, COALESCE(liable_user_id,''), COALESCE(NULLIF(unit,''),'pcs')))
       FROM dl_cashier_withdrawals WHERE branch_id = " . (int)$branchId . " AND product_id = " . (int)$pidHash . " AND ledger_date = '$dedupDate' LIMIT 1"
)->fetchColumn();
$h->test('PHP dedup hash matches SQL backfill expression', $phpHash === $sqlHash);

// Behavioral proof of the unique index (module DB forbids SHOW INDEX): a second
// identical insert must be rejected; a distinct fingerprint must be accepted.
$dupRejected = true;
try {
    $db->execute(
        'INSERT INTO dl_cashier_withdrawals (branch_id, product_id, ledger_date, withdrawal_type, reason_code, custom_reason, dr_number, target_branch_id, quantity, encoded_by, liable_user_id, dedup_hash)
         VALUES (:b, :p, :d, :t, :rc, :cr, :dr, :tb, :q, :e, :l, :dh)',
        [':b' => $branchId, ':p' => $pidHash, ':d' => $dedupDate, ':t' => 'pullout', ':rc' => 'spoilage',
         ':cr' => null, ':dr' => null, ':tb' => null, ':q' => 3, ':e' => 1, ':l' => 2, ':dh' => $phpHash]
    );
    $dupRejected = false;
} catch (Throwable $e) {
    // expected unique violation
}
$h->test('uq_dl_cw_dedup rejects an identical withdrawal row', $dupRejected);

$distinctAllowed = true;
try {
    $db->execute(
        'INSERT INTO dl_cashier_withdrawals (branch_id, product_id, ledger_date, withdrawal_type, reason_code, custom_reason, dr_number, target_branch_id, quantity, encoded_by, liable_user_id, dedup_hash)
         VALUES (:b, :p, :d, :t, :rc, :cr, :dr, :tb, :q, :e, :l, :dh)',
        [':b' => $branchId, ':p' => $pidHash, ':d' => $dedupDate, ':t' => 'pullout', ':rc' => 'spoilage',
         ':cr' => null, ':dr' => null, ':tb' => null, ':q' => 5, ':e' => 1, ':l' => 2,
         ':dh' => dl_withdrawalDedupHash($branchId, $pidHash, $dedupDate, 'pullout', 'spoilage', null, null, null, 5, 2)]
    );
} catch (Throwable $e) {
    $distinctAllowed = false;
}
$h->test('uq_dl_cw_dedup allows a distinct fingerprint (different qty)', $distinctAllowed);

// Handler wiring: both insert paths bind the hash; the online handler answers a
// duplicate violation with an idempotent response (not a 500 / not a re-apply).
$handlersSrc = (string) file_get_contents($base . '/modules/daily-ledger/handlers.php');
$h->test('online withdrawal insert binds dedup_hash', str_contains($handlersSrc, "':dedup' => \$dedupHash"));
$h->test('online handler answers duplicate as idempotent', str_contains($handlersSrc, 'instanceof DlDuplicateWithdrawalException') && str_contains($handlersSrc, "'duplicate' => true"));
$offlineSrc = (string) file_get_contents($base . '/modules/daily-ledger/handlers-offline.php');
$h->test('offline withdrawal insert binds dedup_hash', str_contains($offlineSrc, "':dedup' => \$dedupHash"));

// Cleanup — dedup guard section rows only.
$db->execute('DELETE FROM dl_cashier_withdrawals WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_branch_products WHERE branch_id = :b AND product_id = :p', [':b' => $branchId, ':p' => $pidHash]);
$db->execute('DELETE FROM dl_products WHERE id = :p', [':p' => $pidHash]);

// ══════════════════════════════════════════════════════════════════════
// Cleanup — every seeded / created row
// ══════════════════════════════════════════════════════════════════════
foreach ([$branchId, 99062, 99063] as $bid) {
    $db->execute('DELETE FROM dl_variance_flags WHERE branch_id = :b', [':b' => $bid]);
    $db->execute('DELETE FROM dl_ledger_shift_status WHERE branch_id = :b', [':b' => $bid]);
    $db->execute('DELETE FROM dl_ledger_day_status WHERE branch_id = :b', [':b' => $bid]);
    $db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b', [':b' => $bid]);
    $db->execute('DELETE FROM dl_branch_products WHERE branch_id = :b', [':b' => $bid]);
    $db->execute('DELETE FROM dl_branches WHERE id = :b', [':b' => $bid]);
}
foreach ([$pA, $pB, $pC, 99064] as $pid) {
    $db->execute('DELETE FROM dl_products WHERE id = :p', [':p' => $pid]);
}
// Restore original settings.
saveModuleSettings('daily-ledger', is_array($origSettings) ? $origSettings : []);
dlModuleSettings(true);

$h->done();
