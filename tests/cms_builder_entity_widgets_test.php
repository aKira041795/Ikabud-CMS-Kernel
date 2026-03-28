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
$cleanupEntityIds = [];

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
    global $cleanupEntityIds;

    if ($cleanupEntityIds === []) {
        return;
    }

    $db = app()->db();
    $placeholders = implode(', ', array_fill(0, count($cleanupEntityIds), '?'));
    $db->prepare("DELETE FROM cms_content_meta WHERE content_id IN ({$placeholders})")->execute($cleanupEntityIds);
    $db->prepare("DELETE FROM cms_entity_capabilities WHERE entity_id IN ({$placeholders})")->execute($cleanupEntityIds);
    $db->prepare("DELETE FROM cms_content WHERE id IN ({$placeholders})")->execute($cleanupEntityIds);
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== CMS BUILDER ENTITY WIDGETS ===\n";

$seed = substr(bin2hex(random_bytes(4)), 0, 8);
$userId = cmsBuilderEntityWidgetUserId();

$productA = ecProductCreate([
    'title' => 'Builder Widget Product A ' . $seed,
    'slug' => 'builder-widget-product-a-' . $seed,
    'excerpt' => 'Entity list excerpt A with extra descriptive copy for truncation checks',
    'status' => 'published',
    'price' => 49.5,
    'stock_qty' => 3,
    'track_stock' => true,
], $userId);

$productB = ecProductCreate([
    'title' => 'Builder Widget Product B ' . $seed,
    'slug' => 'builder-widget-product-b-' . $seed,
    'excerpt' => 'Entity list excerpt B with extra descriptive copy for truncation checks',
    'status' => 'published',
    'price' => 89,
    'stock_qty' => 0,
    'track_stock' => true,
], $userId);

$db = app()->db();

cmsEntityAttachCapability($productA, 'inquiry', ['label' => 'Ask About This Item']);
cmsEntityAttachCapability($productA, 'progress_tracking', []);
cmsEntityAttachCapability($productA, 'lessons_index', ['child_type' => 'lesson']);
cmsEntityAttachCapability($productA, 'media_gallery', [
    'columns' => 3,
    'lightbox' => true,
    'items' => [
        ['url' => 'https://example.com/gallery-one.jpg'],
        ['url' => 'https://example.com/gallery-two.jpg'],
        ['url' => 'https://example.com/gallery-three.jpg'],
    ],
]);

$db->prepare(
    "INSERT INTO cms_content (uuid, title, slug, body, excerpt, type, status, author_id, published_at, created_at)
     VALUES (?, ?, ?, ?, ?, 'lesson', 'published', ?, NOW(), NOW())"
)->execute([
    bin2hex(random_bytes(16)),
    'Lesson A ' . $seed,
    'builder-widget-lesson-a-' . $seed,
    'Lesson body A',
    'Lesson excerpt A',
    $userId,
]);
$lessonA = (int)$db->lastInsertId();
$db->prepare(
    "INSERT INTO cms_content (uuid, title, slug, body, excerpt, type, status, author_id, published_at, created_at)
     VALUES (?, ?, ?, ?, ?, 'lesson', 'published', ?, NOW(), NOW())"
)->execute([
    bin2hex(random_bytes(16)),
    'Lesson B ' . $seed,
    'builder-widget-lesson-b-' . $seed,
    'Lesson body B',
    'Lesson excerpt B',
    $userId,
]);
$lessonB = (int)$db->lastInsertId();
$db->prepare("INSERT INTO cms_content_meta (content_id, meta_key, meta_value) VALUES (?, '_parent_id', ?)")->execute([$lessonA, (string)$productA]);
$db->prepare("INSERT INTO cms_content_meta (content_id, meta_key, meta_value) VALUES (?, '_parent_id', ?)")->execute([$lessonB, (string)$productA]);

cmsEntityCapabilityClearCache($productA);

$cleanupEntityIds = [$productA, $productB, $lessonA, $lessonB];

$entity = ecProductGet($productA);
if (!is_array($entity)) {
    throw new RuntimeException('Failed to load builder entity widget fixture');
}

$entity['author_name'] = 'Builder Test Author';
$entity['content_type_label'] = 'Product';
$entity['type'] = 'product';
$entity['published_at'] = date('Y-m-d H:i:s');
$entity['body'] = 'Detailed builder entity body copy.';

