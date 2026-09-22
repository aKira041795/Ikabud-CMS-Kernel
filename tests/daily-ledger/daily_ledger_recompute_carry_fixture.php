<?php
declare(strict_types=1);

/**
 * Daily Ledger — Recompute-sales carry-forward fixture.
 *
 * One press of "Recompute sales" must carry the preceding shift's ending into every
 * beginning that was never recorded, instead of the cashier opening the per-row link on
 * each product. Proving that needs a sheet where the three cases differ, so this fixture
 * seeds its own branch with exactly three products:
 *
 *   CARRY-TEST-1  AM ending 10, PM beginning 0   -> carried to 10
 *   CARRY-TEST-2  AM ending  7, PM beginning 0   -> carried to  7
 *   CARRY-TEST-3  AM ending 30, PM beginning 25  -> recorded beginning, never touched
 *
 * Branch 99472 and products 99472-99474 are disjoint from the cell-adjustment fixture
 * (branch 99471) so the two specs can never tread on each other. No operational branch,
 * user or setting is modified: this data exists only for this spec and is removed by
 * `cleanup`.
 */

$base = dirname(__DIR__, 2);
require_once $base . '/src/helpers/cli-bootstrap.php';
$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/cli/daily-ledger/recompute-carry-fixture';
$app = kernelCliBootstrap($base);
$app->tenant()->setTenantId(207);
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/handlers.php';
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

$branchId = 99472;
$userId = 99472;

/** product_id => [am_ending, pm_beginning, create_pm_row] */
$products = [
    99472 => [10, 0, true],
    99473 => [7, 0, true],
    99474 => [30, 25, true],
    // No PM row at all: the "nobody has started this shift" case, where simply opening the
    // sheet must adopt the AM ending with no press from anyone.
    99475 => [5, null, false],
];
$pmAddtl = 100;
$pmWithdraw = 5;
$pmEnding = 10;

$mode = $argv[1] ?? '';
if (!in_array($mode, ['setup', 'cleanup', 'read', 'read-variance', 'zero', 'close', 'open'], true)) {
    fwrite(STDERR, "usage: fixture setup|cleanup|read|read-variance|zero|close|open\n");
    exit(2);
}

