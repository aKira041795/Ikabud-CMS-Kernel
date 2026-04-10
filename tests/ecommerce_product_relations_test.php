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

$relationMigration = __DIR__ . '/../modules/ecommerce/database/migrations/014_ec_product_relations.sql';
if (is_file($relationMigration)) {
    app()->db()->exec((string)file_get_contents($relationMigration));
}

$pass = 0;
$fail = 0;
$errors = [];
$cleanupProductIds = [];

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

function ecommerceProductRelationsTestUserId(): int
{
    $userId = (int)app()->db()->query('SELECT id FROM cms_users ORDER BY id ASC LIMIT 1')->fetchColumn();
    if ($userId < 1) {
        throw new RuntimeException('No cms_users row available for ecommerce product relation test');
    }

    return $userId;
}

function cleanupEcommerceProductRelationFixtures(array $productIds): void
{
    if ($productIds === []) {
        return;
    }

    $db = app()->db();
    $placeholders = implode(', ', array_fill(0, count($productIds), '?'));
    $db->prepare("DELETE FROM ec_product_relations WHERE product_id IN ({$placeholders}) OR related_product_id IN ({$placeholders})")
        ->execute(array_merge($productIds, $productIds));
    $db->prepare("DELETE FROM cms_content_meta WHERE content_id IN ({$placeholders})")->execute($productIds);
    $db->prepare("DELETE FROM cms_entity_capabilities WHERE entity_id IN ({$placeholders})")->execute($productIds);
    $db->prepare("DELETE FROM cms_content WHERE id IN ({$placeholders})")->execute($productIds);
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');
ecSessionCartClear();

echo "\n=== ECOMMERCE PRODUCT RELATIONS ===\n";

$userId = ecommerceProductRelationsTestUserId();
$seed = substr(bin2hex(random_bytes(4)), 0, 8);

$sourceProductId = ecProductCreate([
    'title' => 'Relation Source ' . $seed,
    'slug' => 'relation-source-' . strtolower($seed),
    'excerpt' => 'Source product for manual relation coverage.',
    'status' => 'published',
    'price' => 149.00,
    'stock_qty' => 8,
    'track_stock' => true,
], $userId);
$upsellProductId = ecProductCreate([
    'title' => 'Upsell Product ' . $seed,
    'slug' => 'upsell-product-' . strtolower($seed),
    'excerpt' => 'Upsell fixture.',
    'status' => 'published',
    'price' => 199.00,
], $userId);
$relatedProductId = ecProductCreate([
    'title' => 'Related Product ' . $seed,
    'slug' => 'related-product-' . strtolower($seed),
    'excerpt' => 'Related fixture.',
    'status' => 'published',
    'price' => 129.00,
], $userId);
$crossSellProductId = ecProductCreate([
    'title' => 'Cross Sell Product ' . $seed,
    'slug' => 'cross-sell-product-' . strtolower($seed),
    'excerpt' => 'Cross-sell fixture.',
    'status' => 'published',
    'price' => 59.00,
], $userId);
$cleanupProductIds = [$sourceProductId, $upsellProductId, $relatedProductId, $crossSellProductId];

ecProductSaveRelations($sourceProductId, [
    'upsell' => [$upsellProductId],
    'related' => [$relatedProductId],
    'cross_sell' => [$crossSellProductId],
]);

$product = ecProductGet($sourceProductId) ?: [];
$relationIds = $product['relation_ids'] ?? [];
$detailSections = ecProductRecommendationSectionsForProduct($sourceProductId);
ecCartAdd($sourceProductId, 1, null);
$cart = ecCartGet();
$cartSections = ecCartRecommendationSections((array)($cart['items'] ?? []));
$relationOptions = ecProductAdminRelationOptions($sourceProductId, $relationIds);
$productTemplate = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/public/product.disyl') ?: '';
$cartTemplate = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/public/cart.disyl') ?: '';
$entityViewTemplate = file_get_contents(__DIR__ . '/../templates/modules/cms/public/entity.view.disyl') ?: '';
$relationBlockTemplate = file_get_contents(__DIR__ . '/../templates/modules/cms/public/blocks/ecommerce-product-relations.block.disyl') ?: '';

t('relation storage is available', ecProductRelationStorageAvailable());
t('product detail hydration keeps upsell relation ids', ($relationIds['upsell'] ?? []) === [$upsellProductId], json_encode($relationIds));
t('product detail hydration keeps related relation ids', ($relationIds['related'] ?? []) === [$relatedProductId], json_encode($relationIds));
t('product detail hydration keeps cross-sell relation ids', ($relationIds['cross_sell'] ?? []) === [$crossSellProductId], json_encode($relationIds));
t('product detail exposes upsell products', count((array)($product['upsell_products'] ?? [])) === 1);
t('product detail exposes related products', count((array)($product['related_products'] ?? [])) === 1);
t('product detail recommendation sections include upsell and related groups', count($detailSections) === 2, 'sections=' . count($detailSections));
t('cart recommendation sections include one cross-sell group', count($cartSections) === 1, 'sections=' . count($cartSections));
t('cart recommendation section contains the cross-sell product', (int)($cartSections[0]['items'][0]['id'] ?? 0) === $crossSellProductId);
t('admin relation options exclude the edited product', !in_array($sourceProductId, array_map(static fn(array $option): int => (int)$option['id'], $relationOptions), true));
t('product template includes the shared relation block', str_contains($productTemplate, 'ecommerce-product-relations.block.disyl'));
t('cart template includes the shared relation block', str_contains($cartTemplate, 'ecommerce-product-relations.block.disyl'));
t('canonical entity view includes the shared relation block', str_contains($entityViewTemplate, 'ecommerce-product-relations.block.disyl'));
t('shared relation block renders dynamic relation sections', str_contains($relationBlockTemplate, 'data-ecommerce-relation-type'));

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
t('no app.log critical errors', !str_contains($appLog, '[critical]'), $appLog !== '' ? substr($appLog, 0, 200) : '');
t('no PHP warnings or fatals in error.log', $errorLog === '' || (!str_contains($errorLog, 'PHP Warning') && !str_contains($errorLog, 'PHP Fatal')), $errorLog !== '' ? substr($errorLog, 0, 200) : '');

cleanupEcommerceProductRelationFixtures($cleanupProductIds);
ecSessionCartClear();

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