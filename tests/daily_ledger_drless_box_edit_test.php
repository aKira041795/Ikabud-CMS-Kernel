<?php

declare(strict_types=1);

/**
 * Daily Ledger — DR-less Production Receive, Box Withdrawals & Cashier Edit
 *
 * Guards the additive features from docs/architecture/
 * daily-ledger-drless-withdrawal-edit-contract.md:
 *
 *   1. Auto-DR minting (AUTO-dd/mm/yyyy-n) for DR-less production-branch
 *      receives (dl_mintAutoDrNumber, apiReceivePaperDelivery auto_dr mode).
 *   2. Box-unit withdrawals: pcs_per_pack on dl_products + unit/pack_qty on
 *      dl_cashier_withdrawals + unit-aware dedup fingerprint.
 *   3. Cashier edit-own-withdrawal endpoint (apiUpdateCashierWithdrawal) with
 *      'withdrawal_updated' audit, today-list endpoint + UI affordances.
 *
 * No HTTP — boots the tenant DB via module context and exercises the new pure
 * helpers + verifies the wiring (routes/templates/schema) statically.
 */

$_SERVER['HTTP_HOST']    = $_SERVER['HTTP_HOST']    ?? 'baronledger.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/daily-ledger/ledger';
$_SERVER['REQUEST_METHOD'] = 'GET';

