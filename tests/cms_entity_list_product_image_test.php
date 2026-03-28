<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/ecommerce/shop';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/cms/handlers/90-public.php';

$pass = 0;
$fail = 0;
$errors = [];
$cleanupFile = null;

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

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

$testDir = cmsUploadsPath() . '/test';
if (!is_dir($testDir)) {
    @mkdir($testDir, 0775, true);
}

$galleryFilename = 'cms-list-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.png';
$galleryRelativePath = 'test/' . $galleryFilename;
$cleanupFile = $testDir . '/' . $galleryFilename;
file_put_contents($cleanupFile, 'png');

echo "\n=== CMS ENTITY LIST PRODUCT IMAGES ===\n";

$featuredItem = [
    'type' => 'product',
    'featured_image_url' => '/assets/modules/cms/uploads/t1/test/featured.png',
    'capability_data' => [],
];
$galleryThumbItem = [
    'type' => 'product',
    'capability_data' => [
        'media_gallery' => [
            'items' => [
                ['thumb' => $galleryRelativePath, 'url' => $galleryRelativePath],
            ],
        ],
    ],
];
$galleryAbsoluteItem = [
    'type' => 'product',
    'capability_data' => [
        'media_gallery' => [
            'items' => [
                ['url' => 'https://cdn.example.test/product.png'],
            ],
        ],
    ],
];
$placeholderProduct = [
    'type' => 'product',
    'capability_data' => [],
];
$nonProduct = [
    'type' => 'service',
    'capability_data' => [],
];

t('featured image url wins immediately', cmsPublicListItemPrimaryImageUrl($featuredItem) === '/assets/modules/cms/uploads/t1/test/featured.png');
t('gallery thumb resolves through cms uploads helper', cmsPublicListItemPrimaryImageUrl($galleryThumbItem) === cmsResolveUploadUrl($galleryRelativePath));
t('absolute gallery url is preserved', cmsPublicListItemPrimaryImageUrl($galleryAbsoluteItem) === 'https://cdn.example.test/product.png');
t('product without image uses ecommerce placeholder', cmsPublicListItemPrimaryImageUrl($placeholderProduct) === '/assets/ecommerce/product-placeholder.svg');
t('non-product without image stays empty', cmsPublicListItemPrimaryImageUrl($nonProduct) === '');

$template = file_get_contents(__DIR__ . '/../templates/modules/cms/public/entity.list.disyl') ?: '';
$inventoryTemplate = file_get_contents(__DIR__ . '/../templates/modules/cms/public/blocks/list-card-inventory.block.disyl') ?: '';
$pricingTemplate = file_get_contents(__DIR__ . '/../templates/modules/cms/public/blocks/list-card-pricing.block.disyl') ?: '';
$progressTemplate = file_get_contents(__DIR__ . '/../templates/modules/cms/public/blocks/list-card-progress.block.disyl') ?: '';
t('entity list template reads primary_image_url', str_contains($template, 'item.primary_image_url'));
t('entity list template no longer renders featured_image_url directly for src', !str_contains($template, 'src="{item.featured_image_url}"'));
t('entity list template renders prebuilt inventory fragment', str_contains($template, 'item.list_card_inventory_html'));
t('entity list template renders prebuilt pricing fragment', str_contains($template, 'item.list_card_pricing_html'));
t('entity list template renders prebuilt progress fragment', str_contains($template, 'item.list_card_progress_html'));
t('inventory block uses low_stock flag', str_contains($inventoryTemplate, 'capability_data.inventory.low_stock'));
t('inventory block uses out_of_stock flag', str_contains($inventoryTemplate, 'capability_data.inventory.out_of_stock'));
t('inventory block uses track_stock key', str_contains($inventoryTemplate, 'capability_data.inventory.track_stock'));
t('inventory block uses stock_qty key', str_contains($inventoryTemplate, 'capability_data.inventory.stock_qty'));
t('pricing block uses sale_price key', str_contains($pricingTemplate, 'capability_data.pricing.sale_price'));
t('progress block uses percent key', str_contains($progressTemplate, 'capability_data.progress_tracking.percent'));

if (is_string($cleanupFile) && is_file($cleanupFile)) {
    @unlink($cleanupFile);
}

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