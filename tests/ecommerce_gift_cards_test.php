<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/ecommerce/cart';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/ecommerce/helpers.php';

$tenantId = (int)(moduleTenantSettingsTenantId() ?? app()->tenant()->current() ?? 0);
if ($tenantId > 0) {
    syncTenantCliMigrationsForTenant($tenantId, 'ecommerce');
}

$giftCardMigration = __DIR__ . '/../modules/ecommerce/database/migrations/019_ec_gift_card_coupon_type.sql';
if (is_file($giftCardMigration)) {
    app()->db()->exec((string)file_get_contents($giftCardMigration));
}

$pass = 0;
$fail = 0;
$errors = [];
$cleanupProductIds = [];
$cleanupCouponCodes = [];
$cleanupOrderIds = [];

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

function ecommerceGiftCardsUserId(): int
{
    $userId = (int)app()->db()->query('SELECT id FROM cms_users ORDER BY id ASC LIMIT 1')->fetchColumn();
    if ($userId < 1) {
        throw new RuntimeException('No cms_users row available for ecommerce gift card test');
    }

    return $userId;
}

function cleanupEcommerceGiftCardFixtures(array $productIds, array $couponCodes, array $orderIds): void
{
    $db = app()->db();

    if ($orderIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($orderIds), '?'));
        $db->prepare("DELETE FROM ec_order_status_history WHERE order_id IN ({$placeholders})")->execute($orderIds);
        $db->prepare("DELETE FROM ec_payment_transactions WHERE order_id IN ({$placeholders})")->execute($orderIds);
        $db->prepare("DELETE FROM ec_order_meta WHERE order_id IN ({$placeholders})")->execute($orderIds);
        $db->prepare("DELETE FROM ec_order_items WHERE order_id IN ({$placeholders})")->execute($orderIds);
        $db->prepare("DELETE FROM ec_orders WHERE id IN ({$placeholders})")->execute($orderIds);
    }

    if ($couponCodes !== []) {
        $placeholders = implode(', ', array_fill(0, count($couponCodes), '?'));
        $db->prepare("DELETE FROM ec_coupons WHERE code IN ({$placeholders})")->execute($couponCodes);
    }

    if ($productIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($productIds), '?'));
        $db->prepare("DELETE FROM cms_content_meta WHERE content_id IN ({$placeholders})")->execute($productIds);
        $db->prepare("DELETE FROM cms_content_categories WHERE content_id IN ({$placeholders})")->execute($productIds);
        $db->prepare("DELETE FROM cms_entity_capabilities WHERE entity_id IN ({$placeholders})")->execute($productIds);
        $db->prepare("DELETE FROM cms_content WHERE id IN ({$placeholders})")->execute($productIds);
    }
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== ECOMMERCE GIFT CARDS ===\n";

$userId = ecommerceGiftCardsUserId();
$seed = substr(bin2hex(random_bytes(4)), 0, 8);
$giftCardCode = 'GIFT-' . strtoupper($seed);
$cleanupCouponCodes[] = $giftCardCode;

ecDb()->execute(
    "INSERT INTO ec_coupons (code, type, value, min_order_amount, max_uses, expires_at, description, is_active, created_at, updated_at)
     VALUES (?, 'gift_card', 120.00, 0, NULL, NULL, ?, 1, NOW(), NOW())",
    [$giftCardCode, 'Gift card fixture']
);

$productId = ecProductCreate([
    'title' => 'Gift Card Fixture Product ' . $seed,
    'slug' => 'gift-card-fixture-product-' . strtolower($seed),
    'excerpt' => 'Gift card checkout fixture.',
    'status' => 'published',
    'price' => 70.00,
    'stock_qty' => 20,
    'track_stock' => true,
    'tax_class' => 'zero',
], $userId);
$cleanupProductIds[] = $productId;

$validation = ecCouponValidate($giftCardCode, 70.00);
$totalsFirst = ecCalculateTotals([[
    'product_id' => $productId,
    'qty' => 1,
    'price_snapshot' => 70.00,
    'product_title' => 'Gift Card Fixture Product ' . $seed,
    'sku' => 'GIFT-FIX-' . strtoupper($seed),
]], $giftCardCode);

$firstOrder = ecOrderCreate([
    'guest_email' => 'gift-card-' . strtolower($seed) . '@example.test',
    'guest_name' => 'Gift Card Buyer',
    'subtotal' => $totalsFirst['subtotal'],
    'discount_amount' => $totalsFirst['discount'],
    'tax_amount' => $totalsFirst['tax'],
    'shipping_amount' => $totalsFirst['shipping'],
    'total' => $totalsFirst['total'],
    'currency' => ecStoreBaseCurrencyCode(),
    'coupon_code' => $giftCardCode,
    'billing' => [
        'first_name' => 'Gift',
        'last_name' => 'Buyer',
        'email' => 'gift-card-' . strtolower($seed) . '@example.test',
    ],
    'cart_items' => [[
        'product_id' => $productId,
        'variant_id' => null,
        'qty' => 1,
        'price_snapshot' => 70.00,
        'product_title' => 'Gift Card Fixture Product ' . $seed,
        'sku' => 'GIFT-FIX-' . strtoupper($seed),
    ]],
    'defer_created_event' => true,
]);
$cleanupOrderIds[] = (int)($firstOrder['order_id'] ?? 0);