// What the variance engine recorded for this branch: an adopted beginning that somebody
// corrected must show up as `handoff` (PM beginning vs AM ending) or `overnight` (AM
// beginning vs the preceding ending).
if ($mode === 'read-variance') {
    echo json_encode($db->query(
        'SELECT product_id, kind, shift, variance, prev_bal_end, current_beg_bal
           FROM dl_variance_flags WHERE branch_id = 99472 ORDER BY id'
    )->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// `close` / `open` flip ONLY the day-status row. Driving apiCloseDay instead would need a
// finalized PM shift and a clean variance, which is a different test's subject; this spec is
// about what the CLOSED screen offers and refuses.
if ($mode === 'close' || $mode === 'open') {
    $dayStatus = $mode === 'close' ? 'closed' : 'open';
    $db->execute(
        'INSERT INTO dl_ledger_day_status (branch_id, ledger_date, status, closed_by, closed_at)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE status = VALUES(status), closed_by = VALUES(closed_by), closed_at = VALUES(closed_at)',
        [
            $branchId,
            dl_businessDate(),
            $dayStatus,
            $dayStatus === 'closed' ? $userId : null,
            $dayStatus === 'closed' ? date('Y-m-d H:i:s') : null,
        ]
    );
    echo json_encode(['ok' => true, 'status' => $dayStatus]);
    exit;
}

// `zero` puts the two unrecorded beginnings back to 0 without touching anything else,
// so a spec can re-run the carry from a clean sheet.
if ($mode === 'zero') {
    $db->execute(
        "UPDATE dl_daily_ledger SET beg_bal = 0,
                sales = GREATEST(0, COALESCE(beg_bal,0) + COALESCE(addtl,0) - COALESCE(withdraw,0) - COALESCE(bal_end,0))
          WHERE branch_id = ? AND shift = 'PM' AND product_id IN (99472, 99473)",
        [$branchId]
    );
    echo json_encode(['ok' => true]);
    exit;
}

if ($mode === 'read') {
    $rows = $db->query(
        'SELECT product_id, shift, beg_bal, addtl, withdraw, bal_end, sales
           FROM dl_daily_ledger WHERE branch_id = 99472 ORDER BY product_id, shift'
    )->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($rows);
    exit;
}

\Ikabud\Kernel\Database\KernelPDO::kernelEscalationEnter();
try {
    $app->db()->prepare('DELETE FROM audit_logs WHERE module = ? AND branch_id = ?')->execute(['daily-ledger', $branchId]);
} finally {
    \Ikabud\Kernel\Database\KernelPDO::kernelEscalationLeave();
}
foreach (['dl_variance_flags', 'dl_cashier_withdrawals', 'dl_ledger_shift_status', 'dl_ledger_day_status', 'dl_daily_ledger', 'dl_user_branches', 'dl_branch_products'] as $table) {
    $db->execute('DELETE FROM ' . $table . ' WHERE branch_id = ?', [$branchId]);
}
$db->execute('DELETE FROM dl_users WHERE id = ?', [$userId]);
$db->execute('DELETE FROM dl_branches WHERE id = ?', [$branchId]);
foreach (array_keys($products) as $pid) {
    $db->execute('DELETE FROM dl_products WHERE id = ?', [$pid]);
}
if ($mode === 'cleanup') {
    exit;
}

$date = dl_businessDate();
$db->execute('INSERT INTO dl_branches (id, code, name, default_supply_mode, is_active) VALUES (?, ?, ?, ?, 1)', [$branchId, 'CARRY-TEST', 'Ledger Carry Test', 'self_managed']);
$db->execute('INSERT INTO dl_users (id, username, full_name, password_hash, role, is_active) VALUES (?, ?, ?, ?, ?, 1)', [$userId, 'browser-carry-qa', 'Browser Carry QA', password_hash('BrowserCarry!2031', PASSWORD_BCRYPT), 'admin']);
$db->execute('INSERT INTO dl_user_branches (branch_id, user_id) VALUES (?, ?)', [$branchId, $userId]);

$expected = [];
foreach ($products as $pid => [$amEnding, $pmBeginning, $createPm]) {
    $db->execute('INSERT INTO dl_products (id, sku, name, current_price, pcs_per_pack, is_active) VALUES (?, ?, ?, 10, 12, 1)', [$pid, 'CARRY-TEST-' . substr((string)$pid, -1), 'Carry Test Product ' . substr((string)$pid, -1)]);
    $db->execute('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1)', [$branchId, $pid]);

    // AM ends the shift with its own count; that ending is what PM should be offered.
    $amBeg = 20;
    $amAddtl = 30;
    $amWithdraw = 0;
    $amSales = max(0, $amBeg + $amAddtl - $amWithdraw - $amEnding);
    $db->execute(
        'INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw, bal_end, sales, encoded_by, updated_by)
         VALUES (?, ?, ?, ?, 10, ?, ?, ?, ?, ?, ?, ?)',
        [$branchId, $pid, $date, 'AM', $amBeg, $amAddtl, $amWithdraw, $amEnding, $amSales, $userId, $userId]
    );

    if ($createPm) {
        $pmSalesBefore = max(0, $pmBeginning + $pmAddtl - $pmWithdraw - $pmEnding);
        $db->execute(
            'INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw, bal_end, sales, encoded_by, updated_by)
             VALUES (?, ?, ?, ?, 10, ?, ?, ?, ?, ?, ?, ?)',
            [$branchId, $pid, $date, 'PM', $pmBeginning, $pmAddtl, $pmWithdraw, $pmEnding, $pmSalesBefore, $userId, $userId]
        );
    }

    // No PM row means the beginning is unrecorded, so opening the sheet adopts the AM
    // ending; a PM row that already exists is never replaced, whatever it holds.
    $beginning = $createPm ? $pmBeginning : $amEnding;
    $expected[] = [
        'product_id' => $pid,
        'name' => 'Carry Test Product ' . substr((string)$pid, -1),
        'am_ending' => $amEnding,
        'pm_beginning' => $createPm ? $pmBeginning : null,
        'pm_row_exists' => $createPm,
        'carried' => $createPm && $pmBeginning !== 0 ? $pmBeginning : $amEnding,
        'sales_before' => max(0, $beginning + $pmAddtl - $pmWithdraw - $pmEnding),
        'sales_after' => max(0, ($createPm && $pmBeginning !== 0 ? $pmBeginning : $amEnding) + $pmAddtl - $pmWithdraw - $pmEnding),
    ];
}

echo json_encode([
    'date' => $date,
    'branch_id' => $branchId,
    'user' => 'browser-carry-qa',
    'password' => 'BrowserCarry!2031',
    'full_name' => 'Browser Carry QA',
    'pm_addtl' => $pmAddtl,
    'pm_withdraw' => $pmWithdraw,
    'pm_ending' => $pmEnding,
    'products' => $expected,
]);
