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

function ecommercePhase6EnsureColumn(string $table, string $column, string $definition): void
{
    $db = app()->db();
    try {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        $exists = (int)($stmt->fetchColumn() ?: 0) > 0;
        if ($exists) {
            return;
        }
        $db->exec("ALTER TABLE {$table} ADD COLUMN {$definition}");
    } catch (Throwable $e) {
    }
}

foreach ([
    __DIR__ . '/../modules/ecommerce/database/migrations/022_ec_subscriptions.sql',
    __DIR__ . '/../modules/ecommerce/database/migrations/025_ec_phase6_product_options_and_loyalty.sql',
] as $migrationFile) {
    if (is_file($migrationFile)) {
        app()->db()->exec((string)file_get_contents($migrationFile));
    }
}

ecommercePhase6EnsureColumn('ec_cart_items', 'options_json', 'options_json LONGTEXT NULL AFTER sku');
ecommercePhase6EnsureColumn('ec_order_items', 'snapshot_json', 'snapshot_json LONGTEXT NULL AFTER variant_label');
ecommercePhase6EnsureColumn('ec_carts', 'loyalty_points', 'loyalty_points INT NOT NULL DEFAULT 0 AFTER coupon_discount');

$pass = 0;
$fail = 0;
$errors = [];
$cleanupProductIds = [];
$cleanupOrderIds = [];

function tph6(string $label, bool $ok, string $detail = ''): void
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

