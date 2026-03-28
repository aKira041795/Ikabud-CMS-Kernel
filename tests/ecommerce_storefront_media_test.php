<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/ecommerce/shop';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/ecommerce/helpers.php';

$pass = 0;
$fail = 0;
$errors = [];
$cleanupFiles = [];
$cleanupMediaIds = [];
$cleanupProductIds = [];

function existingCmsUserId(): int
{
    static $userId = 0;
    if ($userId > 0) {
        return $userId;
    }

    $userId = (int)app()->db()->query('SELECT id FROM cms_users ORDER BY id ASC LIMIT 1')->fetchColumn();
    if ($userId < 1) {
        throw new RuntimeException('No cms_users row available for ecommerce media fixtures');
    }

    return $userId;
}

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
    } else {
        $fail++;
        $errors[] = $label . ($detail !== '' ? ": {$detail}" : '');
        echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

function createTestMedia(string $prefix): array
{
    global $cleanupFiles, $cleanupMediaIds;

    $filename = $prefix . '_' . substr(bin2hex(random_bytes(4)), 0, 8) . '.png';
    $relativePath = 'test/' . $filename;
    $absoluteDir = cmsUploadsPath() . '/test';
    if (!is_dir($absoluteDir)) {
        @mkdir($absoluteDir, 0775, true);
    }

    $absolutePath = $absoluteDir . '/' . $filename;
    file_put_contents($absolutePath, 'png');

    $db = app()->db();
    $stmt = $db->prepare(
        'INSERT INTO cms_media (filename, original_name, mime_type, file_size, file_path, uploaded_by, created_at) '
        . 'VALUES (:filename, :original_name, :mime_type, :file_size, :file_path, :uploaded_by, NOW())'
    );
    $stmt->execute([
        ':filename' => $filename,
        ':original_name' => $filename,
        ':mime_type' => 'image/png',
        ':file_size' => filesize($absolutePath) ?: 3,
        ':file_path' => $relativePath,
        ':uploaded_by' => existingCmsUserId(),
    ]);

    $mediaId = (int)$db->lastInsertId();
    $cleanupFiles[] = $absolutePath;
    $cleanupMediaIds[] = $mediaId;

    return ['id' => $mediaId, 'path' => $relativePath, 'absolute_path' => $absolutePath];
}

function cleanupStorefrontMediaFixtures(): void
{
    global $cleanupFiles, $cleanupMediaIds, $cleanupProductIds;

    $db = app()->db();

    if ($cleanupProductIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($cleanupProductIds), '?'));
        $db->prepare("DELETE FROM cms_content_meta WHERE content_id IN ({$placeholders})")->execute($cleanupProductIds);
        $db->prepare("DELETE FROM cms_entity_capabilities WHERE entity_id IN ({$placeholders})")->execute($cleanupProductIds);
        $db->prepare("DELETE FROM cms_content WHERE id IN ({$placeholders})")->execute($cleanupProductIds);
    }

    if ($cleanupMediaIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($cleanupMediaIds), '?'));
        $db->prepare("DELETE FROM cms_media WHERE id IN ({$placeholders})")->execute($cleanupMediaIds);
    }

    foreach ($cleanupFiles as $file) {
        if (is_string($file) && is_file($file)) {
            @unlink($file);
        }
    }
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== ECOMMERCE STOREFRONT MEDIA ===\n";

$featuredMedia = createTestMedia('featured');
$galleryMedia = createTestMedia('gallery');
$lowStockThreshold = (int)ecSettings('low_stock_threshold');

$cmsUserId = existingCmsUserId();

$featuredProductId = ecProductCreate([
    'title' => 'Storefront Featured ' . substr(bin2hex(random_bytes(3)), 0, 6),
    'slug' => 'storefront-featured-' . substr(bin2hex(random_bytes(3)), 0, 6),
    'excerpt' => 'featured image product',
    'status' => 'published',
    'featured_image_id' => $featuredMedia['id'],
], $cmsUserId);

$galleryOnlyProductId = ecProductCreate([
    'title' => 'Storefront Gallery ' . substr(bin2hex(random_bytes(3)), 0, 6),
    'slug' => 'storefront-gallery-' . substr(bin2hex(random_bytes(3)), 0, 6),
    'excerpt' => 'gallery only product',
    'status' => 'published',
], $cmsUserId);

$noImageProductId = ecProductCreate([
    'title' => 'Storefront Placeholder ' . substr(bin2hex(random_bytes(3)), 0, 6),
    'slug' => 'storefront-placeholder-' . substr(bin2hex(random_bytes(3)), 0, 6),
    'excerpt' => 'no image product',
    'status' => 'published',
], $cmsUserId);

$outOfStockProductId = ecProductCreate([
    'title' => 'Storefront Out Of Stock ' . substr(bin2hex(random_bytes(3)), 0, 6),
    'slug' => 'storefront-out-of-stock-' . substr(bin2hex(random_bytes(3)), 0, 6),
    'excerpt' => 'out of stock product',
    'status' => 'published',
    'stock_qty' => 0,
    'track_stock' => true,
], $cmsUserId);

