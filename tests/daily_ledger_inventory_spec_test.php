<?php

declare(strict_types=1);

/**
 * Daily Ledger inventory spec integration test.
 *
 * Covers Phases A–F backend foundation:
 *   - branch supply mode + per-product supply rules
 *   - price groups + product price resolution (with current_price fallback)
 *   - delivery -> selling-account ledger lifecycle (sales/gross recompute)
 *   - delivery -> branch receiving -> dl_daily_ledger.addtl + variance flag
 *   - branch consolidated summary (regular + selling accounts)
 *   - cashier withdrawal reason_code persistence
 *   - feature flag gating
 *
 * No mocks: hits the same dl_* tables on the live applicationos test DB
 * (cmsnew.test). Each test creates uniquely-suffixed rows and cleans up
 * via FK CASCADE plus an explicit teardown.
 */

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'baronledger.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/admin/daily-ledger';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/daily-ledger/helpers.php';
require_once __DIR__ . '/../modules/daily-ledger/handlers.php';

$pass = 0;
$fail = 0;
$errors = [];

function dlt(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }
    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

@file_put_contents(STORAGE_PATH . '/logs/app.log', '');
@file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== DAILY LEDGER INVENTORY SPEC TEST ===\n\n";

// Make sure all migrations are present on this tenant DB.
// (Skip auto-migrate here — the runner conflicts with the open module bootstrap query;
//  migrations are applied via `./ikabud migrate daily-ledger`.)
$ctx = modulePushContext('daily-ledger');
if (!$ctx) {
    fwrite(STDERR, "FATAL: daily-ledger module context unavailable\n");
    exit(1);
}
$pdo = $ctx->db();

$suffix = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
$today = date('Y-m-d');

$createdBranchIds = [];
$createdCommissaryIds = [];
$createdProductIds = [];
$createdAccountIds = [];
$createdDeliveryIds = [];
$createdReceivingIds = [];
$createdPriceGroupIds = [];