function ecommercePhase6UserFixture(): array
{
    $row = app()->db()->query(
        "SELECT id, email, display_name FROM cms_users WHERE is_active = 1 ORDER BY id ASC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);

    if (!is_array($row) || (int)($row['id'] ?? 0) < 1) {
        throw new RuntimeException('No cms_users row available for ecommerce phase 6 test');
    }

    return $row;
}

function ecommercePhase6BuildOrderData(array $customer, array $items, array $totals = [], array $overrides = []): array
{
    $email = trim((string)($customer['email'] ?? 'phase6@example.com'));
    $name = trim((string)($customer['display_name'] ?? 'Phase Six Customer'));
    $parts = preg_split('/\s+/', $name) ?: [];
    $firstName = (string)($parts[0] ?? 'Phase');
    $lastName = (string)($parts[1] ?? 'Customer');

    $normalizedItems = [];
    $subtotal = 0.0;
    foreach ($items as $item) {
        $qty = max(1, (int)($item['qty'] ?? 1));
        $lineTotal = round((float)($item['line_total'] ?? ((float)($item['price_snapshot'] ?? 0.0) * $qty)), 2);
        $subtotal += $lineTotal;
        $normalizedItems[] = [
            'product_id' => (int)($item['product_id'] ?? 0),
            'variant_id' => $item['variant_id'] ?? null,
            'product_title' => (string)($item['product_title'] ?? 'Product'),
            'sku' => (string)($item['sku'] ?? ''),
            'price_snapshot' => (float)($item['price_snapshot'] ?? 0.0),
            'qty' => $qty,
            'variant_label' => $item['variant_label'] ?? null,
            'line_total' => $lineTotal,
            'options_json' => (string)($item['options_json'] ?? ''),
        ];
    }

    $discountAmount = round((float)($totals['discount'] ?? $totals['discount_amount'] ?? 0.0), 2);
    $taxAmount = round((float)($totals['tax'] ?? $totals['tax_amount'] ?? 0.0), 2);
    $shippingAmount = round((float)($totals['shipping'] ?? $totals['shipping_amount'] ?? 0.0), 2);
    $total = round((float)($totals['total'] ?? ($subtotal - $discountAmount + $taxAmount + $shippingAmount)), 2);

    return array_replace_recursive([
        'cart_items' => $normalizedItems,
        'subtotal' => round($subtotal, 2),
        'discount_amount' => $discountAmount,
        'tax_amount' => $taxAmount,
        'shipping_amount' => $shippingAmount,
        'total' => $total,
        'currency' => (string)($totals['currency'] ?? ecSettings('currency')),
        'coupon_code' => $totals['coupon']['code'] ?? null,
        'shipping_rate_id' => null,
        'source' => 'web',
        'billing' => [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'address_line1' => '123 Phase Six St',
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
        'loyalty_points_redeemed' => (int)($totals['loyalty_points_applied'] ?? 0),
        'loyalty_discount_amount' => (float)($totals['loyalty_discount_amount'] ?? 0.0),
    ], $overrides);
}

function ecommercePhase6Cleanup(array $productIds, array $orderIds): void
{
    ecCartClear();

    $ecDb = ecDb();
    $appDb = app()->db();

    foreach ($orderIds as $orderId) {
        $ecDb->execute('DELETE FROM ec_loyalty_ledger WHERE order_id = ?', [$orderId]);
        $ecDb->execute('DELETE FROM ec_bookings WHERE order_id = ?', [$orderId]);
        $ecDb->execute('DELETE FROM ec_memberships WHERE order_id = ?', [$orderId]);
        $ecDb->execute('DELETE FROM ec_subscriptions WHERE order_id = ?', [$orderId]);
        $ecDb->execute('DELETE FROM ec_order_licenses WHERE order_id = ?', [$orderId]);
        $ecDb->execute('DELETE FROM ec_order_status_history WHERE order_id = ?', [$orderId]);
        $ecDb->execute('DELETE FROM ec_order_items WHERE order_id = ?', [$orderId]);
        $ecDb->execute('DELETE FROM ec_order_meta WHERE order_id = ?', [$orderId]);
        $ecDb->execute('DELETE FROM ec_payment_transactions WHERE order_id = ?', [$orderId]);
        $ecDb->execute('DELETE FROM ec_orders WHERE id = ?', [$orderId]);
    }

    if ($productIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($productIds), '?'));
        $appDb->prepare("DELETE FROM cms_content_meta WHERE content_id IN ({$placeholders})")->execute($productIds);
        $appDb->prepare("DELETE FROM cms_content_categories WHERE content_id IN ({$placeholders})")->execute($productIds);
        $appDb->prepare("DELETE FROM cms_entity_capabilities WHERE entity_id IN ({$placeholders})")->execute($productIds);
        $appDb->prepare("DELETE FROM cms_content WHERE id IN ({$placeholders})")->execute($productIds);
    }

    app()->setUser([]);
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');
ecCartClear();

echo "\n=== ECOMMERCE PHASE 6 FEATURES ===\n";

$customer = ecommercePhase6UserFixture();
$userId = (int)$customer['id'];
$initialBalance = function_exists('ecCustomerLoyaltyPointsBalance') ? ecCustomerLoyaltyPointsBalance($userId) : 0;
$seed = strtolower(substr(bin2hex(random_bytes(4)), 0, 8));
$membershipTier = 'vip-' . $seed;

$currentUser = [
    'id' => $userId,
    'email' => (string)$customer['email'],
    'display_name' => (string)($customer['display_name'] ?? 'Phase Six Customer'),
    'role' => 'customer',
    'source' => 'cms',
];
app()->setUser($currentUser);

$membershipProductId = ecProductCreate([
    'title' => 'VIP Access ' . strtoupper($seed),
    'slug' => 'vip-access-' . $seed,
    'excerpt' => 'Membership activation product.',
    'status' => 'published',
    'price' => 150.00,
    'stock_qty' => 50,
    'track_stock' => true,
    'is_membership_product' => true,
    'membership_tier' => $membershipTier,
    'membership_duration_days' => 30,
], $userId);

$gatedProductId = ecProductCreate([
    'title' => 'Members Only Product ' . strtoupper($seed),
    'slug' => 'members-only-product-' . $seed,
    'excerpt' => 'Requires a matching membership tier.',
    'status' => 'published',
    'price' => 49.00,
    'stock_qty' => 25,
    'track_stock' => true,
    'required_membership_tiers_text' => $membershipTier,
], $userId);

$bookingProductId = ecProductCreate([
    'title' => 'Consultation Session ' . strtoupper($seed),
    'slug' => 'consultation-session-' . $seed,
    'excerpt' => 'Bookable service with add-ons.',
    'status' => 'published',
    'price' => 120.00,
    'stock_qty' => 30,
    'track_stock' => true,
    'addon_lines' => "Workbook | 5.00 | Printed workbook\nExtended Session | 15.00 | Add thirty minutes",
    'booking_enabled' => true,
    'booking_duration_minutes' => 90,
    'booking_notice_hours' => 1,
    'booking_available_weekdays' => [0, 1, 2, 3, 4, 5, 6],
    'booking_time_slots' => "09:00\n14:00",
], $userId);

$cleanupProductIds = [$membershipProductId, $gatedProductId, $bookingProductId];

$membershipProduct = ecProductGet($membershipProductId);
$bookingProduct = ecProductGet($bookingProductId);
$bookingDetail = ecBuildStorefrontDetailContext($bookingProduct, ['route_kind' => 'product_detail']);
$membershipBridgePayload = ecProductBridgeEventPayload($membershipProductId);
$bookingAddonIds = array_values(array_map(static fn(array $addon): string => (string)($addon['id'] ?? ''), (array)($bookingProduct['addons'] ?? [])));
$moduleManifest = file_get_contents(__DIR__ . '/../modules/ecommerce/module.json') ?: '';

tph6(
    'membership product hydration exposes tier and membership product type',
    !empty($membershipProduct['is_membership_product'])
        && (string)($membershipProduct['product_type'] ?? '') === 'membership'
        && (string)($membershipProduct['membership_tier'] ?? '') === $membershipTier,
    json_encode($membershipProduct['membership_summary'] ?? [])
);

tph6(
    'bookable product detail context exposes add-ons and booking configuration',
    (int)count((array)($bookingProduct['addons'] ?? [])) === 2
        && !empty($bookingProduct['booking']['enabled'])
        && (int)count((array)($bookingDetail['product']['addons'] ?? [])) === 2
        && !empty($bookingDetail['product']['booking']['enabled']),
    json_encode($bookingDetail['product'] ?? [])
);

tph6(
    'membership bridge payload marks the product as a membership offering',
    !empty($membershipBridgePayload['is_membership_product'])
        && (string)($membershipBridgePayload['membership_tier'] ?? '') === $membershipTier,
    json_encode($membershipBridgePayload)
);

tph6(
    'module manifest declares the phase 6 tables and migration',
    str_contains($moduleManifest, 'ec_memberships')
        && str_contains($moduleManifest, 'ec_loyalty_ledger')
        && str_contains($moduleManifest, 'ec_bookings')
        && str_contains($moduleManifest, '025_ec_phase6_product_options_and_loyalty.sql'),
    $moduleManifest
);

ecCartClear();
$blockedGatedAdd = ecCartAdd($gatedProductId, 1);
tph6(
    'membership-gated products are blocked before activation',
    empty($blockedGatedAdd['ok']) && str_contains((string)($blockedGatedAdd['error'] ?? ''), 'membership'),
    json_encode($blockedGatedAdd)
);

$preparedMembership = ecCartPrepareItem($membershipProductId, 1);
$membershipOrderId = 0;

if (!empty($preparedMembership['ok'])) {
    $membershipOrder = ecOrderCreate(ecommercePhase6BuildOrderData($customer, [$preparedMembership['item']]));
    $membershipOrderId = (int)($membershipOrder['order_id'] ?? 0);
    if ($membershipOrderId > 0) {
        $cleanupOrderIds[] = $membershipOrderId;
        ecOrderMarkPaid($membershipOrderId);
    }
}

$memberships = function_exists('ecMembershipsForCustomer')
    ? ecMembershipsForCustomer($userId, (string)$customer['email'])
    : [];
$matchingMemberships = array_values(array_filter($memberships, static fn(array $membership): bool => (string)($membership['membership_tier'] ?? '') === $membershipTier));
$balanceAfterMembership = function_exists('ecCustomerLoyaltyPointsBalance') ? ecCustomerLoyaltyPointsBalance($userId) : 0;

tph6(
    'paid membership orders activate the purchased membership tier',
    $membershipOrderId > 0
        && (int)count($matchingMemberships) === 1
        && !empty($matchingMemberships[0]['is_active']),
    json_encode($matchingMemberships)
);

tph6(
    'paid membership orders award loyalty points',
    $balanceAfterMembership >= $initialBalance + 150,
    json_encode(['initial' => $initialBalance, 'after' => $balanceAfterMembership])
);

ecCartClear();
$allowedGatedAdd = ecCartAdd($gatedProductId, 1);
tph6(
    'membership-gated products become available after activation',
    !empty($allowedGatedAdd['ok']),
    json_encode($allowedGatedAdd)
);

ecCartClear();
$bookingDate = date('Y-m-d', strtotime('+2 days'));
$bookingOptions = [
    'add_ons' => $bookingAddonIds,
    'booking' => [
        'date' => $bookingDate,
        'time' => '09:00',
        'notes' => 'Please prepare the extended session materials.',
    ],
];

$preparedBooking = ecCartPrepareItem($bookingProductId, 1, null, $bookingOptions);
tph6(
    'bookable products snapshot selected add-ons and appointment details into the cart item',
    !empty($preparedBooking['ok'])
        && (int)count((array)($preparedBooking['item']['selected_addons'] ?? [])) === 2
        && abs((float)($preparedBooking['item']['addon_total'] ?? 0.0) - 20.0) < 0.001
        && !empty($preparedBooking['item']['booking']['has_booking'])
        && str_contains((string)($preparedBooking['item']['options_json'] ?? ''), 'selected_addons'),
    json_encode($preparedBooking)
);

$bookingAdd = ecCartAdd($bookingProductId, 1, null, $bookingOptions);
$loyaltyApply = function_exists('ecCartApplyLoyalty') ? ecCartApplyLoyalty(100) : ['ok' => false];
$bookingCart = ecCartGet();

tph6(
    'loyalty redemption applies to a cart after points are earned',
    !empty($bookingAdd['ok'])
        && !empty($loyaltyApply['ok'])
        && (int)($bookingCart['totals']['loyalty_points_applied'] ?? 0) === 100,
    json_encode($bookingCart['totals'] ?? [])
);

$bookingOrderId = 0;
$pendingBookings = [];
$confirmedBookings = [];
$bookingOrder = null;

if (!empty($bookingCart['items'])) {
    $bookingOrderResult = ecOrderCreate(ecommercePhase6BuildOrderData($customer, (array)$bookingCart['items'], (array)$bookingCart['totals']));
    $bookingOrderId = (int)($bookingOrderResult['order_id'] ?? 0);
    if ($bookingOrderId > 0) {
        $cleanupOrderIds[] = $bookingOrderId;
        $pendingBookings = ecDb()->query('SELECT * FROM ec_bookings WHERE order_id = ? ORDER BY id ASC', [$bookingOrderId])->fetchAll(PDO::FETCH_ASSOC) ?: [];
        ecOrderMarkPaid($bookingOrderId);
        $confirmedBookings = ecDb()->query('SELECT * FROM ec_bookings WHERE order_id = ? ORDER BY id ASC', [$bookingOrderId])->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $bookingOrder = ecOrderGet($bookingOrderId, $userId);
    }
}

$bookingSnapshot = $bookingOrderId > 0
    ? (string)(ecDb()->query('SELECT snapshot_json FROM ec_order_items WHERE order_id = ? ORDER BY id ASC LIMIT 1', [$bookingOrderId])->fetchColumn() ?: '')
    : '';

tph6(
    'booking orders persist pending appointments before payment and confirm them after payment',
    $bookingOrderId > 0
        && (int)count($pendingBookings) === 1
        && (string)($pendingBookings[0]['status'] ?? '') === 'pending'
        && (int)count($confirmedBookings) === 1
        && (string)($confirmedBookings[0]['status'] ?? '') === 'confirmed',
    json_encode(['pending' => $pendingBookings, 'confirmed' => $confirmedBookings])
);

tph6(
    'booking order hydration restores line item snapshots, booking records, and membership/bookings badges',
    str_contains($bookingSnapshot, 'Workbook')
        && is_array($bookingOrder)
        && (int)count((array)($bookingOrder['items'][0]['selected_addons'] ?? [])) === 2
        && !empty($bookingOrder['items'][0]['booking']['has_booking'])
        && !empty($bookingOrder['items'][0]['booking_record'])
        && (int)count((array)($bookingOrder['bookings'] ?? [])) === 1,
    json_encode($bookingOrder['items'][0] ?? [])
);

$loyaltyEntries = ecDb()->query(
    'SELECT order_id, entry_type, points FROM ec_loyalty_ledger WHERE order_id IN (?, ?) ORDER BY order_id ASC, id ASC',
    [$membershipOrderId, $bookingOrderId]
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$customerOrders = ecCustomerOrders($userId, 20, 0);
$orderFlags = [];
foreach ((array)($customerOrders['items'] ?? []) as $orderRow) {
    $orderFlags[(int)($orderRow['id'] ?? 0)] = $orderRow;
}

tph6(
    'loyalty ledger records both earning and redemption entries for phase 6 orders',
    count(array_filter($loyaltyEntries, static fn(array $row): bool => (int)($row['order_id'] ?? 0) === $membershipOrderId && (string)($row['entry_type'] ?? '') === 'earn' && (int)($row['points'] ?? 0) > 0)) === 1
        && count(array_filter($loyaltyEntries, static fn(array $row): bool => (int)($row['order_id'] ?? 0) === $bookingOrderId && (string)($row['entry_type'] ?? '') === 'redeem' && (int)($row['points'] ?? 0) === -100)) === 1
        && count(array_filter($loyaltyEntries, static fn(array $row): bool => (int)($row['order_id'] ?? 0) === $bookingOrderId && (string)($row['entry_type'] ?? '') === 'earn' && (int)($row['points'] ?? 0) > 0)) === 1,
    json_encode($loyaltyEntries)
);

tph6(
    'customer order listings include membership and booking badges for the new order types',
    !empty($orderFlags[$membershipOrderId]['has_memberships'])
        && !empty($orderFlags[$bookingOrderId]['has_bookings']),
    json_encode($orderFlags)
);

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));

tph6('no app.log critical errors', !str_contains($appLog, '[critical]'), $appLog !== '' ? substr($appLog, 0, 200) : '');
tph6('no PHP warnings or fatals in error.log', $errorLog === '' || (!str_contains($errorLog, 'PHP Warning') && !str_contains($errorLog, 'PHP Fatal')), $errorLog !== '' ? substr($errorLog, 0, 200) : '');

ecommercePhase6Cleanup($cleanupProductIds, $cleanupOrderIds);

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