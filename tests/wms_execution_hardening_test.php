<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'wms.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/wms/scanner';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/wms/helpers.php';

$pass = 0;
$fail = 0;
$skip = 0;
$errors = [];
$cleanupTaskIds = [];
$cleanupOrderIds = [];
$cleanupMovementProductIds = [];
$cleanupBatchIds = [];
$cleanupLocationIds = [];
$cleanupWarehouseIds = [];
$cleanupProductIds = [];

function t(string $label, bool $ok, string $detail = ''): void
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

function s(string $label, string $detail = ''): void
{
    global $skip;

    $skip++;
    echo "  - {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function wmsHardeningRandomSuffix(): string
{
    return substr(bin2hex(random_bytes(4)), 0, 8);
}

function wmsHardeningTableExists(PDO $db, string $table): bool
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $stmt->execute([$table]);
    return (int)($stmt->fetchColumn() ?: 0) > 0;
}

function wmsHardeningColumnExists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $stmt->execute([$table, $column]);
    return (int)($stmt->fetchColumn() ?: 0) > 0;
}

function wmsHardeningActorUserId(PDO $db): int
{
    return (int)($db->query('SELECT id FROM wms_users ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
}

function wmsHardeningCreateWarehouse(PDO $db, string $suffix): array
{
    global $cleanupWarehouseIds;

    $code = 'TWH-' . strtoupper($suffix);
    $name = 'WMS Hardening ' . $suffix;
    $stmt = $db->prepare('INSERT INTO wms_warehouses (code, name, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())');
    $stmt->execute([$code, $name]);

    $warehouseId = (int)$db->lastInsertId();
    $cleanupWarehouseIds[] = $warehouseId;

    return ['id' => $warehouseId, 'code' => $code, 'name' => $name];
}

function wmsHardeningCreateLocation(PDO $db, int $warehouseId, string $code, string $name): array
{
    global $cleanupLocationIds;

    $stmt = $db->prepare(
        'INSERT INTO wms_locations (warehouse_id, parent_id, code, name, type, capacity, capacity_unit, sort_order, is_active, is_staging, created_at, updated_at)
         VALUES (?, NULL, ?, ?, ?, NULL, NULL, 0, 1, 0, NOW(), NOW())'
    );
    $stmt->execute([$warehouseId, $code, $name, 'bin']);

    $locationId = (int)$db->lastInsertId();
    $cleanupLocationIds[] = $locationId;

    return ['id' => $locationId, 'code' => $code, 'name' => $name, 'warehouse_id' => $warehouseId];
}

function wmsHardeningCreateProduct(PDO $db, string $sku, string $name, bool $isBatchTracked): array
{
    global $cleanupProductIds, $cleanupMovementProductIds;

    $stmt = $db->prepare(
        'INSERT INTO wms_products (sku, barcode, name, unit, product_type, is_batch_tracked, is_active, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())'
    );
    $stmt->execute([$sku, $sku, $name, 'pcs', 'physical', $isBatchTracked ? 1 : 0]);

    $productId = (int)$db->lastInsertId();
    $cleanupProductIds[] = $productId;
    $cleanupMovementProductIds[] = $productId;

    return ['id' => $productId, 'sku' => $sku, 'name' => $name, 'is_batch_tracked' => $isBatchTracked ? 1 : 0];
}

function wmsHardeningCreateBatch(PDO $db, int $productId, string $batchNumber): array
{
    global $cleanupBatchIds;

    $stmt = $db->prepare('INSERT INTO wms_batches (product_id, batch_number, created_at, updated_at) VALUES (?, ?, NOW(), NOW())');
    $stmt->execute([$productId, $batchNumber]);

    $batchId = (int)$db->lastInsertId();
    $cleanupBatchIds[] = $batchId;

    return ['id' => $batchId, 'batch_number' => $batchNumber];
}

function wmsHardeningCleanup(PDO $db): void
{
    global $cleanupTaskIds, $cleanupOrderIds, $cleanupMovementProductIds, $cleanupBatchIds, $cleanupLocationIds, $cleanupWarehouseIds, $cleanupProductIds;

    if ($cleanupTaskIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($cleanupTaskIds), '?'));
        $db->prepare("DELETE FROM wms_task_exceptions WHERE task_id IN ({$placeholders})")->execute($cleanupTaskIds);
        $db->prepare("DELETE FROM wms_tasks WHERE id IN ({$placeholders})")->execute($cleanupTaskIds);
    }

    if ($cleanupOrderIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($cleanupOrderIds), '?'));
        $db->prepare("DELETE FROM wms_order_items WHERE order_id IN ({$placeholders})")->execute($cleanupOrderIds);
        $db->prepare("DELETE FROM wms_orders WHERE id IN ({$placeholders})")->execute($cleanupOrderIds);
    }

    if ($cleanupMovementProductIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($cleanupMovementProductIds), '?'));
        $db->prepare("DELETE FROM wms_idempotency_keys WHERE movement_id IN (SELECT id FROM wms_movements WHERE product_id IN ({$placeholders}))")->execute($cleanupMovementProductIds);
        $db->prepare("DELETE FROM wms_movements WHERE product_id IN ({$placeholders})")->execute($cleanupMovementProductIds);
        $db->prepare("DELETE FROM wms_stocks WHERE product_id IN ({$placeholders})")->execute($cleanupMovementProductIds);
    }

    if ($cleanupBatchIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($cleanupBatchIds), '?'));
        $db->prepare("DELETE FROM wms_batches WHERE id IN ({$placeholders})")->execute($cleanupBatchIds);
    }

    if ($cleanupLocationIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($cleanupLocationIds), '?'));
        $db->prepare("DELETE FROM wms_locations WHERE id IN ({$placeholders})")->execute($cleanupLocationIds);
    }

    if ($cleanupWarehouseIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($cleanupWarehouseIds), '?'));
        $db->prepare("DELETE FROM wms_warehouses WHERE id IN ({$placeholders})")->execute($cleanupWarehouseIds);
    }

    if ($cleanupProductIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($cleanupProductIds), '?'));
        $db->prepare("DELETE FROM wms_products WHERE id IN ({$placeholders})")->execute($cleanupProductIds);
    }
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== WMS EXECUTION HARDENING ===\n";

