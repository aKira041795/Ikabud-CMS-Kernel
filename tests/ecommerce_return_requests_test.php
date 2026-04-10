<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/ecommerce/my-orders';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/ecommerce/helpers.php';
require_once __DIR__ . '/../modules/wms/helpers.php';

$tenantId = (int)(moduleTenantSettingsTenantId() ?? app()->tenant()->current() ?? 0);
if ($tenantId > 0) {
    syncTenantCliMigrationsForTenant($tenantId, 'ecommerce');
    syncTenantCliMigrationsForTenant($tenantId, 'wms');
}

$returnMigration = __DIR__ . '/../modules/ecommerce/database/migrations/024_ec_return_requests.sql';
if (is_file($returnMigration)) {
    app()->db()->exec((string)file_get_contents($returnMigration));
}

$pass = 0;
$fail = 0;
$errors = [];
$cleanupProductIds = [];
$cleanupOrderIds = [];
$cleanupWarehouseIds = [];
$cleanupLocationIds = [];
$cleanupWmsProductIds = [];
$cleanupReturnRequestIds = [];
$cleanupWmsReturnIds = [];
$originalSettings = getModuleSettings('ecommerce');

function trr(string $label, bool $ok, string $detail = ''): void
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

function ecommerceReturnRequestsUser(): array
{
    $row = app()->db()->query('SELECT id, email, display_name FROM cms_users ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
    if ((int)($row['id'] ?? 0) < 1) {
        throw new RuntimeException('No cms_users row available for ecommerce return request test');
    }

    return $row;
}

function cleanupEcommerceReturnRequestFixtures(): void
{
    global $cleanupProductIds, $cleanupOrderIds, $cleanupWarehouseIds, $cleanupLocationIds, $cleanupWmsProductIds, $cleanupReturnRequestIds, $cleanupWmsReturnIds, $originalSettings;

    ecCartClear();
    $db = app()->db();

    foreach ($cleanupReturnRequestIds as $requestId) {
        $db->prepare('DELETE FROM ec_return_request_items WHERE request_id = ?')->execute([(int)$requestId]);
        $db->prepare('DELETE FROM ec_return_requests WHERE id = ?')->execute([(int)$requestId]);
    }

    foreach ($cleanupOrderIds as $orderId) {
        $db->prepare('DELETE FROM ec_order_status_history WHERE order_id = ?')->execute([(int)$orderId]);
        $db->prepare('DELETE FROM ec_order_meta WHERE order_id = ?')->execute([(int)$orderId]);
        $db->prepare('DELETE FROM ec_order_items WHERE order_id = ?')->execute([(int)$orderId]);
        $db->prepare('DELETE FROM ec_payment_transactions WHERE order_id = ?')->execute([(int)$orderId]);
        $db->prepare('DELETE FROM ec_orders WHERE id = ?')->execute([(int)$orderId]);
    }

    foreach ($cleanupProductIds as $productId) {
        $db->prepare('DELETE FROM cms_content_meta WHERE content_id = ?')->execute([(int)$productId]);
        $db->prepare('DELETE FROM cms_entity_capabilities WHERE entity_id = ?')->execute([(int)$productId]);
        $db->prepare('DELETE FROM cms_content WHERE id = ?')->execute([(int)$productId]);
    }

    foreach ($cleanupWmsReturnIds as $returnId) {
        $db->prepare('DELETE FROM wms_return_items WHERE return_id = ?')->execute([(int)$returnId]);
        $db->prepare('DELETE FROM wms_returns WHERE id = ?')->execute([(int)$returnId]);
    }
    foreach ($cleanupWmsProductIds as $productId) {
        $db->prepare('DELETE FROM wms_products WHERE id = ?')->execute([(int)$productId]);
    }
    foreach ($cleanupLocationIds as $locationId) {
        $db->prepare('DELETE FROM wms_locations WHERE id = ?')->execute([(int)$locationId]);
    }
    foreach ($cleanupWarehouseIds as $warehouseId) {
        $db->prepare('DELETE FROM wms_warehouses WHERE id = ?')->execute([(int)$warehouseId]);
    }

    saveModuleSettings('ecommerce', is_array($originalSettings) ? $originalSettings : []);
    invalidateTenantModuleSettingsCache();
    if (function_exists('ecSettingsResetCache')) {
        ecSettingsResetCache();
    }
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== ECOMMERCE RETURN REQUESTS ===\n";

$user = ecommerceReturnRequestsUser();
$seed = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
$db = app()->db();

$db->prepare('INSERT INTO wms_warehouses (code, name, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())')
    ->execute(['RET-' . $seed, 'Return Warehouse ' . $seed]);
$warehouseId = (int)$db->lastInsertId();
$cleanupWarehouseIds[] = $warehouseId;

$db->prepare('INSERT INTO wms_locations (warehouse_id, parent_id, code, name, type, capacity, capacity_unit, sort_order, is_active, created_at, updated_at) VALUES (?, NULL, ?, ?, ?, NULL, NULL, 0, 1, NOW(), NOW())')
    ->execute([$warehouseId, 'QUAR-' . $seed, 'Quarantine ' . $seed, 'quarantine']);
$locationId = (int)$db->lastInsertId();
$cleanupLocationIds[] = $locationId;
$db->prepare('UPDATE wms_warehouses SET quarantine_location_id = ?, updated_at = NOW() WHERE id = ?')->execute([$locationId, $warehouseId]);

$sku = 'RET-REQ-' . $seed;
$db->prepare('INSERT INTO wms_products (sku, barcode, name, unit, product_type, is_batch_tracked, is_active, created_at, updated_at) VALUES (?, NULL, ?, ?, ?, 0, 1, NOW(), NOW())')
    ->execute([$sku, 'Return Request Product ' . $seed, 'pcs', 'physical']);
$wmsProductId = (int)$db->lastInsertId();
$cleanupWmsProductIds[] = $wmsProductId;

saveModuleSettings('ecommerce', array_merge(is_array($originalSettings) ? $originalSettings : [], [
    'default_wms_warehouse_id' => (string)$warehouseId,
]));
invalidateTenantModuleSettingsCache();
if (function_exists('ecSettingsResetCache')) {
    ecSettingsResetCache();
}

$productId = ecProductCreate([
    'title' => 'Return Request Product ' . $seed,
    'slug' => 'return-request-product-' . strtolower($seed),
    'excerpt' => 'Return request fixture product.',
    'status' => 'published',
    'price' => 49.00,
    'stock_qty' => 5,
    'track_stock' => true,
    'sku' => $sku,
], (int)$user['id']);
$cleanupProductIds[] = $productId;

$orderResult = ecOrderCreate([
    'cart_items' => [[
        'product_id' => $productId,
        'variant_id' => null,
        'product_title' => 'Return Request Product ' . $seed,
        'sku' => $sku,
        'price_snapshot' => 49.00,
        'qty' => 2,
        'warehouse_id' => $warehouseId,
        'variant_label' => null,
    ]],
    'subtotal' => 98.00,
    'discount_amount' => 0.00,
    'tax_amount' => 0.00,
    'shipping_amount' => 0.00,
    'total' => 98.00,
    'currency' => 'USD',
    'coupon_code' => null,
    'shipping_rate_id' => null,
    'source' => 'web',
    'warehouse_id' => $warehouseId,
    'billing' => [
        'first_name' => 'Return',
        'last_name' => 'Customer',
        'email' => (string)$user['email'],
        'address_line1' => '123 Return Lane',
        'city' => 'Manila',
        'state' => 'Metro Manila',
        'postal_code' => '1000',
        'country' => 'PH',
    ],
    'shipping' => [],
    'guest_email' => (string)$user['email'],
    'guest_name' => 'Return Customer',
    'customer_id' => (int)$user['id'],
    'customer_note' => '',
]);
$orderId = (int)($orderResult['order_id'] ?? 0);
$cleanupOrderIds[] = $orderId;

ecOrderMarkPaid($orderId);
ecOrderUpdateStatusWithOptions($orderId, 'processing', 'Packed for shipment', ['source' => 'test']);
ecOrderUpdateStatusWithOptions($orderId, 'shipped', 'Shipped to customer', ['source' => 'test']);
ecOrderUpdateStatusWithOptions($orderId, 'delivered', 'Delivered successfully', ['source' => 'test']);

$order = ecOrderGet($orderId, (int)$user['id']) ?: [];
$orderItemId = (int)($order['items'][0]['id'] ?? 0);

$createResult = ecReturnRequestCreate($orderId, (int)$user['id'], [$orderItemId => 1], [
    'reason' => 'Arrived damaged',
    'condition' => 'damaged',
    'customer_note' => 'Outer box was crushed on delivery.',
]);
$requestId = (int)($createResult['request']['id'] ?? 0);
$cleanupReturnRequestIds[] = $requestId;

$reviewResult = ecReturnRequestReview($requestId, 'approved', [
    'admin_note' => 'Approved for warehouse inspection.',
    'reviewed_by_user_id' => (int)$user['id'],
]);

$hydratedOrder = ecOrderGet($orderId, (int)$user['id']) ?: [];
$returnRequest = ecReturnRequestGet($requestId) ?: [];
$customerOrders = ecCustomerOrders((int)$user['id'], 10, 0);
$listedOrder = $customerOrders['items'][0] ?? [];
$wmsReturnId = (int)($returnRequest['wms_return_id'] ?? 0);
if ($wmsReturnId > 0) {
    $cleanupWmsReturnIds[] = $wmsReturnId;
}
$wmsReturn = [];
$wmsReturnItem = [];
if ($wmsReturnId > 0) {
    $statement = $db->prepare('SELECT * FROM wms_returns WHERE id = ? LIMIT 1');
    $statement->execute([$wmsReturnId]);
    $wmsReturn = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

    $statement = $db->prepare('SELECT * FROM wms_return_items WHERE return_id = ? ORDER BY id ASC LIMIT 1');
    $statement->execute([$wmsReturnId]);
    $wmsReturnItem = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
}

$publicTemplate = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/public/order-detail.disyl') ?: '';
$adminTemplate = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/admin/order-detail.disyl') ?: '';
$returnsTemplate = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/admin/returns.disyl') ?: '';

trr('return request creation stores pending customer request with requested quantity', (string)($createResult['request']['status'] ?? '') === 'pending' && count((array)($createResult['request']['items'] ?? [])) === 1 && (int)($createResult['request']['items'][0]['qty_requested'] ?? 0) === 1, json_encode($createResult['request'] ?? []));
trr(
    'order hydration exposes return requests and remaining returnable quantity',
    !empty($hydratedOrder['has_return_requests'])
        && count((array)($hydratedOrder['return_requests'] ?? [])) === 1
        && (int)($hydratedOrder['items'][0]['returnable_qty'] ?? 0) === 1,
    json_encode([
        'has_return_requests' => $hydratedOrder['has_return_requests'] ?? null,
        'return_request_count' => count((array)($hydratedOrder['return_requests'] ?? [])),
        'first_item' => $hydratedOrder['items'][0] ?? null,
        'return_summary' => $hydratedOrder['return_summary'] ?? [],
    ])
);
trr('approving a return request syncs a pending WMS return record', !empty($reviewResult['wms_sync']['ok']) && $wmsReturnId > 0 && (string)($wmsReturn['reference_number'] ?? '') === (string)($returnRequest['request_number'] ?? '') && (string)($wmsReturn['status'] ?? '') === 'pending', json_encode(['sync' => $reviewResult['wms_sync'] ?? [], 'wms_return' => $wmsReturn]));
trr('WMS return item stores requested quantity and condition', (int)($wmsReturnItem['product_id'] ?? 0) === $wmsProductId && abs((float)($wmsReturnItem['qty_returned'] ?? 0) - 1.0) < 0.001 && (string)($wmsReturnItem['condition'] ?? '') === 'damaged', json_encode($wmsReturnItem));
trr('customer order list flags orders that have return requests', !empty($listedOrder['has_return_requests']), json_encode($listedOrder));
trr('public and admin templates expose return request UI', str_contains($publicTemplate, 'Request Return') && str_contains($adminTemplate, 'Return Requests') && str_contains($returnsTemplate, 'Return Requests'));

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
trr('no app.log critical errors', !str_contains($appLog, '[critical]'), $appLog !== '' ? substr($appLog, 0, 200) : '');
trr('no PHP warnings or fatals in error.log', $errorLog === '' || (!str_contains($errorLog, 'PHP Warning') && !str_contains($errorLog, 'PHP Fatal')), $errorLog !== '' ? substr($errorLog, 0, 200) : '');

cleanupEcommerceReturnRequestFixtures();

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