<?php

declare(strict_types=1);

/**
 * Daily Ledger — close-order and finalize-recovery (incident contract
 * .ai/dl-close-order-and-finalize-recovery.contract.md).
 *
 * Acceptance oracle for the four deliverables:
 *   A. an unfinalized manual day still CLOSES at the cutoff and the admin is
 *      notified (owner directive 2026-10-05; supersedes contract D1, which
 *      refused the close and left the day open);
 *   B. a cashier is STILL refused on a closed day, the refusal names the real
 *      remedy (day closed + admin must reopen), and the admin reopen path
 *      actually lets the cashier write again;
 *   C. no-movement products must not block finalization;
 *   D. a product WITH movement and NO ending STILL blocks (the guard bites);
 *   E. closed_without_pm_finalize raises a dl_integrity_notifications row.
 *
 * Everything is seeded on reserved 99xxx ids and a throwaway date pair derived
 * from the live business date, then removed in finally.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-close-order-recovery', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();
$h->allowLogLines('disyl.compile.phases');

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers-offline.php';
require_once $base . '/modules/daily-ledger/handlers.php';
app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
/** @var \Ikabud\Kernel\Contracts\DatabaseContract $db */
$db = $ctx->db();

$commBranchId  = 99081;   // commissary (production sheet path)
$branchId      = 99082;   // branch (cashier ledger path)
$prodMoved     = 99081;   // has activity
$prodUntouched = 99082;   // no activity at all
$cashierId     = 999801;
$adminId       = 999802;

$today = dl_businessDate();
$prev  = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');

$cleanup = static function () use ($db, $commBranchId, $branchId, $prodMoved, $prodUntouched, $cashierId, $adminId): void {
    foreach ([$commBranchId, $branchId] as $b) {
        $db->execute('DELETE FROM dl_integrity_notification_recipients WHERE notification_id IN (SELECT id FROM dl_integrity_notifications WHERE branch_id = :b)', [':b' => $b]);
        $db->execute('DELETE FROM dl_integrity_notifications WHERE branch_id = :b', [':b' => $b]);
        $db->execute('DELETE FROM audit_logs WHERE branch_id = :b AND created_at >= (NOW() - INTERVAL 2 HOUR)', [':b' => $b]);
        $db->execute('DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id = :b', [':b' => $b]);
        $db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b', [':b' => $b]);
        $db->execute('DELETE FROM dl_ledger_shift_status WHERE branch_id = :b', [':b' => $b]);
        $db->execute('DELETE FROM dl_ledger_day_status WHERE branch_id = :b', [':b' => $b]);
        $db->execute('DELETE FROM dl_variance_flags WHERE branch_id = :b', [':b' => $b]);
    }
    $db->execute('DELETE FROM dl_branch_products WHERE branch_id IN (:b1, :b2)', [':b1' => $commBranchId, ':b2' => $branchId]);
    $db->execute('DELETE FROM dl_user_branches WHERE user_id IN (:c, :a)', [':c' => $cashierId, ':a' => $adminId]);
    $db->execute('DELETE FROM dl_users WHERE id IN (:c, :a)', [':c' => $cashierId, ':a' => $adminId]);
    $db->execute('DELETE FROM dl_branches WHERE id IN (:b1, :b2)', [':b1' => $commBranchId, ':b2' => $branchId]);
    $db->execute('DELETE FROM dl_products WHERE id IN (:p1, :p2)', [':p1' => $prodMoved, ':p2' => $prodUntouched]);
};

$cleanup();

$dayStatusOf = static function (int $b, string $d) use ($db): string {
    $s = $db->prepare('SELECT status FROM dl_ledger_day_status WHERE branch_id = :b AND ledger_date = :d LIMIT 1');
    $s->execute([':b' => $b, ':d' => $d]);
    $v = $s->fetchColumn();
    return $v === false ? 'open' : (string)$v;
};
$shiftStatusOf = static function (int $b, string $d, string $shift) use ($db): ?string {
    $s = $db->prepare('SELECT status FROM dl_ledger_shift_status WHERE branch_id = :b AND ledger_date = :d AND shift = :sh LIMIT 1');
    $s->execute([':b' => $b, ':d' => $d, ':sh' => $shift]);
    $v = $s->fetchColumn();
    return $v === false ? null : (string)$v;
};