$db = app()->db();

if (!function_exists('module') || !module('wms')) {
    s('WMS module context unavailable', 'Skipping execution hardening regression');
    exit(0);
}

$requiredTables = [
    'wms_users',
    'wms_products',
    'wms_warehouses',
    'wms_locations',
    'wms_batches',
    'wms_stocks',
    'wms_movements',
    'wms_orders',
    'wms_order_items',
    'wms_tasks',
    'wms_task_exceptions',
    'wms_configs',
];

foreach ($requiredTables as $table) {
    if (!wmsHardeningTableExists($db, $table)) {
        s('Required WMS table missing', $table);
        exit(0);
    }
}

$requiredColumns = [
    ['wms_locations', 'is_staging'],
    ['wms_stocks', 'qty_staged'],
    ['wms_task_exceptions', 'disposition_type'],
];

foreach ($requiredColumns as [$table, $column]) {
    if (!wmsHardeningColumnExists($db, $table, $column)) {
        s('Required WMS column missing', $table . '.' . $column);
        exit(0);
    }
}

$actorUserId = wmsHardeningActorUserId($db);

try {
    $suffix = wmsHardeningRandomSuffix();
    $warehouse = wmsHardeningCreateWarehouse($db, $suffix);
    $batchLocation = wmsHardeningCreateLocation($db, (int)$warehouse['id'], 'BATCH-' . strtoupper($suffix), 'Batch Test Bin');
    $releaseLocation = wmsHardeningCreateLocation($db, (int)$warehouse['id'], 'REL-' . strtoupper($suffix), 'Release Test Bin');

    $batchProduct = wmsHardeningCreateProduct($db, 'WMS-BATCH-' . strtoupper($suffix), 'Batch Hardening ' . $suffix, true);
    $batch = wmsHardeningCreateBatch($db, (int)$batchProduct['id'], 'LOT-' . strtoupper($suffix));

    $adjustmentError = '';
    try {
        wmsMovementCreate([
            'movement_type' => 'adjustment',
            'reference_type' => 'test',
            'reference_id' => 1,
            'product_id' => (int)$batchProduct['id'],
            'warehouse_id' => (int)$warehouse['id'],
            'location_id' => (int)$batchLocation['id'],
            'qty' => 1,
            'notes' => 'Should fail without batch.',
            'actor_user_id' => $actorUserId,
        ]);
    } catch (Throwable $e) {
        $adjustmentError = $e->getMessage();
    }

    t(
        'batch-tracked positive adjustment without batch is rejected',
        str_contains($adjustmentError, 'Batch ID is required'),
        $adjustmentError
    );

    $db->prepare(
        'INSERT INTO wms_stocks (product_id, warehouse_id, location_id, batch_id, qty_on_hand, qty_reserved, qty_staged, updated_at)
         VALUES (?, ?, ?, NULL, ?, 0, 0, NOW())'
    )->execute([(int)$batchProduct['id'], (int)$warehouse['id'], (int)$batchLocation['id'], 5]);

    wmsMovementCreate([
        'movement_type' => 'adjustment',
        'reference_type' => 'test',
        'reference_id' => 2,
        'product_id' => (int)$batchProduct['id'],
        'warehouse_id' => (int)$warehouse['id'],
        'location_id' => (int)$batchLocation['id'],
        'batch_id' => (int)$batch['id'],
        'qty' => 3,
        'notes' => 'Seed valid batched stock.',
        'actor_user_id' => $actorUserId,
    ]);

    $batchOrderId = wmsOrderCreate([
        'order_number' => 'WMS-BATCH-ORDER-' . strtoupper($suffix),
        'warehouse_id' => (int)$warehouse['id'],
        'created_by' => $actorUserId,
        'items' => [[
            'product_id' => (int)$batchProduct['id'],
            'qty_ordered' => 1,
        ]],
    ]);
    $cleanupOrderIds[] = $batchOrderId;

    $batchPickList = wmsOrderGeneratePickList($batchOrderId);
    $batchOrderItem = wmsFetchOne('SELECT * FROM wms_order_items WHERE order_id = ? ORDER BY id ASC LIMIT 1', [$batchOrderId]);
    $unbatchedStock = wmsStockGet((int)$batchProduct['id'], (int)$batchLocation['id'], null);
    $batchedStock = wmsStockGet((int)$batchProduct['id'], (int)$batchLocation['id'], (int)$batch['id']);

    t(
        'batch-tracked picklist chooses batched stock',
        (int)($batchPickList[0]['batch_id'] ?? 0) === (int)$batch['id'],
        json_encode($batchPickList[0] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''
    );
    t(
        'batch-tracked order item stores picked batch',
        (int)($batchOrderItem['batch_id'] ?? 0) === (int)$batch['id'],
        json_encode($batchOrderItem ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''
    );
    t(
        'legacy batchless stock row stays unreserved',
        wmsNormalizeDecimal($unbatchedStock['qty_reserved'] ?? 0) === 0.0,
        json_encode($unbatchedStock ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''
    );
    t(
        'valid batched stock row carries the reservation',
        wmsNormalizeDecimal($batchedStock['qty_reserved'] ?? 0) === 1.0,
        json_encode($batchedStock ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''
    );

    $releaseProduct = wmsHardeningCreateProduct($db, 'WMS-REL-' . strtoupper($suffix), 'Release Hardening ' . $suffix, false);
    wmsMovementCreate([
        'movement_type' => 'adjustment',
        'reference_type' => 'test',
        'reference_id' => 3,
        'product_id' => (int)$releaseProduct['id'],
        'warehouse_id' => (int)$warehouse['id'],
        'location_id' => (int)$releaseLocation['id'],
        'qty' => 2,
        'notes' => 'Seed release reservation stock.',
        'actor_user_id' => $actorUserId,
    ]);

    $releaseOrderId = wmsOrderCreate([
        'order_number' => 'WMS-REL-ORDER-' . strtoupper($suffix),
        'warehouse_id' => (int)$warehouse['id'],
        'created_by' => $actorUserId,
        'items' => [[
            'product_id' => (int)$releaseProduct['id'],
            'qty_ordered' => 1,
        ]],
    ]);
    $cleanupOrderIds[] = $releaseOrderId;

    $releasePickList = wmsOrderGeneratePickList($releaseOrderId);
    $releaseTaskId = wmsTaskCreate([
        'warehouse_id' => (int)$warehouse['id'],
        'task_type' => 'pick',
        'reference_type' => 'order',
        'reference_id' => $releaseOrderId,
        'priority' => 20,
        'notes' => 'Release reservation regression task.',
    ]);
    $cleanupTaskIds[] = $releaseTaskId;

    $mismatch = wmsTaskScanConfirm($releaseTaskId, [
        'action' => 'complete',
        'product_code' => 'WRONG-' . strtoupper($suffix),
        'location_code' => (string)$releaseLocation['code'],
        'qty' => 1,
    ], $actorUserId);

    $releaseStockBefore = wmsStockGet((int)$releaseProduct['id'], (int)$releaseLocation['id'], null);
    $resolvedException = wmsTaskExceptionDisposition((int)($mismatch['exception_id'] ?? 0), 'release_reservation', [
        'resolution_note' => 'Released from automated hardening regression.',
    ], $actorUserId);
    $releaseStockAfter = wmsStockGet((int)$releaseProduct['id'], (int)$releaseLocation['id'], null);

    t(
        'release reservation probe creates a task exception',
        (int)($mismatch['exception_id'] ?? 0) > 0 && ($mismatch['matched'] ?? true) === false,
        json_encode($mismatch, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''
    );
    t(
        'release reservation starts with reserved stock',
        wmsNormalizeDecimal($releaseStockBefore['qty_reserved'] ?? 0) === 1.0,
        json_encode($releaseStockBefore ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''
    );
    t(
        'release reservation resolves the exception with disposition metadata',
        ($resolvedException['status'] ?? '') === 'resolved' && ($resolvedException['disposition_type'] ?? '') === 'release_reservation',
        json_encode($resolvedException, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''
    );
    t(
        'release reservation clears the reserved quantity',
        wmsNormalizeDecimal($releaseStockAfter['qty_reserved'] ?? 0) === 0.0,
        json_encode($releaseStockAfter ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''
    );
    t(
        'release reservation picklist resolves the expected handheld location',
        ($releasePickList[0]['location_code'] ?? '') === (string)$releaseLocation['code'],
        json_encode($releasePickList[0] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''
    );

    $scannerHtml = app()->render('modules/wms/admin/scanner-home.disyl', [
        'auth_user' => ['id' => $actorUserId, 'role' => 'admin', 'source' => 'wms', 'username' => 'scanner-test'],
        'base_url' => '',
        'csrf_token' => 'csrf-test-token',
        'products' => [
            ['id' => (int)$releaseProduct['id'], 'sku' => (string)$releaseProduct['sku'], 'name' => (string)$releaseProduct['name']],
        ],
        'locations' => [
            ['id' => (int)$releaseLocation['id'], 'warehouse_id' => (int)$warehouse['id'], 'code' => (string)$releaseLocation['code'], 'name' => (string)$releaseLocation['name']],
        ],
    ]);

    t('scanner template renders disposition CTA', str_contains($scannerHtml, 'Apply Disposition'));
    t('scanner template renders advanced override controls', str_contains($scannerHtml, 'Advanced Overrides'));
    t('scanner template embeds product catalog JSON', str_contains($scannerHtml, 'productsCatalog:'));
    t(
        'scanner template targets the disposition endpoint',
        str_contains($scannerHtml, "/api/v1/wms/tasks/exceptions/' + this.dispositionException.id + '/disposition'"),
        $scannerHtml
    );
} finally {
    wmsHardeningCleanup($db);
}

$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
$errorLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';

t('no app.log critical errors', !str_contains($appLog, '[critical]'), trim($appLog));
t('no PHP errors in error.log', trim($errorLog) === '', trim($errorLog));

echo "\n══════════════════════════════════════════════════\n";
echo "  PASS: {$pass}  FAIL: {$fail}  SKIP: {$skip}\n";
echo "══════════════════════════════════════════════════\n";

if ($errors !== []) {
    echo "\nFailed tests:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

exit($fail > 0 ? 1 : 0);