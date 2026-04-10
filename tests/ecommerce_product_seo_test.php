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

function ecommerceProductSeoTestUserId(): int
{
    $userId = (int)app()->db()->query('SELECT id FROM cms_users ORDER BY id ASC LIMIT 1')->fetchColumn();
    if ($userId < 1) {
        throw new RuntimeException('No cms_users row available for ecommerce product SEO test');
    }

    return $userId;
}

function cleanupEcommerceProductSeoFixtures(array $productIds): void
{
    if ($productIds === []) {
        return;
    }

    $db = app()->db();
    $placeholders = implode(', ', array_fill(0, count($productIds), '?'));
    $db->prepare("DELETE FROM cms_content_meta WHERE content_id IN ({$placeholders})")->execute($productIds);
    $db->prepare("DELETE FROM cms_entity_capabilities WHERE entity_id IN ({$placeholders})")->execute($productIds);
    $db->prepare("DELETE FROM cms_content WHERE id IN ({$placeholders})")->execute($productIds);
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== ECOMMERCE PRODUCT SEO ===\n";

$userId = ecommerceProductSeoTestUserId();
$seed = substr(bin2hex(random_bytes(4)), 0, 8);

$productId = ecProductCreate([
    'title' => 'SEO Product ' . $seed,
    'slug' => 'seo-product-' . strtolower($seed),
    'excerpt' => 'Search-friendly fixture excerpt.',
    'status' => 'published',
    'price' => 249.00,
    'seo_title' => 'Custom Product SEO ' . $seed,
    'seo_description' => 'Custom product SEO description ' . $seed,
    'seo_canonical_url' => 'https://shop.example.test/products/' . strtolower($seed),
    'seo_og_image' => 'https://cdn.example.test/og/' . strtolower($seed) . '.jpg',
], $userId);

$cleanupProductIds = [$productId];

$product = ecProductGet($productId) ?: [];
$seoContent = ecProductSeoContent($product);
$seoHead = cmsDefaultSeoHeadHtml($seoContent);
$seoPageTitle = cmsResolveSeoTitle($seoContent);
$template = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/admin/product-edit.disyl') ?: '';

t('product detail hydration includes seo title', (string)($product['seo_title'] ?? '') === 'Custom Product SEO ' . $seed, json_encode($product));
t('product detail hydration includes seo description', (string)($product['seo_description'] ?? '') === 'Custom product SEO description ' . $seed, json_encode($product));
t('product detail hydration includes canonical override', (string)($product['seo_canonical_url'] ?? '') === 'https://shop.example.test/products/' . strtolower($seed), json_encode($product));
t('product detail hydration includes og image override', (string)($product['seo_og_image'] ?? '') === 'https://cdn.example.test/og/' . strtolower($seed) . '.jpg', json_encode($product));
t('seo head output includes custom title and description', str_contains($seoHead, 'Custom Product SEO ' . $seed) && str_contains($seoHead, 'Custom product SEO description ' . $seed), $seoHead);
t('seo head output includes canonical override and og image', str_contains($seoHead, 'https://shop.example.test/products/' . strtolower($seed)) && str_contains($seoHead, 'https://cdn.example.test/og/' . strtolower($seed) . '.jpg'), $seoHead);
t('seo page title resolves from product seo title', str_contains($seoPageTitle, 'Custom Product SEO ' . $seed), $seoPageTitle);
t('product edit template exposes seo fields', str_contains($template, 'name="seo_title"') && str_contains($template, 'name="seo_canonical_url"') && str_contains($template, 'name="seo_og_image"'));

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
t('no app.log critical errors', !str_contains($appLog, '[critical]'), $appLog !== '' ? substr($appLog, 0, 200) : '');
t('no PHP warnings or fatals in error.log', $errorLog === '' || (!str_contains($errorLog, 'PHP Warning') && !str_contains($errorLog, 'PHP Fatal')), $errorLog !== '' ? substr($errorLog, 0, 200) : '');

cleanupEcommerceProductSeoFixtures($cleanupProductIds);

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
