<?php

declare(strict_types=1);

/**
 * ORACLE — commissary stock depletes on consignee dispatch, from ONE derivation.
 *
 * Contract: .ai/commissary-consignee-depletion.contract.md
 * Owner, 2026-10-08: "consignee movement owned by commissary but dispatch done by
 * cashier" / "commissary stocks are depleted when dispatched to consignee".
 *
 * The defect: the Daily Sheet's total used a branch-only filter and the committed
 * projection `dl_commissary_product_ledger.dispatched_qty` excluded consignee
 * departures, so the commissary still counted dispatched goods as on hand.
 *
 * Cases (each labelled):
 *   A discriminating  a consignee dispatch increases what left the commissary, and
 *                     the commissary balance falls by exactly that quantity
 *   B discriminating  the printed identity BEG + ADDTL - TOTAL - WASTAGE = ACTUAL BAL
 *                     still holds, and calc_variance is corrected through the same input
 *   C discriminating  the projection equals the single derivation after the backfill
 *   D discriminating  Inventory's figure equals the Daily Sheet's AND cannot be the
 *                     branch-only matrix, so a tree with two derivations reddens this
 *   E pin             a day with no consignee dispatch is byte-identical across the backfill
 *   F pin             the live dispatch path depletes the projection exactly once, and the
 *                     backfill then leaves it alone (effect row is the retry boundary)
 *   G pin             running the backfill twice changes nothing the second time
 *   H pin             every fixture row is deleted and settings are untouched
 *
 * Pure helpers and the migration SQL are exercised directly. No handler that calls
 * $ctx->json() is invoked, so nothing can silently EXIT the process and false-pass.
 * Rendering is NOT asserted: storage/cache/compiled is www-user-owned.
 *
 * Fixture ids are private (9979x) and every row is removed in the cleanup.
 */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-commissary-depletion', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';
$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/database/migrations/086_backfill_commissary_consignee_depletion.sql');
$h->allowLogLines('kernel_state_cache: module_registry rebuilt', 'kernel_state_cache: capability_map rebuilt', 'capability.call', 'disyl.compile.phases');

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
// ModuleDB for the typed daily-ledger helpers; a raw KernelPDO (same database) for
// migration 086's DDL/data statements, which ModuleDB access rules forbid.
$db = $ctx->db();
$pdo = app()->dbForTenant(207);

$commissary = 99791;
$branch = 99792;
$consignee = 99793;
$product = 99794;
$quietProduct = 99795;
$liveProduct = 99796;
$date = '2098-05-01';
$quietDate = '2098-05-02';
$liveDate = '2098-05-03';
$drPrefix = 'DEPLORACLE-';
$migrationSql = $base . '/modules/daily-ledger/database/migrations/086_backfill_commissary_consignee_depletion.sql';

$cleanup = static function () use ($db, $commissary, $branch, $consignee, $product, $quietProduct, $liveProduct, $drPrefix): void {
    try {
        $db->prepare('DELETE FROM dl_commissary_depletion_backfill WHERE commissary_branch_id = ? OR product_id IN (?, ?, ?)')
            ->execute([$commissary, $product, $quietProduct, $liveProduct]);
    } catch (\Throwable $ignored) {
        // The marker table is created by migration 086; absent before it runs.
    }
    $deliveryIds = $db->query("SELECT id FROM dl_deliveries WHERE dr_number LIKE '{$drPrefix}%'")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if ($deliveryIds !== []) {
        $idList = implode(',', array_map('intval', $deliveryIds));
        $db->prepare("DELETE FROM dl_delivery_ledger_effects WHERE delivery_id IN ({$idList})")->execute();
        $db->prepare("DELETE FROM dl_consignee_ledger_effects WHERE delivery_id IN ({$idList})")->execute();
        $db->prepare("DELETE FROM dl_delivery_items WHERE delivery_id IN ({$idList})")->execute();
        $db->prepare("DELETE FROM dl_deliveries WHERE id IN ({$idList})")->execute();
    }
    $db->prepare('DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id = ? OR product_id IN (?, ?, ?)')
        ->execute([$commissary, $product, $quietProduct, $liveProduct]);
    $db->prepare('DELETE FROM dl_consignee_ledger WHERE consignee_id = ? OR product_id IN (?, ?, ?)')
        ->execute([$consignee, $product, $quietProduct, $liveProduct]);
    $db->prepare('DELETE FROM dl_consignee_products WHERE consignee_id = ? OR product_id IN (?, ?, ?)')
        ->execute([$consignee, $product, $quietProduct, $liveProduct]);
    $db->prepare('DELETE FROM dl_branch_products WHERE branch_id IN (?, ?) OR product_id IN (?, ?, ?)')
        ->execute([$commissary, $branch, $product, $quietProduct, $liveProduct]);
    $db->prepare('DELETE FROM dl_consignees WHERE id = ?')->execute([$consignee]);
    $db->prepare('DELETE FROM dl_products WHERE id IN (?, ?, ?)')->execute([$product, $quietProduct, $liveProduct]);
    $db->prepare('DELETE FROM dl_branches WHERE id IN (?, ?)')->execute([$commissary, $branch]);
};