$rowAfterFirst = ecDb()->query("SELECT type, value, uses_count, is_active FROM ec_coupons WHERE code = ? LIMIT 1", [$giftCardCode])->fetch(\PDO::FETCH_ASSOC) ?: [];

$totalsSecond = ecCalculateTotals([[
    'product_id' => $productId,
    'qty' => 1,
    'price_snapshot' => 50.00,
    'product_title' => 'Gift Card Fixture Product ' . $seed,
    'sku' => 'GIFT-FIX-' . strtoupper($seed),
]], $giftCardCode);

$secondOrder = ecOrderCreate([
    'guest_email' => 'gift-card-' . strtolower($seed) . '@example.test',
    'guest_name' => 'Gift Card Buyer',
    'subtotal' => $totalsSecond['subtotal'],
    'discount_amount' => $totalsSecond['discount'],
    'tax_amount' => $totalsSecond['tax'],
    'shipping_amount' => $totalsSecond['shipping'],
    'total' => $totalsSecond['total'],
    'currency' => ecStoreBaseCurrencyCode(),
    'coupon_code' => $giftCardCode,
    'billing' => [
        'first_name' => 'Gift',
        'last_name' => 'Buyer',
        'email' => 'gift-card-' . strtolower($seed) . '@example.test',
    ],
    'cart_items' => [[
        'product_id' => $productId,
        'variant_id' => null,
        'qty' => 1,
        'price_snapshot' => 50.00,
        'product_title' => 'Gift Card Fixture Product ' . $seed,
        'sku' => 'GIFT-FIX-' . strtoupper($seed),
    ]],
    'defer_created_event' => true,
]);
$cleanupOrderIds[] = (int)($secondOrder['order_id'] ?? 0);

$rowAfterSecond = ecDb()->query("SELECT type, value, uses_count, is_active FROM ec_coupons WHERE code = ? LIMIT 1", [$giftCardCode])->fetch(\PDO::FETCH_ASSOC) ?: [];

$adminCouponsTemplate = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/admin/coupons.disyl') ?: '';
$cartTemplate = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/public/cart.disyl') ?: '';
$nativeCartTemplate = file_get_contents(__DIR__ . '/../storage/cms-themes/native-default/public/ecommerce/cart.disyl') ?: '';
$apiCouponsHandler = file_get_contents(__DIR__ . '/../modules/ecommerce/handlers/90-api-coupons.php') ?: '';
$pricingHelper = file_get_contents(__DIR__ . '/../modules/ecommerce/helpers/40-pricing.php') ?: '';

t('gift card coupon type validates successfully', !empty($validation['valid']) && (string)($validation['type'] ?? '') === 'gift_card', json_encode($validation));
t('gift card totals treat the code as balance-backed credit', (float)($totalsFirst['discount'] ?? 0) === 70.00 && (string)($totalsFirst['discount_label'] ?? '') === 'Gift Card' && (float)($totalsFirst['total'] ?? 0) === 0.00, json_encode($totalsFirst));
t('first gift card redemption decrements remaining balance instead of exhausting uses only', (float)($rowAfterFirst['value'] ?? 0) === 50.00 && (int)($rowAfterFirst['uses_count'] ?? 0) === 1 && (int)($rowAfterFirst['is_active'] ?? 0) === 1, json_encode($rowAfterFirst));
t('second gift card redemption exhausts balance and deactivates the code', (float)($rowAfterSecond['value'] ?? 0) === 0.00 && (int)($rowAfterSecond['uses_count'] ?? 0) === 2 && (int)($rowAfterSecond['is_active'] ?? 0) === 0, json_encode($rowAfterSecond));
t('admin coupon template exposes gift card creation copy', str_contains($adminCouponsTemplate, 'Gift Card') && str_contains($adminCouponsTemplate, 'Create Coupon or Gift Card'));
t('shared and native cart templates mention coupon or gift card redemption', str_contains($cartTemplate, 'Coupon or gift card code') && str_contains($nativeCartTemplate, 'Coupon or Gift Card'));
t('coupon API and pricing helpers normalize gift card types for create and update flows', str_contains($apiCouponsHandler, 'ecCouponNormalizeType') && str_contains($pricingHelper, "return ['percent', 'fixed', 'gift_card'];"));

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
t('no app.log critical errors', !str_contains($appLog, '[critical]'), $appLog !== '' ? substr($appLog, 0, 200) : '');
t('no PHP warnings or fatals in error.log', $errorLog === '' || (!str_contains($errorLog, 'PHP Warning') && !str_contains($errorLog, 'PHP Fatal')), $errorLog !== '' ? substr($errorLog, 0, 200) : '');

cleanupEcommerceGiftCardFixtures($cleanupProductIds, $cleanupCouponCodes, $cleanupOrderIds);

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