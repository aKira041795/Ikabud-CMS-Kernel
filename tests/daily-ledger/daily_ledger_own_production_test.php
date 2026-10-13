<?php

declare(strict_types=1);

/**
 * Daily Ledger — Add Stock (own production)
 *
 * Some branches produce stock themselves: it enters the branch with NO delivery
 * receipt and NO commissary dispatch, so there was nowhere truthful to record
 * it (Add Stock forced the variance language; a DR would be a fiction). Owner
 * decision 2026-10-13: the cashier modal gains a distinct type
 * `own_production` ("Add Stock (own production)"), reason `self_production`,
 * and — because production happens IN a shift — an explicit AM/PM choice that
 * lands the quantity on the CHOSEN shift's Addt'l cell, for every role. The
 * finalized-shift lock still applies, evaluated on the chosen shift.
 *
 * These tests pin the contract that would otherwise erode:
 *   1. The type stays distinguishable (its own label/meta) but feeds the same
 *      Addt'l accumulator — online, offline replay and the edit path agree.
 *   2. Own production is positive-only (`dl_withdrawalTypeAllowsNegative` must
 *      NOT grow it — taking back an over-count is adjustment_add's job).
 *   3. The shift override changes ONE thing: the chosen shift for
 *      `own_production`. A bound cashier stays locked for every other type.
 *   4. A finalized chosen shift refuses the entry; the other shift still
 *      accepts it (the lock rides the choice, not the viewer).
 *   5. dl_reconcileAddtl recognizes own production as a source, so it never
 *      shows up as unexplained variance.
 *
 * Integration mode — real tenant DB (207), isolated fixtures.
 */

ob_start();

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('daily-ledger-own-production', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/handlers-offline.php');
$h->fingerprint('modules/daily-ledger/helpers.php');
$h->fingerprint('templates/modules/daily-ledger/cashier/modal_patch.disyl');

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

$db = $dlCtx->db();

$branchId = 99073;
$productId = 99073;
$date = '2030-04-10';
$dateFinal = '2030-04-11';
$boundCashierId = 990073;

foreach ([$date, $dateFinal] as $d) {
    $db->execute('DELETE FROM dl_ledger_shift_status WHERE branch_id = :b AND ledger_date = :d', [':b' => $branchId, ':d' => $d]);
    $db->execute('DELETE FROM dl_variance_flags WHERE branch_id = :b AND ledger_date = :d', [':b' => $branchId, ':d' => $d]);
}
$db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_cashier_withdrawals WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_branch_products WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_users WHERE id = :id', [':id' => $boundCashierId]);
$db->execute('DELETE FROM dl_branches WHERE id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_products WHERE id = :p', [':p' => $productId]);

$db->execute(
    'INSERT INTO dl_branches (id, code, name, address, default_supply_mode, is_commissary, is_active)
     VALUES (:id, :code, :name, :addr, :mode, 0, 1)',
    [':id' => $branchId, ':code' => 'T-OWNPROD', ':name' => 'Own Production Branch', ':addr' => 'Test', ':mode' => 'self_managed']
);
$db->execute(
    'INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active)
     VALUES (:id, :sku, :name, 25.0, 0, 1)',
    [':id' => $productId, ':sku' => 'OWNPROD-TEST', ':name' => 'Own Production Product']
);
$db->execute('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (:b, :p, 1)', [':b' => $branchId, ':p' => $productId]);
$db->execute(
    'INSERT INTO dl_users (id, username, password_hash, full_name, role, shift, is_active) VALUES (:id, :u, :p, :n, "cashier", "PM", 1)',
    [':id' => $boundCashierId, ':u' => 'ownprod-cashier-pm', ':p' => 'fixture', ':n' => 'Own Production PM Cashier']
);

$admin = ['id' => 999999, 'role' => 'admin', 'source' => 'daily-ledger'];
$boundCashier = ['id' => $boundCashierId, 'role' => 'cashier', 'source' => 'daily-ledger'];

/** Apply one withdrawal through the real worker (shares the online rules). */
$apply = static function (array $header, int $qty, string $shift, string $forDate, ?array $actor = null) use ($admin, $branchId, $productId): array {
    try {
        $res = dl_offlineApplyWithdrawal($actor ?? $admin, [
            'type' => 'withdrawal',
            'payload' => [
                'branch_id' => $branchId,
                'date' => $forDate,
                'shift' => $shift,
                'header' => $header,
                'lines' => [['product_id' => $productId, 'quantity' => $qty, 'unit' => 'pcs']],
            ],
        ]);
        return ['ok' => !empty($res['ok']), 'error' => '', 'code' => 0];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage(), 'code' => (int)$e->getCode()];
    }
};

