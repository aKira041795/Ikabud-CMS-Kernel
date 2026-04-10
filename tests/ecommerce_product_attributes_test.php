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

function ecommerceProductAttributesTestUserId(): int
{
    $userId = (int)app()->db()->query('SELECT id FROM cms_users ORDER BY id ASC LIMIT 1')->fetchColumn();
    if ($userId < 1) {
        throw new RuntimeException('No cms_users row available for ecommerce product attribute test');
    }

    return $userId;
}

function cleanupEcommerceProductAttributeFixtures(array $productIds): void
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

echo "\n=== ECOMMERCE PRODUCT ATTRIBUTES ===\n";

$userId = ecommerceProductAttributesTestUserId();
$seed = substr(bin2hex(random_bytes(4)), 0, 8);

$productA = ecProductCreate([
    'title' => 'Attribute Red Large ' . $seed,
    'slug' => 'attribute-red-large-' . strtolower($seed),
    'excerpt' => 'Red and large fixture.',
    'status' => 'published',
    'price' => 120.00,
], $userId);
$productB = ecProductCreate([
    'title' => 'Attribute Blue Medium ' . $seed,
    'slug' => 'attribute-blue-medium-' . strtolower($seed),
    'excerpt' => 'Blue and medium fixture.',
    'status' => 'published',
    'price' => 95.00,
], $userId);
$productC = ecProductCreate([
    'title' => 'Attribute Red Cotton ' . $seed,
    'slug' => 'attribute-red-cotton-' . strtolower($seed),
    'excerpt' => 'Red and cotton fixture.',
    'status' => 'published',
    'price' => 130.00,
], $userId);
$cleanupProductIds = [$productA, $productB, $productC];

$parsed = ecProductParseAttributeLines("Color: Red, Blue\nSize: Large");
ecProductSaveAttributes($productA, ecProductParseAttributeLines("Color: Red\nSize: Large"));
ecProductSaveAttributes($productB, ecProductParseAttributeLines("Color: Blue\nSize: Medium"));
ecProductSaveAttributes($productC, ecProductParseAttributeLines("Color: Red\nMaterial: Cotton"));

$product = ecProductGet($productA) ?: [];
$redProducts = ecProductList([
    'status' => 'published',
    'attribute_filters' => ['color' => ['red']],
    'limit' => 20,
    'offset' => 0,
]);
$redLargeProducts = ecProductList([
    'status' => 'published',
    'attribute_filters' => ['color' => ['red'], 'size' => ['large']],
    'limit' => 20,
    'offset' => 0,
]);
$facets = ecProductAttributeFacetSummary([
    'status' => 'published',
    'attribute_filters' => ['color' => ['red']],
]);
$shopTemplate = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/public/shop.disyl') ?: '';
$entityListTemplate = file_get_contents(__DIR__ . '/../templates/modules/cms/public/entity.list.disyl') ?: '';

$facetBySlug = [];
foreach ($facets as $facet) {
    if (is_array($facet) && !empty($facet['attribute_slug'])) {
        $facetBySlug[(string)$facet['attribute_slug']] = $facet;
    }
}

t('attribute storage is available', ecProductAttributeStorageAvailable());
t('attribute parser builds multiple attributes', count($parsed) === 2, 'count=' . count($parsed));
t('product detail hydration includes attributes', count((array)($product['attributes'] ?? [])) === 2);
t('red filter returns both matching products', (int)($redProducts['total'] ?? 0) === 2, 'total=' . (int)($redProducts['total'] ?? 0));
t('red and large filter narrows to one product', (int)($redLargeProducts['total'] ?? 0) === 1, 'total=' . (int)($redLargeProducts['total'] ?? 0));
t('facet summary includes color attribute', isset($facetBySlug['color']));
t('facet summary marks selected red value', (bool)($facetBySlug['color']['values'][0]['is_selected'] ?? false) || (bool)($facetBySlug['color']['values'][1]['is_selected'] ?? false));
t('shop template includes dynamic attr filters', str_contains($shopTemplate, 'name="attr['));
t('canonical entity list template includes dynamic attr filters', str_contains($entityListTemplate, 'name="attr['));

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
t('no app.log critical errors', !str_contains($appLog, '[critical]'), $appLog !== '' ? substr($appLog, 0, 200) : '');
t('no PHP warnings or fatals in error.log', $errorLog === '' || (!str_contains($errorLog, 'PHP Warning') && !str_contains($errorLog, 'PHP Fatal')), $errorLog !== '' ? substr($errorLog, 0, 200) : '');

cleanupEcommerceProductAttributeFixtures($cleanupProductIds);

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