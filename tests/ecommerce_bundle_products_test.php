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

$bundleMigration = __DIR__ . '/../modules/ecommerce/database/migrations/021_ec_product_bundles.sql';
if (is_file($bundleMigration)) {
    app()->db()->exec((string)file_get_contents($bundleMigration));
}

$pass = 0;
$fail = 0;
$errors = [];
$cleanupProductIds = [];

function tb(string $label, bool $ok, string $detail = ''): void
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

function ecommerceBundleProductsUserId(): int
{
    $userId = (int)app()->db()->query('SELECT id FROM cms_users ORDER BY id ASC LIMIT 1')->fetchColumn();
    if ($userId < 1) {
        throw new RuntimeException('No cms_users row available for ecommerce bundle products test');
    }

    return $userId;
}

function cleanupBundleProductFixtures(array $productIds): void
{
    ecCartClear();
    if ($productIds === []) {
        return;
    }

    $db = app()->db();
    $placeholders = implode(', ', array_fill(0, count($productIds), '?'));
    $db->prepare("DELETE FROM ec_product_bundle_items WHERE product_id IN ({$placeholders}) OR child_product_id IN ({$placeholders})")
        ->execute(array_merge($productIds, $productIds));
    $db->prepare("DELETE FROM cms_content_meta WHERE content_id IN ({$placeholders})")->execute($productIds);
    $db->prepare("DELETE FROM cms_content_categories WHERE content_id IN ({$placeholders})")->execute($productIds);
    $db->prepare("DELETE FROM cms_entity_capabilities WHERE entity_id IN ({$placeholders})")->execute($productIds);
    $db->prepare("DELETE FROM cms_content WHERE id IN ({$placeholders})")->execute($productIds);
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');
ecCartClear();

echo "\n=== ECOMMERCE BUNDLE PRODUCTS ===\n";

$userId = ecommerceBundleProductsUserId();
$seed = substr(bin2hex(random_bytes(4)), 0, 8);

$childOneId = ecProductCreate([
    'title' => 'Bundle Child One ' . $seed,
    'slug' => 'bundle-child-one-' . strtolower($seed),
    'excerpt' => 'Bundle child one.',
    'status' => 'published',
    'price' => 40.00,
    'stock_qty' => 20,
    'track_stock' => true,
], $userId);

$childTwoId = ecProductCreate([
    'title' => 'Bundle Child Two ' . $seed,
    'slug' => 'bundle-child-two-' . strtolower($seed),
    'excerpt' => 'Bundle child two.',
    'status' => 'published',
    'price' => 20.00,
    'stock_qty' => 20,
    'track_stock' => true,
], $userId);

$parentId = ecProductCreate([
    'title' => 'Bundle Parent ' . $seed,
    'slug' => 'bundle-parent-' . strtolower($seed),
    'excerpt' => 'Bundle parent product.',
    'status' => 'published',
    'price' => 70.00,
    'stock_qty' => 0,
    'track_stock' => false,
    'bundle_children' => [
        ['product_id' => $childOneId, 'qty' => 1],
        ['product_id' => $childTwoId, 'qty' => 2],
    ],
], $userId);

$cleanupProductIds = [$childOneId, $childTwoId, $parentId];

$parentProduct = ecProductGet($parentId);
$storefront = ecBuildStorefrontDetailContext($parentProduct, ['route_kind' => 'product_detail']);
$bridgePayload = ecProductBridgeEventPayload($parentId);

$standaloneAdd = ecCartAdd($childOneId, 1);
$bundleAdd = ecCartAdd($parentId, 1);
$cart = ecCartGet();

$cartItems = is_array($cart['items'] ?? null) ? $cart['items'] : [];
$childOneLines = array_values(array_filter($cartItems, static fn(array $item): bool => (int)($item['product_id'] ?? 0) === $childOneId));
$childTwoLines = array_values(array_filter($cartItems, static fn(array $item): bool => (int)($item['product_id'] ?? 0) === $childTwoId));

$hasRegularChildOne = false;
$hasBundledChildOne = false;
foreach ($childOneLines as $line) {
    $price = (float)($line['price_snapshot'] ?? 0);
    if ((int)($line['qty'] ?? 0) === 1 && abs($price - 40.0) < 0.001) {
        $hasRegularChildOne = true;
    }
    if ((int)($line['qty'] ?? 0) === 1 && abs($price - 35.0) < 0.001) {
        $hasBundledChildOne = true;
    }
}

$sharedProductTemplate = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/public/product.disyl') ?: '';
$nativeProductTemplate = file_get_contents(__DIR__ . '/../storage/cms-themes/native-default/public/ecommerce/single-product.disyl') ?: '';
$adminTemplate = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/admin/product-edit.disyl') ?: '';

tb('bundle child storage returns fixed bundle products and bundle type', count($parentProduct['bundle_children'] ?? []) === 2 && (string)($parentProduct['product_type'] ?? '') === 'bundle', json_encode($parentProduct['bundle_children'] ?? []));
tb('storefront detail context exposes bundle summary and child quantities', count($storefront['product']['bundle_children'] ?? []) === 2 && (int)($storefront['product']['bundle_summary']['item_count'] ?? 0) === 3 && abs((float)($storefront['product']['bundle_summary']['savings'] ?? 0) - 10.0) < 0.001, json_encode($storefront['product']['bundle_summary'] ?? []));
tb('bundle bridge payload marks the parent product as bundle', (string)($bridgePayload['product_type'] ?? '') === 'bundle', json_encode($bridgePayload));
tb('bundle add explodes child lines and keeps bundle-priced children separate from regular cart lines', !empty($standaloneAdd['ok']) && !empty($bundleAdd['ok']) && count($cartItems) === 3 && count($childOneLines) === 2 && count($childTwoLines) === 1 && $hasRegularChildOne && $hasBundledChildOne, json_encode($cartItems));
tb('bundle child quantities and totals use the parent bundle price', (int)($childTwoLines[0]['qty'] ?? 0) === 2 && abs((float)($childTwoLines[0]['price_snapshot'] ?? 0) - 17.5) < 0.001 && abs((float)($cart['totals']['subtotal'] ?? 0) - 110.0) < 0.001 && (int)($cart['totals']['item_count'] ?? 0) === 4, json_encode($cart['totals'] ?? []));
tb('admin and storefront templates expose bundle configuration and add-to-cart UI', str_contains($adminTemplate, 'Bundle Items') && str_contains($sharedProductTemplate, 'Add Bundle to Cart') && str_contains($nativeProductTemplate, 'Bundle Includes'));

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
tb('no app.log critical errors', !str_contains($appLog, '[critical]'), $appLog !== '' ? substr($appLog, 0, 200) : '');
tb('no PHP warnings or fatals in error.log', $errorLog === '' || (!str_contains($errorLog, 'PHP Warning') && !str_contains($errorLog, 'PHP Fatal')), $errorLog !== '' ? substr($errorLog, 0, 200) : '');

cleanupBundleProductFixtures($cleanupProductIds);

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