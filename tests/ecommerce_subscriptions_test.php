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

$subscriptionMigration = __DIR__ . '/../modules/ecommerce/database/migrations/022_ec_subscriptions.sql';
if (is_file($subscriptionMigration)) {
    app()->db()->exec((string)file_get_contents($subscriptionMigration));
}

$pass = 0;
$fail = 0;
$errors = [];
$cleanupProductIds = [];
$cleanupOrderIds = [];

function tsub(string $label, bool $ok, string $detail = ''): void
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

function ecommerceSubscriptionsUserFixture(): array
{
    $row = app()->db()->query(
        "SELECT id, email, display_name FROM cms_users WHERE is_active = 1 ORDER BY id ASC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);

    if (!is_array($row) || (int)($row['id'] ?? 0) < 1) {
        throw new RuntimeException('No cms_users row available for ecommerce subscriptions test');
    }

    return $row;
}

function ecommerceSubscriptionsCleanup(array $productIds, array $orderIds): void
{
    ecCartClear();
    $db = ecDb();
    $appDb = app()->db();

    foreach ($orderIds as $orderId) {
        $db->execute('DELETE FROM ec_subscriptions WHERE order_id = ?', [$orderId]);
        $db->execute('DELETE FROM ec_order_licenses WHERE order_id = ?', [$orderId]);
        $db->execute('DELETE FROM ec_order_items WHERE order_id = ?', [$orderId]);
        $db->execute('DELETE FROM ec_order_meta WHERE order_id = ?', [$orderId]);
        $db->execute('DELETE FROM ec_payment_transactions WHERE order_id = ?', [$orderId]);
        $db->execute('DELETE FROM ec_orders WHERE id = ?', [$orderId]);
    }

    if ($productIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($productIds), '?'));
        $appDb->prepare("DELETE FROM cms_content_meta WHERE content_id IN ({$placeholders})")->execute($productIds);
        $appDb->prepare("DELETE FROM cms_content_categories WHERE content_id IN ({$placeholders})")->execute($productIds);
        $appDb->prepare("DELETE FROM cms_entity_capabilities WHERE entity_id IN ({$placeholders})")->execute($productIds);
        $appDb->prepare("DELETE FROM cms_content WHERE id IN ({$placeholders})")->execute($productIds);
    }
}

