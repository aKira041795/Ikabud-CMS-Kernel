<?php

declare(strict_types=1);

/**
 * Daily Ledger — close-day-and-notify for an unfinalized fully-manual day.
 *
 * OWNER DIRECTIVE (2026-10-05): a fully-manual day that reaches the close-of-day
 * cutoff with its PM shift still open MUST still close, and the admin MUST still
 * be notified. This reverses the D1 refusal (531106f8) where the day was left
 * open so the cashier kept a self-service late-count window. A day left open
 * compounds into the next day; close + notify is the owner's accepted trade.
 *
 * N1-N4: the auto-close produces a CLOSED day, a single
 *        closed_without_pm_finalize notification, a single audit row and a
 *        stamped pending_notified_at across two consecutive passes, with NO
 *        variance recompute/freeze (that belongs to a finalized day).
 * N5:    the deliberate admin reopen remains the remedy: while closed a cashier
 *        write and a cashier finalize are both refused, the admin reopen puts
 *        the day back to open, the cashier records the ending, and the REAL
 *        apiFinalizePmShift() handler finalizes the PM shift.
 * N6:    a fully-manual day whose PM shift IS finalized still closes, recomputes
 *        and freezes (the normal path must not regress).
 * N7:    dl_cashierMayEdit() behaviour on a closed day is unchanged.
 *
 * Everything is seeded on reserved 99xxx ids on a throwaway previous business
 * date so the cashier late-count window is exercisable, then removed in finally.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-close-day-and-notify', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();
$h->allowLogLines('disyl.compile.phases');

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/helpers/entity-views.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers-offline.php';
require_once $base . '/modules/daily-ledger/handlers.php';

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
/** @var \Ikabud\Kernel\Contracts\DatabaseContract $db */
$db = $ctx->db();

$BRANCH   = 99245;
$P_MOVED  = 99246;
$CASHIER  = 992860;
$ADMIN    = 992861;

// The auto-close always operates on the PREVIOUS business date, so the fixture
// date is derived from the same clock the cashier finalize window uses.
$origSettings = getModuleSettings('daily-ledger');
dlPersistModuleSettings(array_merge((array)$origSettings, [
    'auto_close_enabled' => '1',
    'close_of_day_time' => '22:00',
    'operating_timezone' => 'UTC',
]));
$today = dl_businessDate();
$date  = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');

$cleanup = static function () use ($db, $BRANCH, $P_MOVED, $CASHIER, $ADMIN): void {
    $db->execute('DELETE FROM dl_integrity_notification_recipients WHERE notification_id IN (SELECT id FROM dl_integrity_notifications WHERE branch_id = :b)', [':b' => $BRANCH]);
    $db->execute('DELETE FROM dl_integrity_notifications WHERE branch_id = :b', [':b' => $BRANCH]);
    $db->execute('DELETE FROM audit_logs WHERE branch_id = :b', [':b' => $BRANCH]);
    $db->execute('DELETE FROM dl_variance_flags WHERE branch_id = :b', [':b' => $BRANCH]);
    $db->execute('DELETE FROM dl_ledger_shift_status WHERE branch_id = :b', [':b' => $BRANCH]);
    $db->execute('DELETE FROM dl_ledger_day_status WHERE branch_id = :b', [':b' => $BRANCH]);
    $db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b', [':b' => $BRANCH]);
    $db->execute('DELETE FROM dl_branch_products WHERE branch_id = :b', [':b' => $BRANCH]);
    $db->execute('DELETE FROM dl_user_branches WHERE user_id IN (:c, :a)', [':c' => $CASHIER, ':a' => $ADMIN]);
    $db->execute('DELETE FROM dl_users WHERE id IN (:c, :a)', [':c' => $CASHIER, ':a' => $ADMIN]);
    $db->execute('DELETE FROM dl_branches WHERE id = :b', [':b' => $BRANCH]);
    $db->execute('DELETE FROM dl_products WHERE id = :p', [':p' => $P_MOVED]);
};

$cleanup();