ini_set('display_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/daily-ledger/helpers.php';
require_once __DIR__ . '/../modules/daily-ledger/handlers.php';

$pass = 0;
$fail = 0;
$errors = [];

function fp(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  ✓  {$label}\n";
    } else {
        $fail++;
        $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
        echo "  ✗  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

@file_put_contents(STORAGE_PATH . '/logs/app.log', '');
@file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n╔══════════════════════════════════════════════════════════╗\n";
echo "║  DAILY LEDGER — DR-LESS RECEIVE + BOX + EDIT TEST       ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

$ctx = modulePushContext('daily-ledger');
if (!$ctx) {
    fwrite(STDERR, "FATAL: daily-ledger module context unavailable\n");
    exit(1);
}
$pdo = $ctx->db();

foreach (['modules/daily-ledger/helpers.php', 'modules/daily-ledger/handlers.php', 'modules/daily-ledger/handlers-offline.php', 'modules/daily-ledger/routes.php', 'modules/daily-ledger/handlers-deliveries.php'] as $f) {
    echo "  fingerprint {$f}\n";
}

// ─── Schema: migration 054 columns present ────────────────────────────────
$cols = static function (string $table) use ($pdo): array {
    return array_column($pdo->query("SHOW COLUMNS FROM {$table}")->fetchAll(PDO::FETCH_ASSOC), 'Field');
};
$productCols  = $cols('dl_products');
$withdrawalCols = $cols('dl_cashier_withdrawals');
fp('migration 054: dl_products.pcs_per_pack', in_array('pcs_per_pack', $productCols, true));
fp('migration 054: dl_cashier_withdrawals.unit', in_array('unit', $withdrawalCols, true));
fp('migration 054: dl_cashier_withdrawals.pack_qty', in_array('pack_qty', $withdrawalCols, true));
fp('migration 055: dl_cashier_withdrawals.shift', in_array('shift', $withdrawalCols, true));

// ─── Issue 2: dedup fingerprint is unit-aware (box vs pcs distinct) ───────
$hPcs10 = dl_withdrawalDedupHash(1, 2, '2026-01-01', 'charge', 'manual_adjustment', null, null, null, 48, null);
$hPcs11 = dl_withdrawalDedupHash(1, 2, '2026-01-01', 'charge', 'manual_adjustment', null, null, null, 48, null, 'pcs');
$hBox   = dl_withdrawalDedupHash(1, 2, '2026-01-01', 'charge', 'manual_adjustment', null, null, null, 48, null, 'box');
fp('dedup: 10-arg call === pcs 11-arg (backward compatible)', $hPcs10 === $hPcs11);
fp('dedup: "48 pcs" != "48 pcs as 2 boxes" (unit in fingerprint)', $hPcs11 !== $hBox);

// ─── Issue 2: box-unit normalization helper ───────────────────────────────
$seedSku = 'TESTPACK' . substr((string)time(), -5);
$packPid = 0;
$noPackPid = 0;
try {
    $pdo->prepare('INSERT INTO dl_products (sku, name, current_price, sort_order, pcs_per_pack) VALUES (:s,:n,10,999,:p)')
        ->execute([':s' => $seedSku, ':n' => 'Box Test Product', ':p' => 24]);
    $packPid = (int)$pdo->lastInsertId();

    $plainSku = $seedSku . 'P';
    $pdo->prepare('INSERT INTO dl_products (sku, name, current_price, sort_order, pcs_per_pack) VALUES (:s,:n,10,999,NULL)')
        ->execute([':s' => $plainSku, ':n' => 'Plain Pcs Product']);
    $noPackPid = (int)$pdo->lastInsertId();

    $r = dl_resolveWithdrawalLineUnit($pdo, $packPid, 2, 'box');
    fp('box 2 × pcs_per_pack 24 → 48 pieces, pack_qty 2', $r['quantity'] === 48 && $r['unit'] === 'box' && $r['pack_qty'] === 2, json_encode($r));

    $r2 = dl_resolveWithdrawalLineUnit($pdo, $noPackPid, 7, 'pcs');
    fp('pcs stays piece-equivalent, pack_qty null', $r2['quantity'] === 7 && $r2['unit'] === 'pcs' && $r2['pack_qty'] === null, json_encode($r2));

    $boxThrown = null;
    try {
        dl_resolveWithdrawalLineUnit($pdo, $noPackPid, 1, 'box');
    } catch (\RuntimeException $e) {
        $boxThrown = $e->getCode();
    }
    fp('box on product without pcs_per_pack → 422', $boxThrown === 422, 'code=' . var_export($boxThrown, true));

    fp('dl_productPcsPerPack returns 24 for pack product', dl_productPcsPerPack($pdo, $packPid) === 24);
    fp('dl_productPcsPerPack returns null for plain product', dl_productPcsPerPack($pdo, $noPackPid) === null);
} finally {
    if ($packPid > 0) {
        $pdo->prepare('DELETE FROM dl_products WHERE id = :id')->execute([':id' => $packPid]);
    }
    if ($noPackPid > 0) {
        $pdo->prepare('DELETE FROM dl_products WHERE id = :id')->execute([':id' => $noPackPid]);
    }
}

// ─── Issue 1: auto-DR mint + production-site detection ────────────────────
$someBranch = $pdo->query('SELECT id FROM dl_branches WHERE is_active = 1 ORDER BY id LIMIT 1')->fetch(PDO::FETCH_COLUMN);
if ($someBranch) {
    $autoDr = dl_mintAutoDrNumber($pdo, (int)$someBranch, dl_businessDate());
    fp('auto-DR label format AUTO-dd/mm/yyyy-n', (bool)preg_match('/^AUTO-\d{2}\/\d{2}\/\d{4}-\d+$/', $autoDr), $autoDr);

    // Sequence advances from PERSISTED AUTO-… deliveries (the real flow inserts
    // the posted delivery in the same transaction, so the next mint is n+1).
    $scratchDeliveryId = null;
    try {
        $pdo->prepare(
            'INSERT INTO dl_deliveries (origin_type, origin_id, destination_type, destination_id, dr_number, delivery_date, status, remarks)
             VALUES ("commissary", NULL, "branch", :bid, :dr, :d, "posted", "[test-scratch]")'
        )->execute([':bid' => (int)$someBranch, ':dr' => $autoDr, ':d' => dl_businessDate()]);
        $scratchDeliveryId = (int)$pdo->lastInsertId();

        $autoDr2 = dl_mintAutoDrNumber($pdo, (int)$someBranch, dl_businessDate());
        fp('auto-DR sequence increments after a persisted delivery (n+1)', $autoDr2 !== $autoDr, "{$autoDr} vs {$autoDr2}");
    } finally {
        if ($scratchDeliveryId !== null) {
            $pdo->prepare('DELETE FROM dl_deliveries WHERE id = :id')->execute([':id' => $scratchDeliveryId]);
        }
    }
} else {
    fp('no branch seeded — auto-DR mint not exercised', true, 'gap: branch row');
}

$prodBranch = $pdo->query('SELECT id FROM dl_branches WHERE is_commissary = 1 AND is_active = 1 ORDER BY id LIMIT 1')->fetch(PDO::FETCH_COLUMN);
$plainBranch = $pdo->query('SELECT id FROM dl_branches WHERE is_commissary = 0 AND is_active = 1 ORDER BY id LIMIT 1')->fetch(PDO::FETCH_COLUMN);
if ($prodBranch) {
    fp('production-site detection true for commissary branch', dl_branchIsProductionSite($pdo, (int)$prodBranch) === true);
} else {
    fp('no commissary branch seeded — production-site detection not exercised', true, 'gap: commissary branch');
}
if ($plainBranch) {
    fp('production-site detection false for retail branch', dl_branchIsProductionSite($pdo, (int)$plainBranch) === false);
}

// ─── Wiring: routes + audit + UI affordances present ──────────────────────
$routes = (string)file_get_contents(__DIR__ . '/../modules/daily-ledger/routes.php');
fp('route GET withdrawals/today', strpos($routes, "'/daily-ledger/api/v1/cashier/ledger/withdrawals/today' => 'daily-ledger:apiTodayCashierWithdrawals'") !== false);
fp('route POST withdrawals/edit', strpos($routes, "'/daily-ledger/api/v1/cashier/ledger/withdrawals/edit' => 'daily-ledger:apiUpdateCashierWithdrawal'") !== false);

$handlers = (string)file_get_contents(__DIR__ . '/../modules/daily-ledger/handlers.php');
fp('handler apiUpdateCashierWithdrawal defined', strpos($handlers, 'function apiUpdateCashierWithdrawal(') !== false);
fp('audit event withdrawal_updated used', strpos($handlers, "'withdrawal_updated'") !== false);
fp('auto-DR source audit marker auto_dr_production', strpos($handlers, "'auto_dr_production'") !== false);
fp('auto-DR remarks marker [auto-dr-production]', strpos($handlers, "'[auto-dr-production]'") !== false);

$helpers = (string)file_get_contents(__DIR__ . '/../modules/daily-ledger/helpers.php');
fp('helpers: dl_mintAutoDrNumber defined', strpos($helpers, 'function dl_mintAutoDrNumber(') !== false);
fp('helpers: dl_resolveWithdrawalLineUnit defined', strpos($helpers, 'function dl_resolveWithdrawalLineUnit(') !== false);
fp('helpers: dl_branchIsProductionSite defined', strpos($helpers, 'function dl_branchIsProductionSite(') !== false);

$offline = (string)file_get_contents(__DIR__ . '/../modules/daily-ledger/handlers-offline.php');
fp('offline replay persists unit + pack_qty', strpos($offline, 'pack_qty') !== false && (strpos($offline, 'dl_resolveWithdrawalLineForType') !== false || strpos($offline, 'dl_resolveWithdrawalLineUnit') !== false));
fp('offline replay resolves via type-aware line resolver', strpos($offline, 'dl_resolveWithdrawalLineForType') !== false);

$modalTpl = (string)file_get_contents(__DIR__ . '/../templates/modules/daily-ledger/cashier/modal_patch.disyl');
fp('withdrawal modal: unit (Pcs/Box) select', strpos($modalTpl, 'packFor(line.product_id)') !== false && strpos($modalTpl, '<option value="box">Box</option>') !== false);
fp('withdrawal modal: edit mode routes to /withdrawals/edit', strpos($modalTpl, "withdrawals/edit'") !== false);

$receiveTpl = (string)file_get_contents(__DIR__ . '/../templates/modules/daily-ledger/cashier/receive_modal.disyl');
fp('receive modal: auto-DR checkbox', strpos($receiveTpl, 'paperForm.auto_dr') !== false && strpos($receiveTpl, 'No paper DR') !== false);
fp('receive modal: auto-DR online-only guard', strpos($receiveTpl, 'autoDrBlockedOffline') !== false);

$rowsTpl = (string)file_get_contents(__DIR__ . '/../templates/modules/daily-ledger/cashier/partials/ledger-rows.disyl');
fp('ledger rows expose data-pack per product', strpos($rowsTpl, 'data-pack=') !== false);

$ledgerTpl = (string)file_get_contents(__DIR__ . '/../templates/modules/daily-ledger/cashier/ledger.disyl');
fp('ledger page: Today’s Adjustments list', strpos($ledgerTpl, 'today-adjustments') !== false && strpos($ledgerTpl, 'dlRefreshTodayAdjustments') !== false);

$prodAdminTpl = (string)file_get_contents(__DIR__ . '/../templates/modules/daily-ledger/admin/products.disyl');
fp('products admin exposes pcs-per-pack field', strpos($prodAdminTpl, 'pcs-per-pack') !== false);

$migrationFile = file_exists(__DIR__ . '/../modules/daily-ledger/database/migrations/054_box_unit_withdrawals.sql');
$migrationFileShift = file_exists(__DIR__ . '/../modules/daily-ledger/database/migrations/055_cashier_withdrawal_shift.sql');
$moduleJson = (string)file_get_contents(__DIR__ . '/../modules/daily-ledger/module.json');
fp('migration 054 file exists + registered', $migrationFile && strpos($moduleJson, '054_box_unit_withdrawals.sql') !== false);
fp('migration 055 file exists + registered', $migrationFileShift && strpos($moduleJson, '055_cashier_withdrawal_shift.sql') !== false);

// ─── Error log sanity ───────────────────────────────────────────────────────
$errorLog = (string)@file_get_contents(STORAGE_PATH . '/logs/error.log');
fp('no PHP errors in error.log', trim($errorLog) === '', $errorLog !== '' ? substr(trim($errorLog), 0, 200) : '');

echo "\n── Result ──\n";
echo "  PASS: {$pass}   FAIL: {$fail}   TOTAL: " . ($pass + $fail) . "\n\n";
if ($errors) {
    foreach ($errors as $e) {
        echo "  ✗  {$e}\n";
    }
    exit(1);
}
echo "  OK\n";
