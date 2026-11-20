<?php

declare(strict_types=1);

/**
 * The Business Overview's single-pass aggregate must reproduce the separate scans it replaced,
 * and the schema fact it relies on must be asserted rather than assumed.
 *
 * WHY THIS EXISTS — the Overview used to ask the same 269k-row window three times (completeness,
 * per-branch signals, per-branch official sales): 214 ms + 2,433 ms + 3,472 ms measured on
 * 2026-11-20. dl_overviewWindowAggregate() answers all three in one grouped pass. A refactor of the
 * read path is only safe if "the numbers did not change" is provable, so the SAME window is
 * evaluated both ways here and every cell compared.
 *
 * The precision matters beyond theory: this page tells an owner what the business sold. A one-unit
 * or one-cent disagreement is a wrong claim about money.
 *
 * The second section pins the COLLATE decision. The aggregate joins dl_ledger_shift_status on
 * `shift` WITHOUT `COLLATE utf8mb4_unicode_ci`, which the rest of the module adds because
 * dl_variance_flags.shift is utf8mb4_0900_ai_ci. It is safe only while both ENUM columns share a
 * collation; if a future migration diverges them the join raises error 1267 at runtime, so this
 * fails first with a sentence that says what to do.
 *
 * The fixtures are SYNTHETIC ids (9978x) so nothing here depends on, or disturbs, live rows.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-overview-aggregate', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/helpers/reporting.php';
require_once $base . '/modules/daily-ledger/handlers.php';
app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

$branchA = 99781;   // official branch with a finalized AM and an unfinalized PM
$branchB = 99782;   // branch whose rows carry no shift-status at all
$prod1 = 99783;
$prod2 = 99784;
$d1 = '2032-05-02';
$d2 = '2032-05-03';

$cleanup = static function () use ($db, $branchA, $branchB, $prod1, $prod2): void {
    $db->prepare('DELETE FROM dl_daily_ledger WHERE branch_id IN (?, ?)')->execute([$branchA, $branchB]);
    $db->prepare('DELETE FROM dl_ledger_shift_status WHERE branch_id IN (?, ?)')->execute([$branchA, $branchB]);
    $db->prepare('DELETE FROM dl_variance_flags WHERE branch_id IN (?, ?)')->execute([$branchA, $branchB]);
    $db->prepare('DELETE FROM dl_branch_products WHERE branch_id IN (?, ?)')->execute([$branchA, $branchB]);
    $db->prepare('DELETE FROM dl_products WHERE id IN (?, ?)')->execute([$prod1, $prod2]);
    $db->prepare('DELETE FROM dl_branches WHERE id IN (?, ?)')->execute([$branchA, $branchB]);
};

$cleanup();
try {
    $db->prepare('INSERT INTO dl_branches (id, code, name, default_supply_mode, is_commissary, is_active) VALUES (?, ?, ?, ?, 0, 1)')
        ->execute([$branchA, 'AGG-A', 'Aggregate Branch A', 'commissary_supplied']);
    $db->prepare('INSERT INTO dl_branches (id, code, name, default_supply_mode, is_commissary, is_active) VALUES (?, ?, ?, ?, 0, 1)')
        ->execute([$branchB, 'AGG-B', 'Aggregate Branch B', 'commissary_supplied']);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active) VALUES (?, ?, ?, 10, 0, 1)')
        ->execute([$prod1, 'AGG-P1', 'Aggregate Product 1']);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active) VALUES (?, ?, ?, 25, 0, 1)')
        ->execute([$prod2, 'AGG-P2', 'Aggregate Product 2']);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1)')->execute([$branchA, $prod1]);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1)')->execute([$branchA, $prod2]);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1)')->execute([$branchB, $prod1]);

    // Branch A, day 1: AM finalized (official), PM still open (provisional).
    // The sales QUANTITY is not a stored column: dl_ledgerSalesQuantitySql() derives it as
    // GREATEST(0, beg_bal + addtl - withdraw - bal_end), and a NULL ending contributes NULL. The
    // fixture therefore expresses quantity through the movement columns, not through `sales`.
    $ins = $db->prepare('INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw, bal_end, sales, end_source) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    // A/d1/AM finalized: 10 + 0 - 0 - 6 = 4 units at 10.00 -> 40.00 official
    $ins->execute([$branchA, $prod1, $d1, 'AM', 10, 10, 0, 0, 6, null, null]);
    // A/d1/PM open: 5 - 3 = 2 units at 25.00 -> provisional
    $ins->execute([$branchA, $prod2, $d1, 'PM', 25, 5, 0, 0, 3, null, null]);
    // A/d2/AM finalized: 10 - 3 = 7 units at 10.00 -> 70.00 official
    $ins->execute([$branchA, $prod1, $d2, 'AM', 10, 10, 0, 0, 3, null, null]);
    // A/d2/PM with a DERIVED ending: 5 - 2 = 3 units, provisional by end_source.
    $ins->execute([$branchA, $prod2, $d2, 'PM', 25, 5, 0, 0, 2, null, 'derived-from-movements']);
    // A pending-ending row: bal_end NULL, counts as pending and contributes no quantity or money.
    // The withdrawal is 1 on purpose: since the owner rule of 2026-11-21 a row with no addition, no
    // withdrawal and no sale owes no hand-typed ending (it settles to its carried beginning), so a
    // beg-only row is no longer "pending". The quantity expression returns NULL wherever bal_end is
    // NULL (dl_ledgerSalesQuantitySql), so adding a withdrawal here cannot shift any money or units
    // in this fixture — it only keeps the row genuinely owed.
    $ins->execute([$branchA, $prod2, $d2, 'AM', 25, 5, 0, 1, null, null, null]);
    // Branch B: no shift-status rows at all, ending present -> 5 - 0 = 5 units, provisional.
    $ins->execute([$branchB, $prod1, $d1, 'PM', 10, 5, 0, 0, 0, null, null]);

    $db->prepare("INSERT INTO dl_ledger_shift_status (branch_id, ledger_date, shift, status) VALUES (?, ?, 'AM', 'finalized')")->execute([$branchA, $d1]);
    $db->prepare("INSERT INTO dl_ledger_shift_status (branch_id, ledger_date, shift, status) VALUES (?, ?, 'AM', 'finalized')")->execute([$branchA, $d2]);
    $db->prepare("INSERT INTO dl_ledger_shift_status (branch_id, ledger_date, shift, status) VALUES (?, ?, 'PM', 'open')")->execute([$branchA, $d1]);

    // One unreviewed flag and one corrected flag, so the variance half is exercised too.
    $flag = $db->prepare('INSERT INTO dl_variance_flags (branch_id, product_id, ledger_date, kind, variance, resolution_status, is_reviewed) VALUES (?, ?, ?, ?, ?, ?, 0)');
    $flag->execute([$branchA, $prod1, $d1, 'sales', -6, 'unreviewed']);
    $flag->execute([$branchA, $prod2, $d1, 'sales', 9, 'corrected']);

    $filters = dl_reportFilters(['scope' => 'ALL', 'date_from' => $d1, 'date_to' => $d2], ['id' => 1, 'role' => 'admin', 'source' => 'daily-ledger']);
    $filters['accessible_branch_ids'] = [$branchA, $branchB];
    $filters['branch_id'] = 0;
    $filters['date_from'] = $d1;
    $filters['date_to'] = $d2;

    // ── Section 1: the aggregate equals the scans it replaced ───────────────────────────────
    $h->section('Single pass agrees with the separate scans');
    $agg = dl_overviewWindowAggregate($db, $filters);

    $completeness = dl_overviewLedgerCompleteness($db, $filters);
    $sumRows = 0;
    $sumPending = 0;
    $sumCompleted = 0;
    foreach ($agg as $row) {
        $sumRows += $row['rows_total'];
        $sumPending += $row['pending'];
        $sumCompleted += $row['completed'];
    }
    $h->test(
        'completeness matches dl_overviewLedgerCompleteness()',
        $sumRows === (int)$completeness['rows_total']
            && $sumPending === (int)$completeness['pending']
            && $sumCompleted === (int)$completeness['completed'],
        "aggregate rows={$sumRows} pending={$sumPending} completed={$sumCompleted} | "
        . "completeness rows={$completeness['rows_total']} pending={$completeness['pending']} completed={$completeness['completed']}"
    );

    $signalOracle = $db->prepare(
        "SELECT dl.branch_id, COUNT(*) rows_total,
                SUM(CASE WHEN dl.bal_end IS NULL THEN 1 ELSE 0 END) pending,
                SUM(CASE WHEN dl.bal_end IS NOT NULL THEN 1 ELSE 0 END) completed,
                SUM(CASE WHEN dl.bal_end IS NULL AND ss.status = 'finalized' THEN 1 ELSE 0 END) pending_locked
           FROM dl_daily_ledger dl
           LEFT JOIN dl_ledger_shift_status ss
             ON ss.branch_id = dl.branch_id AND ss.ledger_date = dl.ledger_date
            AND ss.shift = dl.shift COLLATE utf8mb4_unicode_ci
          WHERE dl.ledger_date BETWEEN ? AND ? AND dl.branch_id IN (?, ?)
          GROUP BY dl.branch_id"
    );
    $signalOracle->execute([$d1, $d2, $branchA, $branchB]);
    $oracle = $signalOracle->fetchAll(PDO::FETCH_ASSOC);
    $signalOk = count($oracle) === count($agg);
    $signalDetail = 'count ' . count($oracle) . ' vs ' . count($agg);
    foreach ($oracle as $o) {
        $n = $agg[(int)$o['branch_id']] ?? null;
        if ($n === null) {
            $signalOk = false;
            $signalDetail .= " | missing branch {$o['branch_id']}";
            continue;
        }
        foreach (['rows_total', 'pending', 'completed', 'pending_locked'] as $k) {
            if ((int)$o[$k] !== (int)$n[$k]) {
                $signalOk = false;
                $signalDetail .= " | b{$o['branch_id']}.{$k} {$o[$k]} vs {$n[$k]}";
            }
        }
    }
    $h->test('per-branch signals match the independent grouped scan', $signalOk, $signalDetail);

    // The official money the aggregate reports must be exactly what the canonical predicate says:
    // AM-finalized 4x10=40 and 7x10=70; PM-open 2x25, derived 3x25 and the NULL-ending row are
    // provisional and contribute nothing; branch B has no status row so its PM row is provisional.
    $h->test(
        'official amount counts only rows the canonical rule calls official',
        abs(($agg[$branchA]['official_amount'] ?? 0) - 110.0) < 0.005
            && ($agg[$branchB]['official_amount'] ?? -1) == 0.0,
        'A=' . ($agg[$branchA]['official_amount'] ?? 'n/a') . ' (expected 110.00) B=' . ($agg[$branchB]['official_amount'] ?? 'n/a') . ' (expected 0.00)'
    );
    $h->test(
        'inclusive units keep every row while official units drop the provisional ones',
        (int)($agg[$branchA]['units'] ?? 0) === 16
            && (int)($agg[$branchA]['official_units'] ?? 0) === 11,
        'units=' . ($agg[$branchA]['units'] ?? 'n/a') . ' (expected 16) official=' . ($agg[$branchA]['official_units'] ?? 'n/a') . ' (expected 11)'
    );
    $h->test(
        'pending-locked counts only rows on a FINALIZED shift',
        (int)($agg[$branchA]['pending_locked'] ?? -1) === 1,
        'pending_locked=' . ($agg[$branchA]['pending_locked'] ?? 'n/a') . ' (expected 1: the NULL ending sits on d2/AM, whose shift is finalized)'
    );

    // "Official rows only" basis: the inclusive figure must collapse onto the official one.
    $aggExcl = dl_overviewWindowAggregate($db, $filters, true);
    $h->test(
        'the official-only basis makes units and official_units one population',
        (int)($aggExcl[$branchA]['units'] ?? -1) === (int)($aggExcl[$branchA]['official_units'] ?? -2),
        'units=' . ($aggExcl[$branchA]['units'] ?? 'n/a') . ' official=' . ($aggExcl[$branchA]['official_units'] ?? 'n/a')
    );

    $h->test(
        'variance signals ignore corrected flags and keep the absolute sum',
        (int)(dl_overviewVarianceSignals($db, $filters)[$branchA]['unresolved_variances'] ?? -1) === 1
            && (int)(dl_overviewVarianceSignals($db, $filters)[$branchA]['absolute_variance'] ?? -1) === 6,
        'unresolved=' . (dl_overviewVarianceSignals($db, $filters)[$branchA]['unresolved_variances'] ?? 'n/a')
        . ' absolute=' . (dl_overviewVarianceSignals($db, $filters)[$branchA]['absolute_variance'] ?? 'n/a')
    );

    // ── Section 2: the schema fact the COLLATE removal depends on ───────────────────────────
    // Asserted BEHAVIOURALLY, not by reading information_schema (which ModuleDB refuses under
    // module context, and which would only restate metadata). The thing that can actually break is
    // the join itself: if a future migration diverges the two ENUM columns' collations, this exact
    // join raises SQLSTATE 42S22 / error 1267 "Illegal mix of collations". So the test performs the
    // join the aggregate performs.
    $h->section('The join collation is a schema fact, not an assumption');
    $joinError = '';
    try {
        $probe = $db->prepare(
            'SELECT COUNT(*) FROM dl_daily_ledger dl'
            . ' LEFT JOIN dl_ledger_shift_status ss'
            . ' ON ss.branch_id = dl.branch_id AND ss.ledger_date = dl.ledger_date'
            . ' AND ss.shift = dl.shift'
            . ' WHERE dl.branch_id IN (?, ?)'
        );
        $probe->execute([$branchA, $branchB]);
        $probe->fetchColumn();
    } catch (Throwable $e) {
        $joinError = $e->getMessage();
    }
    $h->test(
        'the shift join without COLLATE is legal on this schema',
        $joinError === '',
        'The join raised: ' . $joinError
        . ' — the two shift columns no longer share a collation. Restore '
        . '`COLLATE utf8mb4_unicode_ci` on the join in dl_overviewWindowAggregate() (it costs ~1.4 s '
        . 'per scan but is required when the collations differ).'
    );
} finally {
    $cleanup();
}

$h->done();
