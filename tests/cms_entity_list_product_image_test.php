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
t('entity list template reads primary_image_url', str_contains($template, 'item.primary_image_url'));
t('entity list template no longer renders featured_image_url directly for src', !str_contains($template, 'src="{item.featured_image_url}"'));

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