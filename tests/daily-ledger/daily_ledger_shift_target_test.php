<?php

declare(strict_types=1);

/**
 * Daily Ledger — which shift a save targets
 *
 * Reported bug: on the live host a Stock Adjustment was accepted but the
 * Addt'l cell stayed at zero. The withdrawal modal posted no `shift`, so
 * dl_resolveLedgerShift() guessed from the request clock (or the user's
 * assigned shift) and the increment landed on the OTHER shift's ledger row
 * while the operator looked at the row they were fixing.
 *
 * This suite locks in:
 *   - an explicit shift from the caller is honoured for admin/supervisor
 *   - an assigned cashier stays locked to their own shift (the control)
 *   - no explicit shift keeps the previous behaviour (bound, else clock)
 *   - every ledger-writing cashier client sends the shift being viewed
 */

ob_start();

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('daily-ledger-shift-target', TestHarness::MODE_INTEGRATION, 'localhost');
ob_end_clean();

$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('templates/modules/daily-ledger/cashier/modal_patch.disyl');
$h->fingerprint('templates/modules/daily-ledger/cashier/receive_modal.disyl');
$h->fingerprint('templates/modules/daily-ledger/cashier/dispatch_modal.disyl');
$h->fingerprint('templates/modules/daily-ledger/cashier/edit_delivery_modal.disyl');
$h->fingerprint('templates/modules/daily-ledger/cashier/ledger.disyl');

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-offline.php';
require_once $base . '/modules/daily-ledger/handlers.php';

app()->tenant()->setTenantId(207);
$dlCtx = modulePushContext('daily-ledger');
if (!$dlCtx) {
    fwrite(STDERR, "daily-ledger module context unavailable\n");
    exit(1);
}

/** @var \Ikabud\Kernel\Contracts\DatabaseContract $db */
$db = $dlCtx->db();

$boundCashierId = 999996;
$freeCashierId = 999995;

/** Seed a dl_users row with only the NOT NULL columns that lack defaults. */
function dl_st_seedUser($db, int $id, string $username, string $role, ?string $shift): void
{
    $cols = [];
    foreach ($db->query('SHOW COLUMNS FROM dl_users') as $c) {
        $cols[(string)$c['Field']] = $c;
    }
    $insertCols = ['id', 'username', 'password_hash', 'full_name', 'role', 'is_active'];
    $bind = [
        ':id' => $id,
        ':username' => $username,
        ':password_hash' => 'unused',
        ':full_name' => $username,
        ':role' => $role,
        ':is_active' => 1,
    ];
    foreach ($cols as $field => $meta) {
        if (in_array($field, $insertCols, true) || in_array($field, ['deleted_at', 'created_at', 'updated_at', 'shift'], true)) {
            continue;
        }
        if ((string)$meta['Null'] === 'NO' && ($meta['Default'] === null || $meta['Default'] === '')) {
            $insertCols[] = $field;
            $bind[':' . $field] = '';
        }
    }
    $colSql = implode(', ', array_map(static fn (string $f) => '`' . $f . '`', $insertCols));
    $valSql = implode(', ', array_map(static fn (string $f) => ':' . $f, $insertCols));
    $db->execute("INSERT INTO dl_users ({$colSql}) VALUES ({$valSql})", $bind);
    if ($shift !== null) {
        $db->execute('UPDATE dl_users SET shift = :s WHERE id = :id', [':s' => $shift, ':id' => $id]);
    }
}

$db->execute('DELETE FROM dl_users WHERE id IN (:a, :b)', [':a' => $boundCashierId, ':b' => $freeCashierId]);
dl_st_seedUser($db, $boundCashierId, 'shift-target-bound', 'cashier', 'AM');
dl_st_seedUser($db, $freeCashierId, 'shift-target-free', 'cashier', null);

