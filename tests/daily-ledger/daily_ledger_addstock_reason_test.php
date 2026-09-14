<?php

declare(strict_types=1);

/**
 * Daily Ledger — Add Stock without a false liable person
 *
 * An Add Stock (adjustment_add) entry normally resolves a shortage, so it must
 * name who is charged. That is wrong for the case that actually happens most:
 * the cashier simply forgot to record stock that was never lost, so the admin
 * adds it to the Addt'l column and nobody is chargeable.
 *
 * That case is the `encoder_omission` reason code, and this suite proves:
 *   - the reason vocabulary and the ENUM accept it
 *   - Add Stock + encoder_omission applies with liable_user_id NULL and credits
 *     Addt'l only (never W/Draw)
 *   - every other Add Stock reason still demands a charge-to, server-side
 *   - the create, edit and offline-replay paths share the same rule
 *   - the cashier UI presets the reason and drops the charge-to requirement
 *
 * Integration mode — real tenant DB (207). Seeds an isolated branch/product in
 * the reserved 99xxx range and cleans up every row it creates.
 */

ob_start();

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('daily-ledger-addstock-reason', TestHarness::MODE_INTEGRATION, 'localhost');
ob_end_clean();

$h->fingerprint('modules/daily-ledger/helpers.php');
$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/handlers-offline.php');
$h->fingerprint('modules/daily-ledger/module.json');
$h->fingerprint('modules/daily-ledger/database/migrations/058_encoder_omission_reason.sql');
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

// ─── Seeds (reserved 99xxx test range) ─────────────────────────────────
$branchId = 99052;
$productId = 99052;
$liableUserId = 999997;
$testDate = '2030-02-12';

function dl_ar_cleanup($db, int $branchId, int $productId, int $userId): void
{
    $db->execute('DELETE FROM dl_daily_ledger WHERE branch_id = :b', [':b' => $branchId]);
    $db->execute('DELETE FROM dl_cashier_withdrawals WHERE branch_id = :b', [':b' => $branchId]);
    $db->execute('DELETE FROM dl_ledger_day_status WHERE branch_id = :b', [':b' => $branchId]);
    $db->execute('DELETE FROM dl_ledger_shift_status WHERE branch_id = :b', [':b' => $branchId]);
    $db->execute('DELETE FROM dl_branch_products WHERE branch_id = :b', [':b' => $branchId]);
    $db->execute('DELETE FROM dl_branches WHERE id = :b', [':b' => $branchId]);
    $db->execute('DELETE FROM dl_products WHERE id = :p', [':p' => $productId]);
    $db->execute('DELETE FROM dl_user_branches WHERE user_id = :u', [':u' => $userId]);
    $db->execute('DELETE FROM dl_users WHERE id = :u', [':u' => $userId]);
}

/** Seed a dl_users row with only the NOT NULL columns that lack defaults. */
function dl_ar_seedUser($db, int $id, string $username, string $role): void
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
}

dl_ar_cleanup($db, $branchId, $productId, $liableUserId);

$db->execute(
    'INSERT INTO dl_branches (id, code, name, address, default_supply_mode, is_commissary, is_active)
     VALUES (:id, :code, :name, :addr, :mode, 0, 1)',
    [':id' => $branchId, ':code' => 'T-OMIT', ':name' => 'Omission Test Branch', ':addr' => 'Test', ':mode' => 'self_managed']
);
$db->execute(
    'INSERT INTO dl_products (id, sku, name, current_price, sort_order, is_active)
     VALUES (:id, :sku, :name, :price, 0, 1)',
    [':id' => $productId, ':sku' => 'OMIT-TEST', ':name' => 'Omission Test Product', ':price' => 25.0]
);
$db->execute(
    'INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (:b, :p, 1)',
    [':b' => $branchId, ':p' => $productId]
);
dl_ar_seedUser($db, $liableUserId, 'omission-test-liable', 'cashier');

/** Synthetic admin actor (branch resolution via DB, no HTTP token needed). */
$adminUser = [
    'id' => 999999,
    'sub' => 'admin:999999',
    'role' => 'admin',
    'source' => 'daily-ledger',
    'username' => 'omission-test-admin',
    'name' => 'Omission Test Admin',
];

/** Apply one withdrawal through the offline worker (same rule as the HTTP path). */
function dl_ar_apply($user, int $branchId, string $date, array $header, int $qty): array
{
    return dl_offlineApplyWithdrawal($user, [
        'type' => 'withdrawal',
        'payload' => [
            'branch_id' => $branchId,
            'date' => $date,
            'shift' => 'AM',
            'header' => $header,
            'lines' => [['product_id' => 99052, 'quantity' => $qty]],
        ],
    ]);
}