$ledgerAddtl = static function (string $shift, string $forDate = '2030-04-10') use ($db, $branchId, $productId): int {
    $stmt = $db->prepare(
        'SELECT COALESCE(SUM(addtl), 0) FROM dl_daily_ledger
          WHERE branch_id = :b AND product_id = :p AND ledger_date = :d AND shift = :s'
    );
    $stmt->execute([':b' => $branchId, ':p' => $productId, ':d' => $forDate, ':s' => $shift]);
    return (int)$stmt->fetchColumn();
};

$rowCount = static fn (): int => (int)$db->query(
    "SELECT COUNT(*) FROM dl_cashier_withdrawals WHERE branch_id = {$branchId} AND product_id = {$productId}"
)->fetchColumn();

// ─── Wiring: lists, labels, migration, source pins ──────────────────────
$h->section('Wiring — reason list, labels, migration, source pins');

$h->test('self_production is an allowed withdrawal reason', in_array('self_production', dl_allowedWithdrawalReasons(), true));
$h->test('own_production has its own badge/label meta', dlWithdrawalTypeMeta('own_production')['label'] === 'Own production');
$h->test('self_production has a human label', (dl_dailySheetAdjustmentReasons()['self_production'] ?? '') === 'Self production');
$h->test('own_production is positive-only (no minus)', !dl_withdrawalTypeAllowsNegative('own_production'));
$h->test('adjustment_add still accepts a minus', dl_withdrawalTypeAllowsNegative('adjustment_add'));

$migrationFile = $base . '/modules/daily-ledger/database/migrations/093_self_production_reason.sql';
$migrationSql = is_file($migrationFile) ? (string)file_get_contents($migrationFile) : '';
$h->test('migration 093 exists and adds the enum value', str_contains($migrationSql, "'self_production'") && str_contains($migrationSql, 'MODIFY COLUMN reason_code'));
$h->test('migration 093 is registered in module.json', str_contains((string)file_get_contents($base . '/modules/daily-ledger/module.json'), '093_self_production_reason.sql'));

$handlersSrc = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
$offlineSrc = (string)file_get_contents($base . '/modules/daily-ledger/handlers-offline.php');
$modalSrc = (string)file_get_contents($base . '/templates/modules/daily-ledger/cashier/modal_patch.disyl');

$h->test('create path allowlists the type', str_contains($handlersSrc, "['charge', 'pullout', 'adjustment_add', 'own_production', 'used', 'correction']"));
$h->test('create + edit paths feed addtl for it', substr_count($handlersSrc, "['adjustment_add', 'own_production']") >= 2);
$h->test('offline replay mirrors the type and addtl wiring', str_contains($offlineSrc, "'own_production'") && str_contains($offlineSrc, "['adjustment_add', 'own_production']"));
$h->test('modal offers the capture option', str_contains($modalSrc, '<option value="own_production">Add Stock (own production)</option>'));
// Without this option the preset state exists but the rendered select cannot SHOW
// it — measured in the browser 2026-10-13: state='self_production', rendered='manual_adjustment'.
$h->test('modal offers the Self production reason option', str_contains($modalSrc, '<option value="self_production">Self production</option>'));
$h->test('modal asks for the production shift', str_contains($modalSrc, 'Production Shift'));
$h->test('modal sends the chosen shift for this type only', str_contains($modalSrc, 'shiftForSave()'));
// The preset is driven by a $watch, not @change: measured in the browser
// (2026-10-13) a native change event can land BEFORE x-model syncs, so an
// @change hook reads the OLD type and presets nothing.
$h->test('modal presets reason via a state watch (ordering-proof)', str_contains($modalSrc, "\$watch('header.withdrawal_type'"));

// ─── Shift semantics of dl_resolveWithdrawalShift ───────────────────────
$h->section('Shift semantics — chosen shift for own production, lock intact elsewhere');

$h->test(
    'bound cashier (PM) gets the CHOSEN AM for own production',
    dl_resolveWithdrawalShift($boundCashier, ['shift' => 'AM'], 'own_production') === 'AM'
);
$h->test(
    'bound cashier (PM) stays locked to PM for charge',
    dl_resolveWithdrawalShift($boundCashier, ['shift' => 'AM'], 'charge') === 'PM'
);
$h->test(
    'bound cashier (PM) without an explicit shift falls back to PM',
    dl_resolveWithdrawalShift($boundCashier, [], 'own_production') === 'PM'
);
$h->test(
    'admin explicit AM honored for own production',
    dl_resolveWithdrawalShift($admin, ['shift' => 'AM'], 'own_production') === 'AM'
);