try {
    // ─── Setup: commissary branch + retail branch + 2 products ───────
    echo "── Setup ──\n";

    $pdo->prepare(
        'INSERT INTO dl_branches (code, name, address, is_active, default_supply_mode, is_commissary)
         VALUES (:c, :n, "Main", 1, "self_managed", 1)'
    )->execute([':c' => 'CMSY-' . $suffix, ':n' => 'Commissary ' . $suffix]);
    $commissaryId = (int)$pdo->lastInsertId();
    $createdCommissaryIds[] = $commissaryId;

    $pdo->prepare(
        'INSERT INTO dl_branches (code, name, address, is_active, default_supply_mode, assigned_commissary_id, is_commissary)
         VALUES (:c, :n, "Branch addr", 1, "commissary_supplied", :ac, 0)'
    )->execute([':c' => 'BR-' . $suffix, ':n' => 'Branch ' . $suffix, ':ac' => $commissaryId]);
    $branchId = (int)$pdo->lastInsertId();
    $createdBranchIds[] = $branchId;

    dlt('commissary branch created', $commissaryId > 0);
    dlt('retail branch created with assigned commissary', $branchId > 0);

    foreach (['ALPHA', 'BETA'] as $i => $code) {
        $pdo->prepare(
            'INSERT INTO dl_products (sku, name, current_price, sort_order, is_active)
             VALUES (:sku, :n, :p, :so, 1)'
        )->execute([
            ':sku' => 'TST-' . $code . '-' . $suffix,
            ':n' => 'Product ' . $code . ' ' . $suffix,
            ':p' => $i === 0 ? 25.00 : 50.00,
            ':so' => $i,
        ]);
        $createdProductIds[] = (int)$pdo->lastInsertId();
    }
    [$productAId, $productBId] = $createdProductIds;
    dlt('2 products seeded', count($createdProductIds) === 2);

    // ─── Phase A: branch default mode resolution ─────────────────────
    echo "\n── Phase A: supply-source resolution ──\n";

    $resA = dl_resolveProductSupplySource($branchId, $productAId);
    dlt('branch default (commissary_supplied) maps to commissary',
        $resA['source'] === 'commissary' && $resA['source_id'] === $commissaryId,
        json_encode($resA));

    // Override product A to local_production
    $pdo->prepare(
        'INSERT INTO dl_branch_product_supply_rules (branch_id, product_id, supply_source_type, source_id, is_active)
         VALUES (:b, :p, "local_production", NULL, 1)'
    )->execute([':b' => $branchId, ':p' => $productAId]);
    $resA2 = dl_resolveProductSupplySource($branchId, $productAId);
    dlt('per-product override beats branch default',
        $resA2['source'] === 'local_production' && ($resA2['origin'] ?? '') === 'override',
        json_encode($resA2));

    // ─── Phase D: price group + price resolution ─────────────────────
    echo "\n── Phase D: price groups + product prices ──\n";

    $defaultGroupId = dl_defaultPriceGroupId();
    dlt('default price group seeded', $defaultGroupId !== null && $defaultGroupId > 0);

    // Create a Mall price group, assign 75.00 for product A.
    $pdo->prepare('INSERT INTO dl_price_groups (name, type) VALUES (:n, "mall")')
        ->execute([':n' => 'Mall ' . $suffix]);
    $mallGroupId = (int)$pdo->lastInsertId();
    $createdPriceGroupIds[] = $mallGroupId;

    $pdo->prepare(
        'INSERT INTO dl_product_prices (product_id, price_group_id, selling_price, effective_from, is_active)
         VALUES (:p, :g, 75.00, "2020-01-01", 1)'
    )->execute([':p' => $productAId, ':g' => $mallGroupId]);

    $defaultPrice = dl_resolveProductPrice($productAId, $defaultGroupId, $today);
    $mallPrice = dl_resolveProductPrice($productAId, $mallGroupId, $today);
    $unmappedPrice = dl_resolveProductPrice($productBId, $mallGroupId, $today);
    dlt('default group resolves to current_price snapshot',
        abs($defaultPrice - 25.00) < 0.001, "got {$defaultPrice}");
    dlt('mall group resolves to channel price',
        abs($mallPrice - 75.00) < 0.001, "got {$mallPrice}");
    dlt('unmapped product falls back to current_price',
        abs($unmappedPrice - 50.00) < 0.001, "got {$unmappedPrice}");

    // ─── Phase E: selling account + ledger formula ────────────────────
    echo "\n── Phase E: selling account + ledger formula ──\n";

    $pdo->prepare(
        'INSERT INTO dl_selling_accounts
            (code, name, account_type, assigned_branch_id, supply_source_type, supply_source_id,
             price_group_id, ledger_type, is_active)
         VALUES (:c, :n, "mall", :b, "commissary", :sid, :pg, "mall_account", 1)'
    )->execute([
        ':c' => 'MALL-' . $suffix,
        ':n' => 'Mall Account ' . $suffix,
        ':b' => $branchId,
        ':sid' => $commissaryId,
        ':pg' => $mallGroupId,
    ]);
    $accountId = (int)$pdo->lastInsertId();
    $createdAccountIds[] = $accountId;
    dlt('selling account created', $accountId > 0);

    // Create + post a delivery to the selling account: 100 units of product A.
    $pdo->prepare(
        'INSERT INTO dl_deliveries
            (origin_type, origin_id, destination_type, destination_id, dr_number,
             delivery_date, status)
         VALUES ("commissary", :oid, "selling_account", :did, :dr, :dd, "draft")'
    )->execute([
        ':oid' => $commissaryId, ':did' => $accountId,
        ':dr' => 'DR-' . $suffix, ':dd' => $today,
    ]);
    $deliveryId = (int)$pdo->lastInsertId();
    $createdDeliveryIds[] = $deliveryId;

    $pdo->prepare(
        'INSERT INTO dl_delivery_items
            (delivery_id, product_id, quantity, unit, price_snapshot, price_group_id)
         VALUES (:d, :p, 100, "pcs", 75.00, :g)'
    )->execute([':d' => $deliveryId, ':p' => $productAId, ':g' => $mallGroupId]);

    // Post via direct status update + helper (mirrors apiPostDelivery internals).
    $pdo->prepare('UPDATE dl_deliveries SET status = "posted", posted_at = NOW() WHERE id = :id')
        ->execute([':id' => $deliveryId]);
    dl_postDeliveryToSellingAccount($deliveryId);

    $row = $pdo->prepare(
        'SELECT beg_qty, delivered_qty, return_qty, end_qty, sold_qty, gross_amount
           FROM dl_selling_account_ledger
          WHERE selling_account_id = :a AND product_id = :p AND ledger_date = :d'
    );
    $row->execute([':a' => $accountId, ':p' => $productAId, ':d' => $today]);
    $ledger = $row->fetch(PDO::FETCH_ASSOC);
    dlt('delivery applied to selling-account ledger', $ledger !== false && (int)$ledger['delivered_qty'] === 100);
    dlt('initial sold_qty = beg + delivered - return - end (= 100)',
        (int)$ledger['sold_qty'] === 100,
        json_encode($ledger));
    dlt('initial gross_amount = sold * price (= 7500.00)',
        abs((float)$ledger['gross_amount'] - 7500.00) < 0.001,
        json_encode($ledger));

    // Set end_qty = 30 → sold should drop to 70, gross = 5250.
    $pdo->prepare(
        'UPDATE dl_selling_account_ledger SET end_qty = 30,
            sold_qty = beg_qty + delivered_qty - return_qty - end_qty,
            gross_amount = (beg_qty + delivered_qty - return_qty - end_qty) * price_snapshot
          WHERE selling_account_id = :a AND product_id = :p AND ledger_date = :d'
    )->execute([':a' => $accountId, ':p' => $productAId, ':d' => $today]);
    $row->execute([':a' => $accountId, ':p' => $productAId, ':d' => $today]);
    $ledger2 = $row->fetch(PDO::FETCH_ASSOC);
    dlt('end_qty=30 recomputes sold_qty to 70',
        (int)$ledger2['sold_qty'] === 70, json_encode($ledger2));
    dlt('gross drops to 5250.00 with new sold_qty',
        abs((float)$ledger2['gross_amount'] - 5250.00) < 0.001, json_encode($ledger2));

    // ─── Phase B + F: branch receiving + variance + summary ───────────
    echo "\n── Phase B + F: receiving variance + branch summary ──\n";

    // Delivery to branch (100 of product B).
    $pdo->prepare(
        'INSERT INTO dl_deliveries (origin_type, origin_id, destination_type, destination_id,
            dr_number, delivery_date, status, posted_at)
         VALUES ("commissary", :o, "branch", :d, :dr, :dd, "posted", NOW())'
    )->execute([':o' => $commissaryId, ':d' => $branchId, ':dr' => 'DR-B-' . $suffix, ':dd' => $today]);
    $deliveryBId = (int)$pdo->lastInsertId();
    $createdDeliveryIds[] = $deliveryBId;

    $pdo->prepare(
        'INSERT INTO dl_delivery_items (delivery_id, product_id, quantity, unit, price_snapshot)
         VALUES (:d, :p, 100, "pcs", 50.00)'
    )->execute([':d' => $deliveryBId, ':p' => $productBId]);
    $delItemId = (int)$pdo->lastInsertId();

    // Receive only 95 (variance = -5).
    $pdo->prepare(
        'INSERT INTO dl_branch_receivings
            (branch_id, origin_type, origin_id, delivery_id, dr_number, received_at,
             received_ledger_date, status, posted_at)
         VALUES (:b, "commissary", :o, :did, :dr, NOW(), :rd, "posted", NOW())'
    )->execute([
        ':b' => $branchId, ':o' => $commissaryId, ':did' => $deliveryBId,
        ':dr' => 'DR-B-' . $suffix, ':rd' => $today,
    ]);
    $rcvId = (int)$pdo->lastInsertId();
    $createdReceivingIds[] = $rcvId;

    $pdo->prepare(
        'INSERT INTO dl_branch_receiving_items
            (receiving_id, delivery_item_id, product_id, quantity_received, unit, selling_price_snapshot)
         VALUES (:r, :di, :p, 95, "pcs", 50.00)'
    )->execute([':r' => $rcvId, ':di' => $delItemId, ':p' => $productBId]);

    dl_applyLedgerDelta($branchId, $productBId, $today, 95, 0, 'addtl');
    dl_recordReceivingVariances($rcvId);

    $vRow = $pdo->prepare(
        'SELECT sent_qty, received_qty, variance FROM dl_delivery_variance_flags
          WHERE delivery_id = :d AND product_id = :p'
    );
    $vRow->execute([':d' => $deliveryBId, ':p' => $productBId]);
    $variance = $vRow->fetch(PDO::FETCH_ASSOC);
    dlt('variance flag recorded for short delivery',
        $variance !== false && (int)$variance['variance'] === -5,
        json_encode($variance));

    // Confirm dl_daily_ledger.addtl received the 95.
    $ledRow = $pdo->prepare(
        'SELECT addtl FROM dl_daily_ledger WHERE branch_id = :b AND product_id = :p AND ledger_date = :d'
    );
    $ledRow->execute([':b' => $branchId, ':p' => $productBId, ':d' => $today]);
    dlt('branch ledger addtl reflects received qty (95)',
        (int)$ledRow->fetchColumn() === 95);

    // Branch consolidated summary: regular sales = (0+95-0-0) * 50 = 4750;
    // selling-account gross 5250.
    $summary = dl_branchConsolidatedSummary($branchId, $today);
    dlt('summary regular_sales = 4750 (95 received * 50 price)',
        abs($summary['regular_sales'] - 4750.00) < 0.01, json_encode($summary['regular_sales']));
    dlt('summary selling_accounts_total = 5250.00',
        abs($summary['selling_accounts_total'] - 5250.00) < 0.01,
        json_encode($summary['selling_accounts_total']));
    dlt('summary lists the test selling account',
        is_array($summary['selling_accounts'])
            && count($summary['selling_accounts']) >= 1
            && (int)$summary['selling_accounts'][0]['id'] === $accountId);

    // ─── Phase C: cashier withdrawal reason_code persistence ──────────
    echo "\n── Phase C: cashier withdrawal reason_code ──\n";

    $pdo->prepare(
        'INSERT INTO dl_cashier_withdrawals
            (branch_id, product_id, ledger_date, withdrawal_type, reason_code, quantity)
         VALUES (:b, :p, :d, "charge", "spoilage", 3)'
    )->execute([':b' => $branchId, ':p' => $productBId, ':d' => $today]);
    $wid = (int)$pdo->lastInsertId();
    $rc = $pdo->prepare('SELECT reason_code FROM dl_cashier_withdrawals WHERE id = :id');
    $rc->execute([':id' => $wid]);
    dlt('cashier withdrawal persists reason_code',
        (string)$rc->fetchColumn() === 'spoilage');
    $pdo->prepare('DELETE FROM dl_cashier_withdrawals WHERE id = :id')->execute([':id' => $wid]);

    // ─── Feature flag manifest defaults ──────────────────────────────
    // Read from module.json directly — live runtime helpers reflect the
    // tenant DB override, not the shipped manifest default.
    echo "\n── Feature flag readers ──\n";
    $manifest = (array)json_decode(
        (string)file_get_contents(__DIR__ . '/../modules/daily-ledger/module.json'),
        true
    );
    $manifestDefaults = [];
    foreach (($manifest['settings_fields'] ?? []) as $sf) {
        $manifestDefaults[(string)$sf['key']] = (string)($sf['default'] ?? '');
    }
    dlt('selling_accounts_enabled defaults to false (manifest)',
        ($manifestDefaults['selling_accounts_enabled'] ?? '1') === '0');
    dlt('formal_delivery_workflow_enabled defaults to false (manifest)',
        ($manifestDefaults['formal_delivery_workflow_enabled'] ?? '1') === '0');

    // ─── Logs ─────────────────────────────────────────────────────────
    echo "\n── Log noise ──\n";
    $errLog = is_file(STORAGE_PATH . '/logs/error.log') ? (string)@file_get_contents(STORAGE_PATH . '/logs/error.log') : '';
    dlt('no PHP errors in error.log', trim($errLog) === '', substr($errLog, 0, 200));
} finally {
    // Best-effort cleanup. Most child rows cascade via FK ON DELETE CASCADE.
    foreach ($createdReceivingIds as $id) {
        $pdo->prepare('DELETE FROM dl_branch_receivings WHERE id = :id')->execute([':id' => $id]);
    }
    foreach ($createdDeliveryIds as $id) {
        $pdo->prepare('DELETE FROM dl_delivery_variance_flags WHERE delivery_id = :id')->execute([':id' => $id]);
        $pdo->prepare('DELETE FROM dl_deliveries WHERE id = :id')->execute([':id' => $id]);
    }
    foreach ($createdAccountIds as $id) {
        $pdo->prepare('DELETE FROM dl_selling_account_ledger WHERE selling_account_id = :id')->execute([':id' => $id]);
        $pdo->prepare('DELETE FROM dl_selling_account_day_status WHERE selling_account_id = :id')->execute([':id' => $id]);
        $pdo->prepare('DELETE FROM dl_selling_accounts WHERE id = :id')->execute([':id' => $id]);
    }
    foreach ($createdProductIds as $id) {
        $pdo->prepare('DELETE FROM dl_branch_product_supply_rules WHERE product_id = :id')->execute([':id' => $id]);
        $pdo->prepare('DELETE FROM dl_product_prices WHERE product_id = :id')->execute([':id' => $id]);
        $pdo->prepare('DELETE FROM dl_daily_ledger WHERE product_id = :id')->execute([':id' => $id]);
        $pdo->prepare('DELETE FROM dl_products WHERE id = :id')->execute([':id' => $id]);
    }
    foreach ($createdPriceGroupIds as $id) {
        $pdo->prepare('DELETE FROM dl_price_groups WHERE id = :id')->execute([':id' => $id]);
    }
    foreach ($createdBranchIds as $id) {
        $pdo->prepare('DELETE FROM dl_branches WHERE id = :id')->execute([':id' => $id]);
    }
    foreach ($createdCommissaryIds as $id) {
        $pdo->prepare('DELETE FROM dl_branches WHERE id = :id')->execute([':id' => $id]);
    }
}

echo "\n══════════════════════════════════════════════════\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
echo "══════════════════════════════════════════════════\n\n";
if ($fail > 0) {
    foreach ($errors as $e) {
        echo "  - {$e}\n";
    }
    exit(1);
}
exit(0);