$lowStockProductId = ecProductCreate([
    'title' => 'Storefront Low Stock ' . substr(bin2hex(random_bytes(3)), 0, 6),
    'slug' => 'storefront-low-stock-' . substr(bin2hex(random_bytes(3)), 0, 6),
    'excerpt' => 'threshold stock product',
    'status' => 'published',
    'stock_qty' => $lowStockThreshold,
    'track_stock' => true,
], $cmsUserId);

$cleanupProductIds = [$featuredProductId, $galleryOnlyProductId, $noImageProductId, $lowStockProductId, $outOfStockProductId];

$db = app()->db();
$galleryMeta = json_encode([
    [
        'src' => $galleryMedia['path'],
        'thumb' => $galleryMedia['path'],
        'caption' => 'Gallery test',
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$stmt = $db->prepare(
    "INSERT INTO cms_content_meta (content_id, meta_key, meta_value) VALUES (:content_id, '_gallery', :meta_value)\n"
    . "ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)"
);
$stmt->execute([':content_id' => $galleryOnlyProductId, ':meta_value' => $galleryMeta]);

$featuredProduct = ecProductGet($featuredProductId);
$galleryOnlyProduct = ecProductGet($galleryOnlyProductId);
$noImageProduct = ecProductGet($noImageProductId);
$lowStockProduct = ecProductGet($lowStockProductId);
$outOfStockProduct = ecProductGet($outOfStockProductId);
$list = ecProductList(['status' => 'published', 'limit' => 100, 'offset' => 0]);

$itemsById = [];
foreach ($list['items'] ?? [] as $item) {
    $itemsById[(int)($item['id'] ?? 0)] = $item;
}

$expectedFeaturedUrl = cmsResolveUploadUrl($featuredMedia['path']);
$expectedGalleryUrl = cmsResolveUploadUrl($galleryMedia['path']);

t('featured product resolves featured_image_url', ($featuredProduct['featured_image_url'] ?? '') === $expectedFeaturedUrl);
t('featured product primary_image_url uses featured image', ($featuredProduct['primary_image_url'] ?? '') === $expectedFeaturedUrl);
t('gallery-only product exposes gallery image', count($galleryOnlyProduct['gallery_images'] ?? []) === 1);
t('gallery-only product primary_image_url falls back to gallery', ($galleryOnlyProduct['primary_image_url'] ?? '') === $expectedGalleryUrl);
t('no-image product helper leaves primary_image_url empty', ($noImageProduct['primary_image_url'] ?? '') === '');
t('threshold stock product is flagged low_stock', ($lowStockProduct['inventory']['low_stock'] ?? false) === true);
t('threshold stock product remains in stock', ($lowStockProduct['inventory']['in_stock'] ?? false) === true);
t('zero-stock product is flagged out_of_stock', ($outOfStockProduct['inventory']['out_of_stock'] ?? false) === true);
t('zero-stock product is not in stock', ($outOfStockProduct['inventory']['in_stock'] ?? true) === false);

t('featured product list item has primary_image_url', (($itemsById[$featuredProductId]['primary_image_url'] ?? '') === $expectedFeaturedUrl));
t('gallery-only list item has primary_image_url', (($itemsById[$galleryOnlyProductId]['primary_image_url'] ?? '') === $expectedGalleryUrl));
t('no-image list item has empty primary_image_url', (($itemsById[$noImageProductId]['primary_image_url'] ?? '') === ''));
t('threshold stock list item keeps low_stock flag', (($itemsById[$lowStockProductId]['inventory']['low_stock'] ?? false) === true));
t('zero-stock list item keeps out_of_stock flag', (($itemsById[$outOfStockProductId]['inventory']['out_of_stock'] ?? false) === true));

$shopTemplate = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/public/shop.disyl') ?: '';
$productTemplate = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/public/product.disyl') ?: '';
$adminOrderTemplate = file_get_contents(__DIR__ . '/../templates/modules/ecommerce/admin/order-detail.disyl') ?: '';
t('shop template includes placeholder fallback', str_contains($shopTemplate, 'product-placeholder.svg'));
t('product template includes placeholder fallback', str_contains($productTemplate, 'product-placeholder.svg'));
t('shop template includes low stock notice', str_contains($shopTemplate, 'Low stock - only'));
t('product template includes low stock notice', str_contains($productTemplate, 'Low stock ('));
t('shop template uses out_of_stock flag', str_contains($shopTemplate, 'p.inventory.out_of_stock'));
t('product template uses out_of_stock flag', str_contains($productTemplate, 'product.inventory.out_of_stock'));
t('admin order detail uses hydrated billing address object', str_contains($adminOrderTemplate, 'order.billing.first_name'));
t('admin order detail uses hydrated shipping address object', str_contains($adminOrderTemplate, 'order.shipping.first_name'));
t('admin order detail does not use raw shipping meta fallback expressions', !str_contains($adminOrderTemplate, 'order.meta.shipping_first_name | default:order.meta.billing_first_name'));

cleanupStorefrontMediaFixtures();

$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
$errorLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';
t('no app.log critical errors', !str_contains($appLog, '[critical]'));
t('no PHP errors in error.log', trim($errorLog) === '', trim($errorLog));

echo "\n══════════════════════════════════════════════════\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
echo "══════════════════════════════════════════════════\n";

if ($errors !== []) {
    echo "\nFailed tests:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

exit($fail > 0 ? 1 : 0);