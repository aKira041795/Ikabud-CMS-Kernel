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

// ─── Cleanup ───────────────────────────────────────────────────────────
$db->execute('DELETE FROM dl_users WHERE id IN (:a, :b)', [':a' => $boundCashierId, ':b' => $freeCashierId]);
$left = (int)$db->query("SELECT COUNT(*) FROM dl_users WHERE id IN ({$boundCashierId}, {$freeCashierId})")->fetchColumn();
$h->test('cleanup removed the test users', $left === 0);

$h->done();