function ecommerceSubscriptionsOrderData(array $customer, array $cartItem): array
{
    $email = trim((string)($customer['email'] ?? 'subscriptions-test@example.com'));
    $name = trim((string)($customer['display_name'] ?? 'Subscription Customer'));
    $nameParts = preg_split('/\s+/', $name) ?: [];
    $firstName = (string)($nameParts[0] ?? 'Subscription');
    $lastName = (string)($nameParts[1] ?? 'Customer');
    $lineTotal = round((float)($cartItem['price_snapshot'] ?? 0) * max(1, (int)($cartItem['qty'] ?? 1)), 2);

    return [
        'cart_items' => [[
            'product_id' => (int)($cartItem['product_id'] ?? 0),
            'variant_id' => $cartItem['variant_id'] ?? null,
            'product_title' => (string)($cartItem['product_title'] ?? 'Subscription Product'),
            'sku' => (string)($cartItem['sku'] ?? ''),
            'price_snapshot' => (float)($cartItem['price_snapshot'] ?? 0.0),
            'qty' => max(1, (int)($cartItem['qty'] ?? 1)),
            'variant_label' => $cartItem['variant_label'] ?? null,
            'line_total' => $lineTotal,
        ]],
        'subtotal' => $lineTotal,
        'discount_amount' => 0.00,
        'tax_amount' => 0.00,
        'shipping_amount' => 0.00,
        'total' => $lineTotal,
        'currency' => (string)ecSettings('currency'),
        'coupon_code' => null,
        'shipping_rate_id' => null,
        'source' => 'web',
        'billing' => [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'address_line1' => '123 Subscription St',
            'address_line2' => '',
            'city' => 'Manila',
            'state' => 'Metro Manila',
            'postal_code' => '1000',
            'country' => 'PH',
            'phone' => '',
        ],
        'shipping' => [],
        'customer_id' => (int)($customer['id'] ?? 0),
        'guest_email' => null,
        'guest_name' => null,
        'customer_note' => '',
    ];
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');
ecCartClear();

echo "\n=== ECOMMERCE SUBSCRIPTIONS ===\n";

$customer = ecommerceSubscriptionsUserFixture();
$authorId = (int)$customer['id'];
$seed = substr(bin2hex(random_bytes(4)), 0, 8);

$regularProductId = ecProductCreate([
    'title' => 'One-time Product ' . $seed,
    'slug' => 'one-time-product-' . strtolower($seed),
    'excerpt' => 'Regular product.',
    'status' => 'published',
    'price' => 19.00,
    'stock_qty' => 25,
    'track_stock' => true,
], $authorId);

$subscriptionProductId = ecProductCreate([
    'title' => 'Subscription Product ' . $seed,
    'slug' => 'subscription-product-' . strtolower($seed),
    'excerpt' => 'Recurring subscription product.',
    'status' => 'published',
    'price' => 29.00,
    'stock_qty' => 100,
    'track_stock' => true,
    'is_subscription' => true,
    'subscription_interval_unit' => 'month',
    'subscription_interval_count' => 1,
    'subscription_trial_days' => 14,
    'subscription_max_cycles' => 0,
    'subscription_grace_period_days' => 5,
], $authorId);

$cleanupProductIds = [$regularProductId, $subscriptionProductId];

$subscriptionProduct = ecProductGet($subscriptionProductId);
$storefrontDetail = ecBuildStorefrontDetailContext($subscriptionProduct, ['route_kind' => 'product_detail']);
$bridgePayload = ecProductBridgeEventPayload($subscriptionProductId);

tsub(
    'subscription product hydration exposes recurring terms and product type',
    !empty($subscriptionProduct['is_subscription'])
        && (string)($subscriptionProduct['product_type'] ?? '') === 'subscription'
        && (int)($subscriptionProduct['subscription_interval_count'] ?? 0) === 1
        && (string)($subscriptionProduct['subscription_interval_unit'] ?? '') === 'month'
        && (int)($subscriptionProduct['subscription_trial_days'] ?? 0) === 14,
    json_encode($subscriptionProduct['subscription_summary'] ?? [])
);
tsub(
    'storefront detail context exposes recurring label and trial copy',
    str_contains((string)($storefrontDetail['product']['subscription_summary']['recurring_label'] ?? ''), 'every 1 month')
        && str_contains((string)($storefrontDetail['product']['subscription_summary']['trial_label'] ?? ''), '14 day'),
    json_encode($storefrontDetail['product']['subscription_summary'] ?? [])
);
tsub(
    'subscription bridge payload marks the product as subscription',
    (string)($bridgePayload['product_type'] ?? '') === 'subscription',
    json_encode($bridgePayload)
);

ecCartClear();
$invalidQuantityAdd = ecCartAdd($subscriptionProductId, 2);
tsub(
    'subscription cart rejects quantities greater than one',
    empty($invalidQuantityAdd['ok']) && str_contains((string)($invalidQuantityAdd['error'] ?? ''), 'one at a time'),
    json_encode($invalidQuantityAdd)
);

ecCartClear();
$addSubscription = ecCartAdd($subscriptionProductId, 1);
$updateSubscriptionQty = ecCartUpdate(0, 2);
$subscriptionCart = ecCartGet();
tsub(
    'subscription cart marks recurring checkout and rejects quantity updates beyond one',
    !empty($addSubscription['ok'])
        && !empty($subscriptionCart['subscription']['has_subscription'])
        && empty($updateSubscriptionQty['ok'])
        && str_contains((string)($updateSubscriptionQty['error'] ?? ''), 'one subscription product'),
    json_encode($subscriptionCart['subscription'] ?? [])
);

ecCartClear();
$oneTimeAdd = ecCartAdd($regularProductId, 1);
$mixAfterOneTime = ecCartAdd($subscriptionProductId, 1);
tsub(
    'subscription products cannot be mixed into a one-time cart',
    !empty($oneTimeAdd['ok'])
        && empty($mixAfterOneTime['ok'])
        && str_contains((string)($mixAfterOneTime['error'] ?? ''), 'separately'),
    json_encode($mixAfterOneTime)
);

ecCartClear();
$subscriptionAdd = ecCartAdd($subscriptionProductId, 1);
$mixAfterSubscription = ecCartAdd($regularProductId, 1);
tsub(
    'one-time products cannot be added after a subscription is already in the cart',
    !empty($subscriptionAdd['ok'])
        && empty($mixAfterSubscription['ok'])
        && str_contains((string)($mixAfterSubscription['error'] ?? ''), 'separately'),
    json_encode($mixAfterSubscription)
);

ecCartClear();
$preparedSubscription = ecCartPrepareItem($subscriptionProductId, 1);
$orderId = 0;
$hydratedOrder = null;
$customerOrders = ['items' => [], 'total' => 0];

if (!empty($preparedSubscription['ok'])) {
    $orderResult = ecOrderCreate(ecommerceSubscriptionsOrderData($customer, $preparedSubscription['item']));
    $orderId = (int)($orderResult['order_id'] ?? 0);
    if ($orderId > 0) {
        $cleanupOrderIds[] = $orderId;
    }

    ecOrderMarkPaid($orderId);
    ecOrderMarkPaid($orderId);

    $subscriptionRows = ecDb()->query(
        'SELECT * FROM ec_subscriptions WHERE order_id = ? ORDER BY id ASC',
        [$orderId]
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $hydratedOrder = $orderId > 0 ? ecOrderGet($orderId, (int)$customer['id']) : null;
    $customerOrders = ecCustomerOrders((int)$customer['id'], 10, 0);

    tsub('paid subscription order creates exactly one subscription record', count($subscriptionRows) === 1, json_encode($subscriptionRows));

    if (!empty($subscriptionRows)) {
        $row = $subscriptionRows[0];
        tsub(
            'subscription record stores active lifecycle snapshots',
            (string)($row['status'] ?? '') === 'active'
                && (int)($row['product_id'] ?? 0) === $subscriptionProductId
                && (int)($row['quantity'] ?? 0) === 1
                && (string)($row['interval_unit'] ?? '') === 'month'
                && (int)($row['interval_count'] ?? 0) === 1
                && (int)($row['trial_days'] ?? 0) === 14
                && !empty($row['next_renewal_at']),
            json_encode($row)
        );
    }

    tsub(
        'hydrated orders and customer order history expose subscription state',
        is_array($hydratedOrder)
            && count((array)($hydratedOrder['subscriptions'] ?? [])) === 1
            && !empty($hydratedOrder['items'][0]['subscription'])
            && !empty($customerOrders['items'][0]['has_subscriptions']),
        json_encode([
            'order_subscriptions' => $hydratedOrder['subscriptions'] ?? [],
            'customer_orders' => $customerOrders['items'] ?? [],
        ])
    );
} else {
    tsub('subscription item can be prepared for order creation', false, json_encode($preparedSubscription));
}

$publicTemplate = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/public/product.disyl') ?: '';
$nativeTemplate = file_get_contents(__DIR__ . '/../storage/cms-themes/native-default/public/ecommerce/single-product.disyl') ?: '';
$checkoutTemplate = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/public/checkout.disyl') ?: '';
$orderTemplate = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/public/order-detail.disyl') ?: '';
$adminOrderTemplate = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/admin/order-detail.disyl') ?: '';
$myOrdersTemplate = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/public/my-orders.disyl') ?: '';
$adminProductTemplate = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/admin/product-edit.disyl') ?: '';

tsub(
    'storefront and admin templates expose subscription UI and messaging',
    str_contains($publicTemplate, 'Start Subscription')
        && str_contains($nativeTemplate, 'Start Subscription')
        && str_contains($checkoutTemplate, 'Subscription checkout.')
        && str_contains($orderTemplate, 'Subscriptions')
        && str_contains($adminOrderTemplate, 'Subscriptions')
        && str_contains($myOrdersTemplate, 'Subscription')
        && str_contains($adminProductTemplate, 'Subscription Plan')
);

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
tsub('no app.log critical errors', !str_contains($appLog, '[critical]'), $appLog !== '' ? substr($appLog, 0, 200) : '');
tsub('no PHP warnings or fatals in error.log', $errorLog === '' || (!str_contains($errorLog, 'PHP Warning') && !str_contains($errorLog, 'PHP Fatal')), $errorLog !== '' ? substr($errorLog, 0, 200) : '');

ecommerceSubscriptionsCleanup($cleanupProductIds, $cleanupOrderIds);

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