/** @return array{addtl:int, withdraw:int, rows:int} */
function dl_ar_ledger($db, int $branchId, int $productId, string $date): array
{
    $stmt = $db->prepare(
        'SELECT addtl, withdraw FROM dl_daily_ledger
          WHERE branch_id = :b AND product_id = :p AND ledger_date = :d AND shift = \'AM\''
    );
    $stmt->execute([':b' => $branchId, ':p' => $productId, ':d' => $date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $count = $db->prepare('SELECT COUNT(*) FROM dl_cashier_withdrawals WHERE branch_id = :b AND product_id = :p AND ledger_date = :d');
    $count->execute([':b' => $branchId, ':p' => $productId, ':d' => $date]);
    return [
        'addtl' => (int)($row['addtl'] ?? 0),
        'withdraw' => (int)($row['withdraw'] ?? 0),
        'rows' => (int)$count->fetchColumn(),
    ];
}

// ─── Reason vocabulary ─────────────────────────────────────────────────
$h->section('Reason vocabulary');

$reasons = dl_allowedWithdrawalReasons();
$h->test('encoder_omission is an allowed reason', in_array('encoder_omission', $reasons, true));
foreach (['spoilage', 'staff_meal', 'sampling', 'testing', 'promo', 'donation', 'damage', 'manual_adjustment', 'other'] as $legacy) {
    $h->test("legacy reason '{$legacy}' still allowed", in_array($legacy, $reasons, true));
}
$h->test('encoder_omission does not need a liable person', dl_adjustmentAddNeedsLiable('encoder_omission') === false);
$h->test('manual_adjustment still needs a liable person', dl_adjustmentAddNeedsLiable('manual_adjustment') === true);
$h->test('spoilage still needs a liable person', dl_adjustmentAddNeedsLiable('spoilage') === true);
$h->test('a missing reason still needs a liable person', dl_adjustmentAddNeedsLiable(null) === true);

// ─── Schema accepts the value (ENUM, not free text) ────────────────────
$h->section('Schema');

// The kernel sandbox forbids information_schema to module AND tenant
// connections while module context is active, so the migration is asserted by
// content here — the round-trip in the next section proves the live ENUM
// actually stores the value instead of truncating it.
$migrationSrc = (string)file_get_contents($base . '/modules/daily-ledger/database/migrations/058_encoder_omission_reason.sql');
$h->test('migration adds encoder_omission to the ENUM', str_contains($migrationSrc, "'encoder_omission'") && stripos($migrationSrc, 'MODIFY COLUMN reason_code') !== false);
$h->test('migration keeps the legacy ENUM values', str_contains($migrationSrc, "'manual_adjustment'") && str_contains($migrationSrc, "'spoilage'"));

$manifest = json_decode((string)file_get_contents($base . '/modules/daily-ledger/module.json'), true);
$h->test(
    'migration 058 is registered in module.json',
    in_array('database/migrations/058_encoder_omission_reason.sql', $manifest['migrations'] ?? [], true)
);

// ─── Add Stock as an encoder omission: nobody is charged ───────────────
$h->section('Add Stock — encoder omission (nothing lost)');

$omitResult = null;
$omitError = '';
try {
    $omitResult = dl_ar_apply($adminUser, $branchId, $testDate, [
        'withdrawal_type' => 'adjustment_add',
        'reason_code' => 'encoder_omission',
    ], 1);
} catch (Throwable $e) {
    $omitError = $e->getMessage();
}
$h->test('Add Stock with encoder omission is accepted', is_array($omitResult) && !empty($omitResult['ok']), $omitError);

$rowStmt = $db->prepare(
    'SELECT withdrawal_type, reason_code, quantity, liable_user_id FROM dl_cashier_withdrawals
      WHERE branch_id = :b AND product_id = :p AND ledger_date = :d ORDER BY id DESC LIMIT 1'
);
$rowStmt->execute([':b' => $branchId, ':p' => $productId, ':d' => $testDate]);
$omitRow = $rowStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$h->test('stored reason_code round-trips as encoder_omission', ($omitRow['reason_code'] ?? '') === 'encoder_omission', (string)($omitRow['reason_code'] ?? ''));
$h->test('no liable person is recorded', ($omitRow['liable_user_id'] ?? null) === null);

$afterOmit = dl_ar_ledger($db, $branchId, $productId, $testDate);
$h->test('Addt\'l is credited', $afterOmit['addtl'] === 1, 'addtl=' . $afterOmit['addtl']);
$h->test('W/Draw is not touched', $afterOmit['withdraw'] === 0, 'withdraw=' . $afterOmit['withdraw']);

// ─── A variance reason still demands a charge-to ───────────────────────
$h->section('Add Stock — variance resolution still requires a charge-to');

$varError = null;
$varRejected = false;
try {
    dl_ar_apply($adminUser, $branchId, $testDate, [
        'withdrawal_type' => 'adjustment_add',
        'reason_code' => 'manual_adjustment',
    ], 1);
} catch (Throwable $e) {
    $varRejected = true;
    $varError = $e;
}
$h->test('Add Stock without a liable person is rejected', $varRejected);
$h->test('rejection is a 422 validation error', ($varError instanceof RuntimeException) && $varError->getCode() === 422, $varError ? $varError->getMessage() : '');

$afterReject = dl_ar_ledger($db, $branchId, $productId, $testDate);
$h->test('rejected Add Stock wrote nothing', $afterReject['addtl'] === 1 && $afterReject['rows'] === 1, 'addtl=' . $afterReject['addtl'] . ' rows=' . $afterReject['rows']);

// ─── A variance Add Stock with a charge-to still works ─────────────────
$h->section('Add Stock — variance resolution with a charge-to');

$liableResult = null;
$liableError = '';
try {
    $liableResult = dl_ar_apply($adminUser, $branchId, $testDate, [
        'withdrawal_type' => 'adjustment_add',
        'reason_code' => 'manual_adjustment',
        'liable_user_id' => $liableUserId,
    ], 1);
} catch (Throwable $e) {
    $liableError = $e->getMessage();
}
$h->test('Add Stock with a liable person is accepted', is_array($liableResult) && !empty($liableResult['ok']), $liableError);

$rowStmt->execute([':b' => $branchId, ':p' => $productId, ':d' => $testDate]);
$liableRow = $rowStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$h->test('the liable person is recorded', (int)($liableRow['liable_user_id'] ?? 0) === $liableUserId, (string)($liableRow['liable_user_id'] ?? 'null'));

$afterLiable = dl_ar_ledger($db, $branchId, $productId, $testDate);
$h->test('Addt\'l accumulates to 2', $afterLiable['addtl'] === 2, 'addtl=' . $afterLiable['addtl']);
$h->test('W/Draw is still not touched', $afterLiable['withdraw'] === 0, 'withdraw=' . $afterLiable['withdraw']);

// ─── Every server path shares the rule ─────────────────────────────────
$h->section('Server paths stay in sync');

$handlersSrc = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
$offlineSrc = (string)file_get_contents($base . '/modules/daily-ledger/handlers-offline.php');

$h->test(
    'create + edit paths both consult dl_adjustmentAddNeedsLiable',
    substr_count($handlersSrc, 'dl_adjustmentAddNeedsLiable') === 2,
    'occurrences=' . substr_count($handlersSrc, 'dl_adjustmentAddNeedsLiable')
);
$h->test(
    'offline replay consults dl_adjustmentAddNeedsLiable',
    substr_count($offlineSrc, 'dl_adjustmentAddNeedsLiable') === 1,
    'occurrences=' . substr_count($offlineSrc, 'dl_adjustmentAddNeedsLiable')
);
$h->test(
    'create + edit paths use the shared reason vocabulary',
    substr_count($handlersSrc, 'dl_allowedWithdrawalReasons()') === 2,
    'occurrences=' . substr_count($handlersSrc, 'dl_allowedWithdrawalReasons()')
);
$h->test(
    'offline replay uses the shared reason vocabulary',
    substr_count($offlineSrc, 'dl_allowedWithdrawalReasons()') === 1,
    'occurrences=' . substr_count($offlineSrc, 'dl_allowedWithdrawalReasons()')
);
$h->test(
    'the rejection message points at the encoder-omission reason',
    str_contains($handlersSrc, 'Choose the Encoder omission reason if nothing was lost')
);

// ─── UI ────────────────────────────────────────────────────────────────
$h->section('Cashier UI');

$modalSrc = (string)file_get_contents($base . '/templates/modules/daily-ledger/cashier/modal_patch.disyl');
$ledgerSrc = (string)file_get_contents($base . '/templates/modules/daily-ledger/cashier/ledger.disyl');
$rowsSrc = (string)file_get_contents($base . '/templates/modules/daily-ledger/cashier/partials/ledger-rows.disyl');

$h->test('the reason dropdown offers encoder_omission', str_contains($modalSrc, '<option value="encoder_omission">'));
$h->test('the modal defines needsLiable()', str_contains($modalSrc, 'needsLiable() {'));
$h->test('validation uses needsLiable()', str_contains($modalSrc, 'if (this.needsLiable() &&'));
$h->test('the charge-to field is hidden when nobody is liable', str_contains($modalSrc, '<template x-if="!needsLiable()">'));
$h->test('the modal honours a preset reason', str_contains($modalSrc, "reason_code: detail.reason_code || 'manual_adjustment'"));
$h->test('the row "+" presets the encoder-omission reason', str_contains($ledgerSrc, "reason_code: 'encoder_omission'"));
$h->test('the row "+" still routes to the DR-free Add Stock flow', str_contains($rowsSrc, 'onclick="dlAddStockDirect({row.product_id})"'));

// ─── Cleanup ───────────────────────────────────────────────────────────
dl_ar_cleanup($db, $branchId, $productId, $liableUserId);

$left = dl_ar_ledger($db, $branchId, $productId, $testDate);
$h->test('cleanup removed the test rows', $left['rows'] === 0 && $left['addtl'] === 0);

$h->done();
