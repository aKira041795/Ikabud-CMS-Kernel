<?php

declare(strict_types=1);

/**
 * Daily Ledger — Stock Adjustment: which combinations require a charged person
 *
 * Add Stock (adjustment_add) resolves a shortage, so it must name who is
 * charged. Nothing else charges anybody: charge/pullout/used/correction record
 * what they record, and the Charge-to field is not even shown for them.
 *
 * The modal's validate() once demanded a liable person for EVERY type, which
 * made every non-Add-Stock adjustment impossible to save (the field it asked
 * for was hidden). This suite pins the whole matrix so the client rule and the
 * server rule cannot drift apart again.
 *
 * Integration mode — real tenant DB (207), isolated fixtures, full cleanup.
 */

ob_start();

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('daily-ledger-adjustment-matrix', TestHarness::MODE_INTEGRATION, 'localhost');
ob_end_clean();

$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/handlers-offline.php');
$h->fingerprint('modules/daily-ledger/helpers.php');
$h->fingerprint('templates/modules/daily-ledger/cashier/modal_patch.disyl');
$h->fingerprint('templates/modules/daily-ledger/cashier/ledger.disyl');
$h->fingerprint('templates/modules/daily-ledger/cashier/partials/ledger-rows.disyl');

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

$branchId = 99061;
$productId = 99061;
$liableId = 999994;
$date = '2030-02-21';

$db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_cashier_withdrawals WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_branch_products WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_branches WHERE id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_products WHERE id = :p', [':p' => $productId]);
$db->execute('DELETE FROM dl_users WHERE id = :u', [':u' => $liableId]);

$db->execute(
    'INSERT INTO dl_branches (id, code, name, address, default_supply_mode, is_commissary, is_active)
     VALUES (:id, :code, :name, :addr, :mode, 0, 1)',
    [':id' => $branchId, ':code' => 'T-MATRIX', ':name' => 'Matrix Branch', ':addr' => 'Test', ':mode' => 'self_managed']
);
$db->execute(
    'INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active)
     VALUES (:id, :sku, :name, 25.0, 0, 1)',
    [':id' => $productId, ':sku' => 'MATRIX-TEST', ':name' => 'Matrix Test Product']
);
$db->execute('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (:b, :p, 1)', [':b' => $branchId, ':p' => $productId]);

/**
 * Seed a minimal dl_users row (NOT NULL columns without defaults get '').
 * $branchIds links the user to branches through dl_user_branches.
 */
function dl_mx_seedUser($db, int $id, string $username, string $role, ?string $shift = null, int $active = 1, bool $deleted = false, array $branchIds = []): void
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
        ':is_active' => $active,
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
    if ($deleted) {
        $db->execute('UPDATE dl_users SET deleted_at = NOW() WHERE id = :id', [':id' => $id]);
    }
    foreach ($branchIds as $bid) {
        $db->execute('INSERT INTO dl_user_branches (user_id, branch_id) VALUES (:u, :b)', [':u' => $id, ':b' => (int)$bid]);
    }
}

$liableId = 999994;
dl_mx_seedUser($db, $liableId, 'matrix-liable', 'cashier', 'AM', 1, false, [$branchId]);

$admin = ['id' => 999999, 'role' => 'admin', 'source' => 'daily-ledger'];

/**
 * Apply one adjustment through the real worker.
 *
 * @return array{ok:bool,error:string,code:int}
 */
$apply = static function (array $header, int $qty) use ($admin, $branchId, $date, $productId): array {
    try {
        $res = dl_offlineApplyWithdrawal($admin, [
            'type' => 'withdrawal',
            'payload' => [
                'branch_id' => $branchId,
                'date' => $date,
                'shift' => 'AM',
                'header' => $header,
                'lines' => [['product_id' => $productId, 'quantity' => $qty, 'unit' => 'pcs']],
            ],
        ]);
        return ['ok' => !empty($res['ok']), 'error' => '', 'code' => 0];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage(), 'code' => (int)$e->getCode()];
    }
};

