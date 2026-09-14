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

/** Seed a minimal liable person (NOT NULL columns without defaults get ''). */
$cols = [];
foreach ($db->query('SHOW COLUMNS FROM dl_users') as $c) {
    $cols[(string)$c['Field']] = $c;
}
$insertCols = ['id', 'username', 'password_hash', 'full_name', 'role', 'is_active'];
$bind = [':id' => $liableId, ':username' => 'matrix-liable', ':password_hash' => 'unused', ':full_name' => 'Matrix Liable', ':role' => 'cashier', ':is_active' => 1];
foreach ($cols as $field => $meta) {
    if (in_array($field, $insertCols, true) || in_array($field, ['deleted_at', 'created_at', 'updated_at'], true)) {
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
$h->test('the Charge-to field only renders for Add Stock', str_contains($modalSrc, "x-show=\"header.withdrawal_type === 'adjustment_add'\""));
$h->test('the charge-to field is hidden for an omission', str_contains($modalSrc, '<template x-if="!needsLiable()">'));

// ─── Row shortcuts preset the product ──────────────────────────────────
$h->section('Row shortcuts preset the product');

$ledgerSrc = (string)file_get_contents($base . '/templates/modules/daily-ledger/cashier/ledger.disyl');
$rowsSrc = (string)file_get_contents($base . '/templates/modules/daily-ledger/cashier/partials/ledger-rows.disyl');
$h->test('the W/Draw cell opens the modal on its own product', str_contains($rowsSrc, 'onclick="dlAdjustRowDirect({row.product_id})"'));
$h->test('the W/Draw shortcut passes the product id through', str_contains($ledgerSrc, 'window.dlAdjustRowDirect = function(productId)'));
$h->test('the W/Draw shortcut no longer opens a blank modal', !str_contains($rowsSrc, 'onclick="openWithdrawalModal()"'));
$h->test('the Addt\'l cell still presets its product and reason', str_contains($rowsSrc, 'onclick="dlAddStockDirect({row.product_id})"') && str_contains($ledgerSrc, "reason_code: 'encoder_omission'"));
$h->test('the W/Draw shortcut names the row product in its tooltip', str_contains($rowsSrc, 'title="Record an adjustment for {row.name}'));

// ─── Cleanup ───────────────────────────────────────────────────────────
$db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_cashier_withdrawals WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_branch_products WHERE branch_id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_branches WHERE id = :b', [':b' => $branchId]);
$db->execute('DELETE FROM dl_products WHERE id = :p', [':p' => $productId]);
$db->execute('DELETE FROM dl_users WHERE id = :u', [':u' => $liableId]);

$left = (int)$db->query("SELECT COUNT(*) FROM dl_cashier_withdrawals WHERE branch_id = {$branchId}")->fetchColumn();
$h->test('cleanup removed the matrix rows', $left === 0);

$h->done();
