<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/ecommerce/shop';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/ecommerce/helpers.php';
require_once __DIR__ . '/../modules/wms/helpers.php';

$pass = 0;
$fail = 0;
$errors = [];

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

function ecommerceWmsInventoryTestUserId(): int
{
    static $userId = 0;
    if ($userId > 0) {
        return $userId;
    }

    $userId = (int)(app()->db()->query('SELECT id FROM cms_users ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
    if ($userId <= 0) {
        throw new RuntimeException('No cms_users row available for ecommerce WMS inventory authority test');
    }

    return $userId;
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== ECOMMERCE WMS INVENTORY AUTHORITY ===\n";

$db = app()->db();
$runner = new \Ikabud\Kernel\Database\MigrationRunner($db);
tenantSyncKernelMigrations($db);
$runner->migrate('wms');
$runner->migrate('ecommerce');
loadModuleRoutes([
    'GET' => [],
    'POST' => [],
    'PUT' => [],
    'DELETE' => [],
]);

$suffix = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
$bridgeName = 'test_inventory_authority_' . strtolower($suffix);
$warehouseCode = 'IAW-' . $suffix;
$locationCode = 'IAL-' . $suffix;
$sku = 'IA-SKU-' . $suffix;
$originalEcommerceSettings = getModuleSettings('ecommerce');
saveModuleSettings('ecommerce', array_merge(
    is_array($originalEcommerceSettings) ? $originalEcommerceSettings : [],
    ['low_stock_threshold' => 10]
));
$productId = 0;
$warehouseId = 0;
$locationId = 0;
$wmsProductId = 0;

try {
    \Ikabud\Kernel\IntegrationBridge::deleteBridgesByNames([$bridgeName]);
    ecActiveIntegrationMode(true);

    $db->prepare('DELETE FROM kernel_integration_logs WHERE integration_id IN (SELECT id FROM kernel_integrations WHERE name = ?)')->execute([$bridgeName]);
    $db->prepare('DELETE FROM kernel_integrations WHERE name = ?')->execute([$bridgeName]);

    $db->prepare('INSERT INTO wms_warehouses (code, name, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())')
        ->execute([$warehouseCode, 'Inventory Authority Warehouse ' . $suffix]);
    $warehouseId = (int)$db->lastInsertId();

    $db->prepare(
        'INSERT INTO wms_locations (warehouse_id, parent_id, code, name, type, capacity, capacity_unit, sort_order, is_active, created_at, updated_at) '
        . 'VALUES (?, NULL, ?, ?, ?, NULL, NULL, 0, 1, NOW(), NOW())'
    )->execute([$warehouseId, $locationCode, 'Inventory Authority Location ' . $suffix, 'bin']);
    $locationId = (int)$db->lastInsertId();

    $db->prepare(
        'INSERT INTO wms_products (sku, barcode, name, unit, product_type, is_batch_tracked, is_active, created_at, updated_at) '
        . 'VALUES (?, ?, ?, ?, ?, 0, 1, NOW(), NOW())'
    )->execute([$sku, $sku, 'Inventory Authority Product ' . $suffix, 'pcs', 'physical']);
    $wmsProductId = (int)$db->lastInsertId();

    $db->prepare(
        'INSERT INTO wms_stocks (product_id, warehouse_id, location_id, batch_id, qty_on_hand, qty_reserved, qty_staged, updated_at) '
        . 'VALUES (?, ?, ?, NULL, ?, ?, ?, NOW())'
    )->execute([$wmsProductId, $warehouseId, $locationId, 10, 3, 0]);

    $productId = ecProductCreate([
        'title' => 'Inventory Authority Product ' . $suffix,
        'sku' => $sku,
        'status' => 'published',
        'track_stock' => true,
        'stock_qty' => 99,
    ], ecommerceWmsInventoryTestUserId());

    ecActiveIntegrationMode(true);
    $localInventory = ecProductInventory($productId);
    t('local ecommerce inventory remains the source when no integration mode is active', (int)($localInventory['stock_qty'] ?? -1) === 99, json_encode($localInventory, JSON_UNESCAPED_SLASHES));
    $localList = ecProductList(['status' => 'published', 'limit' => 100, 'offset' => 0]);
    $localListItem = null;
    foreach (($localList['items'] ?? []) as $row) {
        if ((int)($row['id'] ?? 0) === $productId) {
            $localListItem = $row;
            break;
        }
    }
    t('product list keeps local ecommerce stock when no integration mode is active', (int)($localListItem['inventory']['stock_qty'] ?? -1) === 99, json_encode($localListItem, JSON_UNESCAPED_SLASHES));
    $localReport = ecReportInventory();
    t('low-stock report does not flag the product before WMS-authoritative mode is active', count(array_filter($localReport['items'] ?? [], static fn(array $row): bool => (int)($row['id'] ?? 0) === $productId)) === 0, json_encode($localReport, JSON_UNESCAPED_SLASHES));
    $localPosRows = ecPosProductSearch($sku, 10);
    $localPosRow = null;
    foreach ($localPosRows as $row) {
        if ((int)($row['id'] ?? 0) === $productId) {
            $localPosRow = $row;
            break;
        }
    }
    t('POS search returns local ecommerce stock before WMS-authoritative mode is active', (int)($localPosRow['stock_qty'] ?? -1) === 99, json_encode($localPosRow, JSON_UNESCAPED_SLASHES));

    \Ikabud\Kernel\IntegrationBridge::upsertBridge([
        'name' => $bridgeName,
        'trigger_event' => 'wms.product.updated',
        'target_capability' => 'ecommerce.product.upsert@1',
        'integration_mode' => 'wms_authoritative_products',
        'mapping' => [
            'sku' => '{{sku}}',
            'title' => '{{name}}',
        ],
    ]);

    ecActiveIntegrationMode(true);
    $authoritativeInventory = ecProductInventory($productId);
    t('WMS-authoritative mode overlays ecommerce stock quantity with WMS available qty', (int)($authoritativeInventory['stock_qty'] ?? -1) === 7, json_encode($authoritativeInventory, JSON_UNESCAPED_SLASHES));
    t('WMS-authoritative mode exposes qty_on_hand from WMS', abs((float)($authoritativeInventory['qty_on_hand'] ?? -1) - 10.0) < 0.0001, json_encode($authoritativeInventory, JSON_UNESCAPED_SLASHES));
    t('WMS-authoritative mode exposes qty_reserved from WMS', abs((float)($authoritativeInventory['qty_reserved'] ?? -1) - 3.0) < 0.0001, json_encode($authoritativeInventory, JSON_UNESCAPED_SLASHES));
    $authoritativeList = ecProductList(['status' => 'published', 'limit' => 100, 'offset' => 0]);
    $authoritativeListItem = null;
    foreach (($authoritativeList['items'] ?? []) as $row) {
        if ((int)($row['id'] ?? 0) === $productId) {
            $authoritativeListItem = $row;
            break;
        }
    }
    t('product list reflects WMS-authoritative stock quantities', (int)($authoritativeListItem['inventory']['stock_qty'] ?? -1) === 7 && (string)($authoritativeListItem['inventory']['source'] ?? '') === 'wms', json_encode($authoritativeListItem, JSON_UNESCAPED_SLASHES));

    $providerInventory = ec_cap_inventory_data_1([
        'entity' => ['id' => $productId, 'type' => 'product'],
        'config' => [],
        'entity_id' => $productId,
    ]);
    t('inventory capability provider also reflects WMS authoritative stock', (int)($providerInventory['stock_qty'] ?? -1) === 7, json_encode($providerInventory, JSON_UNESCAPED_SLASHES));
    $authoritativeReport = ecReportInventory();
    $authoritativeReportRow = null;
    foreach (($authoritativeReport['items'] ?? []) as $row) {
        if ((int)($row['id'] ?? 0) === $productId) {
            $authoritativeReportRow = $row;
            break;
        }
    }
    t('low-stock report reflects WMS-authoritative stock quantities', (int)($authoritativeReportRow['stock_qty'] ?? -1) === 7 && (string)($authoritativeReportRow['source'] ?? '') === 'wms', json_encode($authoritativeReportRow, JSON_UNESCAPED_SLASHES));
    $authoritativePosRows = ecPosProductSearch($sku, 10);
    $authoritativePosRow = null;
    foreach ($authoritativePosRows as $row) {
        if ((int)($row['id'] ?? 0) === $productId) {
            $authoritativePosRow = $row;
            break;
        }
    }
    t('POS search reflects WMS-authoritative stock quantities', (int)($authoritativePosRow['stock_qty'] ?? -1) === 7, json_encode($authoritativePosRow, JSON_UNESCAPED_SLASHES));

    \Ikabud\Kernel\IntegrationBridge::deleteBridgesByNames([$bridgeName]);
    ecActiveIntegrationMode(true);
    $fallbackInventory = ecProductInventory($productId);
    t('inventory falls back to ecommerce capability data after managed mode bridge removal', (int)($fallbackInventory['stock_qty'] ?? -1) === 99, json_encode($fallbackInventory, JSON_UNESCAPED_SLASHES));
    $fallbackList = ecProductList(['status' => 'published', 'limit' => 100, 'offset' => 0]);
    $fallbackListItem = null;
    foreach (($fallbackList['items'] ?? []) as $row) {
        if ((int)($row['id'] ?? 0) === $productId) {
            $fallbackListItem = $row;
            break;
        }
    }
    t('product list falls back to ecommerce inventory data after managed mode bridge removal', (int)($fallbackListItem['inventory']['stock_qty'] ?? -1) === 99, json_encode($fallbackListItem, JSON_UNESCAPED_SLASHES));
    $fallbackReport = ecReportInventory();
    t('low-stock report falls back to ecommerce inventory data after managed mode bridge removal', count(array_filter($fallbackReport['items'] ?? [], static fn(array $row): bool => (int)($row['id'] ?? 0) === $productId)) === 0, json_encode($fallbackReport, JSON_UNESCAPED_SLASHES));
} finally {
    \Ikabud\Kernel\IntegrationBridge::deleteBridgesByNames([$bridgeName]);
    ecActiveIntegrationMode(true);
    $db->prepare('DELETE FROM kernel_integration_logs WHERE integration_id IN (SELECT id FROM kernel_integrations WHERE name = ?)')->execute([$bridgeName]);
    $db->prepare('DELETE FROM kernel_integrations WHERE name = ?')->execute([$bridgeName]);

    if ($productId > 0) {
        $db->prepare('DELETE FROM cms_content_meta WHERE content_id = ?')->execute([$productId]);
        $db->prepare('DELETE FROM cms_entity_capabilities WHERE entity_id = ?')->execute([$productId]);
        $db->prepare('DELETE FROM cms_content WHERE id = ?')->execute([$productId]);
    }
    if ($wmsProductId > 0) {
        $db->prepare('DELETE FROM wms_stocks WHERE product_id = ?')->execute([$wmsProductId]);
        $db->prepare('DELETE FROM wms_products WHERE id = ?')->execute([$wmsProductId]);
    }
    if ($locationId > 0) {
        $db->prepare('DELETE FROM wms_locations WHERE id = ?')->execute([$locationId]);
    }
    if ($warehouseId > 0) {
        $db->prepare('DELETE FROM wms_warehouses WHERE id = ?')->execute([$warehouseId]);
    }
    saveModuleSettings('ecommerce', is_array($originalEcommerceSettings) ? $originalEcommerceSettings : []);
}

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
t('no app.log critical errors', !str_contains($appLog, '[critical]'), $appLog !== '' ? substr($appLog, 0, 200) : '');
t('no PHP errors in error.log', $errorLog === '', $errorLog !== '' ? substr($errorLog, 0, 200) : '');

echo "\n════════════════════════════════════════════\n";
echo "  Results: {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "\n  Failures:\n";
    foreach ($errors as $error) {
        echo "    ✗ {$error}\n";
    }
}
echo "════════════════════════════════════════════\n\n";

exit($fail > 0 ? 1 : 0);