// ─── Capture through the real worker ────────────────────────────────────
$h->section('Capture — lands on the chosen shift with the preset reason');

$create = $apply(
    ['withdrawal_type' => 'own_production', 'reason_code' => 'self_production'],
    12,
    'AM',
    $date
);
$h->test('own production creates without a liable person', $create['ok']);

$row = $db->query(
    "SELECT withdrawal_type, reason_code, shift, quantity, liable_user_id FROM dl_cashier_withdrawals
      WHERE branch_id = {$branchId} AND product_id = {$productId} ORDER BY id DESC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
$h->test('row stored as own_production', ($row['withdrawal_type'] ?? '') === 'own_production');
$h->test('row carries the self_production reason', ($row['reason_code'] ?? '') === 'self_production');
$h->test('row landed on the CHOSEN shift (AM)', ($row['shift'] ?? '') === 'AM');
$h->test('no liable person recorded', ($row['liable_user_id'] ?? null) === null || (int)$row['liable_user_id'] === 0);
$h->test('AM Addt\'l cell moved by 12', $ledgerAddtl('AM') === 12);
$h->test('PM Addt\'l cell untouched', $ledgerAddtl('PM') === 0);

$second = $apply(['withdrawal_type' => 'own_production'], 3, 'PM', $date);
$row2 = $db->query(
    "SELECT reason_code, shift FROM dl_cashier_withdrawals
      WHERE branch_id = {$branchId} AND product_id = {$productId} ORDER BY id DESC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
$h->test('omitted reason defaults to self_production (type states the reason)', ($row2['reason_code'] ?? '') === 'self_production');
$h->test('second capture landed on PM as chosen', ($row2['shift'] ?? '') === 'PM');
$h->test('PM Addt\'l cell moved by 3', $ledgerAddtl('PM') === 3);

// ─── Refusals ────────────────────────────────────────────────────────────
$h->section('Refusals — negative refused, finalized chosen shift refused');

$before = $rowCount();
$neg = $apply(['withdrawal_type' => 'own_production', 'reason_code' => 'self_production'], -5, 'AM', $date);
$h->test('negative on own production refused with 422', !$neg['ok'] && $neg['code'] === 422);
$h->test('refusal wrote nothing', $rowCount() === $before && $ledgerAddtl('AM') === 12);

$db->execute(
    "INSERT INTO dl_ledger_shift_status (branch_id, ledger_date, shift, status) VALUES (:b, :d, 'AM', 'finalized')",
    [':b' => $branchId, ':d' => $dateFinal]
);
$finAm = $apply(['withdrawal_type' => 'own_production', 'reason_code' => 'self_production'], 4, 'AM', $dateFinal);
$h->test('finalized chosen shift refuses the entry (403)', !$finAm['ok'] && $finAm['code'] === 403);
$finPm = $apply(['withdrawal_type' => 'own_production', 'reason_code' => 'self_production'], 4, 'PM', $dateFinal);
$h->test('the other shift still accepts it', $finPm['ok']);

// ─── Reconciliation recognizes the source ───────────────────────────────
$h->section('Reconciliation — own production is an explained source, not variance');

$diffs = dl_reconcileAddtl($db, $branchId, $date, $date);
$unexplained = array_filter($diffs, static fn (array $r): bool => (int)$r['difference'] !== 0);
$h->test('no unexplained Addt\'l difference on captured dates', $unexplained === []);

// ─── Cleanup ───────────────────────────────────────────────────────────
$h->section('Cleanup');

foreach ([$date, $dateFinal] as $d) {
    $db->execute('DELETE FROM dl_ledger_shift_status WHERE branch_id = :b AND ledger_date = :d', [':b' => $branchId, ':d' => $d]);
    $db->execute('DELETE FROM dl_variance_flags WHERE branch_id = :b AND ledger_date = :d', [':b' => $branchId, ':d' => $d]);
}
$db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_cashier_withdrawals WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_branch_products WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_users WHERE id = :id', [':id' => $boundCashierId]);
$db->execute('DELETE FROM dl_branches WHERE id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_products WHERE id = :p', [':p' => $productId]);

$left = (int)$db->query("SELECT COUNT(*) FROM dl_cashier_withdrawals WHERE branch_id = {$branchId}")->fetchColumn();
$h->test('cleanup removed the fixture rows', $left === 0);

$h->done();
