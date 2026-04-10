<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/ecommerce/shop';

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

function ecommerceRecentlyViewedUserId(): int
{
    $userId = (int)app()->db()->query('SELECT id FROM cms_users ORDER BY id ASC LIMIT 1')->fetchColumn();
    if ($userId < 1) {
        throw new RuntimeException('No cms_users row available for ecommerce recently viewed test');
    }

    return $userId;
}

function cleanupEcommerceRecentlyViewedFixtures(array $productIds): void
{
    if ($productIds === []) {
        ecRecentlyViewedClear();
        return;
    }

    $db = app()->db();
    $placeholders = implode(', ', array_fill(0, count($productIds), '?'));
    $db->prepare("DELETE FROM cms_content_meta WHERE content_id IN ({$placeholders})")->execute($productIds);
    $db->prepare("DELETE FROM cms_content_categories WHERE content_id IN ({$placeholders})")->execute($productIds);
    $db->prepare("DELETE FROM cms_entity_capabilities WHERE entity_id IN ({$placeholders})")->execute($productIds);
    $db->prepare("DELETE FROM cms_content WHERE id IN ({$placeholders})")->execute($productIds);
    ecRecentlyViewedClear();
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');
ecRecentlyViewedClear();

echo "\n=== ECOMMERCE RECENTLY VIEWED PRODUCTS ===\n";

$userId = ecommerceRecentlyViewedUserId();
$seed = substr(bin2hex(random_bytes(4)), 0, 8);

$firstProductId = ecProductCreate([
    'title' => 'Recently Viewed First ' . $seed,
    'slug' => 'recently-viewed-first-' . strtolower($seed),
    'excerpt' => 'First recently viewed fixture.',
    'status' => 'published',
    'price' => 35.00,
], $userId);
$secondProductId = ecProductCreate([
    'title' => 'Recently Viewed Second ' . $seed,
    'slug' => 'recently-viewed-second-' . strtolower($seed),
    'excerpt' => 'Second recently viewed fixture.',
    'status' => 'published',
    'price' => 45.00,
], $userId);
$draftProductId = ecProductCreate([
    'title' => 'Recently Viewed Draft ' . $seed,
    'slug' => 'recently-viewed-draft-' . strtolower($seed),
    'excerpt' => 'Draft fixture should be filtered from recently viewed results.',
    'status' => 'draft',
    'price' => 55.00,
], $userId);
$cleanupProductIds = [$firstProductId, $secondProductId, $draftProductId];

ecRecentlyViewedRememberProduct($draftProductId);
ecRecentlyViewedRememberProduct($firstProductId);

$_SERVER['REQUEST_URI'] = '/ecommerce/shop/recently-viewed-second-' . strtolower($seed);
ob_start();
ecPublicProduct(['slug' => 'recently-viewed-second-' . strtolower($seed)]);
$html = (string)ob_get_clean();

$recentIds = ecRecentlyViewedGetIds();
$recentItems = ecRecentlyViewedCatalogItems($secondProductId, 4, ['item_base_url' => '/ecommerce/shop']);
$routes = file_get_contents(__DIR__ . '/../modules/ecommerce/handlers/10-public-shop.php') ?: '';
$entityTemplate = file_get_contents(__DIR__ . '/../templates/modules/cms/public/entity.view.disyl') ?: '';
$productTemplate = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/public/product.disyl') ?: '';
$nativeSingleProductTemplate = file_get_contents(__DIR__ . '/../storage/cms-themes/native-default/public/ecommerce/single-product.disyl') ?: '';
$blockTemplate = file_get_contents(__DIR__ . '/../templates/modules/cms/public/blocks/ecommerce-recently-viewed.block.disyl') ?: '';

t('product detail view remembers the current product in session order', $recentIds !== [] && (int)$recentIds[0] === $secondProductId && in_array($firstProductId, $recentIds, true), json_encode($recentIds));
t('recently viewed catalog items exclude the current product and draft products', count($recentItems) === 1 && (int)($recentItems[0]['id'] ?? 0) === $firstProductId, json_encode($recentItems));
t('public product render includes the recently viewed section through the canonical entity view path', str_contains($html, 'Recently Viewed') && str_contains($html, 'Recently Viewed First ' . $seed) && !str_contains($html, 'Recently Viewed Draft ' . $seed), substr($html, 0, 400));
t('product handler passes recently viewed items into product detail context', str_contains($routes, 'recently_viewed_items'));
t('canonical entity view and direct product template include the recently viewed block', str_contains($entityTemplate, 'ecommerce-recently-viewed.block.disyl') && str_contains($productTemplate, 'ecommerce-recently-viewed.block.disyl'));
t('active native single-product theme override includes the recently viewed block', str_contains($nativeSingleProductTemplate, 'ecommerce-recently-viewed.block.disyl'));
t('shared recently viewed block exists with storefront section marker', str_contains($blockTemplate, 'data-ecommerce-section="recently-viewed"') && str_contains($blockTemplate, 'Jump back into products you looked at during this session.'));

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
t('no app.log critical errors', !str_contains($appLog, '[critical]'), $appLog !== '' ? substr($appLog, 0, 200) : '');
t('no PHP warnings or fatals in error.log', $errorLog === '' || (!str_contains($errorLog, 'PHP Warning') && !str_contains($errorLog, 'PHP Fatal')), $errorLog !== '' ? substr($errorLog, 0, 200) : '');

cleanupEcommerceRecentlyViewedFixtures($cleanupProductIds);

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