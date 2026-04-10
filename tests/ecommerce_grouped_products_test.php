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

$groupedMigration = __DIR__ . '/../modules/ecommerce/database/migrations/020_ec_grouped_products.sql';
if (is_file($groupedMigration)) {
    app()->db()->exec((string)file_get_contents($groupedMigration));
}

$pass = 0;
$fail = 0;
$errors = [];
$cleanupProductIds = [];

function tg(string $label, bool $ok, string $detail = ''): void
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

function ecommerceGroupedProductsUserId(): int
{
    $userId = (int)app()->db()->query('SELECT id FROM cms_users ORDER BY id ASC LIMIT 1')->fetchColumn();
    if ($userId < 1) {
        throw new RuntimeException('No cms_users row available for grouped products test');
    }

    return $userId;
}

function cleanupGroupedProductFixtures(array $productIds): void
{
    if ($productIds === []) {
        return;
    }

    $db = app()->db();
    $placeholders = implode(', ', array_fill(0, count($productIds), '?'));
    $db->prepare("DELETE FROM ec_product_group_items WHERE product_id IN ({$placeholders}) OR child_product_id IN ({$placeholders})")
        ->execute(array_merge($productIds, $productIds));
    $db->prepare("DELETE FROM cms_content_meta WHERE content_id IN ({$placeholders})")->execute($productIds);
    $db->prepare("DELETE FROM cms_content_categories WHERE content_id IN ({$placeholders})")->execute($productIds);
    $db->prepare("DELETE FROM cms_entity_capabilities WHERE entity_id IN ({$placeholders})")->execute($productIds);
    $db->prepare("DELETE FROM cms_content WHERE id IN ({$placeholders})")->execute($productIds);
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');
ecCartClear();

echo "\n=== ECOMMERCE GROUPED PRODUCTS ===\n";

$userId = ecommerceGroupedProductsUserId();
$seed = substr(bin2hex(random_bytes(4)), 0, 8);

$childOneId = ecProductCreate([
    'title' => 'Grouped Child One ' . $seed,
    'slug' => 'grouped-child-one-' . strtolower($seed),
    'excerpt' => 'Grouped child one.',
    'status' => 'published',
    'price' => 125.00,
    'stock_qty' => 20,
    'track_stock' => true,
], $userId);

$childTwoId = ecProductCreate([
    'title' => 'Grouped Child Two ' . $seed,
    'slug' => 'grouped-child-two-' . strtolower($seed),
    'excerpt' => 'Grouped child two.',
    'status' => 'published',
    'price' => 75.00,
    'stock_qty' => 20,
    'track_stock' => true,
], $userId);

$parentId = ecProductCreate([
    'title' => 'Grouped Parent ' . $seed,
    'slug' => 'grouped-parent-' . strtolower($seed),
    'excerpt' => 'Grouped parent product.',
    'status' => 'published',
    'price' => 0.00,
    'stock_qty' => 0,
    'track_stock' => false,
    'grouped_children' => [
        ['product_id' => $childOneId, 'qty' => 2],
        ['product_id' => $childTwoId, 'qty' => 1],
    ],
], $userId);

$cleanupProductIds = [$childOneId, $childTwoId, $parentId];

$parentProduct = ecProductGet($parentId);
$storefront = ecBuildStorefrontDetailContext($parentProduct, ['route_kind' => 'product_detail']);
$groupedAddResult = ecCartAddGroupedItems([
    ['product_id' => $childOneId, 'qty' => 2],
    ['product_id' => $childTwoId, 'qty' => 1],
]);
$cart = ecCartGet();

$adminTemplate = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/admin/product-edit.disyl') ?: '';
$sharedProductTemplate = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/public/product.disyl') ?: '';
$nativeProductTemplate = file_get_contents(__DIR__ . '/../storage/cms-themes/native-default/public/ecommerce/single-product.disyl') ?: '';
$cartApiHandler = file_get_contents(__DIR__ . '/../modules/ecommerce/handlers/82-api-cart.php') ?: '';

$cartItems = is_array($cart['items'] ?? null) ? $cart['items'] : [];

tg('grouped child storage returns both configured child products', count($parentProduct['grouped_children'] ?? []) === 2, json_encode($parentProduct['grouped_children'] ?? []));
tg('storefront detail context exposes grouped child rows with default quantities', count($storefront['product']['grouped_children'] ?? []) === 2 && (int)($storefront['product']['grouped_children'][0]['grouped_qty'] ?? 0) === 2, json_encode($storefront['product']['grouped_children'] ?? []));
tg('grouped cart add creates normal individual cart lines', !empty($groupedAddResult['ok']) && count($cartItems) === 2 && (int)($cartItems[0]['qty'] ?? 0) === 2 && (int)($cartItems[1]['qty'] ?? 0) === 1, json_encode($cartItems));
tg('grouped cart add updates totals using child line items only', (float)($cart['totals']['subtotal'] ?? 0) === 325.00 && (int)($cart['totals']['item_count'] ?? 0) === 3, json_encode($cart['totals'] ?? []));
tg('admin product form exposes grouped product selection UI', str_contains($adminTemplate, 'Grouped Products') && str_contains($adminTemplate, 'grouped_product_ids[]'));
tg('shared and native product templates render grouped product add-to-cart flows', str_contains($sharedProductTemplate, 'addGroupedToCart') && str_contains($nativeProductTemplate, 'Add Selected Items'));
tg('cart API handler accepts grouped_items payloads', str_contains($cartApiHandler, 'grouped_items') && str_contains($cartApiHandler, 'ecCartAddGroupedItems'));

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
tg('no app.log critical errors', !str_contains($appLog, '[critical]'), $appLog !== '' ? substr($appLog, 0, 200) : '');
tg('no PHP warnings or fatals in error.log', $errorLog === '' || (!str_contains($errorLog, 'PHP Warning') && !str_contains($errorLog, 'PHP Fatal')), $errorLog !== '' ? substr($errorLog, 0, 200) : '');

ecCartClear();
cleanupGroupedProductFixtures($cleanupProductIds);

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
