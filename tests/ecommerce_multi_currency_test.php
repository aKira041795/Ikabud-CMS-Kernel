<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/ecommerce/shop';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/ecommerce/helpers.php';

$tenantId = (int)(moduleTenantSettingsTenantId() ?? app()->tenant()->current() ?? 0);
if ($tenantId > 0) {
    syncTenantCliMigrationsForTenant($tenantId, 'ecommerce');
}

$cartCurrencyMigration = __DIR__ . '/../modules/ecommerce/database/migrations/023_ec_cart_item_currency.sql';
if (is_file($cartCurrencyMigration)) {
    app()->db()->exec((string)file_get_contents($cartCurrencyMigration));
}

$pass = 0;
$fail = 0;
$errors = [];
$cleanupProductIds = [];
$cleanupOrderIds = [];
$originalSettings = getModuleSettings('ecommerce');

function tmc(string $label, bool $ok, string $detail = ''): void
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

function ecommerceMultiCurrencyUserId(): int
{
    $userId = (int)app()->db()->query('SELECT id FROM cms_users ORDER BY id ASC LIMIT 1')->fetchColumn();
    if ($userId < 1) {
        throw new RuntimeException('No cms_users row available for ecommerce multi-currency test');
    }

    return $userId;
}

function ecommerceMultiCurrencyCleanup(array $productIds, array $orderIds, array $originalSettings): void
{
    ecCartClear();
    unset($_SESSION[EC_SESSION_SELECTED_CURRENCY_KEY], $_SESSION['ec_message'], $_GET['currency'], $_REQUEST['currency']);
    if (function_exists('ecCurrencyResetRuntimeState')) {
        ecCurrencyResetRuntimeState();
    }

    $db = ecDb();
    $appDb = app()->db();

    foreach ($orderIds as $orderId) {
        $db->execute('DELETE FROM ec_order_items WHERE order_id = ?', [$orderId]);
        $db->execute('DELETE FROM ec_order_meta WHERE order_id = ?', [$orderId]);
        $db->execute('DELETE FROM ec_payment_transactions WHERE order_id = ?', [$orderId]);
        $db->execute('DELETE FROM ec_orders WHERE id = ?', [$orderId]);
    }

    if ($productIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($productIds), '?'));
        $appDb->prepare("DELETE FROM cms_content_meta WHERE content_id IN ({$placeholders})")->execute($productIds);
        $appDb->prepare("DELETE FROM cms_entity_capabilities WHERE entity_id IN ({$placeholders})")->execute($productIds);
        $appDb->prepare("DELETE FROM cms_content WHERE id IN ({$placeholders})")->execute($productIds);
    }

    saveModuleSettings('ecommerce', is_array($originalSettings) ? $originalSettings : []);
    invalidateTenantModuleSettingsCache();
    if (function_exists('ecSettingsResetCache')) {
        ecSettingsResetCache();
    }
}

