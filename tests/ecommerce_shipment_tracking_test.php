<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/ecommerce/admin/orders';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/ecommerce/helpers.php';

$tenantId = (int)(moduleTenantSettingsTenantId() ?? app()->tenant()->current() ?? 0);
if ($tenantId > 0) {
    syncTenantCliMigrationsForTenant($tenantId, 'ecommerce');
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

function ecommerceShipmentTrackingTestUser(): array
{
    $row = app()->db()->query('SELECT id, email, display_name FROM cms_users ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
    if ((int)($row['id'] ?? 0) < 1) {
        throw new RuntimeException('No cms_users row available for shipment tracking test');
    }

    return $row;
}

function cleanupEcommerceShipmentTrackingFixtures(array $orderIds, array $productIds): void
{
    $db = app()->db();

    foreach ($orderIds as $orderId) {
        $orderId = (int)$orderId;
        if ($orderId < 1) {
            continue;
        }
        $db->prepare('DELETE FROM ec_order_status_history WHERE order_id = ?')->execute([$orderId]);
        $db->prepare('DELETE FROM ec_order_meta WHERE order_id = ?')->execute([$orderId]);
        $db->prepare('DELETE FROM ec_order_items WHERE order_id = ?')->execute([$orderId]);
        $db->prepare('DELETE FROM ec_payment_transactions WHERE order_id = ?')->execute([$orderId]);
        $db->prepare('DELETE FROM ec_orders WHERE id = ?')->execute([$orderId]);
    }

    foreach ($productIds as $productId) {
        $productId = (int)$productId;
        if ($productId < 1) {
            continue;
        }
        $db->prepare('DELETE FROM cms_content_meta WHERE content_id = ?')->execute([$productId]);
        $db->prepare('DELETE FROM cms_entity_capabilities WHERE entity_id = ?')->execute([$productId]);
        $db->prepare('DELETE FROM cms_content WHERE id = ?')->execute([$productId]);
    }
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== ECOMMERCE SHIPMENT TRACKING ===\n";

$user = ecommerceShipmentTrackingTestUser();
$seed = substr(bin2hex(random_bytes(4)), 0, 8);

$productId = ecProductCreate([
    'title' => 'Tracking Fixture ' . $seed,
    'slug' => 'tracking-fixture-' . strtolower($seed),
    'excerpt' => 'Shipment tracking fixture.',
    'status' => 'published',
    'price' => 80.00,
    'stock_qty' => 3,
    'track_stock' => true,
    'sku' => 'TRK-' . strtoupper($seed),
], (int)$user['id']);
$cleanupProductIds[] = $productId;

$order = ecOrderCreate([
    'cart_items' => [[
        'product_id' => $productId,
        'variant_id' => null,
        'product_title' => 'Tracking Fixture ' . $seed,
        'sku' => 'TRK-' . strtoupper($seed),
        'price_snapshot' => 80.00,
        'qty' => 1,
        'variant_label' => null,
    ]],
    'subtotal' => 80.00,
    'discount_amount' => 0.00,
    'tax_amount' => 0.00,
    'shipping_amount' => 10.00,
    'total' => 90.00,
    'currency' => 'USD',
    'coupon_code' => null,
    'shipping_rate_id' => null,
    'source' => 'web',
    'billing' => [
        'first_name' => 'Tracking',
        'last_name' => 'Tester',
        'email' => (string)$user['email'],
        'address_line1' => '123 Tracking Street',
        'city' => 'Manila',
        'state' => 'Metro Manila',
        'postal_code' => '1000',
        'country' => 'PH',
    ],
    'shipping' => [],
    'guest_email' => (string)$user['email'],
    'guest_name' => 'Tracking Tester',
    'customer_id' => (int)$user['id'],
    'customer_note' => '',
]);
$orderId = (int)$order['order_id'];
$cleanupOrderIds[] = $orderId;

ecOrderMarkPaid($orderId);
ecOrderUpdateStatus($orderId, 'processing');
$updated = ecOrderUpdateStatusWithOptions($orderId, 'shipped', 'Courier pickup complete', [
    'source' => 'ecommerce_admin',
    'actor_user_id' => (int)$user['id'],
    'tracking' => [
        'tracking_number' => 'SHIP-' . strtoupper($seed),
        'carrier' => 'Local Courier',
        'tracking_url' => 'https://tracking.example.test/' . strtolower($seed),
    ],
]);
$orderAfterShipment = ecOrderGet($orderId) ?: [];
$history = is_array($orderAfterShipment['status_history'] ?? null) ? $orderAfterShipment['status_history'] : [];
$latestHistory = end($history);
$adminTemplate = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/admin/order-detail.disyl') ?: '';
$publicTemplate = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/public/order-detail.disyl') ?: '';

t('shipped status update succeeds', $updated);
t('order hydration exposes shipment tracking', (string)($orderAfterShipment['shipment_tracking']['tracking_number'] ?? '') === 'SHIP-' . strtoupper($seed) && (string)($orderAfterShipment['shipment_tracking']['carrier'] ?? '') === 'Local Courier', json_encode($orderAfterShipment['shipment_tracking'] ?? null));
t('shipment tracking stores tracking url', (string)($orderAfterShipment['shipment_tracking']['tracking_url'] ?? '') === 'https://tracking.example.test/' . strtolower($seed), json_encode($orderAfterShipment['shipment_tracking'] ?? null));
t('status history stores shipment tracking metadata', (string)($latestHistory['meta']['tracking_number'] ?? '') === 'SHIP-' . strtoupper($seed), json_encode($latestHistory));
t('admin template exposes tracking fields', str_contains($adminTemplate, 'name="tracking_number"') && str_contains($adminTemplate, 'order.shipment_tracking.tracking_number'));
t('public template exposes shipment tracking details', str_contains($publicTemplate, 'order.shipment_tracking.tracking_number') && str_contains($publicTemplate, 'Track Shipment'));

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
t('no app.log critical errors', !str_contains($appLog, '[critical]'), $appLog !== '' ? substr($appLog, 0, 200) : '');
t('no PHP warnings or fatals in error.log', $errorLog === '' || (!str_contains($errorLog, 'PHP Warning') && !str_contains($errorLog, 'PHP Fatal')), $errorLog !== '' ? substr($errorLog, 0, 200) : '');

cleanupEcommerceShipmentTrackingFixtures($cleanupOrderIds, $cleanupProductIds);

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
