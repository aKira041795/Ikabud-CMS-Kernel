<?php

declare(strict_types=1);

/**
 * Daily Ledger — bounded backward sweep for stale open days.
 *
 * S1..S8 exercise the older-day sweep inside dl_maybeAutoCloseBranchDay():
 *   S1 an older open day IS closed by the sweep; yesterday's behaviour
 *      (per-day notification) is unchanged.
 *   S2 a day with reopened_at set is NOT closed, and the sweep CONTINUES past
 *      it to close older qualifying days.
 *   S3 the sweep STOPS at a closed day.
 *   S4 an idle day (no status row, no ledger activity) gains NO day-status row.
 *   S5 the cap (7 older days per pass) is respected: the remainder closes on a
 *      later pass.
 *   S6 a day whose PM was never finalized closes with the approved notify-once
 *      behaviour and its variance flags are NOT frozen (finalized-only rule).
 *   S7 exactly ONE summary notification for swept days, not one per day.
 *   S8 steady state: nothing stale closes nothing, issues no notification, and
 *      the sweep costs exactly ONE probe query (no writes).
 *
 * Everything is seeded on a private fixture branch (99341) with fixed UTC
 * dates so the sweep's backward walk is deterministic. Never branch 8.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-stale-day-sweep', TestHarness::MODE_INTEGRATION, 'baronledger.test');
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

$BRANCH  = 99341;
$PRODUCT = 99342;
$ADMIN   = 99343;

$origSettings = getModuleSettings('daily-ledger');
dlPersistModuleSettings(array_merge((array)$origSettings, [
    'auto_close_enabled' => '1',
    'close_of_day_time'  => '00:00',
    'operating_timezone' => 'UTC',
    'pos_enabled'        => '0',
]));

// Fixed clock: business date 2026-10-20, yesterday 2026-10-19.
$now = new DateTimeImmutable('2026-10-20 12:00:00', new DateTimeZone('UTC'));
$YESTERDAY = '2026-10-19';

$cleanup = static function () use ($db, $BRANCH, $PRODUCT, $ADMIN): void {
    $db->execute('DELETE FROM dl_integrity_notification_recipients WHERE notification_id IN (SELECT id FROM dl_integrity_notifications WHERE branch_id = :b)', [':b' => $BRANCH]);
    $db->execute('DELETE FROM dl_integrity_notifications WHERE branch_id = :b', [':b' => $BRANCH]);
    $db->execute('DELETE FROM audit_logs WHERE branch_id = :b', [':b' => $BRANCH]);
    $db->execute('DELETE FROM dl_variance_flags WHERE branch_id = :b', [':b' => $BRANCH]);
    $db->execute('DELETE FROM dl_ledger_shift_status WHERE branch_id = :b', [':b' => $BRANCH]);
    $db->execute('DELETE FROM dl_ledger_day_status WHERE branch_id = :b', [':b' => $BRANCH]);
    $db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b', [':b' => $BRANCH]);
    $db->execute('DELETE FROM dl_branch_products WHERE branch_id = :b', [':b' => $BRANCH]);
    $db->execute('DELETE FROM dl_user_branches WHERE user_id = :u', [':u' => $ADMIN]);
    $db->execute('DELETE FROM dl_users WHERE id = :u', [':u' => $ADMIN]);
    $db->execute('DELETE FROM dl_branches WHERE id = :b', [':b' => $BRANCH]);
    $db->execute('DELETE FROM dl_products WHERE id = :p', [':p' => $PRODUCT]);
};

$cleanup();

// ── Fixtures ────────────────────────────────────────────────────────────────
$db->execute(
    'INSERT INTO dl_branches (id, code, name, address, default_supply_mode, is_commissary, is_active)
     VALUES (:id, :code, :name, :addr, "self_managed", 0, 1)',
    [':id' => $BRANCH, ':code' => 'T-SWEEP', ':name' => 'Stale Sweep Branch', ':addr' => 'Test']
);
$db->execute(
    'INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active) VALUES (:id, :sku, :n, 1, 99341, 1)',
    [':id' => $PRODUCT, ':sku' => 'SWEEP-P', ':n' => 'Stale Sweep Product']
);
$db->execute('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (:b, :p, 1)', [':b' => $BRANCH, ':p' => $PRODUCT]);
$db->execute(
    'INSERT INTO dl_users (id, username, password_hash, full_name, role, shift, is_active) VALUES (:id, :u, :p, :n, "admin", NULL, 1)',
    [':id' => $ADMIN, ':u' => 'fixture-sweep-admin', ':p' => 'not-a-login-hash', ':n' => 'Fixture Sweep Admin']
);

// ── Helpers ─────────────────────────────────────────────────────────────────
$reset = static function () use ($db, $BRANCH): void {
    $db->execute('DELETE FROM dl_integrity_notification_recipients WHERE notification_id IN (SELECT id FROM dl_integrity_notifications WHERE branch_id = :b)', [':b' => $BRANCH]);
    $db->execute('DELETE FROM dl_integrity_notifications WHERE branch_id = :b', [':b' => $BRANCH]);
    $db->execute('DELETE FROM audit_logs WHERE branch_id = :b', [':b' => $BRANCH]);
    $db->execute('DELETE FROM dl_variance_flags WHERE branch_id = :b', [':b' => $BRANCH]);
    $db->execute('DELETE FROM dl_ledger_shift_status WHERE branch_id = :b', [':b' => $BRANCH]);
    $db->execute('DELETE FROM dl_ledger_day_status WHERE branch_id = :b', [':b' => $BRANCH]);
    $db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b', [':b' => $BRANCH]);
};
$setDay = static function (string $date, string $status, bool $reopened = false) use ($db, $BRANCH, $ADMIN): void {
    $db->execute(
        'INSERT INTO dl_ledger_day_status (branch_id, ledger_date, status, reopened_by, reopened_at)
         VALUES (:b, :d, :s, :rb, :ra)
         ON DUPLICATE KEY UPDATE status = VALUES(status), reopened_by = VALUES(reopened_by), reopened_at = VALUES(reopened_at)',
        [':b' => $BRANCH, ':d' => $date, ':s' => $status, ':rb' => $reopened ? $ADMIN : null, ':ra' => $reopened ? '2026-10-19 08:00:00' : null]
    );
};
$seedActivity = static function (string $date) use ($db, $BRANCH, $PRODUCT): void {
    $db->execute(
        'INSERT INTO dl_daily_ledger (branch_id, product_id, ledger_date, shift, beg_bal, bal_end, sales)
         VALUES (:b, :p, :d, "PM", 5, NULL, NULL)',
        [':b' => $BRANCH, ':p' => $PRODUCT, ':d' => $date]
    );
};
$dayStatusOf = static function (string $date) use ($db, $BRANCH): string {
    $s = $db->prepare('SELECT status FROM dl_ledger_day_status WHERE branch_id = :b AND ledger_date = :d LIMIT 1');
    $s->execute([':b' => $BRANCH, ':d' => $date]);
    $v = $s->fetchColumn();
    return $v === false ? 'missing' : (string)$v;
};
$dayRowCount = static function (string $date) use ($db, $BRANCH): int {
    $s = $db->prepare('SELECT COUNT(*) FROM dl_ledger_day_status WHERE branch_id = :b AND ledger_date = :d');
    $s->execute([':b' => $BRANCH, ':d' => $date]);
    return (int)$s->fetchColumn();
};
$notifCount = static function (string $key) use ($db): int {
    $s = $db->prepare('SELECT COUNT(*) FROM dl_integrity_notifications WHERE aggregate_key = :k');
    $s->execute([':k' => $key]);
    return (int)$s->fetchColumn();
};
$sweepNotifCount = static function () use ($db, $BRANCH): int {
    $s = $db->prepare("SELECT COUNT(*) FROM dl_integrity_notifications WHERE branch_id = :b AND aggregate_key LIKE 'auto_close_sweep-%'");
    $s->execute([':b' => $BRANCH]);
    return (int)$s->fetchColumn();
};
$perDayNotifs = static function () use ($db, $BRANCH): int {
    $s = $db->prepare("SELECT COUNT(*) FROM dl_integrity_notifications WHERE branch_id = :b AND aggregate_key LIKE 'closed_without_pm_finalize-day-%'");
    $s->execute([':b' => $BRANCH]);
    return (int)$s->fetchColumn();
};
$pendingAt = static function (string $date) use ($db, $BRANCH) {
    $s = $db->prepare('SELECT pending_notified_at FROM dl_ledger_shift_status WHERE branch_id = :b AND ledger_date = :d AND shift = "PM" LIMIT 1');
    $s->execute([':b' => $BRANCH, ':d' => $date]);
    return $s->fetchColumn();
};
$auditCount = static function (string $date) use ($db, $BRANCH): int {
    $s = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE module = 'daily-ledger' AND action = 'auto_close_day' AND entity_id = :eid");
    $s->execute([':eid' => $BRANCH . '-' . $date]);
    return (int)$s->fetchColumn();
};
$sweepClose = static function () use ($BRANCH, $ADMIN, $now): bool {
    return dl_maybeAutoCloseBranchDay($BRANCH, $ADMIN, $now);
};

try {
    // ══════════════════════════════════════════════════════════════════════
    // S1 — an older open day IS closed; yesterday is unchanged
    // ══════════════════════════════════════════════════════════════════════
    $h->section('S1 older open day closed; yesterday per-day notify unchanged');
    $reset();
    $seedActivity($YESTERDAY);           // yesterday: unfinalized manual day
    $setDay('2026-10-18', 'open');       // older day, explicitly open

    $r1 = $sweepClose();
    $h->test(
        'S1a yesterday closes with its per-day notification (unchanged behaviour)',
        $r1 === true
            && $dayStatusOf($YESTERDAY) === 'closed'
            && $notifCount('closed_without_pm_finalize-day-' . $BRANCH . '-' . $YESTERDAY) === 1,
        json_encode(['returned' => $r1, 'day' => $dayStatusOf($YESTERDAY)])
    );
    $h->test(
        'S1b an older explicitly-open day IS closed by the sweep',
        $dayStatusOf('2026-10-18') === 'closed' && $auditCount('2026-10-18') === 1,
        json_encode(['day' => $dayStatusOf('2026-10-18')])
    );

    // ══════════════════════════════════════════════════════════════════════
    // S2 — reopened day skipped, sweep continues past it
    // ══════════════════════════════════════════════════════════════════════
    $h->section('S2 reopened day is skipped and the sweep continues');
    $reset();
    $setDay($YESTERDAY, 'closed');
    $setDay('2026-10-18', 'open', true); // deliberately reopened
    $setDay('2026-10-17', 'open');       // older qualifying day beyond it

    $sweepClose();
    $h->test(
        'S2a the reopened day STAYS open and keeps its reopened_at',
        $dayStatusOf('2026-10-18') === 'open'
            && (int)$db->query('SELECT COUNT(*) FROM dl_ledger_day_status WHERE branch_id = ' . $BRANCH . " AND ledger_date = '2026-10-18' AND reopened_at IS NOT NULL")->fetchColumn() === 1,
        json_encode(['day' => $dayStatusOf('2026-10-18')])
    );
    $h->test(
        'S2b the sweep CONTINUES past the reopened day and closes the older one',
        $dayStatusOf('2026-10-17') === 'closed',
        json_encode(['day' => $dayStatusOf('2026-10-17')])
    );

    // ══════════════════════════════════════════════════════════════════════
    // S3 — the sweep stops at a closed day
    // ══════════════════════════════════════════════════════════════════════
    $h->section('S3 the sweep stops at a closed day');
    $reset();
    $setDay($YESTERDAY, 'closed');
    $setDay('2026-10-18', 'closed'); // the wall
    $setDay('2026-10-17', 'open');   // must NOT be touched

    $sweepClose();
    $h->test(
        'S3 a day beyond a closed day stays open (sweep stopped)',
        $dayStatusOf('2026-10-17') === 'open' && $dayStatusOf('2026-10-18') === 'closed',
        json_encode(['wall' => $dayStatusOf('2026-10-18'), 'beyond' => $dayStatusOf('2026-10-17')])
    );

    // ══════════════════════════════════════════════════════════════════════
    // S4 — an idle day gains NO day-status row
    // ══════════════════════════════════════════════════════════════════════
    $h->section('S4 an idle day is never fabricated');
    $reset();
    $setDay($YESTERDAY, 'closed');
    // 2026-10-18: no status row and no ledger activity (idle).
    $setDay('2026-10-17', 'open');

    $sweepClose();
    $h->test(
        'S4a an idle day gains NO day-status row',
        $dayRowCount('2026-10-18') === 0,
        'rows=' . $dayRowCount('2026-10-18')
    );
    $h->test(
        'S4b the idle day stops the sweep, so the day beyond stays open',
        $dayStatusOf('2026-10-17') === 'open',
        json_encode(['beyond' => $dayStatusOf('2026-10-17')])
    );

    // ══════════════════════════════════════════════════════════════════════
    // S5 — the cap is respected; the remainder closes on a later pass
    // ══════════════════════════════════════════════════════════════════════
    $h->section('S5 cap (7) respected, remainder closes on a later pass');
    $reset();
    $setDay($YESTERDAY, 'closed');
    // Nine contiguous stale open days: 2026-10-18 down to 2026-10-10.
    $stale = [];
    for ($i = 0; $i < 9; $i++) {
        $d = (new DateTimeImmutable('2026-10-18'))->modify("-{$i} day")->format('Y-m-d');
        $stale[] = $d;
        $setDay($d, 'open');
    }
    // A wall below the run so the cap (not the wall) is what stops pass 1.
    $setDay('2026-10-09', 'closed');

    $sweepClose(); // pass 1
    $closedPass1 = 0;
    foreach ($stale as $d) {
        if ($dayStatusOf($d) === 'closed') {
            $closedPass1++;
        }
    }
    $openRemaining = array_values(array_filter($stale, fn(string $d): bool => $dayStatusOf($d) === 'open'));
    $h->test(
        'S5a pass 1 closes exactly the cap (7) older days',
        $closedPass1 === 7 && count($openRemaining) === 2,
        json_encode(['closed' => $closedPass1, 'open' => $openRemaining])
    );

    $sweepClose(); // pass 2
    $closedPass2 = 0;
    foreach ($stale as $d) {
        if ($dayStatusOf($d) === 'closed') {
            $closedPass2++;
        }
    }
    $h->test(
        'S5b the remainder closes on the next pass (all 9 closed)',
        $closedPass2 === 9,
        json_encode(['closed_after_pass2' => $closedPass2])
    );

    // ══════════════════════════════════════════════════════════════════════
    // S6 — unfinalized PM: notify-once behaviour, NO freeze
    // ══════════════════════════════════════════════════════════════════════
    $h->section('S6 unfinalized PM closes without freezing variance');
    $reset();
    $setDay($YESTERDAY, 'closed');
    $setDay('2026-10-18', 'open');
    $seedActivity('2026-10-18'); // moved product, PM ending NULL
    $db->execute(
        'INSERT INTO dl_variance_flags (branch_id, product_id, ledger_date, kind, shift, variance) VALUES (:b, :p, :d, "ending", "PM", 1)',
        [':b' => $BRANCH, ':p' => $PRODUCT, ':d' => '2026-10-18']
    );

    $sweepClose();
    $frozen = $db->prepare('SELECT frozen_at FROM dl_variance_flags WHERE branch_id = :b AND ledger_date = :d AND kind = "ending" LIMIT 1');
    $frozen->execute([':b' => $BRANCH, ':d' => '2026-10-18']);
    $frozenVal = $frozen->fetchColumn();
    $pendingVal = $pendingAt('2026-10-18');
    $gapAudit = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE module = 'daily-ledger' AND action = 'auto_close_day' AND entity_id = :eid");
    $gapAudit->execute([':eid' => $BRANCH . '-2026-10-18-PM']);
    $h->test(
        'S6a the swept unfinalized day closes and its PM is flagged once',
        $dayStatusOf('2026-10-18') === 'closed'
            && $pendingVal !== false && $pendingVal !== null && (string)$pendingVal !== ''
            && (int)$gapAudit->fetchColumn() === 1,
        json_encode(['day' => $dayStatusOf('2026-10-18'), 'pending' => $pendingVal])
    );
    $h->test(
        'S6b variance flags are NOT frozen for the unfinalized day (finalized-only rule)',
        $frozenVal === null,
        json_encode(['frozen_at' => $frozenVal])
    );

    // ══════════════════════════════════════════════════════════════════════
    // S7 — exactly ONE summary notification for swept days
    // ══════════════════════════════════════════════════════════════════════
    $h->section('S7 one summary notification for the swept backlog');
    $reset();
    $setDay($YESTERDAY, 'closed');
    foreach (['2026-10-18', '2026-10-17', '2026-10-16'] as $d) {
        $setDay($d, 'open');
    }

    $sweepClose();
    $h->test(
        'S7a exactly ONE summary notification is raised for the three swept days',
        $sweepNotifCount() === 1,
        'summary=' . $sweepNotifCount()
    );
    $h->test(
        'S7b NO per-day notification is raised for any swept day',
        $perDayNotifs() === 0,
        'per_day=' . $perDayNotifs()
    );

    // ══════════════════════════════════════════════════════════════════════
    // S8 — steady state: one probe query, no write, no notification
    // ══════════════════════════════════════════════════════════════════════
    $h->section('S8 steady state is one probe and no write');
    $reset();
    $setDay($YESTERDAY, 'closed');
    $setDay('2026-10-18', 'closed'); // nothing stale
    $notifsBefore = (int)$db->query('SELECT COUNT(*) FROM dl_integrity_notifications WHERE branch_id = ' . $BRANCH)->fetchColumn();
    $auditsBefore = (int)$db->query('SELECT COUNT(*) FROM audit_logs WHERE branch_id = ' . $BRANCH)->fetchColumn();

    $capture = true;
    $probeHits = 0;
    $writeHits = 0;
    app()->events()->listen('kernel.database.query.after', static function (array $payload) use (&$capture, &$probeHits, &$writeHits): void {
        if (!$capture) {
            return;
        }
        $sql = (string)($payload['sql'] ?? '');
        if (str_contains($sql, 'FROM (SELECT 1) AS one')) {
            $probeHits++;
        }
        if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)/i', $sql)) {
            $writeHits++;
        }
    });
    $r8 = $sweepClose();
    $capture = false;

    $notifsAfter = (int)$db->query('SELECT COUNT(*) FROM dl_integrity_notifications WHERE branch_id = ' . $BRANCH)->fetchColumn();
    $auditsAfter = (int)$db->query('SELECT COUNT(*) FROM audit_logs WHERE branch_id = ' . $BRANCH)->fetchColumn();
    $h->test(
        'S8a nothing stale closes nothing and issues no notification',
        $r8 === false && $notifsAfter === $notifsBefore && $auditsAfter === $auditsBefore,
        json_encode(['returned' => $r8, 'notifs' => [$notifsBefore, $notifsAfter], 'audits' => [$auditsBefore, $auditsAfter]])
    );
    $h->test(
        'S8b the sweep costs exactly ONE probe query and zero writes',
        $probeHits === 1 && $writeHits === 0,
        json_encode(['probe_queries' => $probeHits, 'write_queries' => $writeHits])
    );
} finally {
    $cleanup();
    saveModuleSettings('daily-ledger', is_array($origSettings) ? $origSettings : []);
    dlModuleSettings(true);
}

$h->done();
