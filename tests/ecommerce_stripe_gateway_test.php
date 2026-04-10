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
$stripeRequests = [];

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

function ecommerceStripeGatewayTestUser(): array
{
    $row = app()->db()->query('SELECT id, email, display_name FROM cms_users ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
    if ((int)($row['id'] ?? 0) < 1) {
        throw new RuntimeException('No cms_users row available for ecommerce Stripe gateway test');
    }

    $row['source'] = 'cms';
    return $row;
}

function cleanupEcommerceStripeGatewayFixtures(array $productIds, array $orderIds, array $originalSettings): void
{
    unset($GLOBALS['__ec_stripe_http_mock']);

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

echo "\n=== ECOMMERCE STRIPE GATEWAY ===\n";

$user = ecommerceStripeGatewayTestUser();
$seed = substr(bin2hex(random_bytes(4)), 0, 8);

saveModuleSettings('ecommerce', array_merge(is_array($originalSettings) ? $originalSettings : [], [
    'payment_gateway' => 'stripe',
    'payment_gateway_mode' => 'sandbox',
    'stripe_publishable_key' => 'pk_test_fixture',
    'stripe_secret_key' => 'sk_test_fixture',
    'stripe_webhook_secret' => 'whsec_fixture',
    'stripe_allowed_payment_methods' => 'card,link',
    'currency' => 'USD',
    'currency_symbol' => '$',
]));
invalidateTenantModuleSettingsCache();
if (function_exists('ecSettingsResetCache')) {
    ecSettingsResetCache();
}

$GLOBALS['__ec_stripe_http_mock'] = static function (string $method, string $endpoint, ?array $body = null) use (&$stripeRequests): array {
    $stripeRequests[] = ['method' => $method, 'endpoint' => $endpoint, 'body' => $body];

    if ($method === 'POST' && $endpoint === '/checkout/sessions') {
        return [
            'ok' => true,
            'data' => [
                'id' => 'cs_test_fixture',
                'url' => 'https://checkout.stripe.test/cs_test_fixture',
                'payment_intent' => 'pi_test_fixture',
                'status' => 'open',
                'payment_status' => 'unpaid',
            ],
        ];
    }

    if ($method === 'GET' && $endpoint === '/checkout/sessions/cs_test_fixture') {
        return [
            'ok' => true,
            'data' => [
                'id' => 'cs_test_fixture',
                'payment_intent' => 'pi_test_fixture',
                'status' => 'complete',
                'payment_status' => 'paid',
            ],
        ];
    }

    if ($method === 'GET' && $endpoint === '/payment_intents/pi_test_fixture') {
        return [
            'ok' => true,
            'data' => [
                'id' => 'pi_test_fixture',
                'status' => 'succeeded',
                'latest_charge' => 'ch_test_fixture',
            ],
        ];
    }

    if ($method === 'POST' && $endpoint === '/refunds') {
        return [
            'ok' => true,
            'data' => [
                'id' => 're_test_fixture',
                'status' => 'succeeded',
                'payment_intent' => 'pi_test_fixture',
            ],
        ];
    }

    return ['ok' => false, 'error' => 'Unexpected Stripe request: ' . $method . ' ' . $endpoint];
};

$productId = ecProductCreate([
    'title' => 'Stripe Fixture ' . $seed,
    'slug' => 'stripe-fixture-' . strtolower($seed),
    'excerpt' => 'Stripe gateway fixture.',
    'status' => 'published',
    'price' => 100.00,
    'stock_qty' => 3,
    'track_stock' => true,
    'sku' => 'STRIPE-' . strtoupper($seed),
], (int)$user['id']);
$cleanupProductIds[] = $productId;

$order = ecOrderCreate([
    'cart_items' => [[
        'product_id' => $productId,
        'variant_id' => null,
        'product_title' => 'Stripe Fixture ' . $seed,
        'sku' => 'STRIPE-' . strtoupper($seed),
        'price_snapshot' => 100.00,
        'qty' => 1,
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
        'first_name' => 'Stripe',
        'last_name' => 'Tester',
        'email' => (string)$user['email'],
        'address_line1' => '123 Stripe Street',
        'city' => 'Manila',
        'state' => 'Metro Manila',
        'postal_code' => '1000',
        'country' => 'PH',
    ],
    'shipping' => [],
    'guest_email' => (string)$user['email'],
    'guest_name' => 'Stripe Tester',
    'customer_id' => (int)$user['id'],
    'customer_note' => '',
    'defer_created_event' => true,
]);
$orderId = (int)($order['order_id'] ?? 0);
$cleanupOrderIds[] = $orderId;

$intent = ecPaymentGatewayCreateIntent($orderId, 100.00, 'USD', [
    'description' => 'Order ' . ($order['order_number'] ?? ''),
    'customer_email' => (string)$user['email'],
    'return_url' => 'https://cmsnew.test/ecommerce/payment/return?order_id=' . $orderId . '&token=' . urlencode((string)($order['confirmation_token'] ?? '')),
]);

$transaction = ecDb()->query('SELECT * FROM ec_payment_transactions WHERE order_id = ? ORDER BY id DESC LIMIT 1', [$orderId])->fetch(PDO::FETCH_ASSOC) ?: [];
$verify = ecPaymentGatewayVerify('cs_test_fixture', ['gateway' => 'stripe']);

$webhookPayload = json_encode([
    'id' => 'evt_test_fixture',
    'type' => 'checkout.session.completed',
    'data' => [
        'object' => [
            'id' => 'cs_test_fixture',
            'payment_status' => 'paid',
            'status' => 'complete',
            'payment_intent' => 'pi_test_fixture',
            'metadata' => [
                'order_id' => (string)$orderId,
                'order_number' => (string)($order['order_number'] ?? ''),
            ],
        ],
    ],
], JSON_UNESCAPED_SLASHES);
$timestamp = time();
$signature = 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $webhookPayload, 'whsec_fixture');
$webhookResult = ecPaymentGatewayWebhookHandle('stripe', (string)$webhookPayload, $signature);
$paidOrder = ecOrderGet($orderId) ?: [];
$refundResult = ecPaymentGatewayRefund($orderId, 40.00, 'USD', ['reason' => 'Customer requested a partial refund']);

$settingsTemplate = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/admin/settings.disyl') ?: '';
$routesFile = file_get_contents(__DIR__ . '/../modules/ecommerce/routes.php') ?: '';

t('stripe intent creation returns hosted checkout url', !empty($intent['ok']) && (string)($intent['checkout_url'] ?? '') === 'https://checkout.stripe.test/cs_test_fixture', json_encode($intent));
t('stripe intent creation updates pending payment transaction', (string)($transaction['gateway'] ?? '') === 'stripe' && (string)($transaction['payment_intent_id'] ?? '') === 'pi_test_fixture' && (string)($transaction['client_key'] ?? '') === 'cs_test_fixture', json_encode($transaction));
t('stripe verify maps completed session to succeeded status', !empty($verify['ok']) && (string)($verify['status'] ?? '') === 'succeeded' && (string)($verify['gateway_txn_id'] ?? '') === 'pi_test_fixture', json_encode($verify));
t('stripe webhook marks the order as paid', !empty($webhookResult['ok']) && (int)($webhookResult['order_id'] ?? 0) === $orderId && (string)($paidOrder['payment_status'] ?? '') === 'paid', json_encode(['webhook' => $webhookResult, 'payment_status' => $paidOrder['payment_status'] ?? null]));
t('stripe refund helper submits a refund against the stored payment intent', !empty($refundResult['ok']) && (string)($refundResult['refund_id'] ?? '') === 're_test_fixture', json_encode($refundResult));
t('stripe checkout session request includes configured payment methods', isset($stripeRequests[0]['body']['payment_method_types']) && (array)$stripeRequests[0]['body']['payment_method_types'] === ['card', 'link'], json_encode($stripeRequests[0]['body'] ?? []));
t('settings template exposes Stripe fields', str_contains($settingsTemplate, 'name="stripe_secret_key"') && str_contains($settingsTemplate, 'name="stripe_webhook_secret"'));
t('routes file exposes Stripe webhook endpoint', str_contains($routesFile, '/api/v1/ecommerce/webhooks/stripe'));

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
t('no app.log critical errors', !str_contains($appLog, '[critical]'), $appLog !== '' ? substr($appLog, 0, 200) : '');
t('no PHP warnings or fatals in error.log', $errorLog === '' || (!str_contains($errorLog, 'PHP Warning') && !str_contains($errorLog, 'PHP Fatal')), $errorLog !== '' ? substr($errorLog, 0, 200) : '');

cleanupEcommerceStripeGatewayFixtures($cleanupProductIds, $cleanupOrderIds, is_array($originalSettings) ? $originalSettings : []);

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