$mentionsLiable = static function (string $message): bool {
    return (bool)preg_match('/liable|charge to/i', $message);
};

// ─── Types that must NOT demand a charge ───────────────────────────────
$h->section('No charge demanded (charge / pullout / used / correction)');

$noChargeCases = [
    ['charge', ['withdrawal_type' => 'charge', 'reason_code' => 'manual_adjustment']],
    ['charge + spoilage', ['withdrawal_type' => 'charge', 'reason_code' => 'spoilage']],
    ['pullout', ['withdrawal_type' => 'pullout', 'reason_code' => 'manual_adjustment']],
    ['pullout + damage', ['withdrawal_type' => 'pullout', 'reason_code' => 'damage']],
    ['used + staff_meal', ['withdrawal_type' => 'used', 'reason_code' => 'staff_meal']],
    ['correction + other', ['withdrawal_type' => 'correction', 'reason_code' => 'other', 'custom_reason' => 'matrix probe']],
];
$qty = 1;
foreach ($noChargeCases as [$label, $header]) {
    $res = $apply($header, $qty++);
    $blockedOnLiable = !$res['ok'] && $mentionsLiable($res['error']);
    $h->test(
        "{$label} without a liable person is not blocked by the charge rule",
        !$blockedOnLiable,
        $blockedOnLiable ? 'rejected: ' . $res['error'] : ($res['ok'] ? 'accepted' : 'other error (not the charge rule): ' . $res['error'])
    );
}

// ─── Add Stock: a shortage must be charged ─────────────────────────────
$h->section('Add Stock demands a charge unless nothing was lost');

foreach (['manual_adjustment', 'spoilage', 'damage'] as $reason) {
    $res = $apply(['withdrawal_type' => 'adjustment_add', 'reason_code' => $reason], 1);
    $h->test(
        "Add Stock + {$reason} without a liable person is rejected",
        !$res['ok'] && $res['code'] === 422 && $mentionsLiable($res['error']),
        $res['ok'] ? 'accepted (wrong)' : 'code=' . $res['code'] . ' ' . $res['error']
    );
}

$omission = $apply(['withdrawal_type' => 'adjustment_add', 'reason_code' => 'encoder_omission'], 1);
$h->test('Add Stock + encoder omission is accepted without a charge', $omission['ok'], $omission['error']);

$charged = $apply(['withdrawal_type' => 'adjustment_add', 'reason_code' => 'manual_adjustment', 'liable_user_id' => $liableId], 1);
$h->test('Add Stock + a charge is accepted', $charged['ok'], $charged['error']);

// A charge can be billed to the person responsible (optional, but recorded).
$chargeWithPerson = $apply(['withdrawal_type' => 'charge', 'reason_code' => 'staff_meal', 'liable_user_id' => $liableId], 9);
$h->test('charge + a person is accepted', $chargeWithPerson['ok'], $chargeWithPerson['error']);
$chargeStmt = $db->prepare("SELECT liable_user_id FROM dl_cashier_withdrawals WHERE branch_id = :b AND withdrawal_type = 'charge' ORDER BY id DESC LIMIT 1");
$chargeStmt->execute([':b' => $branchId]);
$h->test('the charged person is stored on a charge', (int)$chargeStmt->fetchColumn() === $liableId);

$rowStmt = $db->prepare('SELECT reason_code, liable_user_id FROM dl_cashier_withdrawals WHERE branch_id = :b ORDER BY id DESC LIMIT 1');
$rowStmt->execute([':b' => $branchId]);
$lastRow = $rowStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$h->test('the charge is stored on the charged entry', (int)($lastRow['liable_user_id'] ?? 0) === $liableId, (string)($lastRow['liable_user_id'] ?? 'null'));

$omitStmt = $db->prepare("SELECT liable_user_id FROM dl_cashier_withdrawals WHERE branch_id = :b AND reason_code = 'encoder_omission' LIMIT 1");
$omitStmt->execute([':b' => $branchId]);
$h->test('no liable person is stored for the omission entry', ($omitStmt->fetchColumn() === null));

