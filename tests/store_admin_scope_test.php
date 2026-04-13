<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'applicationos.test';
$_SERVER['REQUEST_URI'] = '/ecommerce/store-admin';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/ecommerce/helpers.php';
require_once __DIR__ . '/../modules/ecommerce/handlers.php';

$tenantId = (int)(moduleTenantSettingsTenantId() ?? app()->tenant()->current() ?? 0);
if ($tenantId > 0) {
    syncTenantCliMigrationsForTenant($tenantId, 'ecommerce');
}

foreach ([
    __DIR__ . '/../modules/ecommerce/database/migrations/018_ec_abandoned_carts.sql',
    __DIR__ . '/../modules/ecommerce/database/migrations/024_ec_return_requests.sql',
    __DIR__ . '/../modules/ecommerce/database/migrations/036_ec_store_notifications_messages.sql',
] as $migrationFile) {
    if (is_file($migrationFile)) {
        try {
            $sql = (string)file_get_contents($migrationFile);
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
                app()->db()->exec($statement);
            }
        } catch (\Throwable $e) {
        }
    }
}

$pass = 0;
$fail = 0;
$errors = [];

function tStoreScope(string $label, bool $ok, string $detail = ''): void
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

function storeScopeInsertCategory(string $name, string $slug): int
{
    return moduleWithContext('cms', static function () use ($name, $slug): int {
        if (ecHasCmsCategoryTaxonomy()) {
            cmsDb()->execute(
                "INSERT INTO cms_categories (name, slug, taxonomy, created_at, updated_at) VALUES (?, ?, 'product', NOW(), NOW())",
                [$name, $slug]
            );
        } else {
            cmsDb()->execute(
                'INSERT INTO cms_categories (name, slug, created_at, updated_at) VALUES (?, ?, NOW(), NOW())',
                [$name, $slug]
            );
        }

        return (int)cmsDb()->lastInsertId();
    });
}

function storeScopeInsertOrder(array $data): array
{
    ecDb()->execute(
        "INSERT INTO ec_orders (order_number, customer_id, guest_email, guest_name, source, status, payment_status, subtotal, discount_amount, tax_amount, shipping_amount, total, currency, coupon_code, customer_note, confirmation_token, placed_by_user_id, created_at, updated_at)
         VALUES (?, ?, ?, ?, 'web', ?, 'paid', ?, 0.00, 0.00, 0.00, ?, 'PHP', NULL, '', ?, ?, NOW(), NOW())",
        [
            $data['order_number'],
            $data['customer_id'],
            $data['guest_email'],
            $data['guest_name'],
            $data['status'],
            $data['subtotal'],
            $data['total'],
            $data['token'],
            $data['placed_by_user_id'],
        ]
    );
    $orderId = (int)ecDb()->lastInsertId();

    $hasStoreId = false;
    try {
        ecDb()->query('SELECT store_id FROM ec_order_items WHERE 1 = 0');
        $hasStoreId = true;
    } catch (\Throwable $e) {
        $hasStoreId = false;
    }

    $itemIds = [];
    foreach ($data['items'] as $item) {
        if ($hasStoreId) {
            ecDb()->execute(
                'INSERT INTO ec_order_items (order_id, product_id, variant_id, product_title, sku, unit_price, qty, line_total, variant_label, store_id) VALUES (?, ?, NULL, ?, ?, ?, ?, ?, NULL, ?)',
                [$orderId, $item['product_id'], $item['product_title'], $item['sku'], $item['unit_price'], $item['qty'], $item['line_total'], $item['store_id']]
            );
        } else {
            ecDb()->execute(
                'INSERT INTO ec_order_items (order_id, product_id, variant_id, product_title, sku, unit_price, qty, line_total, variant_label) VALUES (?, ?, NULL, ?, ?, ?, ?, ?, NULL)',
                [$orderId, $item['product_id'], $item['product_title'], $item['sku'], $item['unit_price'], $item['qty'], $item['line_total']]
            );
        }

        $itemIds[] = (int)ecDb()->lastInsertId();
    }

    return ['order_id' => $orderId, 'item_ids' => $itemIds];
}

echo "\n=== STORE ADMIN SCOPE ===\n";