$admin = ['id' => 999999, 'role' => 'admin', 'source' => 'daily-ledger'];
$supervisor = ['id' => 999998, 'role' => 'supervisor', 'source' => 'daily-ledger'];
$boundCashier = ['id' => $boundCashierId, 'role' => 'cashier', 'source' => 'daily-ledger'];
$freeCashier = ['id' => $freeCashierId, 'role' => 'cashier', 'source' => 'daily-ledger'];

$clockShift = dl_currentShift();
$otherShift = $clockShift === 'PM' ? 'AM' : 'PM';

// ─── Explicit shift is honoured for ledger-correction roles ────────────
$h->section('Explicit shift (admin / supervisor)');

$h->detail("request-clock shift is {$clockShift}; asserting against {$otherShift}");

$res = dl_resolveLedgerShift($admin, ['shift' => $otherShift]);
$h->test('admin targets the shift they are viewing', $res['shift'] === $otherShift, 'got ' . $res['shift']);
$h->test('admin is not reported as bound', $res['bound'] === false);

$res = dl_resolveLedgerShift($admin, ['shift' => $clockShift]);
$h->test('admin can target the clock shift too', $res['shift'] === $clockShift, 'got ' . $res['shift']);

$res = dl_resolveLedgerShift($supervisor, ['shift' => $otherShift]);
$h->test('supervisor targets the shift they are viewing', $res['shift'] === $otherShift, 'got ' . $res['shift']);

$res = dl_resolveLedgerShift($admin, ['shift' => 'XX']);
$h->test('an invalid shift is ignored', $res['shift'] === $clockShift, 'got ' . $res['shift']);

$res = dl_resolveLedgerShift($admin, []);
$h->test('admin without a shift falls back to the clock', $res['shift'] === $clockShift, 'got ' . $res['shift']);

// ─── The cashier accountability control is untouched ───────────────────
$h->section('Assigned cashier stays locked');

$res = dl_resolveLedgerShift($boundCashier, ['shift' => 'PM']);
$h->test('a cashier assigned to AM cannot post to PM', $res['shift'] === 'AM', 'got ' . $res['shift']);
$h->test('the cashier is reported as bound', $res['bound'] === true);

$res = dl_resolveLedgerShift($boundCashier, []);
$h->test('a bound cashier resolves to their shift', $res['shift'] === 'AM', 'got ' . $res['shift']);

$res = dl_resolveLedgerShift($freeCashier, ['shift' => $otherShift]);
$h->test('an unassigned cashier follows the shift they view', $res['shift'] === $otherShift, 'got ' . $res['shift']);

$res = dl_resolveLedgerShift($freeCashier, []);
$h->test('an unassigned cashier falls back to the clock', $res['shift'] === $clockShift, 'got ' . $res['shift']);

// ─── Client contract: send the shift being viewed ──────────────────────
$h->section('Clients send the viewed shift');

$read = static function (string $rel) use ($base): string {
    return (string)file_get_contents($base . '/' . $rel);
};

$dir = 'templates/modules/daily-ledger/cashier/';
$h->test('withdrawal modal (Add Stock / adjustments) sends the viewed shift', str_contains($read($dir . 'modal_patch.disyl'), "shift: (window.SHIFT || '')"));
$h->test('receive modal sends the viewed shift', str_contains($read($dir . 'receive_modal.disyl'), "shift: (window.SHIFT || '')"));
$h->test('dispatch modal sends the viewed shift', str_contains($read($dir . 'dispatch_modal.disyl'), "shift: (window.SHIFT || '')"));
$h->test('delivery-correction modal sends the viewed shift', str_contains($read($dir . 'edit_delivery_modal.disyl'), "shift: (window.SHIFT || '')"));
$h->test('ledger cell save sends the viewed shift (pre-existing convention)', str_contains($read($dir . 'ledger.disyl'), 'shift: SHIFT'));

// ─── The top bar must show the clock the shifts follow ─────────────────
$h->section('Top-bar server clock');

$clock = dl_operatingClockLabel();
$h->test('clock label helper exposes the server time', isset($clock['server_now_label'], $clock['server_now_offset'], $clock['server_epoch_ms']));