try {
    // ── Fixtures ────────────────────────────────────────────────────────────────
    $db->execute(
        'INSERT INTO dl_branches (id, code, name, address, default_supply_mode, is_commissary, is_active)
         VALUES (:id, :code, :name, :addr, "self_managed", :comm, 1)',
        [':id' => $commBranchId, ':code' => 'T-CLOSE-COMM', ':name' => 'Close-Order Commissary', ':addr' => 'Test', ':comm' => 1]
    );
    $db->execute(
        'INSERT INTO dl_branches (id, code, name, address, default_supply_mode, is_commissary, is_active)
         VALUES (:id, :code, :name, :addr, "self_managed", 0, 1)',
        [':id' => $branchId, ':code' => 'T-CLOSE-BR', ':name' => 'Close-Order Branch', ':addr' => 'Test']
    );
    $db->execute(
        'INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active) VALUES (:id, :sku, :n, 1, 99081, 1)',
        [':id' => $prodMoved, ':sku' => 'FIX-CLOSE-1', ':n' => 'Close-Order Moved Product']
    );
    $db->execute(
        'INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active) VALUES (:id, :sku, :n, 1, 99082, 1)',
        [':id' => $prodUntouched, ':sku' => 'FIX-CLOSE-2', ':n' => 'Close-Order Untouched Product']
    );
    foreach ([$commBranchId, $branchId] as $b) {
        foreach ([$prodMoved, $prodUntouched] as $p) {
            $db->execute('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (:b, :p, 1)', [':b' => $b, ':p' => $p]);
        }
    }
    $db->execute(
        'INSERT INTO dl_users (id, username, password_hash, full_name, role, shift, is_active) VALUES (:id, :u, :p, :n, "cashier", "PM", 1)',
        [':id' => $cashierId, ':u' => 'fixture-close-cashier', ':p' => 'not-a-login-hash', ':n' => 'Fixture Close Cashier']
    );
    $db->execute('INSERT INTO dl_user_branches (user_id, branch_id) VALUES (:u, :b)', [':u' => $cashierId, ':b' => $branchId]);
    $db->execute(
        'INSERT INTO dl_users (id, username, password_hash, full_name, role, shift, is_active) VALUES (:id, :u, :p, :n, "admin", NULL, 1)',
        [':id' => $adminId, ':u' => 'fixture-close-admin', ':p' => 'not-a-login-hash', ':n' => 'Fixture Close Admin']
    );

    $cashier = [
        'id' => $cashierId, 'sub' => 'cashier:' . $cashierId, 'role' => 'cashier', 'shift' => 'PM',
        'source' => 'daily-ledger', 'username' => 'fixture-close-cashier', 'name' => 'Fixture Close Cashier',
    ];
    $prevAuthHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . app()->jwt()->generate($cashier + ['token_type' => 'access']);

    // ══════════════════════════════════════════════════════════════════════════
    // A. an unfinalized day closes + notifies; the admin reopen is the remedy
    // ══════════════════════════════════════════════════════════════════════════
    $h->section('A. close ordering (owner directive 2026-10-05)');

    $db->execute('DELETE FROM dl_ledger_day_status WHERE branch_id = :b AND ledger_date = :d', [':b' => $branchId, ':d' => $prev]);
    $db->execute('DELETE FROM dl_ledger_shift_status WHERE branch_id = :b AND ledger_date = :d', [':b' => $branchId, ':d' => $prev]);
    $db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b AND ledger_date = :d', [':b' => $branchId, ':d' => $prev]);
    // A legitimate unfinished PM: a moved product whose ending was never recorded.
    $db->execute(
        'INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, beg_bal, bal_end, sales) VALUES (:b, :p, :d, "PM", 5, NULL, NULL)',
        [':b' => $branchId, ':p' => $prodMoved, ':d' => $prev]
    );

    $closeRan = false;
    try {
        $closeRan = dl_maybeAutoCloseBranchDay($branchId, $adminId, null);
    } catch (Throwable $e) {
        $h->detail('A close threw: ' . $e->getMessage());
    }
    $dayA = $dayStatusOf($branchId, $prev);
    $pmA = $shiftStatusOf($branchId, $prev, 'PM');
    $notifAStmt = $db->prepare('SELECT COUNT(*) FROM dl_integrity_notifications WHERE aggregate_key = :k');
    $notifAStmt->execute([':k' => 'closed_without_pm_finalize-day-' . $branchId . '-' . $prev]);
    $notifACount = (int)$notifAStmt->fetchColumn();
    $h->test(
        'A1 an unfinalized manual day CLOSES and the admin is notified (no day left open)',
        $closeRan === true && $dayA === 'closed' && $pmA === 'open' && $notifACount === 1,
        json_encode(['close_returned' => $closeRan, 'day' => $dayA, 'pm' => $pmA, 'notifications' => $notifACount])
    );

    // ══════════════════════════════════════════════════════════════════════════
    // B. (D2) guard holds, refusal names the remedy, admin reopen restores writes
    // ══════════════════════════════════════════════════════════════════════════
    $h->section('B. honest refusal + sanctioned admin repair (D2)');

    $db->execute(
        'INSERT INTO dl_ledger_day_status (branch_id, ledger_date, status, closed_by, closed_at)
         VALUES (:b, :d, "closed", :u, NOW())
         ON DUPLICATE KEY UPDATE status = "closed", closed_by = VALUES(closed_by), closed_at = NOW()',
        [':b' => $branchId, ':d' => $prev, ':u' => $adminId]
    );
    $db->execute(
        'INSERT INTO dl_ledger_shift_status (branch_id, ledger_date, shift, status)
         VALUES (:b, :d, "PM", "open")
         ON DUPLICATE KEY UPDATE status = "open", finalized_by = NULL, finalized_at = NULL',
        [':b' => $branchId, ':d' => $prev]
    );

    // A NON-ending field: the only existing closed-day bridge is a cashier PM
    // bal_end (the late-ending reconcile), so beg_bal is the field that proves
    // the guard still refuses and exercises the honest refusal message.
    $refusal = null;
    try {
        dl_offlineApplyLedgerSave($cashier, [
            'type' => 'ledger_save',
            'payload' => ['branch_id' => $branchId, 'product_id' => $prodMoved, 'field' => 'beg_bal', 'value' => 7, 'date' => $prev, 'shift' => 'PM'],
        ], true);
    } catch (Throwable $e) {
        $refusal = ['message' => $e->getMessage(), 'code' => (int)$e->getCode()];
    }
    $h->test(
        'B1 the closed-day guard still REFUSES a cashier (403)',
        is_array($refusal) && $refusal['code'] === 403,
        json_encode($refusal)
    );
    $h->test(
        'B2 the refusal names the closed day and the admin-only remedy (not the bare "Reference only")',
        is_array($refusal)
            && stripos($refusal['message'], 'closed') !== false
            && stripos($refusal['message'], 'admin') !== false
            && stripos($refusal['message'], 'reopen') !== false
            && strcasecmp($refusal['message'], 'Reference only') !== 0,
        'message=' . json_encode($refusal['message'] ?? null)
    );

    $writtenBefore = $db->prepare('SELECT beg_bal FROM dl_daily_ledger WHERE branch_id = :b AND product_id = :p AND ledger_date = :d AND shift = "PM"');
    $writtenBefore->execute([':b' => $branchId, ':p' => $prodMoved, ':d' => $prev]);
    $h->test('B3 the refused write changed nothing', (int)($writtenBefore->fetchColumn() ?: 0) === 5);

    $reopenSeam = 'dl_reopenDayService';
    $reopenExists = function_exists($reopenSeam);
    $h->test(
        'B4 the admin reopen is a testable service the handler uses (' . $reopenSeam . ')',
        $reopenExists,
        $reopenExists ? 'present' : 'MISSING — apiReopenDay is an exit()-terminating handler, so its behaviour cannot be asserted'
    );

    $reopenRan = false;
    if ($reopenExists) {
        try {
            dl_reopenDayService($db, $branchId, $prev, $adminId);
            $reopenRan = true;
        } catch (Throwable $e) {
            $h->detail('reopen threw: ' . $e->getMessage());
        }
    }
    $reopenedAt = $db->prepare('SELECT reopened_at FROM dl_ledger_day_status WHERE branch_id = :b AND ledger_date = :d');
    $reopenedAt->execute([':b' => $branchId, ':d' => $prev]);
    $reopenedAtVal = $reopenedAt->fetchColumn();
    $h->test(
        'B5 admin reopen puts the day back to open with reopened_at set (the exemption)',
        $reopenRan
            && $dayStatusOf($branchId, $prev) === 'open'
            && $reopenedAtVal !== false && $reopenedAtVal !== null && (string)$reopenedAtVal !== '',
        json_encode(['ran' => $reopenRan, 'day' => $dayStatusOf($branchId, $prev), 'reopened_at' => $reopenedAtVal])
    );

    $writeAfter = null;
    try {
        $writeAfter = dl_offlineApplyLedgerSave($cashier, [
            'type' => 'ledger_save',
            'payload' => ['branch_id' => $branchId, 'product_id' => $prodMoved, 'field' => 'beg_bal', 'value' => 7, 'date' => $prev, 'shift' => 'PM'],
        ], true);
    } catch (Throwable $e) {
        $writeAfter = ['error' => $e->getMessage(), 'code' => (int)$e->getCode()];
    }
    $writtenAfter = $db->prepare('SELECT beg_bal FROM dl_daily_ledger WHERE branch_id = :b AND product_id = :p AND ledger_date = :d AND shift = "PM"');
    $writtenAfter->execute([':b' => $branchId, ':p' => $prodMoved, ':d' => $prev]);
    $h->test(
        'B6 after the admin reopen the same cashier write SUCCEEDS and persists',
        is_array($writeAfter) && !empty($writeAfter['ok']) && (int)($writtenAfter->fetchColumn() ?: 0) === 7,
        json_encode(['result' => $writeAfter])
    );

    // ══════════════════════════════════════════════════════════════════════════
    // C. (D3) no-movement products must not block finalization
    // ══════════════════════════════════════════════════════════════════════════
    $h->section('C. no-movement products are not missing (D3)');

    // C(cashier): the branch ledger missing-endings query.
    $db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b AND ledger_date = :d', [':b' => $branchId, ':d' => $prev]);
    $db->execute(
        'INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, beg_bal, bal_end, sales) VALUES (:b, :p, :d, "PM", 5, 10, 0)',
        [':b' => $branchId, ':p' => $prodMoved, ':d' => $prev]
    );
    $missingCashier = dl_shiftMissingEndings($db, $branchId, $prev, 'PM');
    $h->test(
        'C1 (cashier) a shift whose moved product has an ending is not blocked by an untouched product',
        $missingCashier === [],
        json_encode(['missing' => array_column($missingCashier, 'product_id')])
    );

    // C(production): the commissary auto-finalize.
    $db->execute('DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id = :b AND ledger_date = :d', [':b' => $commBranchId, ':d' => $prev]);
    $db->execute('DELETE FROM dl_ledger_shift_status WHERE branch_id = :b AND ledger_date = :d', [':b' => $commBranchId, ':d' => $prev]);
    $db->execute('DELETE FROM dl_ledger_day_status WHERE branch_id = :b AND ledger_date = :d', [':b' => $commBranchId, ':d' => $prev]);
    $db->execute(
        'INSERT INTO dl_commissary_product_ledger (commissary_branch_id, product_id, ledger_date, shift, beg_qty, produced_qty, dispatched_qty, wastage_qty, actual_end_qty)
         VALUES (:b, :p, :d, "PM", 5, 0, 0, 0, 10)',
        [':b' => $commBranchId, ':p' => $prodMoved, ':d' => $prev]
    );
    $resC = dl_maybeAutoFinalizeCommissaryPmShift($commBranchId, $prev, $adminId);
    $pmC = $shiftStatusOf($commBranchId, $prev, 'PM');
    $h->test(
        'C2 (production) a shift whose moved products all have endings FINALIZES',
        ($resC['finalized'] ?? false) === true && ($resC['missing'] ?? -1) === 0 && $pmC === 'finalized',
        json_encode(['result' => $resC, 'pm' => $pmC])
    );

    // ══════════════════════════════════════════════════════════════════════════
    // D. (D3 other direction) movement without an ending STILL blocks
    // ══════════════════════════════════════════════════════════════════════════
    $h->section('D. the guard still bites (D3)');

    // D(cashier)
    $db->execute('UPDATE dl_daily_ledger SET bal_end = NULL, sales = NULL WHERE branch_id = :b AND product_id = :p AND ledger_date = :d AND shift = "PM"', [':b' => $branchId, ':p' => $prodMoved, ':d' => $prev]);
    $missingDCashier = dl_shiftMissingEndings($db, $branchId, $prev, 'PM');
    $h->test(
        'D1 (cashier) a product WITH movement and NO ending is still reported missing',
        count($missingDCashier) === 1 && (int)$missingDCashier[0]['product_id'] === $prodMoved,
        json_encode(['missing' => array_column($missingDCashier, 'product_id')])
    );

    // D(production): reset the cpl rows, then a moved product with no ending.
    $db->execute('DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id = :b AND ledger_date = :d', [':b' => $commBranchId, ':d' => $prev]);
    $db->execute(
        'INSERT INTO dl_commissary_product_ledger (commissary_branch_id, product_id, ledger_date, shift, beg_qty, produced_qty, dispatched_qty, wastage_qty, actual_end_qty)
         VALUES (:b, :p, :d, "PM", 5, 0, 0, 0, NULL)',
        [':b' => $commBranchId, ':p' => $prodMoved, ':d' => $prev]
    );
    $db->execute(
        'UPDATE dl_ledger_shift_status SET status = "open", finalized_by = NULL, finalized_at = NULL, pending_notified_at = NULL
          WHERE branch_id = :b AND ledger_date = :d AND shift = "PM"',
        [':b' => $commBranchId, ':d' => $prev]
    );
    $resD = dl_maybeAutoFinalizeCommissaryPmShift($commBranchId, $prev, $adminId);
    $h->test(
        'D2 (production) a moved product without an ending STILL flags (never force-finalizes)',
        ($resD['flagged'] ?? false) === true && ($resD['finalized'] ?? true) === false && ($resD['missing'] ?? 0) >= 1,
        json_encode(['result' => $resD])
    );

    // ══════════════════════════════════════════════════════════════════════════
    // E. (D4) closed_without_pm_finalize raises an admin integrity notification
    // ══════════════════════════════════════════════════════════════════════════
    $h->section('E. the admin is told immediately (D4)');

    $notif = $db->prepare(
        'SELECT id, finding_type, title FROM dl_integrity_notifications
          WHERE branch_id = :b AND aggregate_key LIKE :k ORDER BY id DESC LIMIT 1'
    );
    $notif->execute([':b' => $commBranchId, ':k' => 'closed_without_pm_finalize-%']);
    $notifRow = $notif->fetch(PDO::FETCH_ASSOC) ?: null;
    $h->test(
        'E1 closed_without_pm_finalize creates a dl_integrity_notifications row',
        is_array($notifRow),
        json_encode(['row' => $notifRow])
    );

    $recipients = 0;
    if (is_array($notifRow)) {
        $rc = $db->prepare('SELECT COUNT(*) FROM dl_integrity_notification_recipients WHERE notification_id = :n');
        $rc->execute([':n' => (int)$notifRow['id']]);
        $recipients = (int)$rc->fetchColumn();
    }
    $h->test(
        'E2 the notification is addressed to an active admin/supervisor recipient',
        $recipients >= 1,
        'recipients=' . $recipients
    );

    if ($prevAuthHeader === null) {
        unset($_SERVER['HTTP_AUTHORIZATION']);
    } else {
        $_SERVER['HTTP_AUTHORIZATION'] = $prevAuthHeader;
    }
} finally {
    $cleanup();
}

$h->done();
