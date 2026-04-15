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
$cleanupOrderIds  = [];
$cleanupStoreIds  = [];
$cleanupCouponCodes = [];
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

function ecommerceMultiCurrencyCleanup(array $productIds, array $orderIds, array $originalSettings, array $storeIds = [], array $couponCodes = []): void
{
    ecCartClear();
    unset($_SESSION[EC_SESSION_SELECTED_CURRENCY_KEY], $_SESSION['ec_message'], $_GET['currency'], $_REQUEST['currency']);
    if (function_exists('ecCurrencyResetRuntimeState')) {
        ecCurrencyResetRuntimeState();
    }
    if (function_exists('ecStoreClearResolvedContext')) {
        ecStoreClearResolvedContext();
    }
    unset($_GET['store'], $_REQUEST['store']);

    $db = ecDb();
    $appDb = app()->db();

    foreach ($orderIds as $orderId) {
        $db->execute('DELETE FROM ec_order_items WHERE order_id = ?', [$orderId]);
        $db->execute('DELETE FROM ec_order_meta WHERE order_id = ?', [$orderId]);
        $db->execute('DELETE FROM ec_payment_transactions WHERE order_id = ?', [$orderId]);
        $db->execute('DELETE FROM ec_orders WHERE id = ?', [$orderId]);
    }

    if ($couponCodes !== []) {
        $placeholders = implode(', ', array_fill(0, count($couponCodes), '?'));
        $db->prepare("DELETE FROM ec_coupons WHERE code IN ({$placeholders})")->execute($couponCodes);
    }

    if ($productIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($productIds), '?'));
        $appDb->prepare("DELETE FROM cms_content_meta WHERE content_id IN ({$placeholders})")->execute($productIds);
        $appDb->prepare("DELETE FROM cms_entity_capabilities WHERE entity_id IN ({$placeholders})")->execute($productIds);
        $appDb->prepare("DELETE FROM cms_content WHERE id IN ({$placeholders})")->execute($productIds);
    }

    if ($storeIds !== []) {
        foreach ($storeIds as $sid) {
            $db->execute('DELETE FROM ec_store_product_overrides WHERE store_id = ?', [$sid]);
            $db->execute('DELETE FROM ec_store_users WHERE store_id = ?', [$sid]);
            $db->execute('DELETE FROM ec_stores WHERE id = ?', [$sid]);
        }
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

// ─── §2  Store-level currency override takes precedence over session/global ───
// When a store has settings_json.currency = 'PHP', the cart must snapshot PHP
// prices (not the shopper's active session currency selection such as EUR).
echo "\n--- §2 Store currency override vs session currency ---\n";

$storeSeed = substr(bin2hex(random_bytes(4)), 0, 6);
$storeSlug = 'php-store-' . $storeSeed;

ecDb()->query(
    "INSERT INTO ec_stores (code, name, slug, is_active, settings_json, created_at, updated_at)
     VALUES (?, ?, ?, 1, ?, NOW(), NOW())",
    [
        'PHP' . strtoupper($storeSeed),
        'PHP Store ' . $storeSeed,
        $storeSlug,
        json_encode(['currency' => 'PHP', 'currency_symbol' => 'PHP ']),
    ]
);
$phpStoreId = (int)ecDb()->query("SELECT LAST_INSERT_ID() AS id", [])->fetchColumn();
$cleanupStoreIds[] = $phpStoreId;

// Restore §1 multi-currency settings so ecCurrentCurrencyCode() can return EUR.
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

$phpProductId = ecProductCreate([
    'title'     => 'PHP Store Product ' . $storeSeed,
    'slug'      => 'php-store-product-' . strtolower($storeSeed),
    'excerpt'   => 'Product priced in PHP.',
    'status'    => 'published',
    'price'     => 1000.00,
    'currency'  => 'PHP',
    'stock_qty' => 10,
    'track_stock' => true,
], $userId);
$cleanupProductIds[] = $phpProductId;

// Assign the product to the PHP store so product['store_id'] is set.
// ecProductGet() derives store_id from ec_store_product_overrides.
if ($phpProductId > 0 && $phpStoreId > 0) {
    ecDb()->execute(
        "INSERT INTO ec_store_product_overrides (store_id, product_id, is_visible, created_at, updated_at)
         VALUES (?, ?, 1, NOW(), NOW())
         ON DUPLICATE KEY UPDATE is_visible = 1",
        [$phpStoreId, $phpProductId]
    );
}

// Shopper selects EUR as their session currency — store PHP override must win.
ecCartClear();
$_SESSION[EC_SESSION_SELECTED_CURRENCY_KEY] = 'EUR';
if (function_exists('ecCurrencyResetRuntimeState')) {
    ecCurrencyResetRuntimeState();
}
if (function_exists('ecStoreClearResolvedContext')) {
    ecStoreClearResolvedContext();
}
$_GET['store'] = $storeSlug;

$phpProduct = ecProductGet($phpProductId) ?: [];
$storeCtxCurrency = ecCurrentCurrencyCode(); // Still EUR from session
$phpAddResult = ecCartAdd($phpProductId, 1);
$phpCart = ecCartGet();

$phpCartItemCurrency = (string)($phpCart['items'][0]['currency'] ?? '');
$phpCartCurrency     = (string)($phpCart['currency'] ?? '');

tmc('§2 session currency is still EUR (control check)', $storeCtxCurrency === 'EUR', $storeCtxCurrency);
tmc('§2 cart item currency is PHP not session EUR (store override wins)', $phpCartItemCurrency === 'PHP', json_encode(['item_currency' => $phpCartItemCurrency, 'session_currency' => $storeCtxCurrency]));
tmc('§2 cart-level currency tracks PHP', $phpCartCurrency === 'PHP', json_encode(['cart_currency' => $phpCartCurrency]));
tmc('§2 cart item active_price unchanged (PHP is native, no conversion)', abs((float)($phpCart['items'][0]['price_snapshot'] ?? 0) - 1000.00) < 0.01, json_encode($phpCart['items'][0] ?? []));

// Storefront display must also show PHP pricing.
if (function_exists('ecStoreClearResolvedContext')) {
    ecStoreClearResolvedContext();
}
$_GET['store'] = $storeSlug;
$phpStorefront = ecBuildStorefrontDetailContext($phpProduct, ['route_kind' => 'product_detail']);
$phpDisplayCurrency = (string)($phpStorefront['product']['pricing']['currency'] ?? '');
tmc('§2 storefront detail pricing shows PHP (store override)', $phpDisplayCurrency === 'PHP', json_encode($phpStorefront['product']['pricing'] ?? []));

// Order must persist PHP currency regardless of session EUR.
$phpOrderResult = ecOrderCreate(ecommerceMultiCurrencyOrderData($phpCart, $userId));
$cleanupOrderIds[] = (int)($phpOrderResult['order_id'] ?? 0);
$phpOrder = ecOrderGet((int)($phpOrderResult['order_id'] ?? 0), $userId);
tmc('§2 order persists PHP currency (not session EUR)', (string)($phpOrder['currency'] ?? '') === 'PHP', json_encode($phpOrder));

echo "\n--- §3 Store-native numeric price is not FX-converted again ---\n";

$nativeSeed = substr(bin2hex(random_bytes(4)), 0, 6);
$nativeStoreSlug = 'native-price-store-' . $nativeSeed;

ecDb()->query(
    "INSERT INTO ec_stores (code, name, slug, is_active, settings_json, created_at, updated_at)
     VALUES (?, ?, ?, 1, ?, NOW(), NOW())",
    [
        'NAT' . strtoupper($nativeSeed),
        'Native Price Store ' . $nativeSeed,
        $nativeStoreSlug,
        json_encode(['currency' => 'PHP', 'currency_symbol' => 'PHP ']),
    ]
);
$nativeStoreId = (int)ecDb()->query("SELECT LAST_INSERT_ID() AS id", [])->fetchColumn();
$cleanupStoreIds[] = $nativeStoreId;

$nativeProductId = ecProductCreate([
    'title' => 'Native Price Product ' . $nativeSeed,
    'slug' => 'native-price-product-' . strtolower($nativeSeed),
    'excerpt' => 'Store-admin numeric price should remain native.',
    'status' => 'published',
    'price' => 1500.00,
    'currency' => 'USD',
    'stock_qty' => 10,
    'track_stock' => true,
], $userId);
$cleanupProductIds[] = $nativeProductId;

ecDb()->execute(
    "INSERT INTO ec_store_product_overrides (store_id, product_id, is_visible, price_override, created_at, updated_at)
     VALUES (?, ?, 1, ?, NOW(), NOW())
     ON DUPLICATE KEY UPDATE is_visible = 1, price_override = VALUES(price_override), updated_at = NOW()",
    [$nativeStoreId, $nativeProductId, 1500.00]
);

ecCartClear();
unset($_SESSION[EC_SESSION_SELECTED_CURRENCY_KEY], $_GET['store'], $_REQUEST['store']);
if (function_exists('ecCurrencyResetRuntimeState')) {
    ecCurrencyResetRuntimeState();
}
if (function_exists('ecStoreClearResolvedContext')) {
    ecStoreClearResolvedContext();
}

$nativeProduct = ecProductGet($nativeProductId) ?: [];
$nativeStorefront = ecBuildStorefrontDetailContext($nativeProduct, ['route_kind' => 'product_detail']);
$nativeAddResult = ecCartAdd($nativeProductId, 1);
$nativeCart = ecCartGet();

tmc('§3 storefront shows native PHP 1500 for store-owned product',
    (string)($nativeStorefront['product']['pricing']['currency'] ?? '') === 'PHP'
        && abs((float)($nativeStorefront['product']['pricing']['active_price'] ?? 0) - 1500.00) < 0.01,
    json_encode($nativeStorefront['product']['pricing'] ?? [])
);
tmc('§3 cart add succeeds for store-owned native-price product', !empty($nativeAddResult['ok']), json_encode($nativeAddResult));
tmc('§3 cart price_snapshot stays 1500 PHP instead of 84000 FX-converted',
    (string)($nativeCart['items'][0]['currency'] ?? '') === 'PHP'
        && abs((float)($nativeCart['items'][0]['price_snapshot'] ?? 0) - 1500.00) < 0.01,
    json_encode($nativeCart['items'][0] ?? [])
);
tmc('§3 cart totals stay at PHP 1500 for qty 1',
    (string)($nativeCart['currency'] ?? '') === 'PHP'
        && abs((float)($nativeCart['totals']['total'] ?? 0) - 1500.00) < 0.01,
    json_encode($nativeCart['totals'] ?? [])
);

echo "\n--- §4 Direct product page store context via X-Store-Slug ---\n";

$headerSeed = substr(bin2hex(random_bytes(4)), 0, 6);
$headerStoreSlug = 'header-store-' . $headerSeed;

ecDb()->query(
    "INSERT INTO ec_stores (code, name, slug, is_active, settings_json, created_at, updated_at)
     VALUES (?, ?, ?, 1, ?, NOW(), NOW())",
    [
        'HDR' . strtoupper($headerSeed),
        'Header Store ' . $headerSeed,
        $headerStoreSlug,
        json_encode(['currency' => 'PHP', 'currency_symbol' => 'PHP ']),
    ]
);
$headerStoreId = (int)ecDb()->query("SELECT LAST_INSERT_ID() AS id", [])->fetchColumn();
$cleanupStoreIds[] = $headerStoreId;

$headerProductId = ecProductCreate([
    'title' => 'Header Store Product ' . $headerSeed,
    'slug' => 'header-store-product-' . strtolower($headerSeed),
    'excerpt' => 'Global product viewed through a store-context product page.',
    'status' => 'published',
    'price' => 1500.00,
    'currency' => 'USD',
    'stock_qty' => 10,
    'track_stock' => true,
], $userId);
$cleanupProductIds[] = $headerProductId;

ecCartClear();
unset($_GET['store'], $_REQUEST['store'], $_SESSION[EC_SESSION_SELECTED_CURRENCY_KEY]);
$_SERVER['HTTP_X_STORE_SLUG'] = $headerStoreSlug;
if (function_exists('ecCurrencyResetRuntimeState')) {
    ecCurrencyResetRuntimeState();
}
if (function_exists('ecStoreClearResolvedContext')) {
    ecStoreClearResolvedContext();
}

$headerProduct = ecProductGet($headerProductId) ?: [];
$headerStorefront = ecBuildStorefrontDetailContext($headerProduct, [
    'route_kind' => 'product_detail',
    'store_context' => ecStoreBySlug($headerStoreSlug),
]);
$headerAddResult = ecCartAdd($headerProductId, 1);
$headerCart = ecCartGet();

tmc('§4 storefront detail preserves active store slug in route context',
    (string)($headerStorefront['route']['store']['slug'] ?? '') === $headerStoreSlug,
    json_encode($headerStorefront['route']['store'] ?? [])
);
tmc('§4 storefront shows PHP 1500 for direct product page store context',
    (string)($headerStorefront['product']['pricing']['currency'] ?? '') === 'PHP'
        && abs((float)($headerStorefront['product']['pricing']['active_price'] ?? 0) - 1500.00) < 0.01,
    json_encode($headerStorefront['product']['pricing'] ?? [])
);
tmc('§4 cart add succeeds when store context is carried by X-Store-Slug', !empty($headerAddResult['ok']), json_encode($headerAddResult));
tmc('§4 cart respects store currency via X-Store-Slug without FX multiplying',
    (string)($headerCart['items'][0]['currency'] ?? '') === 'PHP'
        && abs((float)($headerCart['items'][0]['price_snapshot'] ?? 0) - 1500.00) < 0.01
        && abs((float)($headerCart['totals']['total'] ?? 0) - 1500.00) < 0.01,
    json_encode(['item' => $headerCart['items'][0] ?? [], 'totals' => $headerCart['totals'] ?? []])
);
unset($_SERVER['HTTP_X_STORE_SLUG']);

echo "\n--- §5 Cart currency does not silently rewrite browsing currency ---\n";

$mixedSeed = substr(bin2hex(random_bytes(4)), 0, 6);
$mixedStoreSlug = 'mixed-store-' . $mixedSeed;

ecDb()->query(
    "INSERT INTO ec_stores (code, name, slug, is_active, settings_json, created_at, updated_at)
     VALUES (?, ?, ?, 1, ?, NOW(), NOW())",
    [
        'MIX' . strtoupper($mixedSeed),
        'Mixed Store ' . $mixedSeed,
        $mixedStoreSlug,
        json_encode(['currency' => 'PHP', 'currency_symbol' => 'PHP ']),
    ]
);
$mixedStoreId = (int)ecDb()->query("SELECT LAST_INSERT_ID() AS id", [])->fetchColumn();
$cleanupStoreIds[] = $mixedStoreId;

$globalProductId = ecProductCreate([
    'title' => 'Global Currency Product ' . $mixedSeed,
    'slug' => 'global-currency-product-' . strtolower($mixedSeed),
    'excerpt' => 'Global USD product should stay USD while browsing.',
    'status' => 'published',
    'price' => 200.00,
    'currency' => 'USD',
    'stock_qty' => 10,
    'track_stock' => true,
], $userId);
$cleanupProductIds[] = $globalProductId;

$storeProductId = ecProductCreate([
    'title' => 'Store Currency Product ' . $mixedSeed,
    'slug' => 'store-currency-product-' . strtolower($mixedSeed),
    'excerpt' => 'Store PHP product should stay PHP.',
    'status' => 'published',
    'price' => 1500.00,
    'currency' => 'USD',
    'stock_qty' => 10,
    'track_stock' => true,
], $userId);
$cleanupProductIds[] = $storeProductId;

ecDb()->execute(
    "INSERT INTO ec_store_product_overrides (store_id, product_id, is_visible, price_override, created_at, updated_at)
     VALUES (?, ?, 1, ?, NOW(), NOW())
     ON DUPLICATE KEY UPDATE is_visible = 1, price_override = VALUES(price_override), updated_at = NOW()",
    [$mixedStoreId, $storeProductId, 1500.00]
);

saveModuleSettings('ecommerce', array_merge(is_array($originalSettings) ? $originalSettings : [], [
    'currency' => 'USD',
    'currency_symbol' => '$',
    'enabled_currencies' => 'USD, PHP, EUR',
    'currency_exchange_rates' => "PHP|56\nEUR|0.92",
]));
invalidateTenantModuleSettingsCache();
if (function_exists('ecSettingsResetCache')) {
    ecSettingsResetCache();
}

ecCartClear();
unset($_GET['store'], $_REQUEST['store'], $_SERVER['HTTP_X_STORE_SLUG']);
$_SESSION[EC_SESSION_SELECTED_CURRENCY_KEY] = 'USD';
if (function_exists('ecCurrencyResetRuntimeState')) {
    ecCurrencyResetRuntimeState();
}
if (function_exists('ecStoreClearResolvedContext')) {
    ecStoreClearResolvedContext();
}

$globalCatalogBefore = ecBuildStorefrontCatalogItem(ecProductGet($globalProductId) ?: [], ['item_base_url' => '/ecommerce/shop']);
$storeCatalogBefore = ecBuildStorefrontCatalogItem(ecProductGet($storeProductId) ?: [], [
    'item_base_url' => '/ecommerce/shop',
    'store_context' => ecStoreById($mixedStoreId),
]);

$_SERVER['HTTP_X_STORE_SLUG'] = $mixedStoreSlug;
if (function_exists('ecStoreClearResolvedContext')) {
    ecStoreClearResolvedContext();
}
$mixedAddResult = ecCartAdd($storeProductId, 1);
ecCartGet();
if (function_exists('ecCurrencyResetRuntimeState')) {
    ecCurrencyResetRuntimeState();
}
unset($_SERVER['HTTP_X_STORE_SLUG']);
if (function_exists('ecStoreClearResolvedContext')) {
    ecStoreClearResolvedContext();
}

$globalCatalogAfter = ecBuildStorefrontCatalogItem(ecProductGet($globalProductId) ?: [], ['item_base_url' => '/ecommerce/shop']);
$storeCatalogAfter = ecBuildStorefrontCatalogItem(ecProductGet($storeProductId) ?: [], [
    'item_base_url' => '/ecommerce/shop',
    'store_context' => ecStoreById($mixedStoreId),
]);

tmc('§5 store product can still be added in PHP store context', !empty($mixedAddResult['ok']), json_encode($mixedAddResult));
tmc('§5 global browsing product stays USD before cart visit',
    (string)($globalCatalogBefore['pricing']['currency'] ?? '') === 'USD'
        && abs((float)($globalCatalogBefore['pricing']['active_price'] ?? 0) - 200.00) < 0.01,
    json_encode($globalCatalogBefore['pricing'] ?? [])
);
tmc('§5 store browsing product stays PHP before cart visit',
    (string)($storeCatalogBefore['pricing']['currency'] ?? '') === 'PHP'
        && abs((float)($storeCatalogBefore['pricing']['active_price'] ?? 0) - 1500.00) < 0.01,
    json_encode($storeCatalogBefore['pricing'] ?? [])
);
tmc('§5 global browsing product remains USD after cart visit',
    (string)($globalCatalogAfter['pricing']['currency'] ?? '') === 'USD'
        && abs((float)($globalCatalogAfter['pricing']['active_price'] ?? 0) - 200.00) < 0.01,
    json_encode($globalCatalogAfter['pricing'] ?? [])
);
tmc('§5 store browsing product remains PHP after cart visit',
    (string)($storeCatalogAfter['pricing']['currency'] ?? '') === 'PHP'
        && abs((float)($storeCatalogAfter['pricing']['active_price'] ?? 0) - 1500.00) < 0.01,
    json_encode($storeCatalogAfter['pricing'] ?? [])
);

echo "\n--- §6 Store coupon currency resolves from store settings ---\n";

$couponSeed = substr(bin2hex(random_bytes(4)), 0, 6);
$couponStoreSlug = 'coupon-store-' . $couponSeed;

ecDb()->query(
    "INSERT INTO ec_stores (code, name, slug, is_active, settings_json, created_at, updated_at)
     VALUES (?, ?, ?, 1, ?, NOW(), NOW())",
    [
        'CPN' . strtoupper($couponSeed),
        'Coupon Store ' . $couponSeed,
        $couponStoreSlug,
        json_encode(['currency' => 'PHP', 'currency_symbol' => 'PHP ']),
    ]
);
$couponStoreId = (int)ecDb()->query("SELECT LAST_INSERT_ID() AS id", [])->fetchColumn();
$cleanupStoreIds[] = $couponStoreId;

$couponOtherStoreSlug = 'coupon-other-store-' . $couponSeed;
ecDb()->query(
    "INSERT INTO ec_stores (code, name, slug, is_active, settings_json, created_at, updated_at)
     VALUES (?, ?, ?, 1, ?, NOW(), NOW())",
    [
        'CPNALT' . strtoupper($couponSeed),
        'Coupon Other Store ' . $couponSeed,
        $couponOtherStoreSlug,
        json_encode(['currency' => 'PHP', 'currency_symbol' => 'PHP ']),
    ]
);
$couponOtherStoreId = (int)ecDb()->query("SELECT LAST_INSERT_ID() AS id", [])->fetchColumn();
$cleanupStoreIds[] = $couponOtherStoreId;

$couponProductId = ecProductCreate([
    'title' => 'Coupon Currency Product ' . $couponSeed,
    'slug' => 'coupon-currency-product-' . strtolower($couponSeed),
    'excerpt' => 'Store coupon currency fixture.',
    'status' => 'published',
    'price' => 1500.00,
    'currency' => 'USD',
    'stock_qty' => 10,
    'track_stock' => true,
], $userId);
$cleanupProductIds[] = $couponProductId;

$couponOtherProductId = ecProductCreate([
    'title' => 'Coupon Other Store Product ' . $couponSeed,
    'slug' => 'coupon-other-store-product-' . strtolower($couponSeed),
    'excerpt' => 'Mixed store coupon rejection fixture.',
    'status' => 'published',
    'price' => 900.00,
    'currency' => 'USD',
    'stock_qty' => 10,
    'track_stock' => true,
], $userId);
$cleanupProductIds[] = $couponOtherProductId;

ecDb()->execute(
    "INSERT INTO ec_store_product_overrides (store_id, product_id, is_visible, price_override, created_at, updated_at)
     VALUES (?, ?, 1, ?, NOW(), NOW())
     ON DUPLICATE KEY UPDATE is_visible = 1, price_override = VALUES(price_override), updated_at = NOW()",
    [$couponStoreId, $couponProductId, 1500.00]
);
ecDb()->execute(
    "INSERT INTO ec_store_product_overrides (store_id, product_id, is_visible, price_override, created_at, updated_at)
     VALUES (?, ?, 1, ?, NOW(), NOW())
     ON DUPLICATE KEY UPDATE is_visible = 1, price_override = VALUES(price_override), updated_at = NOW()",
    [$couponOtherStoreId, $couponOtherProductId, 900.00]
);

$fixedCouponCode = 'STOREFIX' . strtoupper($couponSeed);
$giftCouponCode = 'STOREGFT' . strtoupper($couponSeed);
$cleanupCouponCodes = array_merge($cleanupCouponCodes, [$fixedCouponCode, $giftCouponCode]);

ecDb()->execute(
    "INSERT INTO ec_coupons (store_id, code, type, value, min_order_amount, max_uses, expires_at, description, is_active, created_at, updated_at)
     VALUES (?, ?, 'fixed', ?, ?, NULL, NULL, ?, 1, NOW(), NOW())",
    [$couponStoreId, $fixedCouponCode, 100.00, 1000.00, 'Store-fixed coupon fixture']
);
ecDb()->execute(
    "INSERT INTO ec_coupons (store_id, code, type, value, min_order_amount, max_uses, expires_at, description, is_active, created_at, updated_at)
     VALUES (?, ?, 'gift_card', ?, 0, NULL, NULL, ?, 1, NOW(), NOW())",
    [$couponStoreId, $giftCouponCode, 150.00, 'Store gift-card fixture']
);

ecCartClear();
$_GET['store'] = $couponStoreSlug;
$_REQUEST['store'] = $couponStoreSlug;
$_SESSION[EC_SESSION_SELECTED_CURRENCY_KEY] = 'USD';
if (function_exists('ecCurrencyResetRuntimeState')) {
    ecCurrencyResetRuntimeState();
}
if (function_exists('ecStoreClearResolvedContext')) {
    ecStoreClearResolvedContext();
}

$couponAddResult = ecCartAdd($couponProductId, 1);
$couponApplyResult = ecCartApplyCoupon($fixedCouponCode);
$couponCart = ecCartGet();

tmc('§6 store coupon add fixture succeeds', !empty($couponAddResult['ok']), json_encode($couponAddResult));
tmc('§6 fixed store coupon applies using the store currency instead of the platform base currency',
    !empty($couponApplyResult['ok'])
        && (string)($couponApplyResult['coupon']['source_currency'] ?? '') === 'PHP'
        && abs((float)($couponCart['totals']['coupon_discount_amount'] ?? 0) - 100.00) < 0.01
        && abs((float)($couponCart['totals']['total'] ?? 0) - 1400.00) < 0.01,
    json_encode(['apply' => $couponApplyResult, 'cart' => $couponCart])
);

ecCartClear();
$_GET['store'] = $couponOtherStoreSlug;
$_REQUEST['store'] = $couponOtherStoreSlug;
if (function_exists('ecCurrencyResetRuntimeState')) {
    ecCurrencyResetRuntimeState();
}
if (function_exists('ecStoreClearResolvedContext')) {
    ecStoreClearResolvedContext();
}

$otherStoreAddResult = ecCartAdd($couponOtherProductId, 1);
$otherStoreCouponApplyResult = ecCartApplyCoupon($fixedCouponCode);
$otherStoreCouponCart = ecCartGet();

tmc('§6 other-store cart fixture add succeeds before coupon rejection',
    !empty($otherStoreAddResult['ok']),
    json_encode($otherStoreAddResult)
);
tmc('§6 store coupon is rejected outside the store that created it',
    empty($otherStoreCouponApplyResult['ok'])
        && str_contains((string)($otherStoreCouponApplyResult['error'] ?? ''), 'Invalid coupon')
        && (string)($otherStoreCouponCart['coupon_code'] ?? '') === '',
    json_encode(['apply' => $otherStoreCouponApplyResult, 'cart' => $otherStoreCouponCart])
);

ecCartClear();
$_GET['store'] = $couponStoreSlug;
$_REQUEST['store'] = $couponStoreSlug;
if (function_exists('ecCurrencyResetRuntimeState')) {
    ecCurrencyResetRuntimeState();
}
if (function_exists('ecStoreClearResolvedContext')) {
    ecStoreClearResolvedContext();
}

ecCartAdd($couponProductId, 1);
$giftApplyResult = ecCartApplyCoupon($giftCouponCode);
$giftCart = ecCartGet();
$giftOrderResult = ecOrderCreate(ecommerceMultiCurrencyOrderData($giftCart, $userId));
$cleanupOrderIds[] = (int)($giftOrderResult['order_id'] ?? 0);
$giftCouponRow = ecDb()->query('SELECT value, uses_count, is_active FROM ec_coupons WHERE code = ? LIMIT 1', [$giftCouponCode])->fetch(PDO::FETCH_ASSOC) ?: [];

tmc('§6 store gift card applies in the store currency before order placement',
    !empty($giftApplyResult['ok'])
        && abs((float)($giftCart['totals']['gift_card_amount'] ?? 0) - 150.00) < 0.01
        && abs((float)($giftCart['totals']['total'] ?? 0) - 1350.00) < 0.01,
    json_encode(['apply' => $giftApplyResult, 'cart' => $giftCart])
);
tmc('§6 store gift card redemption decrements remaining balance in the store currency',
    abs((float)($giftCouponRow['value'] ?? 0) - 0.00) < 0.01
        && (int)($giftCouponRow['uses_count'] ?? 0) === 1
        && (int)($giftCouponRow['is_active'] ?? 0) === 0,
    json_encode($giftCouponRow)
);

// Clean up store context state for subsequent operations.
ecCartClear();
if (function_exists('ecStoreClearResolvedContext')) {
    ecStoreClearResolvedContext();
}
unset($_GET['store'], $_REQUEST['store']);
unset($_SESSION[EC_SESSION_SELECTED_CURRENCY_KEY]);
if (function_exists('ecCurrencyResetRuntimeState')) {
    ecCurrencyResetRuntimeState();
}

ecommerceMultiCurrencyCleanup($cleanupProductIds, array_filter($cleanupOrderIds), is_array($originalSettings) ? $originalSettings : [], $cleanupStoreIds, $cleanupCouponCodes);

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