$runBackfill = static function ($conn, string $sqlPath): void {
    $sql = (string)file_get_contents($sqlPath);
    $lines = preg_split('/\r?\n/', $sql) ?: [];
    $kept = [];
    foreach ($lines as $line) {
        if (preg_match('/^\s*--/', $line)) {
            continue;
        }
        $kept[] = $line;
    }
    // The migration includes DDL; KernelPDO.enforceModuleAccess permits it only
    // while kernel-escalated. This test file is not a module caller, so the
    // escalation is legitimate (the runner does the same around migrations).
    \Ikabud\Kernel\Database\KernelPDO::kernelEscalationEnter();
    try {
        foreach (explode(';', implode("\n", $kept)) as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }
            if ($conn instanceof PDO) {
                $conn->exec($statement);
            } else {
                $conn->execute($statement);
            }
        }
    } finally {
        \Ikabud\Kernel\Database\KernelPDO::kernelEscalationLeave();
    }
};

$settingsBefore = dlModuleSettings(true);
$cleanup();

try {
    $db->prepare('INSERT INTO dl_branches (id, code, name, assigned_commissary_id, is_commissary, is_active) VALUES (?, "DEPL-COM", "DEPL Commissary", NULL, 1, 1), (?, "DEPL-BR", "DEPL Branch", ?, 0, 1)')
        ->execute([$commissary, $branch, $commissary]);
    $db->prepare('INSERT INTO dl_products (id, sku, name, current_price, is_active) VALUES (?, "DEPL-P", "DEPL Product", 10.00, 1), (?, "DEPL-Q", "DEPL Quiet Product", 10.00, 1), (?, "DEPL-L", "DEPL Live Product", 10.00, 1)')
        ->execute([$product, $quietProduct, $liveProduct]);
    $db->prepare('INSERT INTO dl_branch_products (branch_id, product_id, is_active) VALUES (?, ?, 1), (?, ?, 1), (?, ?, 1), (?, ?, 1)')
        ->execute([$commissary, $product, $branch, $product, $commissary, $quietProduct, $commissary, $liveProduct]);
    $db->prepare('INSERT INTO dl_consignees (id, code, name, assigned_commissary_id, is_active) VALUES (?, "DEPL-C", "DEPL Consignee", ?, 1)')
        ->execute([$consignee, $commissary]);
    $db->prepare('INSERT INTO dl_consignee_products (consignee_id, product_id, is_active) VALUES (?, ?, 1), (?, ?, 1), (?, ?, 1)')
        ->execute([$consignee, $product, $consignee, $quietProduct, $consignee, $liveProduct]);

    // The measured shape: BEG 10, ADDTL 5, WASTAGE 1. dispatched starts at 2 (the
    // branch delivery already applied) - the consignee's 4 was never applied.
    $db->prepare('INSERT INTO dl_commissary_product_ledger (commissary_branch_id, product_id, ledger_date, shift, beg_qty, produced_qty, dispatched_qty, wastage_qty) VALUES (?, ?, ?, "AM", 10, 5, 2, 1)')
        ->execute([$commissary, $product, $date]);
    // Quiet day: branch-only, already correct at dispatched 3.
    $db->prepare('INSERT INTO dl_commissary_product_ledger (commissary_branch_id, product_id, ledger_date, shift, beg_qty, produced_qty, dispatched_qty, wastage_qty) VALUES (?, ?, ?, "AM", 2, 0, 3, 0)')
        ->execute([$commissary, $quietProduct, $quietDate]);

    // Consignee dispatch: the cashier performs it, so origin_type='branch'. This is
    // the row the old branch-only filter excluded.
    $db->prepare('INSERT INTO dl_deliveries (origin_type, origin_id, destination_type, consignee_id, dr_number, delivery_date, production_shift, status) VALUES ("branch", ?, "consignee", ?, ?, ?, "AM", "posted")')
        ->execute([$branch, $consignee, $drPrefix . 'CONS', $date]);
    $consigneeDeliveryId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO dl_delivery_items (delivery_id, product_id, quantity, price_snapshot) VALUES (?, ?, 4, 10.00)')
        ->execute([$consigneeDeliveryId, $product]);
    $db->prepare('INSERT INTO dl_consignee_ledger (consignee_id, product_id, ledger_date, shift, price_snapshot, beg_bal, addtl, withdraw) VALUES (?, ?, ?, "AM", 10.00, 0, 4, 0)')
        ->execute([$consignee, $product, $date]);
    // The branch delivery already applied to the projection (dispatched=2).
    $db->prepare('INSERT INTO dl_deliveries (origin_type, origin_id, destination_type, destination_id, dr_number, delivery_date, production_shift, status) VALUES ("commissary", ?, "branch", ?, ?, ?, "AM", "posted")')
        ->execute([$commissary, $branch, $drPrefix . 'BR', $date]);
    $branchDeliveryId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO dl_delivery_items (delivery_id, product_id, quantity, price_snapshot) VALUES (?, ?, 2, 10.00)')
        ->execute([$branchDeliveryId, $product]);
    // Quiet day branch-only departure.
    $db->prepare('INSERT INTO dl_deliveries (origin_type, origin_id, destination_type, destination_id, dr_number, delivery_date, production_shift, status) VALUES ("commissary", ?, "branch", ?, ?, ?, "AM", "posted")')
        ->execute([$commissary, $branch, $drPrefix . 'QUIET', $quietDate]);
    $quietDeliveryId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO dl_delivery_items (delivery_id, product_id, quantity, price_snapshot) VALUES (?, ?, 3, 10.00)')
        ->execute([$quietDeliveryId, $quietProduct]);

    // ── A / B: the single derivation and the balance before the backfill. ──
    $derivationBefore = dl_commissaryDepartedQtyByProduct($db, $commissary, $date, 'AM');
    $branchMatrix = dl_fetchProductionSheetDispatchMatrix($db, $date, $commissary, 'AM');
    $branchOnlyTotal = 0;
    foreach ((array)($branchMatrix[$product] ?? []) as $qty) {
        $branchOnlyTotal += (int)$qty;
    }
    $beg = 10;
    $addtl = 5;
    $wastage = 1;
    $derivationTotal = (int)($derivationBefore[$product] ?? 0);
    $oldBookBalance = $beg + $addtl - $branchOnlyTotal - $wastage;
    $newBookBalance = $beg + $addtl - $derivationTotal - $wastage;

    $h->test(
        'A discriminating: the branch-originated consignee dispatch is in the single derivation and lowers the balance by exactly its quantity',
        $derivationTotal === 6
        && $branchOnlyTotal === 2
        && $oldBookBalance - $newBookBalance === 4
    );

    // ── C: the backfill makes the projection equal the single derivation. ──
    $projectionBefore = (int)$db->query("SELECT dispatched_qty FROM dl_commissary_product_ledger WHERE commissary_branch_id = {$commissary} AND product_id = {$product} AND ledger_date = '{$date}' AND shift = 'AM'")->fetchColumn();
    $runBackfill($pdo, $migrationSql);
    $projectionAfterRow = $db->query("SELECT dispatched_qty, beg_qty, produced_qty, wastage_qty FROM dl_commissary_product_ledger WHERE commissary_branch_id = {$commissary} AND product_id = {$product} AND ledger_date = '{$date}' AND shift = 'AM'")->fetch(PDO::FETCH_ASSOC) ?: [];
    $h->test(
        'C discriminating: the projection equals the single derivation after the backfill (branch 2 + consignee 4)',
        $projectionBefore === 2
        && (int)($projectionAfterRow['dispatched_qty'] ?? -1) === 6
        && (int)$projectionAfterRow['dispatched_qty'] === (int)($derivationBefore[$product] ?? -1)
    );

    // ── B: with the corrected projection as the input, the printed identity holds
    // and calc_variance is 0 (it is generated from the corrected dispatched_qty).
    $db->prepare('UPDATE dl_commissary_product_ledger SET actual_end_qty = ? WHERE commissary_branch_id = ? AND product_id = ? AND ledger_date = ? AND shift = "AM"')
        ->execute([$newBookBalance, $commissary, $product, $date]);
    $identityRow = $db->query("SELECT beg_qty, produced_qty, dispatched_qty, wastage_qty, actual_end_qty, calc_variance FROM dl_commissary_product_ledger WHERE commissary_branch_id = {$commissary} AND product_id = {$product} AND ledger_date = '{$date}' AND shift = 'AM'")->fetch(PDO::FETCH_ASSOC) ?: [];
    $h->test(
        'B discriminating: BEG + ADDTL - TOTAL - WASTAGE = ACTUAL BAL still holds and calc_variance is corrected through the same input',
        (int)($identityRow['actual_end_qty'] ?? -1) === 8
        && (int)$identityRow['beg_qty'] + (int)$identityRow['produced_qty'] - (int)$identityRow['dispatched_qty'] - (int)$identityRow['wastage_qty'] === (int)$identityRow['actual_end_qty']
        && (int)($identityRow['calc_variance'] ?? -1) === 0
    );

    // ── D: meshing. The Inventory figure and the Daily Sheet figure are the same
    // derivation; the branch-only matrix is deliberately different, so a tree that
    // derives them separately cannot satisfy this.
    $inventoryDispatched = (int)$db->query("SELECT dispatched_qty FROM dl_commissary_product_ledger WHERE commissary_branch_id = {$commissary} AND product_id = {$product} AND ledger_date = '{$date}' AND shift = 'AM'")->fetchColumn();
    $h->test(
        'D discriminating: Inventory equals the Daily Sheet derivation AND differs from the branch-only matrix (fails if derived separately)',
        $inventoryDispatched === $derivationTotal
        && $inventoryDispatched === 6
        && $branchOnlyTotal === 2
        && $inventoryDispatched !== $branchOnlyTotal
    );

    // ── G: idempotent. Snapshot, run the backfill again, snapshot. ──
    $snapBefore = $db->query("SELECT id, dispatched_qty, remaining_qty, actual_end_qty FROM dl_commissary_product_ledger WHERE commissary_branch_id = {$commissary} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $effectCountBefore = (int)$db->query('SELECT COUNT(*) FROM dl_delivery_ledger_effects WHERE delivery_id IN (' . $consigneeDeliveryId . ',' . $branchDeliveryId . ',' . $quietDeliveryId . ')')->fetchColumn();
    $markerCountBefore = (int)$db->query("SELECT COUNT(*) FROM dl_commissary_depletion_backfill WHERE commissary_branch_id = {$commissary}")->fetchColumn();
    $runBackfill($pdo, $migrationSql);
    $snapAfter = $db->query("SELECT id, dispatched_qty, remaining_qty, actual_end_qty FROM dl_commissary_product_ledger WHERE commissary_branch_id = {$commissary} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $effectCountAfter = (int)$db->query('SELECT COUNT(*) FROM dl_delivery_ledger_effects WHERE delivery_id IN (' . $consigneeDeliveryId . ',' . $branchDeliveryId . ',' . $quietDeliveryId . ')')->fetchColumn();
    $markerCountAfter = (int)$db->query("SELECT COUNT(*) FROM dl_commissary_depletion_backfill WHERE commissary_branch_id = {$commissary}")->fetchColumn();
    $h->test(
        'G pin: running the backfill twice changes nothing the second time (defends rerun safety)',
        $snapBefore === $snapAfter
        && $effectCountBefore === $effectCountAfter
        && $markerCountBefore === $markerCountAfter
    );

    // ── E: a day with no consignee dispatch is byte-identical across the backfill. ──
    $quietBefore = $db->query("SELECT dispatched_qty, remaining_qty FROM dl_commissary_product_ledger WHERE commissary_branch_id = {$commissary} AND product_id = {$quietProduct} AND ledger_date = '{$quietDate}' AND shift = 'AM'")->fetch(PDO::FETCH_ASSOC) ?: [];
    $derivationQuiet = dl_commissaryDepartedQtyByProduct($db, $commissary, $quietDate, 'AM');
    $h->test(
        'E pin: a day with no consignee dispatch yields only the branch departure and is untouched by the backfill',
        (int)($derivationQuiet[$quietProduct] ?? 0) === 3
        && (int)($quietBefore['dispatched_qty'] ?? -1) === 3
        && !array_key_exists($product, $derivationQuiet)
    );

    // ── F: the live dispatch path depletes the projection exactly once. ──
    $db->prepare('INSERT INTO dl_deliveries (origin_type, origin_id, destination_type, consignee_id, dr_number, delivery_date, production_shift, status) VALUES ("branch", ?, "consignee", ?, ?, ?, "AM", "posted")')
        ->execute([$branch, $consignee, $drPrefix . 'LIVE', $liveDate]);
    $liveDeliveryId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO dl_delivery_items (delivery_id, product_id, quantity, price_snapshot) VALUES (?, ?, 5, 10.00)')
        ->execute([$liveDeliveryId, $liveProduct]);
    $liveEffect = dl_applyPostedDeliveryCommissaryLedger($db, $liveDeliveryId, 0);
    $liveProjection = (int)$db->query("SELECT dispatched_qty FROM dl_commissary_product_ledger WHERE commissary_branch_id = {$commissary} AND product_id = {$liveProduct} AND ledger_date = '{$liveDate}'")->fetchColumn();
    // The backfill must NOT double a live-applied departure.
    $runBackfill($pdo, $migrationSql);
    $liveProjectionAfterBackfill = (int)$db->query("SELECT dispatched_qty FROM dl_commissary_product_ledger WHERE commissary_branch_id = {$commissary} AND product_id = {$liveProduct} AND ledger_date = '{$liveDate}'")->fetchColumn();
    $liveEffectCount = (int)$db->query("SELECT COUNT(*) FROM dl_delivery_ledger_effects WHERE delivery_id = {$liveDeliveryId} AND effect_status = 'applied'")->fetchColumn();
    $h->test(
        'F pin: the live consignee dispatch depletes the projection exactly once and the backfill leaves it alone (defends the retry boundary)',
        ($liveEffect['status'] ?? '') === 'applied'
        && (int)($liveEffect['applied'] ?? 0) === 1
        && $liveProjection === 5
        && $liveProjectionAfterBackfill === 5
        && $liveEffectCount === 1
    );
} finally {
    $cleanup();
}

$remaining = 0;
$remaining += (int)$db->query("SELECT COUNT(*) FROM dl_branches WHERE id IN ({$commissary}, {$branch})")->fetchColumn();
$remaining += (int)$db->query("SELECT COUNT(*) FROM dl_products WHERE id IN ({$product}, {$quietProduct}, {$liveProduct})")->fetchColumn();
$remaining += (int)$db->query("SELECT COUNT(*) FROM dl_consignees WHERE id = {$consignee}")->fetchColumn();
$remaining += (int)$db->query("SELECT COUNT(*) FROM dl_consignee_products WHERE consignee_id = {$consignee} OR product_id IN ({$product}, {$quietProduct}, {$liveProduct})")->fetchColumn();
$remaining += (int)$db->query("SELECT COUNT(*) FROM dl_consignee_ledger WHERE consignee_id = {$consignee} OR product_id IN ({$product}, {$quietProduct}, {$liveProduct})")->fetchColumn();
$remaining += (int)$db->query("SELECT COUNT(*) FROM dl_commissary_product_ledger WHERE commissary_branch_id = {$commissary} OR product_id IN ({$product}, {$quietProduct}, {$liveProduct})")->fetchColumn();
$remaining += (int)$db->query("SELECT COUNT(*) FROM dl_commissary_depletion_backfill WHERE commissary_branch_id = {$commissary} OR product_id IN ({$product}, {$quietProduct}, {$liveProduct})")->fetchColumn();
$remaining += (int)$db->query("SELECT COUNT(*) FROM dl_deliveries WHERE dr_number LIKE '{$drPrefix}%'")->fetchColumn();
$settingsAfter = dlModuleSettings(true);
$h->test(
    'H pin: every fixture row is deleted and settings are untouched (defends tenant state and proves cleanup)',
    $remaining === 0
    && ($settingsAfter['consignee_enabled'] ?? null) === ($settingsBefore['consignee_enabled'] ?? null)
    && ($settingsAfter['consignee_sales_mode'] ?? null) === ($settingsBefore['consignee_sales_mode'] ?? null)
);

$h->done();
