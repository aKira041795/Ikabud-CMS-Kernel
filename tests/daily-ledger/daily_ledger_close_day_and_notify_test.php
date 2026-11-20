<?php

declare(strict_types=1);

/**
 * Daily Ledger — close-of-day behaviour towards the day-ending shift.
 *
 * OWNER DIRECTIVE (2026-10-05): a fully-manual day that reaches the close-of-day
 * cutoff with its PM shift still open MUST still close, and the admin MUST still
 * be notified. A day left open compounds into the next day.
 *
 * CONTRACT REVISED 2026-11-16 (client report: "ang PM shift wlay makita nga closed
 * day mao d masave ug dili mocompute ang sales"). "Close anyway" produced days
 * closed with their PM still open, no close control anywhere (every control is
 * gated on day_status=='open') and PM sales that never became official. Measured
 * on tenant 207: 439 closed days with the ending shift still open, 415 of them
 * with every ending recorded. The cutoff now:
 *   a. FINALIZES the day-ending shift when every ending is recorded, then closes
 *      the day with its variances swept and frozen (official sales);
 *   b. DEFERS the close when endings are still missing — the day stays open for
 *      the branch's own late-count window, with a notification;
 *   c. still closes an ABANDONED day quietly on the later sweep, with the flag.
 *
 * N1-N4: an unfinalized fully-manual day with a missing ending is NOT closed at
 *        its own cutoff: a single notification, a single audit row, a stamped
 *        pending_notified_at, no variance freeze, and the day stays editable.
 * N5:    the branch finishes it WITHOUT an admin reopen: record the ending, the
 *        REAL apiFinalizePmShift() handler finalizes the shift, and the next
 *        cutoff pass closes the day and freezes it.
 * N6:    a day whose endings are complete is finalized BY the cutoff and closed
 *        (N6a), and an already-finalized day still closes (N6b).
 * N7:    dl_cashierMayEdit() behaviour on a closed day is unchanged.
 * N8:    a day abandoned past its grace day is closed by the sweep with the
 *        existing closed_without_pm_finalize flag.
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
    $s->execute([':k' => 'pending_endings-day-' . $BRANCH . '-' . $date]);
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
        // `withdraw` is 1 on purpose: since the owner rule of 2026-11-21 a row with NO movement and
        // NO sales owes no hand-typed ending (its ending settles to the carried beginning), so a
        // fixture carrying only beg_bal can no longer exercise the deferral this suite exists to
        // test. Real movement keeps the row owing an ending under both the old and the new rule.
        'INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, beg_bal, withdraw, bal_end, sales) VALUES (:b, :p, :d, "PM", 5, 1, NULL, NULL)',
        [':b' => $BRANCH, ':p' => $P_MOVED, ':d' => $date]
    );

    $firstClose = false;
    try {
        $firstClose = dl_maybeAutoCloseBranchDay($BRANCH, $ADMIN, null);
    } catch (Throwable $e) {
        $h->detail('N1 close threw: ' . $e->getMessage());
    }
    $h->test(
        'N1 a day whose ending shift still owes endings is NOT closed at its own cutoff',
        $firstClose === false && $dayStatusOf() === 'open',
        json_encode(['returned' => $firstClose, 'day' => $dayStatusOf(), 'pm' => $pmStatusOf()])
    );
    $h->test(
        'N1b the PM shift keeps its own status (the cutoff did not fake a finalize)',
        $pmStatusOf() === 'open',
        'pm=' . $pmStatusOf()
    );
    $h->test(
        'N2 exactly one "left open for pending endings" notification exists for branch+date',
        $notifCount() === 1,
        'notifications=' . $notifCount()
    );
    $notifRow = $db->prepare('SELECT title, detail FROM dl_integrity_notifications WHERE aggregate_key = :k LIMIT 1');
    $notifRow->execute([':k' => 'pending_endings-day-' . $BRANCH . '-' . $date]);
    $notifPayload = $notifRow->fetch(PDO::FETCH_ASSOC) ?: [];
    $h->test(
        'N2b the notification says the day was LEFT OPEN so the counts can be finished',
        stripos((string)($notifPayload['title'] ?? ''), 'left open') !== false
            && stripos((string)($notifPayload['detail'] ?? ''), 'was NOT closed') !== false,
        json_encode($notifPayload)
    );
    $auditPayloadOf = static function () use ($db, $BRANCH, $date): array {
        $s = $db->prepare("SELECT new_data FROM audit_logs WHERE module = 'daily-ledger' AND action = 'auto_close_day' AND entity_id = :eid ORDER BY id DESC LIMIT 1");
        $s->execute([':eid' => $BRANCH . '-' . $date . '-PM']);
        $decoded = json_decode((string)$s->fetchColumn(), true);
        return is_array($decoded) ? $decoded : [];
    };
    $h->test(
        'N3 one auto_close_day audit row records the DEFERRAL after the first pass',
        $auditCount() === 1 && (string)($auditPayloadOf()['status'] ?? '') === 'deferred_pending_endings',
        json_encode(['audit' => $auditCount(), 'payload' => $auditPayloadOf()])
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

    // Second consecutive pass (as the next page load would do). Still deferred →
    // idempotent: no second audit row, no second notification.
    $secondClose = dl_maybeAutoCloseBranchDay($BRANCH, $ADMIN, null);
    $h->test(
        'N3b a second pass is a no-op: exactly one audit row and one notification',
        $secondClose === false && $auditCount() === 1 && $notifCount() === 1,
        json_encode(['returned' => $secondClose, 'audit' => $auditCount(), 'notifications' => $notifCount()])
    );
    $h->test(
        'N3c the second pass left the day OPEN and the shift open',
        $dayStatusOf() === 'open' && $pmStatusOf() === 'open',
        json_encode(['day' => $dayStatusOf(), 'pm' => $pmStatusOf()])
    );

    // ══════════════════════════════════════════════════════════════════════
    // N5 — the branch finishes it WITHOUT an admin reopen
    // ══════════════════════════════════════════════════════════════════════
    $h->section('N5 cashier finishes the deferred day → finalize → cutoff closes it');

    // 1. The cashier records the missing ending. No admin reopen is involved:
    //    the day was left OPEN by the deferral, which is the whole point.
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
        'N5a the deferred day is still editable, so the cashier records the ending',
        is_array($editAfter) && !empty($editAfter['ok']) && (int)($writtenEnd->fetchColumn() ?? -1) === 7,
        json_encode(['result' => $editAfter])
    );

    $missingAfterEdit = dl_shiftMissingEndings($db, $BRANCH, $date, 'PM');
    $h->test(
        'N5b the recorded ending cleared the missing-ending gate',
        $missingAfterEdit === [],
        json_encode(['missing' => array_column($missingAfterEdit, 'product_id')])
    );

    // 2. The REAL cashier finalize handler finalizes the PM shift.
    $finalizeAfter = $runFinalize($BRANCH, $date, $CASHIER, 'cashier');
    $h->test(
        'N5c the real cashier finalize handler finalizes the PM shift',
        is_array($finalizeAfter['json']) && ($finalizeAfter['json']['ok'] ?? false) === true
            && $pmStatusOf() === 'finalized',
        json_encode(['harness' => $finalizeAfter, 'pm' => $pmStatusOf()])
    );

    // 3. The next cutoff pass closes the day and freezes it — the day is not
    //    left open forever, and its sales are official because the shift is.
    $postFinalizeClose = dl_maybeAutoCloseBranchDay($BRANCH, $ADMIN, null);
    $h->test(
        'N5d the next cutoff pass closes the finalized day',
        $postFinalizeClose === true && $dayStatusOf() === 'closed' && $pmStatusOf() === 'finalized',
        json_encode(['returned' => $postFinalizeClose, 'day' => $dayStatusOf(), 'pm' => $pmStatusOf()])
    );
    $h->test(
        'N5e the closed day has its variances frozen',
        $frozenCount() > 0 && $dayStatusOf() === 'closed',
        'frozen=' . $frozenCount()
    );

    // ══════════════════════════════════════════════════════════════════════
    // N6 — the cutoff finalizes a COMPLETE ending shift, then closes
    // ══════════════════════════════════════════════════════════════════════
    $h->section('N6 the cutoff finalizes a complete ending shift, then closes');

    $db->execute('DELETE FROM dl_ledger_day_status WHERE branch_id = :b AND ledger_date = :d', [':b' => $BRANCH, ':d' => $date]);
    $db->execute('DELETE FROM dl_ledger_shift_status WHERE branch_id = :b AND ledger_date = :d', [':b' => $BRANCH, ':d' => $date]);
    $db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b AND ledger_date = :d', [':b' => $BRANCH, ':d' => $date]);
    $db->execute('DELETE FROM dl_variance_flags WHERE branch_id = :b AND ledger_date = :d', [':b' => $BRANCH, ':d' => $date]);
    // beg 5 end 9 → ending variance 4, so the freeze has something to freeze.
    // No shift-status row: nobody pressed "Close PM Shift".
    $db->execute(
        'INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, beg_bal, bal_end, sales) VALUES (:b, :p, :d, "PM", 5, 9, 0)',
        [':b' => $BRANCH, ':p' => $P_MOVED, ':d' => $date]
    );

    $autoFinalizeClose = false;
    try {
        $autoFinalizeClose = dl_maybeAutoCloseBranchDay($BRANCH, $ADMIN, null);
    } catch (Throwable $e) {
        $h->detail('N6 close threw: ' . $e->getMessage());
    }
    $h->test(
        'N6a a day whose endings are complete is FINALIZED by the cutoff and then closed',
        $autoFinalizeClose === true && $dayStatusOf() === 'closed' && $pmStatusOf() === 'finalized',
        json_encode(['returned' => $autoFinalizeClose, 'day' => $dayStatusOf(), 'pm' => $pmStatusOf()])
    );
    $h->test(
        'N6b the auto-finalized day has its variance flags frozen',
        $frozenCount() > 0,
        'frozen=' . $frozenCount()
    );
    $finalizeAudit = $db->prepare("SELECT new_data FROM audit_logs WHERE module = 'daily-ledger' AND action = 'finalize_shift' AND entity_id = :eid ORDER BY id DESC LIMIT 1");
    $finalizeAudit->execute([':eid' => $BRANCH . '-' . $date . '-PM']);
    $finalizePayload = json_decode((string)$finalizeAudit->fetchColumn(), true);
    $h->test(
        'N6c the auto-finalize is audited with its cutoff source',
        is_array($finalizePayload) && (string)($finalizePayload['source'] ?? '') === 'auto_close_cutoff',
        json_encode($finalizePayload)
    );

    // An ALREADY finalized day still takes the plain close path (no second finalize).
    $db->execute('DELETE FROM dl_ledger_day_status WHERE branch_id = :b AND ledger_date = :d', [':b' => $BRANCH, ':d' => $date]);
    $finalizedClose = dl_maybeAutoCloseBranchDay($BRANCH, $ADMIN, null);
    $h->test(
        'N6d an already-finalized day still closes (the normal path did not regress)',
        $finalizedClose === true && $dayStatusOf() === 'closed' && $pmStatusOf() === 'finalized',
        json_encode(['returned' => $finalizedClose, 'day' => $dayStatusOf(), 'pm' => $pmStatusOf()])
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

    // ══════════════════════════════════════════════════════════════════════
    // N8 — an ABANDONED day is still closed by the later sweep
    // ══════════════════════════════════════════════════════════════════════
    $h->section('N8 an abandoned day past its grace day is still closed by the sweep');

    $staleDate = (new DateTimeImmutable($date))->modify('-3 days')->format('Y-m-d');
    $db->execute('DELETE FROM dl_ledger_day_status WHERE branch_id = :b AND ledger_date = :d', [':b' => $BRANCH, ':d' => $staleDate]);
    $db->execute('DELETE FROM dl_ledger_shift_status WHERE branch_id = :b AND ledger_date = :d', [':b' => $BRANCH, ':d' => $staleDate]);
    $db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b AND ledger_date = :d', [':b' => $BRANCH, ':d' => $staleDate]);
    $db->execute('DELETE FROM dl_variance_flags WHERE branch_id = :b AND ledger_date = :d', [':b' => $BRANCH, ':d' => $staleDate]);
    // The sweep only selects days that carry an explicit 'open' status row.
    $db->execute(
        "INSERT INTO dl_ledger_day_status (branch_id, ledger_date, status) VALUES (:b, :d, 'open')",
        [':b' => $BRANCH, ':d' => $staleDate]
    );
    // ...and this one still owes its PM ending, so it cannot be auto-finalized.
    $db->execute(
        'INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, beg_bal, withdraw, bal_end, sales) VALUES (:b, :p, :d, "PM", 5, 1, NULL, NULL)',
        [':b' => $BRANCH, ':p' => $P_MOVED, ':d' => $staleDate]
    );

    $staleClose = dl_maybeAutoCloseBranchDay($BRANCH, $ADMIN, null);
    $staleDayStatus = $db->prepare('SELECT status FROM dl_ledger_day_status WHERE branch_id = :b AND ledger_date = :d LIMIT 1');
    $staleDayStatus->execute([':b' => $BRANCH, ':d' => $staleDate]);
    $stalePmStatus = $db->prepare('SELECT status FROM dl_ledger_shift_status WHERE branch_id = :b AND ledger_date = :d AND shift = "PM" LIMIT 1');
    $stalePmStatus->execute([':b' => $BRANCH, ':d' => $staleDate]);
    $h->test(
        'N8a the abandoned day is closed by the sweep with the PM still open',
        $staleClose === true
            && (string)$staleDayStatus->fetchColumn() === 'closed'
            && (string)($stalePmStatus->fetchColumn() ?: 'missing') !== 'finalized',
        json_encode(['returned' => $staleClose])
    );
    $staleAudit = $db->prepare("SELECT new_data FROM audit_logs WHERE module = 'daily-ledger' AND action = 'auto_close_day' AND entity_id = :eid ORDER BY id DESC LIMIT 1");
    $staleAudit->execute([':eid' => $BRANCH . '-' . $staleDate . '-PM']);
    $stalePayload = json_decode((string)$staleAudit->fetchColumn(), true);
    $h->test(
        'N8b the sweep records the closed_without_pm_finalize flag it always did',
        is_array($stalePayload) && (string)($stalePayload['status'] ?? '') === 'closed_without_pm_finalize',
        json_encode($stalePayload)
    );

    // ══════════════════════════════════════════════════════════════════════
    // N9 — an admin's deliberate reopen unlocks the BRANCH, including the
    //      day-ending shift, or the day can never be closed again
    // ══════════════════════════════════════════════════════════════════════
    $h->section('N9 a deliberately reopened day unlocks the branch');

    $reopenedDate = (new DateTimeImmutable($today))->modify('-3 days')->format('Y-m-d');
    $db->execute('DELETE FROM dl_ledger_day_status WHERE branch_id = :b AND ledger_date = :d', [':b' => $BRANCH, ':d' => $reopenedDate]);
    $db->execute('DELETE FROM dl_ledger_shift_status WHERE branch_id = :b AND ledger_date = :d', [':b' => $BRANCH, ':d' => $reopenedDate]);
    $db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b AND ledger_date = :d', [':b' => $BRANCH, ':d' => $reopenedDate]);
    $db->execute(
        "INSERT INTO dl_ledger_day_status (branch_id, ledger_date, status, reopened_by, reopened_at) VALUES (:b, :d, 'open', :u, NOW())",
        [':b' => $BRANCH, ':d' => $reopenedDate, ':u' => $ADMIN]
    );
    // The one active product carries its ending, so the shift CAN be finalized.
    $db->execute(
        'INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, beg_bal, bal_end, sales) VALUES (:b, :p, :d, "PM", 5, 7, -2)',
        [':b' => $BRANCH, ':p' => $P_MOVED, ':d' => $reopenedDate]
    );

    $h->test(
        'N9a a cashier may edit the PM of a reopened past day (beyond the late-count window)',
        dl_cashierMayEdit($BRANCH, $reopenedDate, 'PM', $today, 'open') === true
    );
    $h->test(
        'N9b the reopen unlocks the AM shift of that day too',
        dl_cashierMayEdit($BRANCH, $reopenedDate, 'AM', $today, 'open') === true
    );
    $h->test(
        'N9c an older OPEN day nobody reopened stays reference-only',
        dl_cashierMayEdit($BRANCH, (new DateTimeImmutable($today))->modify('-4 days')->format('Y-m-d'), 'PM', $today, 'open') === false
    );

    // The real finalize handler must accept it — a reopen without a way to finalize strands the day.
    $reopenedFinalize = $runFinalize($BRANCH, $reopenedDate, $CASHIER, 'cashier');
    $h->test(
        'N9d the cashier can finalize the ending shift of the reopened day',
        is_array($reopenedFinalize['json']) && ($reopenedFinalize['json']['ok'] ?? false) === true,
        json_encode(['harness' => $reopenedFinalize])
    );
    $finalizedStmt = $db->prepare('SELECT status FROM dl_ledger_shift_status WHERE branch_id = :b AND ledger_date = :d AND shift = "PM"');
    $finalizedStmt->execute([':b' => $BRANCH, ':d' => $reopenedDate]);
    $h->test('N9e and the shift really is finalized afterwards', (string)$finalizedStmt->fetchColumn() === 'finalized');

    // A closed day is still closed, reopened_at or not.
    $h->test(
        'N9f a CLOSED day stays reference-only even with a stale reopened_at',
        dl_cashierMayEdit($BRANCH, $staleDate, 'PM', $today, 'closed') === false
    );

    // The screen must say WHY there is no Close PM button on the AM sheet.
    $ledgerTpl = (string)file_get_contents($base . '/templates/modules/daily-ledger/cashier/ledger.disyl');
    $h->test(
        'N9g the AM sheet points at the PM sheet instead of hiding the control silently',
        str_contains($ledgerTpl, 'id="open-pm-sheet-hint"')
            && preg_match("/shift == 'AM'[^}]*!shift_locked[^}]*day_ending_shift == 'PM'/", $ledgerTpl) === 1
    );
    // Measured with the client's admin account: today's PM was already finalized, so the bar showed
    // only the green pill and "Close Day" (Reopen Day is correctly absent while the day is open) —
    // "cannot see Close PM or Reopen". The undo must sit NEXT TO the state, for the office only.
    $h->test(
        'N9h the finalized state carries its Reopen action in the action bar, office only',
        str_contains($ledgerTpl, 'id="reopen-shift-btn"')
            && preg_match('/\{if can_ledger_override\}\s*<button[^>]*id="reopen-shift-btn"/s', $ledgerTpl) === 1
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