// ── Fixtures ────────────────────────────────────────────────────────────────
$db->execute(
    'INSERT INTO dl_branches (id, code, name, address, default_supply_mode, is_commissary, is_active)
     VALUES (:id, :code, :name, :addr, "self_managed", 0, 1)',
    [':id' => $BRANCH, ':code' => 'T-NOTIFY', ':name' => 'Close-Day Notify Branch', ':addr' => 'Test']
);
$db->execute(
    'INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active) VALUES (:id, :sku, :n, 1, 99245, 1)',
    [':id' => $P_MOVED, ':sku' => 'NOTIFY-P', ':n' => 'Close-Day Notify Product']
);
$db->execute('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (:b, :p, 1)', [':b' => $BRANCH, ':p' => $P_MOVED]);
$db->execute(
    'INSERT INTO dl_users (id, username, password_hash, full_name, role, shift, is_active) VALUES (:id, :u, :p, :n, "cashier", "PM", 1)',
    [':id' => $CASHIER, ':u' => 'fixture-notify-cashier', ':p' => 'not-a-login-hash', ':n' => 'Fixture Notify Cashier']
);
$db->execute('INSERT INTO dl_user_branches (user_id, branch_id) VALUES (:u, :b)', [':u' => $CASHIER, ':b' => $BRANCH]);
$db->execute(
    'INSERT INTO dl_users (id, username, password_hash, full_name, role, shift, is_active) VALUES (:id, :u, :p, :n, "admin", NULL, 1)',
    [':id' => $ADMIN, ':u' => 'fixture-notify-admin', ':p' => 'not-a-login-hash', ':n' => 'Fixture Notify Admin']
);

$cashier = [
    'id' => $CASHIER, 'sub' => 'cashier:' . $CASHIER, 'role' => 'cashier', 'shift' => 'PM',
    'source' => 'daily-ledger', 'username' => 'fixture-notify-cashier', 'name' => 'Fixture Notify Cashier',
];
$prevAuthHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . app()->jwt()->generate($cashier + ['token_type' => 'access']);

$dayStatusOf = static function () use ($db, $BRANCH, $date): string {
    $s = $db->prepare('SELECT status FROM dl_ledger_day_status WHERE branch_id = :b AND ledger_date = :d LIMIT 1');
    $s->execute([':b' => $BRANCH, ':d' => $date]);
    $v = $s->fetchColumn();
    return $v === false ? 'open' : (string)$v;
};
$pmStatusOf = static function () use ($db, $BRANCH, $date): string {
    $s = $db->prepare('SELECT status FROM dl_ledger_shift_status WHERE branch_id = :b AND ledger_date = :d AND shift = "PM" LIMIT 1');
    $s->execute([':b' => $BRANCH, ':d' => $date]);
    $v = $s->fetchColumn();
    return $v === false ? 'missing' : (string)$v;
};
$pmPendingAt = static function () use ($db, $BRANCH, $date) {
    $s = $db->prepare('SELECT pending_notified_at FROM dl_ledger_shift_status WHERE branch_id = :b AND ledger_date = :d AND shift = "PM" LIMIT 1');
    $s->execute([':b' => $BRANCH, ':d' => $date]);
    return $s->fetchColumn();
};
$notifCount = static function () use ($db, $BRANCH, $date): int {
    $s = $db->prepare('SELECT COUNT(*) FROM dl_integrity_notifications WHERE aggregate_key = :k');
    $s->execute([':k' => 'closed_without_pm_finalize-day-' . $BRANCH . '-' . $date]);
    return (int)$s->fetchColumn();
};
$auditCount = static function () use ($db, $BRANCH, $date): int {
    $s = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE module = 'daily-ledger' AND action = 'auto_close_day' AND entity_id = :eid");
    $s->execute([':eid' => $BRANCH . '-' . $date . '-PM']);
    return (int)$s->fetchColumn();
};
$frozenCount = static function () use ($db, $BRANCH, $date): int {
    $s = $db->prepare('SELECT COUNT(*) FROM dl_variance_flags WHERE branch_id = :b AND ledger_date = :d AND frozen_at IS NOT NULL');
    $s->execute([':b' => $BRANCH, ':d' => $date]);
    return (int)$s->fetchColumn();
};
$runFinalize = static function (int $branchId, string $d, int $userId, string $role = 'cashier'): array {
    $output = [];
    $exit = 0;
    exec(
        escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg(__DIR__ . '/daily_ledger_finalize_pm_harness.php') . ' '
        . escapeshellarg((string)$branchId) . ' '
        . escapeshellarg($d) . ' '
        . escapeshellarg((string)$userId) . ' '
        . escapeshellarg($role) . ' 2>&1',
        $output,
        $exit
    );
    $raw = implode("\n", $output);
    return ['exit' => $exit, 'raw' => $raw, 'json' => json_decode($raw, true)];
};

