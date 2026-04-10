<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/ecommerce/admin/orders';

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

$refundMigration = __DIR__ . '/../modules/ecommerce/database/migrations/016_ec_refunds.sql';
if (is_file($refundMigration)) {
    app()->db()->exec((string)file_get_contents($refundMigration));
}

$pass = 0;
$fail = 0;
$errors = [];
$cleanupOrderIds = [];
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

function ecommerceWmsBridgeExtensionsUser(): array
{
    $row = app()->db()->query('SELECT id, email, display_name FROM cms_users ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
    if ((int)($row['id'] ?? 0) < 1) {
        throw new RuntimeException('No cms_users row available for ecommerce WMS bridge extension test');
    }

    return $row;
}

function cleanupEcommerceWmsBridgeExtensionFixtures(array $productIds, array $orderIds): void
{
    $db = app()->db();
    foreach ($orderIds as $orderId) {
        $db->prepare('DELETE FROM ec_refund_items WHERE refund_id IN (SELECT id FROM ec_refunds WHERE order_id = ?)')->execute([(int)$orderId]);
        $db->prepare('DELETE FROM ec_refunds WHERE order_id = ?')->execute([(int)$orderId]);
        $db->prepare('DELETE FROM ec_order_status_history WHERE order_id = ?')->execute([(int)$orderId]);
        $db->prepare('DELETE FROM ec_order_meta WHERE order_id = ?')->execute([(int)$orderId]);
        $db->prepare('DELETE FROM ec_order_items WHERE order_id = ?')->execute([(int)$orderId]);
        $db->prepare('DELETE FROM ec_payment_transactions WHERE order_id = ?')->execute([(int)$orderId]);
        $db->prepare('DELETE FROM ec_orders WHERE id = ?')->execute([(int)$orderId]);
    }
    foreach ($productIds as $productId) {
        $db->prepare('DELETE FROM cms_content_meta WHERE content_id = ?')->execute([(int)$productId]);
        $db->prepare('DELETE FROM cms_entity_capabilities WHERE entity_id = ?')->execute([(int)$productId]);
        $db->prepare('DELETE FROM cms_content WHERE id = ?')->execute([(int)$productId]);
    }
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== ECOMMERCE WMS BRIDGE EXTENSIONS ===\n";

$user = ecommerceWmsBridgeExtensionsUser();
$seed = substr(bin2hex(random_bytes(4)), 0, 8);
$definitions = ecWmsFulfillmentBridgeDefinitions();
$managedNames = ecWmsFulfillmentManagedBridgeNames();
$capabilityHandlers = ecommerce_capability_handlers();

$definitionNames = array_map(static fn(array $definition): string => (string)($definition['name'] ?? ''), $definitions);

$productId = ecProductCreate([
    'title' => 'WMS Bridge Fixture ' . $seed,
    'slug' => 'wms-bridge-fixture-' . strtolower($seed),
    'excerpt' => 'WMS bridge extension fixture.',
    'status' => 'published',
    'price' => 35.00,
    'stock_qty' => 3,
    'track_stock' => true,
    'sku' => 'WMS-BRIDGE-' . strtoupper($seed),
], (int)$user['id']);
$cleanupProductIds[] = $productId;

$order = ecOrderCreate([
    'cart_items' => [[
        'product_id' => $productId,
        'variant_id' => null,
        'product_title' => 'WMS Bridge Fixture ' . $seed,
        'sku' => 'WMS-BRIDGE-' . strtoupper($seed),
        'price_snapshot' => 35.00,
        'qty' => 1,
        'variant_label' => null,
    ]],
    'subtotal' => 35.00,
    'discount_amount' => 0.00,
    'tax_amount' => 0.00,
    'shipping_amount' => 0.00,
    'total' => 35.00,
    'currency' => 'USD',
    'coupon_code' => null,
    'shipping_rate_id' => null,
    'source' => 'web',
    'billing' => [
        'first_name' => 'WMS',
        'last_name' => 'Bridge Tester',
        'email' => (string)$user['email'],
        'address_line1' => '123 WMS Street',
        'city' => 'Manila',
        'state' => 'Metro Manila',
        'postal_code' => '1000',
        'country' => 'PH',
    ],
    'shipping' => [],
    'guest_email' => (string)$user['email'],
    'guest_name' => 'WMS Bridge Tester',
    'customer_id' => (int)$user['id'],
    'customer_note' => '',
    'defer_created_event' => true,
]);
$orderId = (int)($order['order_id'] ?? 0);
$cleanupOrderIds[] = $orderId;

ecOrderMarkPaid($orderId);
$orderData = ecOrderGet($orderId) ?: [];
$orderItemId = (int)($orderData['items'][0]['id'] ?? 0);
$refund = ecOrderCreateRefund($orderId, [$orderItemId => 1], [
    'amount' => 35.00,
    'reason' => 'Bridge release item test',
    'restock_inventory' => true,
    'created_by_user_id' => (int)$user['id'],
    'skip_gateway_refund' => true,
]);
$trackingSync = ec_cap_orders_tracking_sync_1([
    'order_id' => $orderId,
    'tracking_number' => 'WMS-TRACK-' . strtoupper($seed),
    'tracking_carrier' => 'Warehouse Courier',
    'tracking_url' => 'https://wms.example.test/track/' . strtolower($seed),
    'source' => 'wms_bridge',
    'event' => 'wms.order.dispatched',
    'wms_order_id' => 2001,
    'history_key' => 'wms:2001:tracking',
]);
$trackedOrder = ecOrderGet($orderId) ?: [];

$wmsPayload = wmsOrderBridgeEventPayload(17, [
    'order_number' => 'WMS-17',
    'external_reference' => 'EC-17',
    'warehouse_id' => 5,
    'customer_name' => 'Bridge Customer',
    'status' => 'dispatched',
    'meta' => json_encode([
        'ecommerce_order_id' => $orderId,
        'ecommerce_order_number' => (string)($orderData['order_number'] ?? ''),
        'tracking_number' => 'META-TRACK-' . strtoupper($seed),
        'tracking_carrier' => 'Meta Courier',
        'tracking_url' => 'https://meta.example.test/' . strtolower($seed),
    ], JSON_UNESCAPED_SLASHES),
], 'dispatched');

$ecommerceModule = file_get_contents(__DIR__ . '/../modules/ecommerce/module.json') ?: '';
$wmsModule = file_get_contents(__DIR__ . '/../modules/wms/module.json') ?: '';

t('managed bridge names include refund release and tracking sync', in_array('ecommerce_wms_refund_release', $managedNames, true) && in_array('wms_ecommerce_tracking_sync', $managedNames, true), json_encode($managedNames));
t('bridge definitions include refund release and tracking sync mappings', in_array('ecommerce_wms_refund_release', $definitionNames, true) && in_array('wms_ecommerce_tracking_sync', $definitionNames, true), json_encode($definitionNames));
t('capability handlers expose tracking sync handler', isset($capabilityHandlers['ecommerce.orders.tracking.sync@1']));
t('refund record includes release items for WMS restock bridge', count((array)($refund['refund']['release_items'] ?? [])) === 1 && abs((float)($refund['refund']['release_items'][0]['qty'] ?? 0) - 1.0) < 0.001, json_encode($refund['refund'] ?? []));
t('tracking sync capability stores shipment tracking on the order', !empty($trackingSync['ok']) && (string)($trackedOrder['shipment_tracking']['tracking_number'] ?? '') === 'WMS-TRACK-' . strtoupper($seed), json_encode(['result' => $trackingSync, 'tracking' => $trackedOrder['shipment_tracking'] ?? null]));
t('WMS order bridge payload exposes tracking fields from meta', (string)($wmsPayload['tracking_number'] ?? '') === 'META-TRACK-' . strtoupper($seed) && (string)($wmsPayload['tracking_carrier'] ?? '') === 'Meta Courier', json_encode($wmsPayload));
t('module manifests declare the new refund event and tracking capability', str_contains($ecommerceModule, 'ecommerce.order.refunded') && str_contains($ecommerceModule, 'ecommerce.orders.tracking.sync@1') && str_contains($wmsModule, 'tracking_number'));

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
t('no app.log critical errors', !str_contains($appLog, '[critical]'), $appLog !== '' ? substr($appLog, 0, 200) : '');
t('no PHP warnings or fatals in error.log', $errorLog === '' || (!str_contains($errorLog, 'PHP Warning') && !str_contains($errorLog, 'PHP Fatal')), $errorLog !== '' ? substr($errorLog, 0, 200) : '');

cleanupEcommerceWmsBridgeExtensionFixtures($cleanupProductIds, $cleanupOrderIds);

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