$db = app()->db();
$seed = strtolower(substr(bin2hex(random_bytes(5)), 0, 10));
$existingUser = $db->query('SELECT id, email, display_name FROM cms_users ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];

if ((int)($existingUser['id'] ?? 0) < 1) {
    tStoreScope('fixture user exists', false, 'No cms_users row available for store scope test');
    goto summary;
}

$originalSettings = getModuleSettings('ecommerce');
$cleanup = [
    'product_ids' => [],
    'store_ids' => [],
    'category_ids' => [],
    'order_ids' => [],
    'cart_emails' => [],
    'return_request_ids' => [],
];

try {
    saveModuleSettings('ecommerce', array_merge(is_array($originalSettings) ? $originalSettings : [], [
        'low_stock_threshold' => '5',
        'currency_symbol' => 'P',
    ]));
    invalidateTenantModuleSettingsCache();
    if (function_exists('ecSettingsResetCache')) {
        ecSettingsResetCache();
    }

    $storeA = ecStoreCreate([
        'name' => 'Scope Store A ' . strtoupper($seed),
        'code' => 'scope-a-' . $seed,
        'slug' => 'scope-a-' . $seed,
        'description' => 'Store scope fixture A',
        'is_active' => true,
        'is_default' => false,
    ]);
    $storeB = ecStoreCreate([
        'name' => 'Scope Store B ' . strtoupper($seed),
        'code' => 'scope-b-' . $seed,
        'slug' => 'scope-b-' . $seed,
        'description' => 'Store scope fixture B',
        'is_active' => true,
        'is_default' => false,
    ]);

    $storeAId = (int)($storeA['id'] ?? 0);
    $storeBId = (int)($storeB['id'] ?? 0);
    $cleanup['store_ids'] = [$storeAId, $storeBId];

    ecStoreUserAssign($storeAId, (int)$existingUser['id'], 'owner');
    ecStoreUserAssign($storeBId, (int)$existingUser['id'], 'manager');

    $categoryAId = storeScopeInsertCategory('Scope Category A ' . strtoupper($seed), 'scope-category-a-' . $seed);
    $categoryBId = storeScopeInsertCategory('Scope Category B ' . strtoupper($seed), 'scope-category-b-' . $seed);
    $cleanup['category_ids'] = [$categoryAId, $categoryBId];

    $productAId = ecProductCreate([
        'title' => 'Scope Product A ' . strtoupper($seed),
        'slug' => 'scope-product-a-' . $seed,
        'excerpt' => 'Fixture product A',
        'status' => 'published',
        'price' => 40.00,
        'sku' => 'SCOPE-A-' . strtoupper($seed),
        'stock_qty' => 2,
        'track_stock' => true,
        'category_id' => $categoryAId,
    ], (int)$existingUser['id']);
    $productBId = ecProductCreate([
        'title' => 'Scope Product B ' . strtoupper($seed),
        'slug' => 'scope-product-b-' . $seed,
        'excerpt' => 'Fixture product B',
        'status' => 'published',
        'price' => 60.00,
        'sku' => 'SCOPE-B-' . strtoupper($seed),
        'stock_qty' => 9,
        'track_stock' => true,
        'category_id' => $categoryBId,
    ], (int)$existingUser['id']);
    $cleanup['product_ids'] = [$productAId, $productBId];

    ecProductSaveStoreAssignments($productAId, [$storeAId]);
    ecProductSaveStoreAssignments($productBId, [$storeBId]);

    $orderOne = storeScopeInsertOrder([
        'order_number' => 'STORE-SCOPE-1-' . strtoupper($seed),
        'customer_id' => (int)$existingUser['id'],
        'guest_email' => (string)$existingUser['email'],
        'guest_name' => (string)($existingUser['display_name'] ?? 'Store Scope User'),
        'status' => 'delivered',
        'subtotal' => 100.00,
        'total' => 100.00,
        'token' => bin2hex(random_bytes(12)),
        'placed_by_user_id' => (int)$existingUser['id'],
        'items' => [
            [
                'product_id' => $productAId,
                'product_title' => 'Scope Product A ' . strtoupper($seed),
                'sku' => 'SCOPE-A-' . strtoupper($seed),
                'unit_price' => 40.00,
                'qty' => 1,
                'line_total' => 40.00,
                'store_id' => $storeAId,
            ],
            [
                'product_id' => $productBId,
                'product_title' => 'Scope Product B ' . strtoupper($seed),
                'sku' => 'SCOPE-B-' . strtoupper($seed),
                'unit_price' => 60.00,
                'qty' => 1,
                'line_total' => 60.00,
                'store_id' => $storeBId,
            ],
        ],
    ]);
    $orderTwo = storeScopeInsertOrder([
        'order_number' => 'STORE-SCOPE-2-' . strtoupper($seed),
        'customer_id' => null,
        'guest_email' => 'guest-' . $seed . '@example.com',
        'guest_name' => 'Scope Guest ' . strtoupper($seed),
        'status' => 'delivered',
        'subtotal' => 30.00,
        'total' => 30.00,
        'token' => bin2hex(random_bytes(12)),
        'placed_by_user_id' => (int)$existingUser['id'],
        'items' => [
            [
                'product_id' => $productAId,
                'product_title' => 'Scope Product A ' . strtoupper($seed),
                'sku' => 'SCOPE-A-' . strtoupper($seed),
                'unit_price' => 30.00,
                'qty' => 1,
                'line_total' => 30.00,
                'store_id' => $storeAId,
            ],
        ],
    ]);
    $cleanup['order_ids'] = [(int)$orderOne['order_id'], (int)$orderTwo['order_id']];

    $returnA = ecReturnRequestCreate((int)$orderOne['order_id'], (int)$existingUser['id'], [
        (int)$orderOne['item_ids'][0] => 1,
    ], [
        'reason' => 'Damaged item',
        'condition' => 'damaged',
    ]);
    $returnB = ecReturnRequestCreate((int)$orderOne['order_id'], (int)$existingUser['id'], [
        (int)$orderOne['item_ids'][1] => 1,
    ], [
        'reason' => 'Wrong item',
        'condition' => 'good',
    ]);
    $cleanup['return_request_ids'] = [(int)($returnA['request']['id'] ?? 0), (int)($returnB['request']['id'] ?? 0)];

    $activeCartEmail = 'cart-active-' . $seed . '@example.com';
    $recoveredCartEmail = 'cart-recovered-' . $seed . '@example.com';
    $otherCartEmail = 'cart-other-' . $seed . '@example.com';
    $cleanup['cart_emails'] = [$activeCartEmail, $recoveredCartEmail, $otherCartEmail];

    ecDb()->execute(
        'INSERT INTO ec_abandoned_carts (user_id, session_id, guest_email, guest_name, recovery_token, status, cart_snapshot, item_count, subtotal, total, last_activity_at, created_at, updated_at) VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())',
        [
            'sess-active-' . $seed,
            $activeCartEmail,
            'Scope Active',
            'token-active-' . $seed,
            'active',
            json_encode(['items' => [['product_id' => $productAId, 'qty' => 1, 'price_snapshot' => 55, 'product_title' => 'Scope Product A', 'sku' => 'SCOPE-A-' . strtoupper($seed)]], 'totals' => ['item_count' => 1, 'subtotal' => 55, 'total' => 55]], JSON_UNESCAPED_SLASHES),
            1,
            55.00,
            55.00,
        ]
    );
    ecDb()->execute(
        'INSERT INTO ec_abandoned_carts (user_id, session_id, guest_email, guest_name, recovery_token, status, cart_snapshot, item_count, subtotal, total, recovered_order_id, recovered_at, last_activity_at, created_at, updated_at) VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW(), NOW())',
        [
            'sess-recovered-' . $seed,
            $recoveredCartEmail,
            'Scope Recovered',
            'token-recovered-' . $seed,
            'recovered',
            json_encode(['items' => [['product_id' => $productAId, 'qty' => 1, 'price_snapshot' => 35, 'product_title' => 'Scope Product A', 'sku' => 'SCOPE-A-' . strtoupper($seed)]], 'totals' => ['item_count' => 1, 'subtotal' => 35, 'total' => 35]], JSON_UNESCAPED_SLASHES),
            1,
            35.00,
            35.00,
            (int)$orderTwo['order_id'],
        ]
    );
    ecDb()->execute(
        'INSERT INTO ec_abandoned_carts (user_id, session_id, guest_email, guest_name, recovery_token, status, cart_snapshot, item_count, subtotal, total, last_activity_at, created_at, updated_at) VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())',
        [
            'sess-other-' . $seed,
            $otherCartEmail,
            'Scope Other',
            'token-other-' . $seed,
            'active',
            json_encode(['items' => [['product_id' => $productBId, 'qty' => 1, 'price_snapshot' => 65, 'product_title' => 'Scope Product B', 'sku' => 'SCOPE-B-' . strtoupper($seed)]], 'totals' => ['item_count' => 1, 'subtotal' => 65, 'total' => 65]], JSON_UNESCAPED_SLASHES),
            1,
            65.00,
            65.00,
        ]
    );

    echo "\n§1 Permissions\n";
    $ownerPermissions = ecStoreAdminPermissions('owner');
    $managerPermissions = ecStoreAdminPermissions('manager');
    $supervisorPermissions = ecStoreAdminPermissions('supervisor');
    tStoreScope('owner can manage settings', !empty($ownerPermissions['manage_settings']));
    tStoreScope('manager can edit products but cannot manage settings', !empty($managerPermissions['edit_products']) && empty($managerPermissions['manage_settings']));
    tStoreScope('supervisor is limited to the read-only core sections', empty($supervisorPermissions['edit_products']) && empty($supervisorPermissions['reports']) && empty($supervisorPermissions['customers']) && !empty($supervisorPermissions['reviews']));

    echo "\n§2 Categories and Inventory\n";
    $categories = ecStoreCategoryList($storeAId);
    $inventory = ecReportInventory(['store_id' => $storeAId]);
    tStoreScope('store category list returns only assigned store categories', count($categories) === 1 && (int)($categories[0]['id'] ?? 0) === $categoryAId, json_encode($categories));
    tStoreScope('store inventory report only includes low stock assigned products', (int)($inventory['count'] ?? 0) === 1 && (int)($inventory['items'][0]['id'] ?? 0) === $productAId, json_encode($inventory));

    echo "\n§2B Store Profile Fields\n";
    $storeABase = ecStoreById($storeAId) ?? [];
    $brandingSave = ecStoreUpdate($storeAId, [
        'name' => $storeABase['name'] ?? ('Scope Store A ' . strtoupper($seed)),
        'code' => $storeABase['code'] ?? ('scope-a-' . $seed),
        'slug' => $storeABase['slug'] ?? ('scope-a-' . $seed),
        'description' => $storeABase['description'] ?? 'Store scope fixture A',
        'announcement' => 'Store notice ' . strtoupper($seed),
        'banner_image_id' => 101,
        'logo_image_id' => 202,
        'is_active' => true,
        'is_default' => false,
        'settings_json' => ecStoreSettingsJsonFromInput([
            'setting_currency' => 'PHP',
            'setting_checkout_note' => 'Pickup ready in 1 hour',
            'setting_shipping_mode' => 'flat',
            'setting_shipping_label' => 'Express Scope',
            'setting_shipping_carrier' => 'Scope Fleet',
            'setting_shipping_estimated_days' => '1-2 days',
            'setting_shipping_flat_rate' => '79',
            'setting_shipping_free_above' => '500',
            'setting_shipping_default_country' => 'PH',
        ]),
    ]);
    $storeAfterBranding = ecStoreById($storeAId) ?? [];
    tStoreScope(
        'store profile fields persist branding and announcement when supported',
        !ecStoreBrandingColumnsAvailable()
            || (
                !empty($brandingSave['ok'])
                && (string)($storeAfterBranding['announcement'] ?? '') === 'Store notice ' . strtoupper($seed)
                && (int)($storeAfterBranding['banner_image_id'] ?? 0) === 101
                && (int)($storeAfterBranding['logo_image_id'] ?? 0) === 202
            ),
        json_encode($storeAfterBranding)
    );
        $storeShippingRates = ecShippingAvailableRates([
            [
                'product_id' => $productAId,
                'qty' => 1,
                'price_snapshot' => 40.0,
                'store_id' => $storeAId,
            ],
        ], ['country' => 'PH'], null);
        tStoreScope(
            'store shipping settings override platform rates for single-store carts',
            count($storeShippingRates) === 1
                && (string)($storeShippingRates[0]['label'] ?? '') === 'Express Scope'
                && (float)($storeShippingRates[0]['rate'] ?? 0) === 79.0,
            json_encode($storeShippingRates)
        );

    echo "\n§3 Sales and Customers\n";
    $sales = ecReportSales([
        'period' => 'custom',
        'start_date' => date('Y-m-d', strtotime('-1 day')),
        'end_date' => date('Y-m-d', strtotime('+1 day')),
        'store_id' => $storeAId,
    ]);
    $customers = ecStoreCustomerList($storeAId, ['limit' => 10, 'offset' => 0]);
    $customerEmails = array_column($customers['items'], 'email');
    tStoreScope('store sales report uses store item totals instead of full order totals', (float)($sales['total_revenue'] ?? 0) === 70.0 && (int)($sales['order_count'] ?? 0) === 2, json_encode($sales));
    tStoreScope('store customer list includes both registered and guest buyers for the store', (int)($customers['total'] ?? 0) === 2 && in_array(strtolower((string)$existingUser['email']), $customerEmails, true) && in_array('guest-' . $seed . '@example.com', $customerEmails, true), json_encode($customers));

    echo "\n§3B Notifications, Messaging, and Loyalty\n";
    ecStoreNotificationMarkAllRead($storeAId, (int)$existingUser['id']);
    ecStoreNotificationCreate($storeAId, [
        'type' => 'manual',
        'title' => 'Inventory audit',
        'body' => 'Cycle count scheduled for tonight.',
        'related_order_id' => (int)$orderOne['order_id'],
    ], [(int)$existingUser['id']]);
    $unreadBeforeRead = ecStoreNotificationUnreadCount($storeAId, (int)$existingUser['id']);
    $notificationList = ecStoreNotificationList($storeAId, (int)$existingUser['id'], 10, 0);
    $firstNotificationId = (int)(($notificationList['items'][0]['id'] ?? 0));
    if ($firstNotificationId > 0) {
        ecStoreNotificationMarkRead($firstNotificationId, $storeAId, (int)$existingUser['id']);
    }
    $unreadAfterRead = ecStoreNotificationUnreadCount($storeAId, (int)$existingUser['id']);

    $customerMessageResult = ecStoreMessageCreateFromCustomer((int)$orderOne['order_id'], $storeAId, $existingUser, 'Can you confirm packaging?');
    $storeMessageResult = ecStoreMessageCreateFromStore((int)$orderOne['order_id'], $storeAId, [
        'id' => (int)$existingUser['id'],
        'display_name' => (string)($existingUser['display_name'] ?? 'Scope Owner'),
        'name' => (string)($existingUser['display_name'] ?? 'Scope Owner'),
    ], 'Packaging is secured and tagged for dispatch.');
    $messageThreads = ecStoreMessageThreadList($storeAId, 10);
    $messageThread = $messageThreads[0] ?? [];
    $threadMessages = ecStoreMessagesForOrder($storeAId, (int)$orderOne['order_id']);

    ecLoyaltyRecordEntry((int)$existingUser['id'], (int)$orderOne['order_id'], 'earn', 15, 'Store earn points');
    ecLoyaltyRecordEntry((int)$existingUser['id'], (int)$orderTwo['order_id'], 'redeem', -5, 'Store redeem points');
    $loyaltySummary = ecStoreLoyaltySummary($storeAId, 20);
    $productExport = ecStoreCsvExportDefinition($storeAId, 'products');

    tStoreScope('store notifications track unread state per store user', $unreadBeforeRead >= 1 && $unreadAfterRead === 0, json_encode($notificationList));
    tStoreScope('store message threads accept customer and merchant replies on the same order', !empty($customerMessageResult['ok']) && !empty($storeMessageResult['ok']) && (int)($messageThread['order_id'] ?? 0) === (int)$orderOne['order_id'] && count($threadMessages) === 2, json_encode($threadMessages));
    tStoreScope('store loyalty summary aggregates earned and redeemed points from store orders', (int)($loyaltySummary['total_earned'] ?? 0) === 15 && (int)($loyaltySummary['total_redeemed'] ?? 0) === 5 && (int)($loyaltySummary['unique_customers'] ?? 0) === 1, json_encode($loyaltySummary));
    tStoreScope('store CSV export definition includes only products assigned to the store', (string)($productExport['label'] ?? '') === 'Products' && count((array)($productExport['rows'] ?? [])) === 1 && (int)($productExport['rows'][0]['id'] ?? 0) === $productAId, json_encode($productExport));

    echo "\n§4 Returns and Abandoned Carts\n";
    $returns = ecReturnRequestList(['store_id' => $storeAId, 'limit' => 10, 'offset' => 0]);
    $storeCarts = ecStoreAbandonedCartList($storeAId, 10);
    $cartMetrics = ecStoreAbandonedCartMetrics($storeAId);
    tStoreScope('store return list only exposes requests tied to the store order items', (int)($returns['total'] ?? 0) === 1 && (int)($returns['items'][0]['item_count'] ?? 0) === 1 && (int)($returns['items'][0]['items'][0]['store_id'] ?? 0) === $storeAId, json_encode($returns));
    tStoreScope('store abandoned cart list filters carts by assigned products', count($storeCarts) === 2, json_encode($storeCarts));
    tStoreScope('store abandoned cart metrics track active and recovered store revenue', (int)($cartMetrics['active_count'] ?? 0) === 1 && (int)($cartMetrics['recovered_count'] ?? 0) === 1 && (float)($cartMetrics['revenue_at_risk'] ?? 0) === 55.0 && (float)($cartMetrics['recovered_revenue'] ?? 0) === 35.0, json_encode($cartMetrics));
} catch (\Throwable $e) {
    tStoreScope('store scope fixture setup completes', false, $e->getMessage());
}

foreach (array_filter($cleanup['return_request_ids']) as $requestId) {
    $db->prepare('DELETE FROM ec_return_request_items WHERE request_id = ?')->execute([(int)$requestId]);
    $db->prepare('DELETE FROM ec_return_requests WHERE id = ?')->execute([(int)$requestId]);
}
foreach ($cleanup['order_ids'] as $orderId) {
    $db->prepare('DELETE FROM ec_store_messages WHERE order_id = ?')->execute([(int)$orderId]);
    $db->prepare('DELETE FROM ec_order_items WHERE order_id = ?')->execute([(int)$orderId]);
    $db->prepare('DELETE FROM ec_order_meta WHERE order_id = ?')->execute([(int)$orderId]);
    $db->prepare('DELETE FROM ec_orders WHERE id = ?')->execute([(int)$orderId]);
}
foreach ($cleanup['cart_emails'] as $email) {
    $db->prepare('DELETE FROM ec_abandoned_carts WHERE guest_email = ?')->execute([$email]);
}
foreach ($cleanup['product_ids'] as $productId) {
    $db->prepare('DELETE FROM ec_store_product_overrides WHERE product_id = ?')->execute([(int)$productId]);
    moduleWithContext('cms', static function () use ($productId): void {
        cmsDb()->prepare('DELETE FROM cms_content_categories WHERE content_id = ?')->execute([(int)$productId]);
        cmsDb()->prepare('DELETE FROM cms_content_meta WHERE content_id = ?')->execute([(int)$productId]);
        cmsDb()->prepare('DELETE FROM cms_entity_capabilities WHERE entity_id = ?')->execute([(int)$productId]);
        cmsDb()->prepare('DELETE FROM cms_content WHERE id = ?')->execute([(int)$productId]);
    });
}
foreach ($cleanup['category_ids'] as $categoryId) {
    moduleWithContext('cms', static function () use ($categoryId): void {
        cmsDb()->prepare('DELETE FROM cms_content_categories WHERE category_id = ?')->execute([(int)$categoryId]);
        cmsDb()->prepare('DELETE FROM cms_categories WHERE id = ?')->execute([(int)$categoryId]);
    });
}
foreach ($cleanup['store_ids'] as $storeId) {
    $db->prepare('DELETE FROM ec_store_notifications WHERE store_id = ?')->execute([(int)$storeId]);
    $db->prepare('DELETE FROM ec_store_messages WHERE store_id = ?')->execute([(int)$storeId]);
    $db->prepare('DELETE FROM ec_store_users WHERE store_id = ?')->execute([(int)$storeId]);
    $db->prepare('DELETE FROM ec_store_inventory_sources WHERE store_id = ?')->execute([(int)$storeId]);
    $db->prepare('DELETE FROM ec_stores WHERE id = ?')->execute([(int)$storeId]);
}

saveModuleSettings('ecommerce', is_array($originalSettings) ? $originalSettings : []);
invalidateTenantModuleSettingsCache();
if (function_exists('ecSettingsResetCache')) {
    ecSettingsResetCache();
}

summary:
echo "\n";
if ($fail === 0) {
    echo "PASS  {$pass} assertions passed\n";
    exit(0);
}

echo "FAIL  {$fail} assertion(s) failed\n";
foreach ($errors as $error) {
    echo " - {$error}\n";
}
exit(1);