try {
    // ══════════════════════════════════════════════════════════════════════
    // N1-N4 — auto-close + notify, once, with no freeze
    // ══════════════════════════════════════════════════════════════════════
    $h->section('N1-N4 close + notify an unfinalized fully-manual day');

    $db->execute('DELETE FROM dl_ledger_day_status WHERE branch_id = :b AND ledger_date = :d', [':b' => $BRANCH, ':d' => $date]);
    $db->execute('DELETE FROM dl_ledger_shift_status WHERE branch_id = :b AND ledger_date = :d', [':b' => $BRANCH, ':d' => $date]);
    $db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b AND ledger_date = :d', [':b' => $BRANCH, ':d' => $date]);
    $db->execute('DELETE FROM dl_variance_flags WHERE branch_id = :b AND ledger_date = :d', [':b' => $BRANCH, ':d' => $date]);
    // A moved product whose ending was never recorded → genuine pending.
    $db->execute(
        'INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, beg_bal, bal_end, sales) VALUES (:b, :p, :d, "PM", 5, NULL, NULL)',
        [':b' => $BRANCH, ':p' => $P_MOVED, ':d' => $date]
    );

    $firstClose = false;
    try {
        $firstClose = dl_maybeAutoCloseBranchDay($BRANCH, $ADMIN, null);
    } catch (Throwable $e) {
        $h->detail('N1 close threw: ' . $e->getMessage());
    }
    $h->test(
        'N1 an unfinalized fully-manual day CLOSES at the cutoff',
        $firstClose === true && $dayStatusOf() === 'closed',
        json_encode(['returned' => $firstClose, 'day' => $dayStatusOf(), 'pm' => $pmStatusOf()])
    );
    $h->test(
        'N1b the PM shift is still open (the close did not fake a finalize)',
        $pmStatusOf() === 'open',
        'pm=' . $pmStatusOf()
    );
    $h->test(
        'N2 a closed_without_pm_finalize notification exists for branch+date',
        $notifCount() === 1,
        'notifications=' . $notifCount()
    );
    $h->test(
        'N3 one closed_without_pm_finalize audit row after the first pass',
        $auditCount() === 1,
        'audit=' . $auditCount()
    );
    $h->test(
        'N4 pending_notified_at is stamped on the open PM shift',
        ($v = $pmPendingAt()) !== false && $v !== null && (string)$v !== '',
        'pending_notified_at=' . json_encode($pmPendingAt())
    );
    $h->test(
        'N5-no-freeze no variance flag was frozen for the unfinalized day',
        $frozenCount() === 0,
        'frozen=' . $frozenCount()
    );

    // Second consecutive pass (as the next page load would do). Already closed →
    // idempotent: no second audit row, no second notification.
    $secondClose = dl_maybeAutoCloseBranchDay($BRANCH, $ADMIN, null);
    $h->test(
        'N3b a second pass is a no-op: exactly one audit row and one notification',
        $secondClose === false && $auditCount() === 1 && $notifCount() === 1,
        json_encode(['returned' => $secondClose, 'audit' => $auditCount(), 'notifications' => $notifCount()])
    );
    $h->test(
        'N3c the second pass left the day closed and the shift open',
        $dayStatusOf() === 'closed' && $pmStatusOf() === 'open',
        json_encode(['day' => $dayStatusOf(), 'pm' => $pmStatusOf()])
    );

    // ══════════════════════════════════════════════════════════════════════
    // N5 — the deliberate admin reopen is the remedy, end to end
    // ══════════════════════════════════════════════════════════════════════
    $h->section('N5 admin reopen → cashier edit → cashier finalize');

    // While closed, a cashier can neither edit a non-ending field nor finalize.
    $closedEdit = null;
    try {
        dl_offlineApplyLedgerSave($cashier, [
            'type' => 'ledger_save',
            'payload' => ['branch_id' => $BRANCH, 'product_id' => $P_MOVED, 'field' => 'beg_bal', 'value' => 6, 'date' => $date, 'shift' => 'PM'],
        ], true);
    } catch (Throwable $e) {
        $closedEdit = ['message' => $e->getMessage(), 'code' => (int)$e->getCode()];
    }
    $h->test(
        'N5a a cashier edit is refused while the day is closed',
        is_array($closedEdit) && $closedEdit['code'] === 403,
        json_encode($closedEdit)
    );

    $closedFinalize = $runFinalize($BRANCH, $date, $CASHIER, 'cashier');
    $h->test(
        'N5b a cashier finalize is refused while the day is closed',
        is_array($closedFinalize['json']) && ($closedFinalize['json']['ok'] ?? null) === false
            && ($closedFinalize['json']['code'] ?? '') === 'DAY_CLOSED',
        json_encode($closedFinalize)
    );

    // Admin reopen (the real service the handler uses).
    $reopenRan = false;
    try {
        dl_reopenDayService($db, $BRANCH, $date, $ADMIN);
        $reopenRan = true;
    } catch (Throwable $e) {
        $h->detail('N5 reopen threw: ' . $e->getMessage());
    }
    $reopenedAt = $db->prepare('SELECT reopened_at FROM dl_ledger_day_status WHERE branch_id = :b AND ledger_date = :d LIMIT 1');
    $reopenedAt->execute([':b' => $BRANCH, ':d' => $date]);
    $reopenedAtVal = $reopenedAt->fetchColumn();
    $h->test(
        'N5c admin reopen puts the day back to open with reopened_at set',
        $reopenRan && $dayStatusOf() === 'open'
            && $reopenedAtVal !== false && $reopenedAtVal !== null && (string)$reopenedAtVal !== '',
        json_encode(['day' => $dayStatusOf(), 'reopened_at' => $reopenedAtVal])
    );

    // Cashier records the missing ending on the reopened day.
    $editAfter = null;
    try {
        $editAfter = dl_offlineApplyLedgerSave($cashier, [
            'type' => 'ledger_save',
            'payload' => ['branch_id' => $BRANCH, 'product_id' => $P_MOVED, 'field' => 'bal_end', 'value' => 7, 'date' => $date, 'shift' => 'PM'],
        ], true);
    } catch (Throwable $e) {
        $editAfter = ['error' => $e->getMessage(), 'code' => (int)$e->getCode()];
    }
    $writtenEnd = $db->prepare('SELECT bal_end FROM dl_daily_ledger WHERE branch_id = :b AND product_id = :p AND ledger_date = :d AND shift = "PM"');
    $writtenEnd->execute([':b' => $BRANCH, ':p' => $P_MOVED, ':d' => $date]);
    $h->test(
        'N5d after the admin reopen the cashier edit succeeds and persists',
        is_array($editAfter) && !empty($editAfter['ok']) && (int)($writtenEnd->fetchColumn() ?: 0) === 7,
        json_encode(['result' => $editAfter])
    );

    $missingAfterEdit = dl_shiftMissingEndings($db, $BRANCH, $date, 'PM');
    $h->test(
        'N5e the reopen + edit cleared the missing-ending gate',
        $missingAfterEdit === [],
        json_encode(['missing' => array_column($missingAfterEdit, 'product_id')])
    );

    // The REAL cashier finalize handler finalizes the PM shift.
    $finalizeAfter = $runFinalize($BRANCH, $date, $CASHIER, 'cashier');
    $h->test(
        'N5f the real cashier finalize handler finalizes the PM shift',
        is_array($finalizeAfter['json']) && ($finalizeAfter['json']['ok'] ?? false) === true
            && $pmStatusOf() === 'finalized',
        json_encode(['harness' => $finalizeAfter, 'pm' => $pmStatusOf()])
    );

    // The deliberately reopened day is exempt from auto-close even after the PM
    // is finalized, so it is not re-closed under the admin: the admin closes it
    // manually once the pending endings are done.
    $postFinalizeClose = dl_maybeAutoCloseBranchDay($BRANCH, $ADMIN, null);
    $h->test(
        'N5g the reopened day is not re-auto-closed after finalize (admin closes manually)',
        $postFinalizeClose === false && $dayStatusOf() === 'open',
        json_encode(['returned' => $postFinalizeClose, 'day' => $dayStatusOf()])
    );

    // ══════════════════════════════════════════════════════════════════════
    // N6 — the finalized normal path still closes, recomputes and freezes
    // ══════════════════════════════════════════════════════════════════════
    $h->section('N6 a finalized fully-manual day still closes + freezes');

    $db->execute('DELETE FROM dl_ledger_day_status WHERE branch_id = :b AND ledger_date = :d', [':b' => $BRANCH, ':d' => $date]);
    $db->execute('DELETE FROM dl_ledger_shift_status WHERE branch_id = :b AND ledger_date = :d', [':b' => $BRANCH, ':d' => $date]);
    $db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b AND ledger_date = :d', [':b' => $BRANCH, ':d' => $date]);
    $db->execute('DELETE FROM dl_variance_flags WHERE branch_id = :b AND ledger_date = :d', [':b' => $BRANCH, ':d' => $date]);
    // beg 5 end 9 → ending variance 4, so the freeze has something to freeze.
    $db->execute(
        'INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, beg_bal, bal_end, sales) VALUES (:b, :p, :d, "PM", 5, 9, 0)',
        [':b' => $BRANCH, ':p' => $P_MOVED, ':d' => $date]
    );
    $db->execute(
        "INSERT INTO dl_ledger_shift_status (branch_id, ledger_date, shift, status, finalized_by, finalized_at)
         VALUES (:b, :d, 'PM', 'finalized', :u, CURRENT_TIMESTAMP)",
        [':b' => $BRANCH, ':d' => $date, ':u' => $ADMIN]
    );

    $finalizedClose = false;
    try {
        $finalizedClose = dl_maybeAutoCloseBranchDay($BRANCH, $ADMIN, null);
    } catch (Throwable $e) {
        $h->detail('N6 close threw: ' . $e->getMessage());
    }
    $h->test(
        'N6a a finalized fully-manual day still closes',
        $finalizedClose === true && $dayStatusOf() === 'closed',
        json_encode(['returned' => $finalizedClose, 'day' => $dayStatusOf()])
    );
    $h->test(
        'N6b the finalized path froze the variance flags (recompute + freeze ran)',
        $frozenCount() > 0,
        'frozen=' . $frozenCount()
    );

    // ══════════════════════════════════════════════════════════════════════
    // N7 — dl_cashierMayEdit on a closed day is unchanged
    // ══════════════════════════════════════════════════════════════════════
    $h->section('N7 cashier edit permission is unchanged');

    // Independent of N6's finalized shift: the open-day late-count window only
    // exists while the shift is unfinalized.
    $db->execute('DELETE FROM dl_ledger_shift_status WHERE branch_id = :b AND ledger_date = :d', [':b' => $BRANCH, ':d' => $date]);

    $h->test(
        'N7a a prior PM on a CLOSED day is still not editable',
        dl_cashierMayEdit($BRANCH, $date, 'PM', $today, 'closed') === false
    );
    $h->test(
        'N7b a prior PM on an OPEN, unfinalized day is still in the late-count window',
        dl_cashierMayEdit($BRANCH, $date, 'PM', $today, 'open') === true
    );
    $h->test(
        'N7c the current business date is still editable',
        dl_cashierMayEdit($BRANCH, $today, 'PM', $today, 'closed') === true
    );
    $h->test(
        'N7d a prior AM is still not editable',
        dl_cashierMayEdit($BRANCH, $date, 'AM', $today, 'open') === false
    );
    $h->test(
        'N7e the cashier-facing closed-day refusal message is unchanged',
        dl_closedDayRefusalMessage() === 'This business date is closed. An admin must reopen the day before entries can be changed.'
    );

    if ($prevAuthHeader === null) {
        unset($_SERVER['HTTP_AUTHORIZATION']);
    } else {
        $_SERVER['HTTP_AUTHORIZATION'] = $prevAuthHeader;
    }
} finally {
    $cleanup();
    saveModuleSettings('daily-ledger', is_array($origSettings) ? $origSettings : []);
    dlModuleSettings(true);
}

$h->done();