// ─── The client rule mirrors the server rule ───────────────────────────
$h->section('Modal rule mirrors the server');

$modalSrc = (string)file_get_contents($base . '/templates/modules/daily-ledger/cashier/modal_patch.disyl');
$h->test(
    'needsLiable() is limited to Add Stock that is not an encoder omission',
    str_contains($modalSrc, "return this.header.withdrawal_type === 'adjustment_add' && this.header.reason_code !== 'encoder_omission';")
);
$h->test('validate() uses needsLiable()', str_contains($modalSrc, 'if (this.needsLiable() &&'));
$h->test('the Charge-to field is offered for Add Stock and for Charge', str_contains($modalSrc, 'x-show="showsLiable()"') && str_contains($modalSrc, "=== 'adjustment_add' || this.header.withdrawal_type === 'charge'"));
$h->test('an encoder omission shows the nothing-lost note instead of the picker', str_contains($modalSrc, 'isOmission()') && str_contains($modalSrc, 'Nothing was lost'));
$h->test('the field is optional for a charge and required for Add Stock', str_contains($modalSrc, '<span x-show="!needsLiable()" class="text-gray-400 font-normal">(optional)</span>'));
$h->test('the payload sends the person whenever the field is offered', str_contains($modalSrc, 'liable_user_id: this.showsLiable() ?'));
$h->test('the Charge type dropped its cashier qualifier', str_contains($modalSrc, '<option value="charge">Charge</option>'));
$h->test('the Pullout type dropped its past-saleable qualifier', str_contains($modalSrc, '<option value="pullout">Pullout</option>'));

// ─── Row shortcuts preset the product ──────────────────────────────────
$h->section('Row shortcuts preset the product');

$ledgerSrc = (string)file_get_contents($base . '/templates/modules/daily-ledger/cashier/ledger.disyl');
$rowsSrc = (string)file_get_contents($base . '/templates/modules/daily-ledger/cashier/partials/ledger-rows.disyl');
$h->test('the W/Draw cell opens the modal on its own product', str_contains($rowsSrc, 'onclick="dlAdjustRowDirect({row.product_id})"'));
$h->test('the W/Draw shortcut passes the product id through', str_contains($ledgerSrc, 'window.dlAdjustRowDirect = function(productId)'));
$h->test('the W/Draw shortcut no longer opens a blank modal', !str_contains($rowsSrc, 'onclick="openWithdrawalModal()"'));
$h->test('the Addt\'l cell still presets its product and reason', str_contains($rowsSrc, 'onclick="dlAddStockDirect({row.product_id})"') && str_contains($ledgerSrc, "reason_code: 'encoder_omission'"));
$h->test('the W/Draw shortcut names the row product in its tooltip', str_contains($rowsSrc, 'title="Record an adjustment for {row.name}'));

// ─── Who can be charged: this branch's cashiers come first ─────────────
$h->section('Charge-to list is branch-scoped');

$amCashier = 999991;
$pmCashier = 999990;
$otherBranchCashier = 999989;
$globalSupervisor = 999988;
$inactiveCashier = 999987;
$deletedCashier = 999986;
$chargeUsers = [$amCashier, $pmCashier, $otherBranchCashier, $globalSupervisor, $inactiveCashier, $deletedCashier];

foreach ($chargeUsers as $uid) {
    $db->execute('DELETE FROM dl_user_branches WHERE user_id = :u', [':u' => $uid]);
    $db->execute('DELETE FROM dl_users WHERE id = :u', [':u' => $uid]);
}
dl_mx_seedUser($db, $amCashier, 'aaa-am-cashier', 'cashier', 'AM', 1, false, [$branchId]);
dl_mx_seedUser($db, $pmCashier, 'zzz-pm-cashier', 'cashier', 'PM', 1, false, [$branchId]);
dl_mx_seedUser($db, $otherBranchCashier, 'other-branch-cashier', 'cashier', 'PM', 1, false, [8]);
dl_mx_seedUser($db, $globalSupervisor, 'global-supervisor', 'supervisor', null, 1, false, []);
dl_mx_seedUser($db, $inactiveCashier, 'inactive-cashier', 'cashier', 'AM', 0, false, [$branchId]);
dl_mx_seedUser($db, $deletedCashier, 'deleted-cashier', 'cashier', 'AM', 1, true, [$branchId]);