function ecommerceMultiCurrencyOrderData(array $cart, int $customerId): array
{
    return [
        'cart_items' => (array)($cart['items'] ?? []),
        'subtotal' => (float)($cart['totals']['subtotal'] ?? 0),
        'discount_amount' => (float)($cart['totals']['discount'] ?? 0),
        'tax_amount' => (float)($cart['totals']['tax'] ?? 0),
        'shipping_amount' => (float)($cart['totals']['shipping'] ?? 0),
        'total' => (float)($cart['totals']['total'] ?? 0),
        'currency' => (string)($cart['currency'] ?? ecStoreBaseCurrencyCode()),
        'coupon_code' => $cart['coupon_code'] ?? null,
        'shipping_rate_id' => null,
        'source' => 'web',
        'billing' => [
            'first_name' => 'Currency',
            'last_name' => 'Tester',
            'email' => 'currency-test@example.com',
            'address_line1' => '123 FX Street',
            'address_line2' => '',
            'city' => 'Manila',
            'state' => 'Metro Manila',
            'postal_code' => '1000',
            'country' => 'PH',
            'phone' => '',
        ],
        'shipping' => [],
        'customer_id' => $customerId,
        'guest_email' => null,
        'guest_name' => null,
        'customer_note' => '',
    ];
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== ECOMMERCE MULTI-CURRENCY ===\n";

$userId = ecommerceMultiCurrencyUserId();
$seed = substr(bin2hex(random_bytes(4)), 0, 8);

saveModuleSettings('ecommerce', array_merge(is_array($originalSettings) ? $originalSettings : [], [
    'currency' => 'USD',
    'currency_symbol' => '$',
    'enabled_currencies' => 'USD, EUR',
    'currency_exchange_rates' => "EUR|0.92",
]));
invalidateTenantModuleSettingsCache();
if (function_exists('ecSettingsResetCache')) {
    ecSettingsResetCache();
}

$productId = ecProductCreate([
    'title' => 'Multi Currency Product ' . $seed,
    'slug' => 'multi-currency-product-' . strtolower($seed),
    'excerpt' => 'Base USD product.',
    'status' => 'published',
    'price' => 100.00,
    'currency' => 'USD',
    'stock_qty' => 20,
    'track_stock' => true,
], $userId);
$cleanupProductIds[] = $productId;

$_SESSION[EC_SESSION_SELECTED_CURRENCY_KEY] = 'EUR';
if (function_exists('ecCurrencyResetRuntimeState')) {
    ecCurrencyResetRuntimeState();
}

$currencyContext = ecCurrentCurrencyContext();
$product = ecProductGet($productId) ?: [];
$storefront = ecBuildStorefrontDetailContext($product, ['route_kind' => 'product_detail']);

tmc('currency context resolves selected EUR storefront currency', (string)($currencyContext['code'] ?? '') === 'EUR' && !empty($currencyContext['selector_enabled']), json_encode($currencyContext));
tmc('storefront detail pricing converts USD base price into EUR display price', (string)($storefront['product']['pricing']['currency'] ?? '') === 'EUR' && abs((float)($storefront['product']['pricing']['active_price'] ?? 0) - 92.00) < 0.001 && str_contains((string)($storefront['product']['pricing']['formatted'] ?? ''), 'EUR '), json_encode($storefront['product']['pricing'] ?? []));

ecCartClear();
$addResult = ecCartAdd($productId, 1);
$cart = ecCartGet();

tmc('cart snapshots selected currency on added items', !empty($addResult['ok']) && (string)($cart['currency'] ?? '') === 'EUR' && (string)($cart['items'][0]['currency'] ?? '') === 'EUR', json_encode($cart));
tmc('cart totals format and persist converted EUR values', abs((float)($cart['totals']['total'] ?? 0) - 92.00) < 0.001 && str_contains((string)($cart['totals']['total_fmt'] ?? ''), 'EUR '), json_encode($cart['totals'] ?? []));

$_GET['currency'] = 'USD';
$_REQUEST['currency'] = 'USD';
if (function_exists('ecCurrencyResetRuntimeState')) {
    ecCurrencyResetRuntimeState();
}
$lockedCurrency = ecCurrentCurrencyCode();
tmc('currency switch is blocked while a cart exists in another currency', $lockedCurrency === 'EUR' && !empty($_SESSION['ec_message']['text']), json_encode($_SESSION['ec_message'] ?? []));
unset($_GET['currency'], $_REQUEST['currency'], $_SESSION['ec_message']);
if (function_exists('ecCurrencyResetRuntimeState')) {
    ecCurrencyResetRuntimeState();
}

$orderResult = ecOrderCreate(ecommerceMultiCurrencyOrderData($cart, $userId));
$cleanupOrderIds[] = (int)($orderResult['order_id'] ?? 0);

saveModuleSettings('ecommerce', array_merge(is_array($originalSettings) ? $originalSettings : [], [
    'currency' => 'PHP',
    'currency_symbol' => 'PHP ',
    'enabled_currencies' => 'PHP, EUR',
    'currency_exchange_rates' => "EUR|0.016",
]));
invalidateTenantModuleSettingsCache();
if (function_exists('ecSettingsResetCache')) {
    ecSettingsResetCache();
}
if (function_exists('ecCurrencyResetRuntimeState')) {
    ecCurrencyResetRuntimeState();
}

$order = ecOrderGet((int)$orderResult['order_id'], $userId);
$customerOrders = ecCustomerOrders($userId, 10, 0);
$listedOrder = $customerOrders['items'][0] ?? [];

tmc('orders persist checkout currency independent of later store-setting changes', is_array($order) && (string)($order['currency'] ?? '') === 'EUR' && (string)($order['currency_symbol'] ?? '') === 'EUR ' && abs((float)($order['total_amount'] ?? 0) - 92.00) < 0.001, json_encode($order));
tmc('customer order list uses stored order currency formatting', (string)($listedOrder['currency'] ?? '') === 'EUR' && str_contains((string)($listedOrder['total_amount_fmt'] ?? ''), 'EUR '), json_encode($listedOrder));

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
tmc('no app.log critical errors', !str_contains($appLog, '[critical]'), $appLog !== '' ? substr($appLog, 0, 200) : '');
tmc('no PHP warnings or fatals in error.log', $errorLog === '' || (!str_contains($errorLog, 'PHP Warning') && !str_contains($errorLog, 'PHP Fatal')), $errorLog !== '' ? substr($errorLog, 0, 200) : '');

ecommerceMultiCurrencyCleanup($cleanupProductIds, array_filter($cleanupOrderIds), is_array($originalSettings) ? $originalSettings : []);

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