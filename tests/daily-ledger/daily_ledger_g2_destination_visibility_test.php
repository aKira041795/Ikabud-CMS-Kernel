<?php

declare(strict_types=1);

/** G2 destination visibility oracle. All rows use private 9983x fixtures. */
ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-g2-destination-visibility', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();
$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';
$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('modules/daily-ledger/handlers-deliveries.php');
$h->fingerprint('templates/modules/daily-ledger/admin/commissary.disyl');
$h->fingerprint('tests/daily-ledger/daily_ledger_g2_destination_visibility_harness.php');
$h->allowLogLines(
    'disyl.compile.phases',
    'kernel_state_cache: module_registry rebuilt',
    'kernel_state_cache: capability_map rebuilt',
    'capability.call'
);
app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

$comm = 99831;
$store = 99832;
$product = 99831;
$carriedProduct = 99832;
$today = dl_businessDate();
$payloadFile = tempnam(sys_get_temp_dir(), 'dl-g2-');
$_COOKIE[dlCookieName()] = app()->jwt()->generate([
    'sub' => 'admin:1', 'id' => 1, 'username' => 'g2-oracle', 'name' => 'G2 Oracle',
    'role' => 'admin', 'source' => 'daily-ledger',
]);

$cleanup = static function () use ($db, $comm, $store, $product, $carriedProduct): void {
    $branches = "{$comm},{$store}";
    $products = "{$product},{$carriedProduct}";
    $deliveryIdRows = $db->query("SELECT id FROM dl_deliveries WHERE origin_id IN ({$branches}) OR destination_id IN ({$branches})")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $deliveryIds = $deliveryIdRows !== [] ? implode(',', array_map('intval', $deliveryIdRows)) : '0';
    $db->execute("DELETE FROM dl_delivery_variance_flags WHERE delivery_id IN ({$deliveryIds}) OR product_id IN ({$products})");
    $db->execute("DELETE FROM dl_branch_receiving_items WHERE receiving_id IN (SELECT id FROM dl_branch_receivings WHERE delivery_id IN ({$deliveryIds}))");
    $db->execute("DELETE FROM dl_branch_receivings WHERE delivery_id IN ({$deliveryIds}) OR branch_id IN ({$branches})");
    $db->execute("DELETE FROM dl_delivery_ledger_effects WHERE delivery_id IN ({$deliveryIds})");
    $db->execute("DELETE FROM dl_delivery_items WHERE delivery_id IN ({$deliveryIds}) OR product_id IN ({$products})");
    $db->execute("DELETE FROM dl_deliveries WHERE id IN ({$deliveryIds})");
    $db->execute("DELETE FROM dl_production_movements WHERE destination_branch_id IN ({$branches}) OR product_id IN ({$products})");
    $db->execute("DELETE FROM dl_daily_ledger WHERE branch_id IN ({$branches}) OR product_id IN ({$products})");
    $db->execute("DELETE FROM dl_commissary_product_ledger WHERE commissary_branch_id IN ({$branches}) OR product_id IN ({$products})");
    $db->execute("DELETE FROM dl_production_runs WHERE destination_branch_id IN ({$branches}) OR product_id IN ({$products})");
    $db->execute("DELETE FROM dl_ledger_shift_status WHERE branch_id IN ({$branches})");
    $db->execute("DELETE FROM dl_ledger_day_status WHERE branch_id IN ({$branches})");
    $db->execute("DELETE FROM audit_logs WHERE module = 'daily-ledger' AND (branch_id IN ({$branches}) OR entity_id IN ('{$product}','{$carriedProduct}'))");
    $db->execute("DELETE FROM dl_branch_products WHERE branch_id IN ({$branches}) OR product_id IN ({$products})");
    // The revival finding this oracle creates must not survive the run. A stale
    // row would satisfy a later COUNT(*) and let D6b pass without any write having
    // happened, which is exactly the false pass this cleanup exists to prevent.
    $db->execute("DELETE FROM dl_integrity_notifications WHERE branch_id IN ({$branches}) AND aggregate_key LIKE 'branch-link-revived:%'");
    $db->execute("DELETE FROM dl_products WHERE id IN ({$products})");
    $db->execute("DELETE FROM dl_branches WHERE id IN ({$branches})");
};
$assign = static function (int $branchId, int $productId, int $active) use ($db): void {
    $db->prepare('INSERT INTO dl_branch_products (branch_id,product_id,is_active) VALUES (?,?,?) ON DUPLICATE KEY UPDATE is_active=VALUES(is_active)')
        ->execute([$branchId, $productId, $active]);
};
$render = static function () use ($comm, $today): string {
    $_GET = ['commissary_id' => (string)$comm, 'date' => $today, 'shift' => 'AM'];
    ob_start();
    handleAdminCommissary();
    return (string)ob_get_clean();
};
$cellMarkup = static function (string $html, int $productId, int $branchId): string {
    if (!preg_match('/<tr class="daily-sheet-product-row" data-product-id="' . $productId . '".*?<\/tr>/s', $html, $row)) {
        return '';
    }
    if (!preg_match('/<td class="text-right production-branch-cell" data-branch-id="' . $branchId . '">(.*?)<\/td>/s', $row[0], $cell)) {
        return '';
    }
    return $cell[0];
};
$runDeliveryApi = static function (array $body) use ($payloadFile): array {
    file_put_contents($payloadFile, json_encode($body, JSON_THROW_ON_ERROR));
    $lines = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/daily_ledger_g2_destination_visibility_harness.php') . ' ' . escapeshellarg($payloadFile) . ' 2>/dev/null', $lines, $code);
    $raw = implode("\n", $lines);
    return ['exit' => $code, 'raw' => $raw, 'body' => json_decode($raw, true) ?: []];
};

