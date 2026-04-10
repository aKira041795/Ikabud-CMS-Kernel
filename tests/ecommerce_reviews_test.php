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

$reviewMigration = __DIR__ . '/../modules/ecommerce/database/migrations/013_ec_reviews.sql';
if (is_file($reviewMigration)) {
    app()->db()->exec((string)file_get_contents($reviewMigration));
}

$pass = 0;
$fail = 0;
$errors = [];
$cleanupOrderIds = [];
$cleanupProductIds = [];
$cleanupReviewIds = [];

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

function ecommerceReviewTestUser(): array
{
    $row = app()->db()->query('SELECT id, username, email, display_name, role FROM cms_users ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('No cms_users row available for ecommerce review test');
    }

    $row['source'] = 'cms';
    return $row;
}

function cleanupEcommerceReviewFixtures(array $productIds, array $reviewIds, array $orderIds): void
{
    $db = app()->db();

    foreach ($reviewIds as $reviewId) {
        $db->prepare('DELETE FROM ec_reviews WHERE id = ?')->execute([(int)$reviewId]);
    }

    foreach ($orderIds as $orderId) {
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

echo "\n=== ECOMMERCE REVIEWS ===\n";

$user = ecommerceReviewTestUser();
$seed = substr(bin2hex(random_bytes(4)), 0, 8);
$productId = ecProductCreate([
    'title' => 'Review Fixture ' . $seed,
    'slug' => 'review-fixture-' . strtolower($seed),
    'excerpt' => 'Fixture product for ecommerce review testing',
    'body' => '<p>Review fixture body</p>',
    'status' => 'published',
    'price' => 199.00,
    'sku' => 'REV-' . strtoupper($seed),
], (int)$user['id']);
$cleanupProductIds[] = $productId;

$order = ecOrderCreate([
    'cart_items' => [[
        'product_id' => $productId,
        'variant_id' => null,
        'product_title' => 'Review Fixture ' . $seed,
        'sku' => 'REV-' . strtoupper($seed),
        'price_snapshot' => 199.00,
        'qty' => 1,
        'variant_label' => null,
    ]],
    'subtotal' => 199.00,
    'discount_amount' => 0.00,
    'tax_amount' => 0.00,
    'shipping_amount' => 0.00,
    'total' => 199.00,
    'currency' => 'PHP',
    'coupon_code' => null,
    'shipping_rate_id' => null,
    'source' => 'web',
    'billing' => [
        'first_name' => 'Review',
        'last_name' => 'Tester',
        'email' => (string)$user['email'],
        'address_line1' => '123 Review Street',
        'address_line2' => '',
        'city' => 'Manila',
        'state' => 'Metro Manila',
        'postal_code' => '1000',
        'country' => 'PH',
        'phone' => '09170000000',
    ],
    'shipping' => [],
    'guest_email' => (string)$user['email'],
    'guest_name' => 'Review Tester',
    'customer_id' => (int)$user['id'],
    'customer_note' => '',
]);
$cleanupOrderIds[] = (int)$order['order_id'];

$approved = ecReviewCreate($productId, [
    'rating' => 5,
    'review_body' => 'Excellent product for review testing with clear output and reliable behavior.',
], $user);
$cleanupReviewIds[] = (int)$approved['review_id'];

$pending = ecReviewCreate($productId, [
    'guest_name' => 'Pending Guest',
    'guest_email' => 'pending-review@example.test',
    'rating' => 3,
    'review_body' => 'This pending review should stay out of the public list until moderated.',
], null);
$cleanupReviewIds[] = (int)$pending['review_id'];

ecReviewSetStatus((int)$approved['review_id'], 'approved', (int)$user['id']);

$summary = ecReviewSummary($productId);
$publicReviews = ecReviewList(['product_id' => $productId, 'status' => 'approved', 'limit' => 10, 'offset' => 0]);
$adminPending = ecReviewList(['product_id' => $productId, 'status' => 'pending', 'limit' => 10, 'offset' => 0]);
$product = ecProductGet($productId) ?: [];
$productList = ecProductList(['status' => 'published', 'search' => $seed, 'limit' => 10, 'offset' => 0]);
$listItem = $productList['items'][0] ?? [];
$entityViewTemplate = file_get_contents(__DIR__ . '/../templates/modules/cms/public/entity.view.disyl') ?: '';
$entityListTemplate = file_get_contents(__DIR__ . '/../templates/modules/cms/public/entity.list.disyl') ?: '';

t('verified purchase is detected for purchased customer review', !empty($approved['verified_purchase']));
t('approved summary counts only approved reviews', (int)($summary['approved_count'] ?? 0) === 1, json_encode($summary));
t('approved summary average rating is correct', abs((float)($summary['average_rating'] ?? 0.0) - 5.0) < 0.001, json_encode($summary));
t('public review list only returns approved review', (int)($publicReviews['total'] ?? 0) === 1, 'total=' . (int)($publicReviews['total'] ?? 0));
t('pending review stays in moderation queue', (int)($adminPending['total'] ?? 0) === 1, 'pending=' . (int)($adminPending['total'] ?? 0));
t('product detail hydration includes review summary', (int)($product['review_summary']['approved_count'] ?? 0) === 1);
t('product detail hydration includes approved review rows', count((array)($product['reviews'] ?? [])) === 1);
t('product list includes review summary for cards', (int)($listItem['review_summary']['approved_count'] ?? 0) === 1);
t('canonical entity view template includes ecommerce review block', str_contains($entityViewTemplate, 'ecommerce-reviews.block.disyl'));
t('canonical entity list template renders the review card slot', str_contains($entityListTemplate, 'item.list_card_review_html'));

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
t('no app.log critical errors', !str_contains($appLog, '[critical]'), $appLog !== '' ? substr($appLog, 0, 200) : '');
t('no PHP warnings or fatals in error.log', $errorLog === '' || (!str_contains($errorLog, 'PHP Warning') && !str_contains($errorLog, 'PHP Fatal')), $errorLog !== '' ? substr($errorLog, 0, 200) : '');

cleanupEcommerceReviewFixtures($cleanupProductIds, $cleanupReviewIds, $cleanupOrderIds);

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