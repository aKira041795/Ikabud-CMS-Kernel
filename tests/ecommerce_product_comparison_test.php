<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/ecommerce/compare';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/ecommerce/helpers.php';
require_once __DIR__ . '/../modules/ecommerce/handlers.php';

$tenantId = (int)(moduleTenantSettingsTenantId() ?? app()->tenant()->current() ?? 0);
if ($tenantId > 0) {
    syncTenantCliMigrationsForTenant($tenantId, 'ecommerce');
}

$pass = 0;
$fail = 0;
$errors = [];
$cleanupProductIds = [];

function tcp(string $label, bool $ok, string $detail = ''): void
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

function ecommerceProductComparisonUserId(): int
{
    $userId = (int)app()->db()->query('SELECT id FROM cms_users ORDER BY id ASC LIMIT 1')->fetchColumn();
    if ($userId < 1) {
        throw new RuntimeException('No cms_users row available for ecommerce product comparison test');
    }

    return $userId;
}

function cleanupEcommerceProductComparisonFixtures(array $productIds): void
{
    if ($productIds !== []) {
        $db = app()->db();
        $placeholders = implode(', ', array_fill(0, count($productIds), '?'));
        $db->prepare("DELETE FROM cms_content_meta WHERE content_id IN ({$placeholders})")->execute($productIds);
        $db->prepare("DELETE FROM cms_content_categories WHERE content_id IN ({$placeholders})")->execute($productIds);
        $db->prepare("DELETE FROM cms_entity_capabilities WHERE entity_id IN ({$placeholders})")->execute($productIds);
        $db->prepare("DELETE FROM cms_content WHERE id IN ({$placeholders})")->execute($productIds);
    }

    ecCompareClear();
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');
ecCompareClear();

echo "\n=== ECOMMERCE PRODUCT COMPARISON ===\n";

$userId = ecommerceProductComparisonUserId();
$seed = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

$productIds = [];
for ($index = 1; $index <= 5; $index++) {
    $productIds[] = ecProductCreate([
        'title' => 'Compare Product ' . $index . ' ' . $seed,
        'slug' => 'compare-product-' . $index . '-' . strtolower($seed),
        'excerpt' => 'Comparison fixture product ' . $index . '.',
        'status' => 'published',
        'price' => 20.00 + $index,
        'sku' => 'CMP-' . $index . '-' . $seed,
    ], $userId);
}

$draftProductId = ecProductCreate([
    'title' => 'Compare Draft ' . $seed,
    'slug' => 'compare-draft-' . strtolower($seed),
    'excerpt' => 'Draft comparison fixture.',
    'status' => 'draft',
    'price' => 99.00,
], $userId);

$cleanupProductIds = array_merge($productIds, [$draftProductId]);

$firstAdd = ecCompareAddProduct($productIds[0]);
$secondAdd = ecCompareAddProduct($productIds[1]);
$thirdAdd = ecCompareAddProduct($productIds[2]);
$fourthAdd = ecCompareAddProduct($productIds[3]);
$fifthAdd = ecCompareAddProduct($productIds[4]);
$draftAdd = ecCompareAddProduct($draftProductId);
$beforeRemoveIds = ecCompareGetIds();
$removeResult = ecCompareRemoveProduct($productIds[2]);
$afterRemoveIds = ecCompareGetIds();
$compareProducts = ecCompareProducts();
$compareRows = ecCompareTableRows($compareProducts);

ob_start();
ecPublicCompare();
$html = (string)ob_get_clean();

$routes = file_get_contents(__DIR__ . '/../modules/ecommerce/routes.php') ?: '';
$handlerLoader = file_get_contents(__DIR__ . '/../modules/ecommerce/handlers.php') ?: '';
$entityTemplate = file_get_contents(__DIR__ . '/../templates/modules/cms/public/entity.view.disyl') ?: '';
$productTemplate = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/public/product.disyl') ?: '';
$shopTemplate = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/public/shop.disyl') ?: '';
$blockTemplate = file_get_contents(__DIR__ . '/../templates/modules/cms/public/blocks/ecommerce-product-compare.block.disyl') ?: '';
$nativeSingleProductTemplate = file_get_contents(__DIR__ . '/../storage/cms-themes/native-default/public/ecommerce/single-product.disyl') ?: '';

tcp('compare helper stores unique published products and enforces the max shortlist size', !empty($firstAdd['ok']) && !empty($secondAdd['ok']) && !empty($thirdAdd['ok']) && !empty($fourthAdd['ok']) && !empty($fifthAdd['ok']) && $beforeRemoveIds === [$productIds[4], $productIds[3], $productIds[2], $productIds[1]], json_encode($beforeRemoveIds));
tcp('draft products are rejected from comparison', empty($draftAdd['ok']) && str_contains((string)($draftAdd['error'] ?? ''), 'unavailable'), json_encode($draftAdd));
tcp('compare removal updates the session shortlist order', !empty($removeResult['ok']) && $afterRemoveIds === [$productIds[4], $productIds[3], $productIds[1]], json_encode($afterRemoveIds));
tcp('compare page renders selected products and comparison rows', str_contains($html, 'Compare Products') && str_contains($html, 'Price') && str_contains($html, 'Compare Product 5 ' . $seed) && str_contains($html, 'Compare Product 2 ' . $seed), substr($html, 0, 500));
tcp('compare helper builds price and summary rows for selected products', count($compareProducts) === 3 && count($compareRows) >= 4 && (string)($compareRows[0]['label'] ?? '') === 'Price', json_encode($compareRows));
tcp('routes and handler loader expose the public compare flow', str_contains($routes, '/ecommerce/compare') && str_contains($handlerLoader, '18-public-compare.php'));
tcp('canonical and storefront templates expose comparison UI', str_contains($entityTemplate, 'ecommerce-product-compare.block.disyl') && str_contains($productTemplate, 'ecommerce-product-compare.block.disyl') && str_contains($shopTemplate, '/ecommerce/compare') && str_contains($blockTemplate, 'Add to Compare') && str_contains($nativeSingleProductTemplate, 'ecommerce-product-compare.block.disyl'));

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
tcp('no app.log critical errors', !str_contains($appLog, '[critical]'), $appLog !== '' ? substr($appLog, 0, 200) : '');
tcp('no PHP warnings or fatals in error.log', $errorLog === '' || (!str_contains($errorLog, 'PHP Warning') && !str_contains($errorLog, 'PHP Fatal')), $errorLog !== '' ? substr($errorLog, 0, 200) : '');

cleanupEcommerceProductComparisonFixtures($cleanupProductIds);

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