$cleanup();
try {
    $db->prepare('INSERT INTO dl_branches (id,code,name,is_commissary,is_active) VALUES (?,?,?,1,1)')
        ->execute([$comm, 'G2-COMM', 'G2 Commissary']);
    $db->prepare('INSERT INTO dl_branches (id,code,name,is_commissary,is_active,assigned_commissary_id) VALUES (?,?,?,0,1,?)')
        ->execute([$store, 'G2-STORE', 'G2 Store', $comm]);
    $db->prepare('INSERT INTO dl_products (id,sku,name,current_price,is_active) VALUES (?,?,?,10,1)')
        ->execute([$product, 'G2-HIDDEN', 'G2 Hidden Product']);
    $db->prepare('INSERT INTO dl_products (id,sku,name,current_price,is_active) VALUES (?,?,?,10,1)')
        ->execute([$carriedProduct, 'G2-CARRIED', 'G2 Carried Product']);
    $assign($comm, $product, 1);
    $assign($comm, $carriedProduct, 1);
    $assign($store, $product, 0);
    $assign($store, $carriedProduct, 1);

    $h->section('C1 destination cells remain rectangular and preserve records');
    $emptyHtml = $render();
    $emptyCell = $cellMarkup($emptyHtml, $product, $store);
    $h->test('D1 unassigned empty destination cell renders enterable, warned, and in place',
        $emptyCell !== ''
        && str_contains($emptyCell, 'data-unassigned="1"')
        && !str_contains($emptyCell, 'aria-disabled')
        && !preg_match('/\sdisabled(?:\s|>)/', $emptyCell)
        && str_contains($emptyCell, "is not in G2 Store's product list"),
        $emptyCell);

    $db->prepare('INSERT INTO dl_deliveries (origin_type,origin_id,destination_type,destination_id,dr_number,delivery_date,status,production_shift,created_by) VALUES ("commissary",?,"branch",?,?,?,"posted","AM",1)')
        ->execute([$comm, $store, 'G2-EXISTING', $today]);
    $deliveryId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO dl_delivery_items (delivery_id,product_id,quantity,unit,price_snapshot) VALUES (?,?,7,"pcs",10)')
        ->execute([$deliveryId, $product]);
    $existingHtml = $render();
    $existingCell = $cellMarkup($existingHtml, $product, $store);
    $h->test('D2 unassigned cell keeps its existing dispatch quantity visible', str_contains($existingCell, 'data-product="' . $product . '"') && preg_match('/>7<\/span>/', $existingCell) === 1, $existingCell);
    $h->test('D2 existing unassigned cell is visible and still enterable', str_contains($existingCell, 'data-unassigned="1"') && !str_contains($existingCell, 'aria-disabled') && !preg_match('/\sdisabled(?:\s|>)/', $existingCell), $existingCell);

    $assign($store, $product, 1);
    $assignedHtml = $render();
    $assignedCell = $cellMarkup($assignedHtml, $product, $store);
    $h->test('D3 re-assignment clears the warning on the same cell', $assignedCell !== '' && !str_contains($assignedCell, 'data-unassigned') && !preg_match('/\sdisabled(?:\s|>)/', $assignedCell), $assignedCell);

    $expectedColumns = (int)$db->query("SELECT COUNT(*) FROM dl_branches WHERE is_active=1 AND id<>{$comm}")->fetchColumn();
    preg_match('/<tr class="daily-sheet-product-row" data-product-id="' . $product . '".*?<\/tr>/s', $assignedHtml, $productRow);
    $actualCells = substr_count($productRow[0] ?? '', 'production-branch-cell');
    $headerColumns = substr_count($assignedHtml, '<th class="text-right daily-sheet-branch-column"');
    $h->test('D4 product row retains one destination cell per non-source active branch', $actualCells === $expectedColumns && $headerColumns === $expectedColumns, "cells={$actualCells} headers={$headerColumns} expected={$expectedColumns}");

    $h->section('C2 destination writes');
    $assign($store, $product, 0);
    $user = ['id' => 1, 'sub' => 'admin:1', 'role' => 'admin', 'source' => 'daily-ledger', 'username' => 'g2-oracle', 'name' => 'G2 Oracle'];
    $moveCode = '';
    try {
        dl_processProductionMovement($user, 'output', [
            'destination_branch_id' => $store, 'product_id' => $product, 'quantity' => 2,
            'ledger_date' => $today, 'shift' => 'AM', 'flow_mode' => 'legacy',
        ]);
    } catch (Throwable $e) {
        $moveCode = $e instanceof DlProductNotAssignedException ? $e->errorCode() : '';
    }
    $movementCount = (int)$db->query("SELECT COUNT(*) FROM dl_production_movements WHERE destination_branch_id={$store} AND product_id={$product}")->fetchColumn();
    $h->test('D5 dispatch to unassigned destination is refused with PRODUCT_NOT_ASSIGNED and writes nothing', $moveCode === 'PRODUCT_NOT_ASSIGNED' && $movementCount === 0, "code={$moveCode} rows={$movementCount}");

    $beforeDeliveries = (int)$db->query("SELECT COUNT(*) FROM dl_deliveries WHERE destination_id={$store}")->fetchColumn();
    $deliveryResult = $runDeliveryApi([
        'origin_type' => 'commissary', 'origin_id' => $comm,
        'destination_type' => 'branch', 'destination_id' => $store,
        'workflow_mode' => 'exception_recovery', 'recovery_reason' => 'G2 oracle',
        'delivery_date' => $today, 'items' => [['product_id' => $product, 'quantity' => 3, 'unit' => 'pcs']],
    ]);
    $afterDeliveries = (int)$db->query("SELECT COUNT(*) FROM dl_deliveries WHERE destination_id={$store}")->fetchColumn();
    $error = (string)($deliveryResult['body']['error'] ?? '');
    $h->test('D6 delivery to unassigned destination is refused and writes nothing', ($deliveryResult['body']['code'] ?? '') === 'PRODUCT_NOT_ASSIGNED' && str_contains($error, 'G2 Hidden Product') && str_contains($error, 'G2 Store') && $afterDeliveries === $beforeDeliveries, $deliveryResult['raw'] . " before={$beforeDeliveries} after={$afterDeliveries}");

    // D6b: owner reversal (2026-10-08). A hide is authoritative until the
    // operator explicitly confirms the unassigned-destination override.
    $assign($store, $product, 0);
    $beforeSheet = (int)$db->query("SELECT COUNT(*) FROM dl_deliveries WHERE destination_id={$store}")->fetchColumn();
    $sheetMessage = '';
    try {
        dl_recordDailySheetBranchEntry($user, [
            'date' => $today, 'commissary_branch_id' => $comm, 'destination_branch_id' => $store,
            'product_id' => $product, 'quantity' => 3, 'shift' => 'AM',
            'submission_id' => 'g2-sheet-no-override', 'type' => 'correction', 'reason_code' => 'encoder_omission',
        ]);
    } catch (\Throwable $e) {
        $sheetMessage = $e->getMessage();
    }
    $afterRefusal = (int)$db->query("SELECT COUNT(*) FROM dl_deliveries WHERE destination_id={$store}")->fetchColumn();
    $pairAfterRefusal = (int)$db->query("SELECT is_active FROM dl_branch_products WHERE branch_id={$store} AND product_id={$product}")->fetchColumn();
    $h->test('D6b sheet entry without override is refused and preserves the admin hide',
        str_contains($sheetMessage, 'Confirm the unassigned-destination override')
        && $afterRefusal === $beforeSheet && $pairAfterRefusal === 0,
        "msg={$sheetMessage} before={$beforeSheet} after={$afterRefusal} pair={$pairAfterRefusal}");

    $sheetResult = dl_recordDailySheetBranchEntry($user, [
        'date' => $today, 'commissary_branch_id' => $comm, 'destination_branch_id' => $store,
        'product_id' => $product, 'quantity' => 3, 'shift' => 'AM',
        'submission_id' => 'g2-sheet-with-override', 'type' => 'correction',
        'reason_code' => 'encoder_omission', 'unassigned_override' => '1',
    ]);
    $afterSheet = (int)$db->query("SELECT COUNT(*) FROM dl_deliveries WHERE destination_id={$store}")->fetchColumn();
    $pairAfterSheet = (int)$db->query("SELECT is_active FROM dl_branch_products WHERE branch_id={$store} AND product_id={$product}")->fetchColumn();
    $h->test('D6c the same sheet entry with explicit override succeeds and revives the pair',
        $afterSheet === $beforeSheet + 1 && $pairAfterSheet === 1
        && !empty($sheetResult['branch_link_revived'])
        && ($sheetResult['branch_name'] ?? '') === 'G2 Store',
        "before={$beforeSheet} after={$afterSheet} pair={$pairAfterSheet} result=" . json_encode($sheetResult));

    $assign($store, $product, 1);
    $normal = dl_processProductionMovement($user, 'output', [
        'destination_branch_id' => $store, 'product_id' => $product, 'quantity' => 2,
        'ledger_date' => $today, 'shift' => 'AM', 'flow_mode' => 'legacy',
    ]);
    $normalCount = (int)$db->query("SELECT COUNT(*) FROM dl_production_movements WHERE destination_branch_id={$store} AND product_id={$product}")->fetchColumn();
    $h->test('D7 normal dispatch to assigned destination still succeeds', !empty($normal['movement_id']) && $normalCount === 1, json_encode($normal));

    $h->section('C3 Usage product list');
    $assign($store, $product, 0);
    $usage = dl_buildUsagePageData($db, $user, $today, $store);
    $actualUsageIds = array_map('intval', array_merge(
        array_column($usage['product_rows_bread'], 'product_id'),
        array_column($usage['product_rows_cake'], 'product_id')
    ));
    sort($actualUsageIds);
    $expectedUsageIds = array_map('intval', $db->query(
        "SELECT p.id FROM dl_products p INNER JOIN dl_branch_products bp ON bp.product_id=p.id AND bp.branch_id={$store} AND bp.is_active=1 WHERE p.is_active=1 ORDER BY p.id"
    )->fetchAll(PDO::FETCH_COLUMN) ?: []);
    $h->test('D8 Usage omits unassigned products and lists every assigned active product', !in_array($product, $actualUsageIds, true) && $actualUsageIds === $expectedUsageIds, 'actual=' . json_encode($actualUsageIds) . ' expected=' . json_encode($expectedUsageIds));

    $h->section('C4 the product itself is never revived by a sheet entry');
    // Reviving one branch's link is bookkeeping. Reviving a discontinued PRODUCT
    // would put it back into every list at once, so that stays refused - and the
    // refusal must leave the branch link exactly as it found it.
    $db->execute("UPDATE dl_products SET is_active = 0 WHERE id = {$product}");
    $assign($store, $product, 0);
    $beforeInactive = (int)$db->query("SELECT COUNT(*) FROM dl_deliveries WHERE destination_id={$store}")->fetchColumn();
    $inactiveMessage = '';
    try {
        dl_recordDailySheetBranchEntry($user, [
            'date' => $today, 'commissary_branch_id' => $comm, 'destination_branch_id' => $store,
            'product_id' => $product, 'quantity' => 3, 'shift' => 'AM',
            'submission_id' => 'g2-sheet-inactive',
        ]);
    } catch (\Throwable $e) {
        $inactiveMessage = $e->getMessage();
    }
    $afterInactive = (int)$db->query("SELECT COUNT(*) FROM dl_deliveries WHERE destination_id={$store}")->fetchColumn();
    $pairAfterInactive = (int)$db->query("SELECT is_active FROM dl_branch_products WHERE branch_id={$store} AND product_id={$product}")->fetchColumn();
    $h->test('D9 a discontinued product is still refused and its branch link is never revived',
        $inactiveMessage !== '' && $afterInactive === $beforeInactive && $pairAfterInactive === 0,
        "msg={$inactiveMessage} before={$beforeInactive} after={$afterInactive} pair={$pairAfterInactive}");
    $db->execute("UPDATE dl_products SET is_active = 1 WHERE id = {$product}");

    $h->section('C5 the finding_type 078 introduces is deployable');
    // The revival is only observable to an admin if its finding_type exists.
    // An unregistered migration silently never runs, which is exactly how this
    // feature would ship looking finished while notifying nobody.
    $migrationRel = 'modules/daily-ledger/database/migrations/078_sheet_entry_branch_link_finding_type.sql';
    $migrationSql = is_file($base . '/' . $migrationRel) ? (string)file_get_contents($base . '/' . $migrationRel) : '';
    $manifest078 = json_decode((string)file_get_contents($base . '/modules/daily-ledger/module.json'), true);
    $h->test('D10 migration 078 exists, is registered, and appends the new value after 076\'s six',
        $migrationSql !== ''
        && in_array('database/migrations/' . basename($migrationRel), $manifest078['migrations'] ?? [], true)
        && str_contains($migrationSql, "'closed_without_pm_finalize'',''product_branch_link_revived'")
        && preg_match('/LOCATE\s*\(/i', $migrationSql) === 1
        && preg_match('/\bPREPARE\b/i', $migrationSql) === 1,
        'registered=' . var_export(in_array('database/migrations/' . basename($migrationRel), $manifest078['migrations'] ?? [], true), true));
} finally {
    $cleanup();
    @unlink($payloadFile);
}

$h->done();