$parsed = \DateTimeImmutable::createFromFormat(
    'D, M j, Y, h:i:s A',
    (string)($clock['server_now_label'] ?? ''),
    new \DateTimeZone((string)($clock['operating_timezone'] ?? 'UTC'))
);
$h->test(
    'the clock reads the current time in the business timezone',
    $parsed instanceof \DateTimeImmutable && abs($parsed->getTimestamp() - time()) <= 5,
    (string)($clock['server_now_label'] ?? '')
);
$h->test('the clock is anchored to the server clock, not the browser', abs(((int)($clock['server_epoch_ms'] ?? 0) / 1000) - microtime(true)) < 5);
$h->test('the offset is rendered next to the timezone', (bool)preg_match('/^[+-]\d{2}:\d{2}$/', (string)($clock['server_now_offset'] ?? '')));

$clockPartial = $read('templates/modules/daily-ledger/cashier/partials/server-clock.disyl');
$h->test('the clock partial targets the business timezone', str_contains($clockPartial, 'data-server-timezone="{operating_timezone}"'));
$h->test('the clock partial formats in an explicit timezone (never the viewer default)', str_contains($clockPartial, 'timeZone: zone'));
$h->test('the clock partial keeps the server-rendered text when JS cannot format', str_contains($clockPartial, 'keep the server-rendered string'));
$h->test('the clock compares the viewer zone with the business zone', str_contains($clockPartial, 'resolvedOptions().timeZone'));
$h->test('a viewer/business zone mismatch is warned about, not silently adopted', str_contains($clockPartial, 'deviceZone === zone') && str_contains($clockPartial, 'shifts and dates follow'));
$h->test('the note is announced to assistive tech', str_contains($clockPartial, 'aria-live="polite"'));
$h->test('the ledger top bar includes the clock', str_contains($read('templates/modules/daily-ledger/cashier/ledger.disyl'), 'partials/server-clock.disyl'));
$h->test('the ledger handler passes the clock values', str_contains((string)file_get_contents($base . '/modules/daily-ledger/handlers.php'), "'server_epoch_ms' => \$clockLabel['server_epoch_ms']"));



$hashFor = static function (string $shift) {
    return dl_withdrawalDedupHash(8, 35, '2026-09-03', 'adjustment_add', 'encoder_omission', null, null, null, 1, null, 'pcs', $shift);
};
$h->test('the same line on AM and PM fingerprints differently', $hashFor('AM') !== $hashFor('PM'));
$h->test('the same line on the same shift fingerprints identically', $hashFor('PM') === $hashFor('PM'));
$h->test('a legacy NULL shift stays distinct from AM/PM', $hashFor('PM') !== dl_withdrawalDedupHash(8, 35, '2026-09-03', 'adjustment_add', 'encoder_omission', null, null, null, 1, null, 'pcs', null));

$dedupBranchId = 99057;
$dedupProductId = 99057;
$dedupDate = '2030-02-16';

$db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b', [':b' => $dedupBranchId]);
$db->execute('DELETE FROM dl_cashier_withdrawals WHERE branch_id = :b', [':b' => $dedupBranchId]);
$db->execute('DELETE FROM dl_branch_products WHERE branch_id = :b', [':b' => $dedupBranchId]);
$db->execute('DELETE FROM dl_branches WHERE id = :b', [':b' => $dedupBranchId]);
$db->execute('DELETE FROM dl_products WHERE id = :p', [':p' => $dedupProductId]);
$db->execute(
    'INSERT INTO dl_branches (id, code, name, address, default_supply_mode, is_commissary, is_active)
     VALUES (:id, :code, :name, :addr, :mode, 0, 1)',
    [':id' => $dedupBranchId, ':code' => 'T-DEDUP', ':name' => 'Dedup Test Branch', ':addr' => 'Test', ':mode' => 'self_managed']
);
$db->execute(
    'INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active)
     VALUES (:id, :sku, :name, 25.0, 0, 1)',
    [':id' => $dedupProductId, ':sku' => 'DEDUP-TEST', ':name' => 'Dedup Test Product']
);
$db->execute('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (:b, :p, 1)', [':b' => $dedupBranchId, ':p' => $dedupProductId]);

