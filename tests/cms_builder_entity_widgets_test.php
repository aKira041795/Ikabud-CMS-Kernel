<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/cms/builder-preview';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/ecommerce/helpers.php';

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

function cmsBuilderEntityWidgetUserId(): int
{
    static $userId = 0;
    if ($userId > 0) {
        return $userId;
    }

    $userId = (int)app()->db()->query('SELECT id FROM cms_users ORDER BY id ASC LIMIT 1')->fetchColumn();
    if ($userId < 1) {
        throw new RuntimeException('No cms_users row available for builder widget test');
    }

    return $userId;
}

function cleanupCmsBuilderEntityWidgetFixtures(): void
{
    global $cleanupProductIds;

    if ($cleanupProductIds === []) {
        return;
    }

    $db = app()->db();
    $placeholders = implode(', ', array_fill(0, count($cleanupProductIds), '?'));
    $db->prepare("DELETE FROM cms_content_meta WHERE content_id IN ({$placeholders})")->execute($cleanupProductIds);
    $db->prepare("DELETE FROM cms_entity_capabilities WHERE entity_id IN ({$placeholders})")->execute($cleanupProductIds);
    $db->prepare("DELETE FROM cms_content WHERE id IN ({$placeholders})")->execute($cleanupProductIds);
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== CMS BUILDER ENTITY WIDGETS ===\n";

$seed = substr(bin2hex(random_bytes(4)), 0, 8);
$userId = cmsBuilderEntityWidgetUserId();

$productA = ecProductCreate([
    'title' => 'Builder Widget Product A ' . $seed,
    'slug' => 'builder-widget-product-a-' . $seed,
    'excerpt' => 'Entity list excerpt A',
    'status' => 'published',
    'price' => 49.5,
    'stock_qty' => 3,
    'track_stock' => true,
], $userId);

$productB = ecProductCreate([
    'title' => 'Builder Widget Product B ' . $seed,
    'slug' => 'builder-widget-product-b-' . $seed,
    'excerpt' => 'Entity list excerpt B',
    'status' => 'published',
    'price' => 89,
    'stock_qty' => 0,
    'track_stock' => true,
], $userId);

$cleanupProductIds = [$productA, $productB];

$entity = ecProductGet($productA);
if (!is_array($entity)) {
    throw new RuntimeException('Failed to load builder entity widget fixture');
}

$entityViewHtml = cmsBuilderRenderNode([
    'id' => 'entity-view-test',
    'type' => 'entity_view',
    'props' => [
        'showTitle' => true,
        'showPricing' => true,
        'showInventory' => true,
        'showBody' => true,
    ],
    'style' => [],
    'children' => [],
    'meta' => [],
], $entity);

$entityListHtml = cmsBuilderRenderNode([
    'id' => 'entity-list-test',
    'type' => 'entity_list',
    'props' => [
        'entityType' => 'product',
        'itemCount' => 2,
        'showExcerpt' => true,
        'showPricing' => true,
        'showInventory' => true,
        'layout' => 'grid',
    ],
    'style' => [],
    'children' => [],
    'meta' => [],
], []);

$entityPricing = ecProductPricing($productA);
$listPricingA = ecProductPricing($productA);
$listPricingB = ecProductPricing($productB);
$entityInventory = ecProductInventory($productA);

t('entity view renderer outputs product title', str_contains($entityViewHtml, 'Builder Widget Product A ' . $seed));
t('entity view renderer outputs formatted pricing', str_contains($entityViewHtml, (string)($entityPricing['formatted'] ?? '')), $entityViewHtml);
t('entity view renderer outputs inventory status', str_contains($entityViewHtml, !empty($entityInventory['low_stock']) ? 'Low stock' : 'In stock'), $entityViewHtml);

t('entity list renderer outputs first product title', str_contains($entityListHtml, 'Builder Widget Product A ' . $seed));
t('entity list renderer outputs second product title', str_contains($entityListHtml, 'Builder Widget Product B ' . $seed));
t('entity list renderer outputs formatted prices', str_contains($entityListHtml, (string)($listPricingA['formatted'] ?? '')) && str_contains($entityListHtml, (string)($listPricingB['formatted'] ?? '')), $entityListHtml);
t('entity list renderer outputs stock states', str_contains($entityListHtml, 'Out of stock') && str_contains($entityListHtml, 'Low stock'), $entityListHtml);

cleanupCmsBuilderEntityWidgetFixtures();

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
t('no app.log critical errors', !str_contains($appLog, '[critical]'), $appLog !== '' ? substr($appLog, 0, 200) : '');
t('no PHP errors in error.log', $errorLog === '', $errorLog !== '' ? substr($errorLog, 0, 200) : '');

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
