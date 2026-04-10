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

$attributeMigration = __DIR__ . '/../modules/ecommerce/database/migrations/015_ec_product_attributes.sql';
if (is_file($attributeMigration)) {
    app()->db()->exec((string)file_get_contents($attributeMigration));
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

function ecommerceAjaxCatalogSearchUserId(): int
{
    $userId = (int)app()->db()->query('SELECT id FROM cms_users ORDER BY id ASC LIMIT 1')->fetchColumn();
    if ($userId < 1) {
        throw new RuntimeException('No cms_users row available for ecommerce AJAX catalog search test');
    }

    return $userId;
}

function cleanupEcommerceAjaxCatalogSearchFixtures(array $productIds): void
{
    if ($productIds === []) {
        return;
    }

    $db = app()->db();
    $placeholders = implode(', ', array_fill(0, count($productIds), '?'));
    $db->prepare("DELETE FROM ec_product_attribute_values WHERE product_id IN ({$placeholders})")->execute($productIds);
    $db->prepare("DELETE FROM cms_content_meta WHERE content_id IN ({$placeholders})")->execute($productIds);
    $db->prepare("DELETE FROM cms_entity_capabilities WHERE entity_id IN ({$placeholders})")->execute($productIds);
    $db->prepare("DELETE FROM cms_content WHERE id IN ({$placeholders})")->execute($productIds);
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== ECOMMERCE AJAX CATALOG SEARCH ===\n";

$userId = ecommerceAjaxCatalogSearchUserId();
$seed = substr(bin2hex(random_bytes(4)), 0, 8);

$redProductId = ecProductCreate([
    'title' => 'Ajax Red Product ' . $seed,
    'slug' => 'ajax-red-product-' . strtolower($seed),
    'excerpt' => 'Red ajax catalog fixture.',
    'status' => 'published',
    'price' => 49.00,
], $userId);
$blueProductId = ecProductCreate([
    'title' => 'Ajax Blue Product ' . $seed,
    'slug' => 'ajax-blue-product-' . strtolower($seed),
    'excerpt' => 'Blue ajax catalog fixture.',
    'status' => 'published',
    'price' => 59.00,
], $userId);
$cleanupProductIds = [$redProductId, $blueProductId];

ecProductSaveAttributes($redProductId, ecProductParseAttributeLines("Color: Red\nMaterial: Cotton"));
ecProductSaveAttributes($blueProductId, ecProductParseAttributeLines("Color: Blue\nMaterial: Linen"));

$payload = ecBuildCatalogSearchPayload([
    'search' => 'Ajax',
    'attribute_filters' => ['color' => ['red']],
    'base_list_url' => '/ecommerce/shop',
    'search_action_url' => '/ecommerce/shop',
    'all_items_url' => '/ecommerce/shop',
    'item_base_url' => '/ecommerce/shop',
    'route_kind' => 'shop_index',
    'presentation_mode' => 'traditional',
    'page' => 1,
    'limit' => 12,
]);

$routes = file_get_contents(__DIR__ . '/../modules/ecommerce/routes.php') ?: '';
$ajaxBlock = file_get_contents(__DIR__ . '/../templates/modules/cms/public/blocks/ecommerce-catalog-ajax.block.disyl') ?: '';
$canonicalTemplate = file_get_contents(__DIR__ . '/../templates/modules/cms/public/entity.list.disyl') ?: '';
$nativeTemplate = file_get_contents(__DIR__ . '/../storage/cms-themes/native-default/public/entity.list.disyl') ?: '';
$pocTemplate = file_get_contents(__DIR__ . '/../storage/cms-themes/entity-commerce-poc/public/entity.list.disyl') ?: '';

t('catalog payload renders themed section html', str_contains((string)($payload['html'] ?? ''), 'data-storefront-page-kind="catalog"'), substr((string)($payload['html'] ?? ''), 0, 200));
t('catalog payload respects attribute filters', str_contains((string)($payload['html'] ?? ''), 'Ajax Red Product ' . $seed) && !str_contains((string)($payload['html'] ?? ''), 'Ajax Blue Product ' . $seed), substr((string)($payload['html'] ?? ''), 0, 400));
t('catalog payload reports filtered result counts', (int)($payload['total'] ?? 0) === 1 && (int)($payload['active_filter_count'] ?? 0) >= 2, json_encode($payload));
t('routes expose the AJAX catalog endpoint', str_contains($routes, '/api/v1/ecommerce/catalog'));
t('shared AJAX block reads the catalog endpoint from section data', str_contains($ajaxBlock, 'catalogApiUrl') && str_contains($ajaxBlock, 'data-catalog-api-url'));
t('canonical entity list template wires the AJAX data attribute', str_contains($canonicalTemplate, 'data-catalog-api-url'));
t('native theme entity list wires AJAX and attribute facets', str_contains($nativeTemplate, 'data-catalog-api-url') && str_contains($nativeTemplate, 'Filter Products'));
t('POC theme entity list wires AJAX and attribute facets', str_contains($pocTemplate, 'data-catalog-api-url') && str_contains($pocTemplate, 'Apply Filters'));

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
t('no app.log critical errors', !str_contains($appLog, '[critical]'), $appLog !== '' ? substr($appLog, 0, 200) : '');
t('no PHP warnings or fatals in error.log', $errorLog === '' || (!str_contains($errorLog, 'PHP Warning') && !str_contains($errorLog, 'PHP Fatal')), $errorLog !== '' ? substr($errorLog, 0, 200) : '');

cleanupEcommerceAjaxCatalogSearchFixtures($cleanupProductIds);

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