$liableList = dl_liablePersonsForBranch($db, $branchId);
$liableIds = array_map(static fn (array $p) => $p['id'], $liableList);
$liableById = [];
foreach ($liableList as $p) {
    $liableById[$p['id']] = $p;
}

$h->test('the branch cashiers are listed', in_array($amCashier, $liableIds, true) && in_array($pmCashier, $liableIds, true), json_encode($liableIds));
$h->test('another branch\'s cashier is not listed', !in_array($otherBranchCashier, $liableIds, true));
$h->test('an inactive cashier is not listed', !in_array($inactiveCashier, $liableIds, true));
$h->test('a soft-deleted cashier is not listed', !in_array($deletedCashier, $liableIds, true));
$h->test('branch-independent roles are still listed', in_array($globalSupervisor, $liableIds, true));

$roles = array_map(static fn (array $p) => $p['role'], $liableList);
$cashierCount = count(array_filter($roles, static fn (string $r) => $r === 'cashier'));
$firstNonCashier = null;
foreach ($roles as $i => $role) {
    if ($role !== 'cashier') {
        $firstNonCashier = $i;
        break;
    }
}
$h->test(
    'every cashier is offered before the other roles',
    $firstNonCashier !== null ? $firstNonCashier === $cashierCount : $cashierCount === count($roles),
    'cashiers=' . $cashierCount . ' order=' . json_encode($roles)
);
$h->test('cashiers are ordered by name', ($liableById[$amCashier]['name'] ?? '') === 'aaa-am-cashier');
$h->test('the label carries the role and shift', str_contains((string)($liableById[$amCashier]['label'] ?? ''), '(cashier · AM)'), (string)($liableById[$amCashier]['label'] ?? ''));
$h->test('a role without a shift has no shift in the label', !str_contains((string)($liableById[$globalSupervisor]['label'] ?? ''), '·'), (string)($liableById[$globalSupervisor]['label'] ?? ''));
$h->test('the shift is exposed as a field', ($liableById[$pmCashier]['shift'] ?? null) === 'PM');

$modalForList = (string)file_get_contents($base . '/templates/modules/daily-ledger/cashier/modal_patch.disyl');
$h->test('the dropdown renders the label', str_contains($modalForList, "lp.label || (lp.name + ' (' + lp.role + ')')"));
$h->test('both liable-person call sites use the shared helper', substr_count((string)file_get_contents($base . '/modules/daily-ledger/handlers.php'), 'dl_liablePersonsForBranch(') === 1 && substr_count((string)file_get_contents($base . '/modules/daily-ledger/handlers-offline.php'), 'dl_liablePersonsForBranch(') === 1);

// ─── Cleanup ───────────────────────────────────────────────────────────
foreach ($chargeUsers as $uid) {
    $db->execute('DELETE FROM dl_user_branches WHERE user_id = :u', [':u' => $uid]);
    $db->execute('DELETE FROM dl_users WHERE id = :u', [':u' => $uid]);
}
$db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_cashier_withdrawals WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_branch_products WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_branches WHERE id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_products WHERE id = :p', [':p' => $productId]);
$db->execute('DELETE FROM dl_user_branches WHERE user_id = :u', [':u' => $liableId]);
$db->execute('DELETE FROM dl_users WHERE id = :u', [':u' => $liableId]);

$left = (int)$db->query("SELECT COUNT(*) FROM dl_cashier_withdrawals WHERE branch_id = {$branchId}")->fetchColumn();
$h->test('cleanup removed the matrix rows', $left === 0);

$h->done();