/** Apply the identical Add Stock line on a given shift through the real worker. */
$applyAdd = static function (string $shift) use ($admin, $dedupBranchId, $dedupDate, $dedupProductId) {
    return dl_offlineApplyWithdrawal($admin, [
        'type' => 'withdrawal',
        'payload' => [
            'branch_id' => $dedupBranchId,
            'date' => $dedupDate,
            'shift' => $shift,
            'header' => ['withdrawal_type' => 'adjustment_add', 'reason_code' => 'encoder_omission'],
            'lines' => [['product_id' => $dedupProductId, 'quantity' => 1, 'unit' => 'pcs']],
        ],
    ]);
};

$amApplied = null;
$amError = '';
try {
    $amApplied = $applyAdd('AM');
} catch (Throwable $e) {
    $amError = $e->getMessage();
}
$h->test('the first (AM) line is recorded', is_array($amApplied) && !empty($amApplied['ok']), $amError);

$pmApplied = null;
$pmError = '';
try {
    $pmApplied = $applyAdd('PM');
} catch (Throwable $e) {
    $pmError = $e->getMessage();
}
$h->test(
    'the identical line on PM is NOT treated as a duplicate',
    is_array($pmApplied) && !empty($pmApplied['ok']) && empty($pmApplied['duplicate']),
    $pmError !== '' ? $pmError : json_encode($pmApplied)
);

$pmRepeatRejected = false;
try {
    $applyAdd('PM');
} catch (Throwable $e) {
    $pmRepeatRejected = true;
}
$h->test('replaying the identical PM line is still rejected', $pmRepeatRejected);

$rows = [];
foreach ($db->query("SELECT shift, addtl FROM dl_daily_ledger WHERE branch_id = {$dedupBranchId} AND product_id = {$dedupProductId} ORDER BY shift") as $r) {
    $rows[$r['shift']] = (int)$r['addtl'];
}
$h->test('both shifts carry the adjustment', ($rows['AM'] ?? 0) === 1 && ($rows['PM'] ?? 0) === 1, json_encode($rows));

$countStmt = $db->prepare('SELECT COUNT(*) FROM dl_cashier_withdrawals WHERE branch_id = :b AND product_id = :p');
$countStmt->execute([':b' => $dedupBranchId, ':p' => $dedupProductId]);
$h->test('exactly two withdrawal rows exist (AM + PM)', (int)$countStmt->fetchColumn() === 2);

$h->test(
    'migration 059 is registered in module.json',
    in_array('database/migrations/059_refresh_dedup_hash_with_shift.sql', json_decode((string)file_get_contents($base . '/modules/daily-ledger/module.json'), true)['migrations'] ?? [], true)
);

$db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b', [':b' => $dedupBranchId]);
$db->execute('DELETE FROM dl_cashier_withdrawals WHERE branch_id = :b', [':b' => $dedupBranchId]);
$db->execute('DELETE FROM dl_branch_products WHERE branch_id = :b', [':b' => $dedupBranchId]);
$db->execute('DELETE FROM dl_branches WHERE id = :b', [':b' => $dedupBranchId]);
$db->execute('DELETE FROM dl_products WHERE id = :p', [':p' => $dedupProductId]);

// ─── Cleanup ───────────────────────────────────────────────────────────
$db->execute('DELETE FROM dl_users WHERE id IN (:a, :b)', [':a' => $boundCashierId, ':b' => $freeCashierId]);
$left = (int)$db->query("SELECT COUNT(*) FROM dl_users WHERE id IN ({$boundCashierId}, {$freeCashierId})")->fetchColumn();
$h->test('cleanup removed the test users', $left === 0);

$h->done();