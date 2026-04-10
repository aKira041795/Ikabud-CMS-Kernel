<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/ecommerce/checkout';

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
$originalSettings = getModuleSettings('ecommerce');
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

function ecommercePaypalGatewayTestUser(): array
{
    $row = app()->db()->query('SELECT id, email, display_name FROM cms_users ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
    if ((int)($row['id'] ?? 0) < 1) {
        throw new RuntimeException('No cms_users row available for ecommerce PayPal gateway test');
    }

    $row['source'] = 'cms';
    return $row;
}

function cleanupEcommercePaypalGatewayFixtures(array $productIds, array $orderIds, array $originalSettings): void
{
    unset($GLOBALS['__ec_paypal_http_mock']);

    $db = app()->db();
    foreach ($orderIds as $orderId) {
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

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== ECOMMERCE PAYPAL GATEWAY ===\n";

$user = ecommercePaypalGatewayTestUser();
$seed = substr(bin2hex(random_bytes(4)), 0, 8);

saveModuleSettings('ecommerce', array_merge(is_array($originalSettings) ? $originalSettings : [], [
    'payment_gateway' => 'paypal',
    'payment_gateway_mode' => 'sandbox',
    'paypal_client_id' => 'paypal_client_fixture',
    'paypal_secret' => 'paypal_secret_fixture',
    'paypal_webhook_id' => 'wh_paypal_fixture',
    'currency' => 'USD',
    'currency_symbol' => '$',
]));
invalidateTenantModuleSettingsCache();
if (function_exists('ecSettingsResetCache')) {
    ecSettingsResetCache();
}

$GLOBALS['__ec_paypal_http_mock'] = static function (string $method, string $endpoint, ?array $body = null, array $headers = [], bool $skipAuth = false) use (&$paypalRequests): array {
    $paypalRequests[] = ['method' => $method, 'endpoint' => $endpoint, 'body' => $body, 'skipAuth' => $skipAuth];

    if ($method === 'POST' && $endpoint === '/v1/oauth2/token') {
        return ['ok' => true, 'data' => ['access_token' => 'paypal_access_fixture', 'expires_in' => 3600]];
    }

    if ($method === 'POST' && $endpoint === '/v2/checkout/orders') {
        return ['ok' => true, 'data' => [
            'id' => 'PAYPAL-ORDER-FIXTURE',
            'status' => 'CREATED',
            'links' => [
                ['rel' => 'approve', 'href' => 'https://paypal.test/checkoutnow?token=PAYPAL-ORDER-FIXTURE'],
            ],
        ]];
    }

    if ($method === 'GET' && $endpoint === '/v2/checkout/orders/PAYPAL-ORDER-FIXTURE') {
        return ['ok' => true, 'data' => [
            'id' => 'PAYPAL-ORDER-FIXTURE',
            'status' => 'APPROVED',
            'purchase_units' => [[
                'payments' => ['captures' => []],
            ]],
        ]];
    }

    if ($method === 'POST' && $endpoint === '/v2/checkout/orders/PAYPAL-ORDER-FIXTURE/capture') {
        return ['ok' => true, 'data' => [
            'id' => 'PAYPAL-ORDER-FIXTURE',
            'status' => 'COMPLETED',
            'purchase_units' => [[
                'payments' => [
                    'captures' => [[
                        'id' => 'PAYPAL-CAPTURE-FIXTURE',
                        'status' => 'COMPLETED',
                    ]],
                ],
            ]],
        ]];
    }

    if ($method === 'POST' && $endpoint === '/v1/notifications/verify-webhook-signature') {
        return ['ok' => true, 'data' => ['verification_status' => 'SUCCESS']];
    }

    if ($method === 'POST' && $endpoint === '/v2/payments/captures/PAYPAL-CAPTURE-FIXTURE/refund') {
        return ['ok' => true, 'data' => ['id' => 'PAYPAL-REFUND-FIXTURE', 'status' => 'COMPLETED']];
    }

    return ['ok' => false, 'error' => 'Unexpected PayPal request: ' . $method . ' ' . $endpoint];
};

$productId = ecProductCreate([
    'title' => 'PayPal Fixture ' . $seed,
    'slug' => 'paypal-fixture-' . strtolower($seed),
    'excerpt' => 'PayPal gateway fixture.',
    'status' => 'published',
    'price' => 90.00,
    'stock_qty' => 2,
    'track_stock' => true,
    'sku' => 'PAYPAL-' . strtoupper($seed),
], (int)$user['id']);
$cleanupProductIds[] = $productId;

$order = ecOrderCreate([
    'cart_items' => [[
        'product_id' => $productId,
        'variant_id' => null,
        'product_title' => 'PayPal Fixture ' . $seed,
        'sku' => 'PAYPAL-' . strtoupper($seed),
        'price_snapshot' => 90.00,
        'qty' => 1,
        'variant_label' => null,
    ]],
    'subtotal' => 90.00,
    'discount_amount' => 0.00,
    'tax_amount' => 0.00,
    'shipping_amount' => 0.00,
    'total' => 90.00,
    'currency' => 'USD',
    'coupon_code' => null,
    'shipping_rate_id' => null,
    'source' => 'web',
    'billing' => [
        'first_name' => 'PayPal',
        'last_name' => 'Tester',
        'email' => (string)$user['email'],
        'address_line1' => '123 PayPal Street',
        'city' => 'Manila',
        'state' => 'Metro Manila',
        'postal_code' => '1000',
        'country' => 'PH',
    ],
    'shipping' => [],
    'guest_email' => (string)$user['email'],
    'guest_name' => 'PayPal Tester',
    'customer_id' => (int)$user['id'],
    'customer_note' => '',
    'defer_created_event' => true,
]);
$orderId = (int)($order['order_id'] ?? 0);
$cleanupOrderIds[] = $orderId;

$intent = ecPaymentGatewayCreateIntent($orderId, 90.00, 'USD', [
    'description' => 'Order ' . ($order['order_number'] ?? ''),
    'customer_email' => (string)$user['email'],
    'return_url' => 'https://cmsnew.test/ecommerce/payment/return?order_id=' . $orderId . '&token=' . urlencode((string)($order['confirmation_token'] ?? '')),
]);

$transaction = ecDb()->query('SELECT * FROM ec_payment_transactions WHERE order_id = ? ORDER BY id DESC LIMIT 1', [$orderId])->fetch(PDO::FETCH_ASSOC) ?: [];
$verify = ecPaymentGatewayVerify('PAYPAL-ORDER-FIXTURE', ['gateway' => 'paypal', 'order' => ['id' => $orderId], 'capture' => true]);

$webhookPayload = json_encode([
    'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
    'resource' => [
        'id' => 'PAYPAL-CAPTURE-FIXTURE',
        'supplementary_data' => [
            'related_ids' => ['order_id' => 'PAYPAL-ORDER-FIXTURE'],
        ],
    ],
], JSON_UNESCAPED_SLASHES);
$webhookHeaders = json_encode([
    'paypal-transmission-id' => 'transmission-fixture',
    'paypal-transmission-time' => gmdate('c'),
    'paypal-transmission-sig' => 'signature-fixture',
    'paypal-cert-url' => 'https://paypal.test/cert.pem',
    'paypal-auth-algo' => 'SHA256withRSA',
], JSON_UNESCAPED_SLASHES);
$webhookResult = ecPaymentGatewayWebhookHandle('paypal', (string)$webhookPayload, (string)$webhookHeaders);
$paidOrder = ecOrderGet($orderId) ?: [];
$refundResult = ecPaymentGatewayRefund($orderId, 25.00, 'USD', ['reason' => 'Customer requested a partial refund']);

$settingsTemplate = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/admin/settings.disyl') ?: '';
$routesFile = file_get_contents(__DIR__ . '/../modules/ecommerce/routes.php') ?: '';

t('paypal intent creation returns approval url', !empty($intent['ok']) && (string)($intent['checkout_url'] ?? '') === 'https://paypal.test/checkoutnow?token=PAYPAL-ORDER-FIXTURE', json_encode($intent));
t('paypal intent creation updates pending payment transaction', (string)($transaction['gateway'] ?? '') === 'paypal' && (string)($transaction['payment_intent_id'] ?? '') === 'PAYPAL-ORDER-FIXTURE', json_encode($transaction));
t('paypal verify captures the approved order and returns succeeded status', !empty($verify['ok']) && (string)($verify['status'] ?? '') === 'succeeded' && (string)($verify['gateway_txn_id'] ?? '') === 'PAYPAL-CAPTURE-FIXTURE', json_encode($verify));
t('paypal webhook marks the order as paid', !empty($webhookResult['ok']) && (int)($webhookResult['order_id'] ?? 0) === $orderId && (string)($paidOrder['payment_status'] ?? '') === 'paid', json_encode(['webhook' => $webhookResult, 'payment_status' => $paidOrder['payment_status'] ?? null]));
t('paypal refund helper submits a refund against the stored capture id', !empty($refundResult['ok']) && (string)($refundResult['refund_id'] ?? '') === 'PAYPAL-REFUND-FIXTURE', json_encode($refundResult));
t('paypal return url is rewritten to preserve confirmation token separately', str_contains((string)($intent['return_url'] ?? ''), 'confirmation_token='), json_encode($intent));
t('settings template exposes PayPal fields', str_contains($settingsTemplate, 'name="paypal_client_id"') && str_contains($settingsTemplate, 'name="paypal_webhook_id"'));
t('routes file exposes PayPal webhook endpoint', str_contains($routesFile, '/api/v1/ecommerce/webhooks/paypal'));

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
t('no app.log critical errors', !str_contains($appLog, '[critical]'), $appLog !== '' ? substr($appLog, 0, 200) : '');
t('no PHP warnings or fatals in error.log', $errorLog === '' || (!str_contains($errorLog, 'PHP Warning') && !str_contains($errorLog, 'PHP Fatal')), $errorLog !== '' ? substr($errorLog, 0, 200) : '');

cleanupEcommercePaypalGatewayFixtures($cleanupProductIds, $cleanupOrderIds, is_array($originalSettings) ? $originalSettings : []);

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