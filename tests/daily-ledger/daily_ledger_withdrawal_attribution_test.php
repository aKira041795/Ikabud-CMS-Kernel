<?php

declare(strict_types=1);

/**
 * GUARD RAIL — every withdrawal in a branch's book must be attributable in that branch's sheet.
 *
 * Owner decision 2026-11-10, choosing between debiting the sender at send time or at receipt time:
 * keep the debit at send, but make it VISIBLE. The reason is the client report:
 *
 *   "ang mga entry delivery ni commissary automatic ma-withdraw kang branch bisan wla pa silay
 *    gi-entry or gi-receive sa receiving stock."
 *
 * A delivery debits the sending branch the moment it is recorded, and a paper DR captured by the
 * RECEIVING branch debits the ORIGIN branch from another branch's action. Neither writes a
 * dl_cashier_withdrawals row, so the Daily Sheet showed a W/Draw figure larger than the entries
 * listed underneath it: a withdrawal nobody in that branch made, with nothing on screen to explain
 * it. dl_unattributedWithdrawalRows() closes exactly that gap.
 *
 * This test pins BOTH directions, because a reconciliation that only ever adds rows is as wrong as
 * one that never does — it would show a phantom "delivery" against a cashier's own pullout.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-withdrawal-attribution', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();
$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/handlers.php';
$h->fingerprint('modules/daily-ledger/handlers.php');
$h->allowLogLines('kernel_state_cache: module_registry rebuilt');
$h->allowLogLines('disyl.compile.phases');
app()->tenant()->setTenantId(207);
$db = modulePushContext('daily-ledger')->db();

// dl_daily_ledger enforces a foreign key to dl_branches, so the fixture needs a REAL branch. It is
// isolated by DATE instead: 2035-02-11 cannot carry real rows, and cleanup targets exactly
// (branch, date), so nothing a real branch owns is ever touched.
$branchId = (int)($db->query('SELECT id FROM dl_branches ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
$productId = (int)($db->query('SELECT id FROM dl_products WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
$date = '2035-02-11';
$ledgerId = 0;
$cashierId = 0;
$dedupHash = substr(hash('sha1', 'attribution-' . $branchId . '-' . random_int(1000, 9999)), 0, 40);

$cleanup = static function () use ($db, &$ledgerId, &$cashierId, $branchId, $date): void {
    if ($ledgerId > 0) {
        $db->execute('DELETE FROM dl_daily_ledger WHERE id = ?', [$ledgerId]);
    }
    if ($cashierId > 0) {
        $db->execute('DELETE FROM dl_cashier_withdrawals WHERE id = ?', [$cashierId]);
    }
    // Belt and braces: any residue for this synthetic branch/date, whatever path created it.
    $db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = ? AND ledger_date = ?', [$branchId, $date]);
    $db->execute('DELETE FROM dl_cashier_withdrawals WHERE branch_id = ? AND ledger_date = ?', [$branchId, $date]);
};

$h->section('A ledger withdrawal with no cashier row is surfaced, not hidden');

if ($productId <= 0 || $branchId <= 0) {
    $h->test('a branch and product exist to attribute against', false);
    $h->done();
    exit;
}

// Never seed onto a date that already carries data: the assertions below assume this fixture is the
// only writer, and cleanup deletes by (branch, date).
$preexisting = (int)$db->query('SELECT COUNT(*) FROM dl_daily_ledger WHERE branch_id = ' . (int)$branchId . " AND ledger_date = '2035-02-11'")->fetchColumn();
$h->test('the future fixture date is untouched before seeding', $preexisting === 0);
if ($preexisting !== 0) {
    $h->done();
    exit;
}

$db->prepare(
    'INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw, bal_end)
     VALUES (:bid, :pid, :d, "AM", 0, 0, 0, 7, NULL)'
)->execute([':bid' => $branchId, ':pid' => $productId, ':d' => $date]);
$ledgerId = (int)$db->lastInsertId();

$rows = dl_unattributedWithdrawalRows($db, $branchId, $date, 'AM');
$h->test('the unexplained ledger withdrawal produces exactly one reconciliation row', count($rows) === 1);
$h->test('the row carries the whole unexplained quantity', (int)($rows[0]['quantity'] ?? -1) === 7);
$h->test('the row is typed as a delivery dispatch, not as a cashier entry', (string)($rows[0]['withdrawal_type'] ?? '') === 'delivery_dispatch');
$h->test('the row can never be edited from this branch', ($rows[0]['editable'] ?? true) === false);
$h->test(
    'the row says plainly that no delivery document explained it',
    str_contains((string)($rows[0]['reason_code_label'] ?? ''), 'Recorded outside this branch')
);
$h->test('the product is named so the row is actionable', (string)($rows[0]['product_name'] ?? '') !== '');

$h->section('The reconciliation disappears once the branch has its own row for it');

$db->prepare(
    'INSERT INTO dl_cashier_withdrawals (branch_id, product_id, ledger_date, shift, withdrawal_type, quantity, unit, dedup_hash)
     VALUES (:bid, :pid, :d, "AM", "pullout", 7, "pcs", :hash)'
)->execute([':bid' => $branchId, ':pid' => $productId, ':d' => $date, ':hash' => $dedupHash]);
$cashierId = (int)$db->lastInsertId();

$afterRows = dl_unattributedWithdrawalRows($db, $branchId, $date, 'AM');
$h->test('a fully explained withdrawal raises no reconciliation row', $afterRows === []);
$h->test(
    'the cashier row and the ledger figure now agree, so nothing is owed an explanation',
    (int)($db->query('SELECT withdraw FROM dl_daily_ledger WHERE id = ' . (int)$ledgerId)->fetchColumn() ?: 0) === 7
);

$h->section('A partly explained withdrawal only reports the remainder');

$db->prepare('UPDATE dl_cashier_withdrawals SET quantity = 3 WHERE id = :id')->execute([':id' => $cashierId]);
$partial = dl_unattributedWithdrawalRows($db, $branchId, $date, 'AM');
$h->test('the unreconciled remainder is what surfaces', count($partial) === 1 && (int)($partial[0]['quantity'] ?? -1) === 4);

$cleanup();
// Scoped to the fixture DATE, not the branch: the branch is real and owns thousands of legitimate
// rows, so a branch-wide count would be asserted against live data and could never be zero.
$h->test(
    'the fixture leaves no residue in the ledger',
    (int)$db->query('SELECT COUNT(*) FROM dl_daily_ledger WHERE branch_id = ' . (int)$branchId . " AND ledger_date = '2035-02-11'")->fetchColumn() === 0
);

$h->done();
