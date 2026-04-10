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
$originalSettings = getModuleSettings('ecommerce');
$stripeRequests = [];
$paypalRequests = [];

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

function ecommerceGatewayRefundReversalUser(): array
{
    $row = app()->db()->query('SELECT id, email, display_name, role FROM cms_users ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
    if ((int)($row['id'] ?? 0) < 1) {
        throw new RuntimeException('No cms_users row available for ecommerce gateway refund reversal test');
    }

    $row['source'] = 'cms';
    return $row;
}

function cleanupEcommerceGatewayRefundReversalFixtures(array $productIds, array $orderIds, array $originalSettings): void
{
    unset($GLOBALS['__ec_stripe_http_mock'], $GLOBALS['__ec_paypal_http_mock']);

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

    saveModuleSettings('ecommerce', is_array($originalSettings) ? $originalSettings : []);
    invalidateTenantModuleSettingsCache();
    if (function_exists('ecSettingsResetCache')) {
        ecSettingsResetCache();
    }
}

function ecommerceGatewayRefundCreateOrder(string $gateway, array $settings, array $gatewayTransaction, int $userId, string $seed): array
{
    saveModuleSettings('ecommerce', array_merge(is_array(getModuleSettings('ecommerce')) ? getModuleSettings('ecommerce') : [], $settings, [
        'payment_gateway' => $gateway,
        'currency' => 'USD',
        'currency_symbol' => '$',
    ]));
    invalidateTenantModuleSettingsCache();
    if (function_exists('ecSettingsResetCache')) {
        ecSettingsResetCache();
    }

    $productId = ecProductCreate([
        'title' => ucfirst($gateway) . ' Refund Fixture ' . $seed,
        'slug' => $gateway . '-refund-fixture-' . strtolower($seed),
        'excerpt' => 'Gateway refund reversal fixture.',
        'status' => 'published',
        'price' => 60.00,
        'stock_qty' => 4,
        'track_stock' => true,
        'sku' => strtoupper($gateway) . '-REF-' . strtoupper($seed),
    ], $userId);

    $user = ecommerceGatewayRefundReversalUser();
    $order = ecOrderCreate([
        'cart_items' => [[
            'product_id' => $productId,
            'variant_id' => null,
            'product_title' => ucfirst($gateway) . ' Refund Fixture ' . $seed,
            'sku' => strtoupper($gateway) . '-REF-' . strtoupper($seed),
            'price_snapshot' => 60.00,
            'qty' => 1,
            'variant_label' => null,
        ]],
        'subtotal' => 60.00,
        'discount_amount' => 0.00,
        'tax_amount' => 0.00,
        'shipping_amount' => 0.00,
        'total' => 60.00,
        'currency' => 'USD',
        'coupon_code' => null,
        'shipping_rate_id' => null,
        'source' => 'web',
        'billing' => [
            'first_name' => ucfirst($gateway),
            'last_name' => 'Refund Tester',
            'email' => (string)$user['email'],
            'address_line1' => '123 Gateway Street',
            'city' => 'Manila',
            'state' => 'Metro Manila',
            'postal_code' => '1000',
            'country' => 'PH',
        ],
        'shipping' => [],
        'guest_email' => (string)$user['email'],
        'guest_name' => ucfirst($gateway) . ' Refund Tester',
        'customer_id' => (int)$user['id'],
        'customer_note' => '',
        'defer_created_event' => true,
    ]);

    $orderId = (int)($order['order_id'] ?? 0);
    ecDb()->execute(
        'UPDATE ec_payment_transactions SET gateway = ?, payment_intent_id = ?, gateway_txn_id = ?, status = ? WHERE order_id = ? ORDER BY id DESC LIMIT 1',
        [
            $gateway,
            $gatewayTransaction['payment_intent_id'] ?? null,
            $gatewayTransaction['gateway_txn_id'] ?? null,
            'succeeded',
            $orderId,
        ]
    );
    ecOrderMarkPaid($orderId);

    return ['product_id' => $productId, 'order_id' => $orderId, 'order' => ecOrderGet($orderId) ?: []];
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== ECOMMERCE GATEWAY REFUND REVERSAL ===\n";

$user = ecommerceGatewayRefundReversalUser();
$seed = substr(bin2hex(random_bytes(4)), 0, 8);

$GLOBALS['__ec_stripe_http_mock'] = static function (string $method, string $endpoint, ?array $body = null) use (&$stripeRequests): array {
    $stripeRequests[] = ['method' => $method, 'endpoint' => $endpoint, 'body' => $body];
    if ($method === 'POST' && $endpoint === '/refunds') {
        return ['ok' => true, 'data' => ['id' => 'reversal_stripe_fixture', 'status' => 'succeeded']];
    }
    return ['ok' => false, 'error' => 'Unexpected Stripe request: ' . $method . ' ' . $endpoint];
};

$GLOBALS['__ec_paypal_http_mock'] = static function (string $method, string $endpoint, ?array $body = null, array $headers = [], bool $skipAuth = false) use (&$paypalRequests): array {
    $paypalRequests[] = ['method' => $method, 'endpoint' => $endpoint, 'body' => $body, 'skipAuth' => $skipAuth];
    if ($method === 'POST' && $endpoint === '/v1/oauth2/token') {
        return ['ok' => true, 'data' => ['access_token' => 'paypal_refund_token', 'expires_in' => 3600]];
    }
    if ($method === 'POST' && $endpoint === '/v2/payments/captures/PAYPAL-CAPTURE-REVERSAL/refund') {
        return ['ok' => true, 'data' => ['id' => 'reversal_paypal_fixture', 'status' => 'COMPLETED']];
    }
    return ['ok' => false, 'error' => 'Unexpected PayPal request: ' . $method . ' ' . $endpoint];
};

$stripeFixture = ecommerceGatewayRefundCreateOrder('stripe', [
    'stripe_secret_key' => 'sk_test_reversal',
], [
    'payment_intent_id' => 'pi_refund_reversal',
    'gateway_txn_id' => 'pi_refund_reversal',
], (int)$user['id'], $seed . 's');
$cleanupProductIds[] = (int)$stripeFixture['product_id'];
$cleanupOrderIds[] = (int)$stripeFixture['order_id'];
$stripeOrder = $stripeFixture['order'];
$stripeOrderItemId = (int)($stripeOrder['items'][0]['id'] ?? 0);
$stripeRefund = ecOrderCreateRefund((int)$stripeFixture['order_id'], [$stripeOrderItemId => 1], [
    'amount' => 60.00,
    'reason' => 'Stripe refund reversal test',
    'created_by_user_id' => (int)$user['id'],
]);

$paypalFixture = ecommerceGatewayRefundCreateOrder('paypal', [
    'paypal_client_id' => 'paypal_reversal_client',
    'paypal_secret' => 'paypal_reversal_secret',
], [
    'payment_intent_id' => 'PAYPAL-ORDER-REVERSAL',
    'gateway_txn_id' => 'PAYPAL-CAPTURE-REVERSAL',
], (int)$user['id'], $seed . 'p');
$cleanupProductIds[] = (int)$paypalFixture['product_id'];
$cleanupOrderIds[] = (int)$paypalFixture['order_id'];
$paypalOrder = $paypalFixture['order'];
$paypalOrderItemId = (int)($paypalOrder['items'][0]['id'] ?? 0);
$paypalRefund = ecOrderCreateRefund((int)$paypalFixture['order_id'], [$paypalOrderItemId => 1], [
    'amount' => 60.00,
    'reason' => 'PayPal refund reversal test',
    'created_by_user_id' => (int)$user['id'],
]);

$stripeRefundRow = ecDb()->query('SELECT gateway_refund_id FROM ec_refunds WHERE order_id = ? ORDER BY id DESC LIMIT 1', [(int)$stripeFixture['order_id']])->fetch(PDO::FETCH_ASSOC) ?: [];
$paypalRefundRow = ecDb()->query('SELECT gateway_refund_id FROM ec_refunds WHERE order_id = ? ORDER BY id DESC LIMIT 1', [(int)$paypalFixture['order_id']])->fetch(PDO::FETCH_ASSOC) ?: [];

t('stripe refund flow stores the external refund id on the refund record', (string)($stripeRefund['refund']['gateway_refund_id'] ?? '') === 'reversal_stripe_fixture' && (string)($stripeRefundRow['gateway_refund_id'] ?? '') === 'reversal_stripe_fixture', json_encode($stripeRefund));
t('paypal refund flow stores the external refund id on the refund record', (string)($paypalRefund['refund']['gateway_refund_id'] ?? '') === 'reversal_paypal_fixture' && (string)($paypalRefundRow['gateway_refund_id'] ?? '') === 'reversal_paypal_fixture', json_encode($paypalRefund));
t('stripe gateway reversal called the refund endpoint', count(array_filter($stripeRequests, static fn(array $request): bool => $request['endpoint'] === '/refunds')) === 1, json_encode($stripeRequests));
t('paypal gateway reversal called the capture refund endpoint', count(array_filter($paypalRequests, static fn(array $request): bool => $request['endpoint'] === '/v2/payments/captures/PAYPAL-CAPTURE-REVERSAL/refund')) === 1, json_encode($paypalRequests));

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
t('no app.log critical errors', !str_contains($appLog, '[critical]'), $appLog !== '' ? substr($appLog, 0, 200) : '');
t('no PHP warnings or fatals in error.log', $errorLog === '' || (!str_contains($errorLog, 'PHP Warning') && !str_contains($errorLog, 'PHP Fatal')), $errorLog !== '' ? substr($errorLog, 0, 200) : '');

cleanupEcommerceGatewayRefundReversalFixtures($cleanupProductIds, $cleanupOrderIds, is_array($originalSettings) ? $originalSettings : []);

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