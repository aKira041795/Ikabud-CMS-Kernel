<?php

declare(strict_types=1);

/** Daily Ledger — bread/non-bread filtering and Bodega receiving provenance. */

ob_start();
require_once __DIR__ . '/../harness/TestHarness.php';
$h = new TestHarness('daily-ledger-bread-bodega-wiring', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();

$h->allowLogLines(
    'disyl.compile.phases',
    'disyl.render.breakdown',
    'kernel_state_cache: module_registry rebuilt',
    'kernel_state_cache: capability_map rebuilt',
    'kernel_state_cache: entity_presets rebuilt',
    'capability.call'
);

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/helpers.php';
require_once $base . '/modules/daily-ledger/handlers-deliveries.php';
require_once $base . '/modules/daily-ledger/handlers.php';

app()->tenant()->setTenantId(207);
$ctx = modulePushContext('daily-ledger');
$db = $ctx->db();

$branchId = 99871;
$productIds = ['bread' => 99871, 'cake' => 99872, 'other' => 99873];
$deliveryId = 0;
$receivingId = 0;
$date = dl_businessDate();

$moduleJson = (string)file_get_contents($base . '/modules/daily-ledger/module.json');
$deliverySource = (string)file_get_contents($base . '/modules/daily-ledger/handlers-deliveries.php');
$deliveriesTemplate = (string)file_get_contents($base . '/templates/modules/daily-ledger/admin/deliveries.disyl');

$cleanup = static function () use ($db, $branchId, $productIds): void {
    $ids = array_values($productIds);
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $db->prepare("DELETE FROM audit_logs WHERE module = 'daily-ledger' AND (branch_id = ? OR entity_id IN (SELECT CAST(id AS CHAR) FROM dl_branch_receivings WHERE branch_id = ?))")
        ->execute([$branchId, $branchId]);
    $db->prepare('DELETE FROM dl_delivery_variance_flags WHERE product_id IN (' . $marks . ')')->execute($ids);
    $db->prepare('DELETE FROM dl_daily_ledger WHERE branch_id = ? OR product_id IN (' . $marks . ')')->execute(array_merge([$branchId], $ids));
    $db->prepare('DELETE FROM dl_branch_receivings WHERE branch_id = ?')->execute([$branchId]);
    $db->prepare('DELETE FROM dl_deliveries WHERE destination_type = "branch" AND destination_id = ?')->execute([$branchId]);
    $db->prepare('DELETE FROM dl_branch_products WHERE branch_id = ? OR product_id IN (' . $marks . ')')->execute(array_merge([$branchId], $ids));
    $db->prepare('DELETE FROM dl_products WHERE id IN (' . $marks . ')')->execute($ids);
    $db->prepare('DELETE FROM dl_branches WHERE id = ?')->execute([$branchId]);
};

$cleanup();
try {
    $db->prepare(
        'INSERT INTO dl_branches (id, code, name, is_active, is_commissary, paperless_dr_enabled, single_shift_mode, has_production)
         VALUES (?, ?, ?, 1, 0, 1, 0, 0)'
    )->execute([$branchId, 'BOD-99871', 'Bodega Wiring Fixture']);

    $insertProduct = $db->prepare(
        'INSERT INTO dl_products (id, sku, name, current_price, product_category, is_active)
         VALUES (?, ?, ?, 1, ?, 1)'
    );
    foreach ($productIds as $category => $id) {
        $insertProduct->execute([$id, 'BOD-' . strtoupper($category) . '-' . $id, 'Bodega ' . ucfirst($category) . ' Fixture', $category]);
    }

    $h->section('category groups');
    $groupStmt = $db->prepare(
        'SELECT product_category,
                CASE WHEN product_category = "bread" THEN "bread" ELSE "non_bread" END AS category_group
           FROM dl_products WHERE id = :product_id'
    );
    $resolved = [];
    foreach ($productIds as $category => $id) {
        $groupStmt->execute([':product_id' => $id]);
        $resolved[$category] = (string)$groupStmt->fetchColumn(1);
    }
    $h->test(
        'real bread, cake, and other rows resolve to the required groups',
        $resolved === ['bread' => 'bread', 'cake' => 'non_bread', 'other' => 'non_bread'],
        json_encode($resolved)
    );

    $h->section('migration and live resolver');
    $column = $db->query("SHOW COLUMNS FROM dl_branch_receiving_items LIKE 'supply_origin'")->fetch(PDO::FETCH_ASSOC) ?: [];
    $h->test(
        'migration 097 is registered and supply_origin defaults to commissary',
        str_contains($moduleJson, 'database/migrations/097_receiving_item_supply_origin.sql')
            && ($column['Default'] ?? null) === 'commissary',
        'register/apply migration 097 with the required default'
    );
    $h->test(
        'the live resolver maps non-bread to bodega and bread to its fallback',
        dl_supplyOriginForProduct($productIds['bread'], 'branch') === 'branch'
            && dl_supplyOriginForProduct($productIds['cake']) === 'bodega'
            && dl_supplyOriginForProduct($productIds['other']) === 'bodega',
        'product_category was not read live or non-bread was not mapped to Bodega'
    );
    $h->test(
        'an unknown product fails closed to the supplied fallback',
        dl_supplyOriginForProduct(0, 'supplier') === 'supplier'
            && dl_supplyOriginForProduct(999999999, 'branch') === 'branch',
        'unknown products must not be guessed as Bodega'
    );

    $h->section('mixed posted DR');
    $db->prepare(
        'INSERT INTO dl_deliveries (origin_type, origin_id, destination_type, destination_id, dr_number, delivery_date, status, posted_at)
         VALUES ("commissary", NULL, "branch", ?, "DR-BODEGA-99871", ?, "posted", NOW())'
    )->execute([$branchId, $date]);
    $deliveryId = (int)$db->lastInsertId();
    $insertItem = $db->prepare(
        'INSERT INTO dl_delivery_items (delivery_id, product_id, quantity, unit, price_snapshot)
         VALUES (?, ?, ?, "pcs", 1)'
    );
    $insertItem->execute([$deliveryId, $productIds['bread'], 2]);
    $insertItem->execute([$deliveryId, $productIds['cake'], 3]);
    $insertItem->execute([$deliveryId, $productIds['other'], 4]);

    $receivingId = dl_acceptFormalDelivery($db, $branchId, $deliveryId, 0, $date, null, 'AM');
    $storedStmt = $db->prepare(
        'SELECT p.product_category, ri.supply_origin
           FROM dl_branch_receiving_items ri
           INNER JOIN dl_products p ON p.id = ri.product_id
          WHERE ri.receiving_id = :receiving_id ORDER BY p.product_category'
    );
    $storedStmt->execute([':receiving_id' => $receivingId]);
    $stored = [];
    foreach ($storedStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $stored[(string)$row['product_category']] = (string)$row['supply_origin'];
    }
    $h->test(
        'one posted mixed DR stores bread as commissary and every non-bread line as bodega',
        $stored === ['bread' => 'commissary', 'cake' => 'bodega', 'other' => 'bodega'],
        json_encode($stored)
    );

    $audit = $db->prepare("SELECT new_data FROM audit_logs WHERE module = 'daily-ledger' AND action = 'receiving_bodega_lines' AND entity_id = :entity_id");
    $audit->execute([':entity_id' => (string)$receivingId]);
    $auditRows = $audit->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $auditData = isset($auditRows[0]) ? (json_decode((string)$auditRows[0], true) ?: []) : [];
    $h->test(
        'the posted receiving has one durable Bodega summary audit',
        count($auditRows) === 1
            && (int)($auditData['receiving_id'] ?? 0) === $receivingId
            && (int)($auditData['delivery_id'] ?? 0) === $deliveryId
            && ($auditData['dr_number'] ?? '') === 'DR-BODEGA-99871'
            && (int)($auditData['bodega_line_count'] ?? 0) === 2
            && (int)($auditData['bodega_total_qty'] ?? 0) === 7,
        json_encode(['rows' => count($auditRows), 'data' => $auditData])
    );

    $insertCount = preg_match_all('/INSERT INTO dl_branch_receiving_items[\s\S]*?supply_origin[\s\S]*?VALUES\s*\(/', $deliverySource);
    $h->test(
        'every receiving-item insert in the handler writes supply_origin',
        substr_count($deliverySource, 'INSERT INTO dl_branch_receiving_items') === 2 && $insertCount === 2,
        'a receiving path can still rely on the column default instead of classifying its product'
    );

    $h->section('admin tracing');
    $h->test(
        'the admin deliveries receipt renders a per-line Source column',
        str_contains($deliveriesTemplate, '>Source</th>')
            && str_contains($deliveriesTemplate, "it.supply_origin === 'bodega' ? 'Bodega'")
            && str_contains($deliveriesTemplate, "it.supply_origin === 'commissary' ? 'Commissary'"),
        'admins cannot see Bodega/Commissary on receipt lines'
    );
} finally {
    $cleanup();
}

$remaining = [
    'branches' => (int)$db->query("SELECT COUNT(*) FROM dl_branches WHERE id = {$branchId}")->fetchColumn(),
    'products' => (int)$db->query('SELECT COUNT(*) FROM dl_products WHERE id IN (' . implode(',', array_values($productIds)) . ')')->fetchColumn(),
    'receivings' => (int)$db->query("SELECT COUNT(*) FROM dl_branch_receivings WHERE branch_id = {$branchId}")->fetchColumn(),
    'deliveries' => (int)$db->query("SELECT COUNT(*) FROM dl_deliveries WHERE destination_type = 'branch' AND destination_id = {$branchId}")->fetchColumn(),
    'audit' => (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE module = 'daily-ledger' AND branch_id = {$branchId}")->fetchColumn(),
];
$h->test('fixture cleanup pin: every private-id row is removed', array_sum($remaining) === 0, json_encode($remaining));

$h->done();
