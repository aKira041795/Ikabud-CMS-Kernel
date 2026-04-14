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

function ecommerceRefundsTestUser(): array
{
    $row = app()->db()->query('SELECT id, username, email, display_name, role FROM cms_users ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('No cms_users row available for ecommerce refunds test');
    }

    $row['source'] = 'cms';
    return $row;
}

function cleanupEcommerceRefundFixtures(array $productIds, array $orderIds): void
{
    $db = app()->db();

    foreach ($orderIds as $orderId) {
        $db->prepare('DELETE FROM ec_refund_items WHERE refund_id IN (SELECT id FROM ec_refunds WHERE order_id = ?)')->execute([(int)$orderId]);
        $db->prepare('DELETE FROM ec_refunds WHERE order_id = ?')->execute([(int)$orderId]);
        $db->prepare('DELETE FROM ec_order_status_history WHERE order_id = ?')->execute([(int)$orderId]);
        $db->prepare('DELETE FROM ec_order_licenses WHERE order_id = ?')->execute([(int)$orderId]);
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

echo "\n=== ECOMMERCE REFUNDS ===\n";
$user = null;
$seed = substr(bin2hex(random_bytes(4)), 0, 8);

try {
    $user = ecommerceRefundsTestUser();

    $productId = ecProductCreate([
        'title' => 'Refund Fixture ' . $seed,
        'slug' => 'refund-fixture-' . strtolower($seed),
        'excerpt' => 'Refund coverage fixture.',
        'status' => 'published',
        'price' => 50.00,
        'stock_qty' => 5,
        'track_stock' => true,
        'sku' => 'REF-' . strtoupper($seed),
    ], (int)$user['id']);
    $cleanupProductIds[] = $productId;

    $order = ecOrderCreate([
        'cart_items' => [[
            'product_id' => $productId,
            'variant_id' => null,
            'product_title' => 'Refund Fixture ' . $seed,
            'sku' => 'REF-' . strtoupper($seed),
            'price_snapshot' => 50.00,
            'qty' => 2,
            'variant_label' => null,
        ]],
        'subtotal' => 100.00,
        'discount_amount' => 0.00,
        'tax_amount' => 0.00,
        'shipping_amount' => 0.00,
        'total' => 100.00,
        'currency' => 'USD',
        'coupon_code' => null,
        'shipping_rate_id' => null,
        'source' => 'web',
        'billing' => [
            'first_name' => 'Refund',
            'last_name' => 'Tester',
            'email' => (string)$user['email'],
            'address_line1' => '123 Refund Street',
            'city' => 'Manila',
            'state' => 'Metro Manila',
            'postal_code' => '1000',
            'country' => 'PH',
        ],
        'shipping' => [],
        'guest_email' => (string)$user['email'],
        'guest_name' => 'Refund Tester',
        'customer_id' => (int)$user['id'],
        'customer_note' => '',
    ]);
    $orderId = (int)$order['order_id'];
    $cleanupOrderIds[] = $orderId;

    ecOrderMarkPaid($orderId);
    ecOrderUpdateStatus($orderId, 'processing');
    ecOrderUpdateStatus($orderId, 'shipped');
    ecOrderUpdateStatus($orderId, 'delivered');

    $initialInventory = ecProductInventory($productId);
    $createdOrder = ecOrderGet($orderId);
    $orderItemId = (int)($createdOrder['items'][0]['id'] ?? 0);

    $partialRefund = ecOrderCreateRefund($orderId, [$orderItemId => 1], [
        'amount' => 50.00,
        'reason' => 'Customer returned one item',
        'admin_note' => 'Box was damaged',
        'restock_inventory' => true,
        'skip_gateway_refund' => true,
        'created_by_user_id' => (int)$user['id'],
    ]);

    $afterPartialOrder = ecOrderGet($orderId);
    $afterPartialInventory = ecProductInventory($productId);

    $fullRefund = ecOrderCreateRefund($orderId, [$orderItemId => 1], [
        'amount' => 50.00,
        'reason' => 'Remaining balance refunded',
        'admin_note' => 'Customer requested final refund',
        'restock_inventory' => false,
        'skip_gateway_refund' => true,
        'created_by_user_id' => (int)$user['id'],
    ]);

    $afterFullOrder = ecOrderGet($orderId);
    $afterFullInventory = ecProductInventory($productId);
    $refundTransactions = ecDb()->query(
        "SELECT status, amount FROM ec_payment_transactions WHERE order_id = ? AND status = 'refunded' ORDER BY id ASC",
        [$orderId]
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $adminTemplate = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/admin/order-detail.disyl') ?: '';
    $publicTemplate = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/public/order-detail.disyl') ?: '';

    t('refund storage is available', ecRefundStorageAvailable());
    t('order creation decremented stock before refunds', (int)($initialInventory['stock_qty'] ?? 0) === 3, json_encode($initialInventory));
    t('partial refund creates a refund record', (int)($partialRefund['refund']['id'] ?? 0) > 0, json_encode($partialRefund));
    t('partial refund restocks inventory when requested', (int)($afterPartialInventory['stock_qty'] ?? 0) === 4, json_encode($afterPartialInventory));
    t('partial refund keeps order delivered while payment stays paid', (string)($afterPartialOrder['status'] ?? '') === 'delivered' && (string)($afterPartialOrder['payment_status'] ?? '') === 'paid', json_encode(['status' => $afterPartialOrder['status'] ?? null, 'payment_status' => $afterPartialOrder['payment_status'] ?? null]));
    t('partial refund updates refund summary and item refundable qty', abs((float)($afterPartialOrder['refund_summary']['total_refunded_amount'] ?? 0) - 50.00) < 0.001 && (int)($afterPartialOrder['items'][0]['refundable_qty'] ?? 0) === 1, json_encode($afterPartialOrder['refund_summary'] ?? []));
    t('full remaining refund marks order and payment as refunded', (string)($afterFullOrder['status'] ?? '') === 'refunded' && (string)($afterFullOrder['payment_status'] ?? '') === 'refunded', json_encode(['status' => $afterFullOrder['status'] ?? null, 'payment_status' => $afterFullOrder['payment_status'] ?? null]));
    t('full refund keeps stock unchanged when restock is disabled', (int)($afterFullInventory['stock_qty'] ?? 0) === 4, json_encode($afterFullInventory));
    t('refund summary reports full refund amount', abs((float)($afterFullOrder['refund_summary']['total_refunded_amount'] ?? 0) - 100.00) < 0.001 && !empty($afterFullOrder['refund_summary']['is_fully_refunded']), json_encode($afterFullOrder['refund_summary'] ?? []));
    t('status history contains refund entries', count(array_filter((array)($afterFullOrder['status_history'] ?? []), static fn(array $entry): bool => (string)($entry['status'] ?? '') === 'refunded')) >= 2);
    t('refunded payment transactions are persisted', count($refundTransactions) === 2, json_encode($refundTransactions));
    t('admin order template exposes refund form', str_contains($adminTemplate, 'name="refund_amount"'));
    t('public order template shows refund activity', str_contains($publicTemplate, 'Refund Activity'));

    $appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
    $errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
    t('no app.log critical errors', !str_contains($appLog, '[critical]'), $appLog !== '' ? substr($appLog, 0, 200) : '');
    t('no PHP warnings or fatals in error.log', $errorLog === '' || (!str_contains($errorLog, 'PHP Warning') && !str_contains($errorLog, 'PHP Fatal')), $errorLog !== '' ? substr($errorLog, 0, 200) : '');
} catch (Throwable $e) {
    t('refund regression setup and execution completes without uncaught exceptions', false, $e->getMessage());
} finally {
    cleanupEcommerceRefundFixtures($cleanupProductIds, $cleanupOrderIds);
}

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