$entityValidation = cmsBuilderValidateDocument([
    'schema_version' => '1.0',
    'document' => [
        'id' => 'doc_root',
        'type' => 'document',
        'props' => [],
        'style' => [],
        'children' => [
            [
                'id' => 'entity-view-test',
                'type' => 'entity_view',
                'props' => [
                    'showTitle' => true,
                    'showPricing' => true,
                ],
                'style' => [],
                'children' => [],
                'meta' => [],
            ],
            [
                'id' => 'entity-list-test',
                'type' => 'entity_list',
                'props' => [
                    'entityType' => 'product',
                    'itemCount' => 2,
                    'layout' => 'grid',
                ],
                'style' => [],
                'children' => [],
                'meta' => [],
            ],
        ],
        'meta' => [],
    ],
]);

t('builder validation accepts entity widgets', !empty($entityValidation['ok']), json_encode($entityValidation['issues'] ?? []));

$entityViewHtml = cmsBuilderRenderNode([
    'id' => 'entity-view-test',
    'type' => 'entity_view',
    'props' => [
        'showFeaturedImage' => true,
        'showTitle' => true,
        'showMeta' => true,
        'showTypeLabel' => true,
        'showAuthor' => true,
        'showDate' => true,
        'showPricing' => true,
        'showInventory' => true,
        'showSku' => true,
        'showProgress' => true,
        'showLessons' => true,
        'showActions' => true,
        'showBody' => true,
    ],
    'style' => [],
    'children' => [],
    'meta' => [],
], [
    'entity' => $entity,
    'post_html' => '<p>Rendered body from post_html context.</p>',
]);

$entityListHtml = cmsBuilderRenderNode([
    'id' => 'entity-list-test',
    'type' => 'entity_list',
    'props' => [
        'entityType' => 'product',
        'itemCount' => 2,
        'showExcerpt' => true,
        'excerptLength' => 18,
        'showPricing' => true,
        'showInventory' => true,
        'layout' => 'grid',
    ],
    'style' => [],
    'children' => [],
    'meta' => [],
], []);

$entityListSixColumnHtml = cmsBuilderRenderNode([
    'id' => 'entity-list-six-column-test',
    'type' => 'entity_list',
    'props' => [
        'entityType' => 'product',
        'itemCount' => 2,
        'gridColumns' => 6,
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
t('entity view renderer outputs type label', str_contains($entityViewHtml, 'Product'), $entityViewHtml);
t('entity view renderer outputs author', str_contains($entityViewHtml, 'Builder Test Author'), $entityViewHtml);
t('entity view renderer outputs formatted pricing', str_contains($entityViewHtml, (string)($entityPricing['formatted'] ?? '')), $entityViewHtml);
t('entity view renderer outputs inventory status', str_contains($entityViewHtml, !empty($entityInventory['low_stock']) ? 'Low stock' : 'In stock'), $entityViewHtml);
t('entity view renderer outputs SKU when enabled', str_contains($entityViewHtml, 'SKU'), $entityViewHtml);
t('entity view renderer outputs progress section', str_contains($entityViewHtml, 'Progress') && str_contains($entityViewHtml, '0%'), $entityViewHtml);
t('entity view renderer outputs lessons section', str_contains($entityViewHtml, 'Lesson A ' . $seed) && str_contains($entityViewHtml, 'Lesson B ' . $seed), $entityViewHtml);
t('entity view renderer outputs inquiry action', str_contains($entityViewHtml, 'Ask About This Item'), $entityViewHtml);
t('entity view renderer outputs post_html body', str_contains($entityViewHtml, 'Rendered body from post_html context.'), $entityViewHtml);

t('entity list renderer outputs first product title', str_contains($entityListHtml, 'Builder Widget Product A ' . $seed));
t('entity list renderer outputs second product title', str_contains($entityListHtml, 'Builder Widget Product B ' . $seed));
t('entity list renderer honors six-column grid setting', str_contains($entityListSixColumnHtml, 'repeat(6, 1fr)'), $entityListSixColumnHtml);
t('entity list renderer respects excerpt length', str_contains($entityListHtml, 'Entity list excer...'), $entityListHtml);
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
