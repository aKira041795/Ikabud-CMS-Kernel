<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/cms/builder-preview';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';

$pass = 0;
$fail = 0;
$errors = [];
$cleanupContentIds = [];
$cleanupCategoryIds = [];
$cleanupMediaIds = [];
$cleanupFiles = [];

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

function cmsBuilderPostsGridTestUserId(): int
{
    static $userId = 0;
    if ($userId > 0) {
        return $userId;
    }

    $userId = (int)app()->db()->query('SELECT id FROM cms_users ORDER BY id ASC LIMIT 1')->fetchColumn();
    if ($userId < 1) {
        throw new RuntimeException('No cms_users row available for posts grid test');
    }

    return $userId;
}

function cleanupCmsBuilderPostsGridFixtures(): void
{
    global $cleanupContentIds, $cleanupCategoryIds, $cleanupMediaIds, $cleanupFiles;

    $db = app()->db();

    if ($cleanupContentIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($cleanupContentIds), '?'));
        $db->prepare("DELETE FROM cms_content_categories WHERE content_id IN ({$placeholders})")->execute($cleanupContentIds);
        $db->prepare("DELETE FROM cms_content_meta WHERE content_id IN ({$placeholders})")->execute($cleanupContentIds);
        $db->prepare("DELETE FROM cms_content WHERE id IN ({$placeholders})")->execute($cleanupContentIds);
    }

    if ($cleanupCategoryIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($cleanupCategoryIds), '?'));
        $db->prepare("DELETE FROM cms_categories WHERE id IN ({$placeholders})")->execute($cleanupCategoryIds);
    }

    if ($cleanupMediaIds !== []) {
        $placeholders = implode(', ', array_fill(0, count($cleanupMediaIds), '?'));
        $db->prepare("DELETE FROM cms_media WHERE id IN ({$placeholders})")->execute($cleanupMediaIds);
    }

    foreach ($cleanupFiles as $file) {
        if (is_string($file) && $file !== '' && file_exists($file)) {
            @unlink($file);
        }
    }
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== CMS BUILDER POSTS GRID ===\n";

$seed = substr(bin2hex(random_bytes(4)), 0, 8);
$db = app()->db();
$userId = cmsBuilderPostsGridTestUserId();

$hasTaxonomyColumn = cmsCategoriesHasTaxonomyNamespace();
if ($hasTaxonomyColumn) {
    $db->prepare("INSERT INTO cms_categories (name, slug, taxonomy, created_at) VALUES (?, ?, 'default', NOW())")
        ->execute(['Editorial ' . $seed, 'editorial-' . $seed]);
} else {
    $db->prepare("INSERT INTO cms_categories (name, slug, created_at) VALUES (?, ?, NOW())")
        ->execute(['Editorial ' . $seed, 'editorial-' . $seed]);
}
$editorialCategoryId = (int)$db->lastInsertId();
$cleanupCategoryIds[] = $editorialCategoryId;

if ($hasTaxonomyColumn) {
    $db->prepare("INSERT INTO cms_categories (name, slug, taxonomy, created_at) VALUES (?, ?, 'default', NOW())")
        ->execute(['Updates ' . $seed, 'updates-' . $seed]);
} else {
    $db->prepare("INSERT INTO cms_categories (name, slug, created_at) VALUES (?, ?, NOW())")
        ->execute(['Updates ' . $seed, 'updates-' . $seed]);
}
$updatesCategoryId = (int)$db->lastInsertId();
$cleanupCategoryIds[] = $updatesCategoryId;

if ($hasTaxonomyColumn) {
    $db->prepare("INSERT INTO cms_categories (name, slug, taxonomy, created_at) VALUES (?, ?, 'product', NOW())")
        ->execute(['Store Category ' . $seed, 'store-category-' . $seed]);
    $productCategoryId = (int)$db->lastInsertId();
    $cleanupCategoryIds[] = $productCategoryId;
} else {
    $productCategoryId = 0;
}

$filename = 'posts-grid-' . $seed . '.png';
$relativePath = 'test/' . $filename;
$absoluteDir = cmsUploadsPath() . '/test';
if (!is_dir($absoluteDir)) {
    @mkdir($absoluteDir, 0775, true);
}
$absolutePath = $absoluteDir . '/' . $filename;
file_put_contents($absolutePath, 'png');
$cleanupFiles[] = $absolutePath;

$db->prepare(
    'INSERT INTO cms_media (filename, original_name, mime_type, file_size, file_path, uploaded_by, created_at) '
    . 'VALUES (?, ?, ?, ?, ?, ?, NOW())'
)->execute([
    $filename,
    $filename,
    'image/png',
    filesize($absolutePath) ?: 3,
    $relativePath,
    $userId,
]);
$mediaId = (int)$db->lastInsertId();
$cleanupMediaIds[] = $mediaId;

$insertPost = $db->prepare(
    'INSERT INTO cms_content (uuid, title, slug, excerpt, body, type, status, author_id, featured_image_id, created_at, published_at) '
    . "VALUES (?, ?, ?, ?, ?, 'post', 'published', ?, ?, NOW(), NOW())"
);

$insertPost->execute([
    bin2hex(random_bytes(16)),
    'Zebra Post ' . $seed,
    'zebra-post-' . $seed,
    'Zebra excerpt with enough detail to verify truncation in the builder output.',
    'Zebra body',
    $userId,
    $mediaId,
]);
$zebraPostId = (int)$db->lastInsertId();

$insertPost->execute([
    bin2hex(random_bytes(16)),
    'Alpha Post ' . $seed,
    'alpha-post-' . $seed,
    'Alpha excerpt with enough detail to verify truncation in the builder output.',
    'Alpha body',
    $userId,
    0,
]);
$alphaPostId = (int)$db->lastInsertId();

$insertPost->execute([
    bin2hex(random_bytes(16)),
    'Other Post ' . $seed,
    'other-post-' . $seed,
    'Other excerpt that should stay out of the filtered result set.',
    'Other body',
    $userId,
    0,
]);
$otherPostId = (int)$db->lastInsertId();

$cleanupContentIds = [$zebraPostId, $alphaPostId, $otherPostId];

$db->prepare('INSERT INTO cms_content_categories (content_id, category_id) VALUES (?, ?)')->execute([$zebraPostId, $editorialCategoryId]);
$db->prepare('INSERT INTO cms_content_categories (content_id, category_id) VALUES (?, ?)')->execute([$alphaPostId, $editorialCategoryId]);
$db->prepare('INSERT INTO cms_content_categories (content_id, category_id) VALUES (?, ?)')->execute([$otherPostId, $updatesCategoryId]);
if ($productCategoryId > 0) {
    $db->prepare('INSERT INTO cms_content_categories (content_id, category_id) VALUES (?, ?)')->execute([$otherPostId, $productCategoryId]);
}

$filteredCategories = cmsGetCategories(false, ['exclude_taxonomy' => 'product']);
$filteredCategoryIds = array_map(static fn (array $category): int => (int)($category['id'] ?? 0), $filteredCategories);
t('post category source excludes product taxonomy', !in_array($productCategoryId, $filteredCategoryIds, true) && in_array($editorialCategoryId, $filteredCategoryIds, true), json_encode($filteredCategories));

$postsGridHtml = cmsBuilderRenderNode([
    'id' => 'posts-grid-test',
    'type' => 'posts_grid',
    'props' => [
        'postCount' => 2,
        'postType' => 'post',
        'categoryIds' => [$editorialCategoryId],
        'showFeaturedImage' => true,
        'showDate' => false,
        'showExcerpt' => true,
        'excerptLength' => 24,
        'showAuthor' => false,
        'showReadMore' => true,
        'readMoreText' => 'Continue Reading',
        'orderBy' => 'title',
        'order' => 'asc',
    ],
    'style' => [],
    'children' => [],
    'meta' => [],
], []);

$alphaPos = strpos($postsGridHtml, 'Alpha Post ' . $seed);
$zebraPos = strpos($postsGridHtml, 'Zebra Post ' . $seed);

t('posts grid respects category filter', str_contains($postsGridHtml, 'Alpha Post ' . $seed) && str_contains($postsGridHtml, 'Zebra Post ' . $seed) && !str_contains($postsGridHtml, 'Other Post ' . $seed), $postsGridHtml);
t('posts grid respects title sort order', $alphaPos !== false && $zebraPos !== false && $alphaPos < $zebraPos, $postsGridHtml);
t('posts grid respects custom read-more label', str_contains($postsGridHtml, 'Continue Reading'), $postsGridHtml);
t('posts grid respects excerpt length', str_contains($postsGridHtml, 'Alpha excerpt with en...') && str_contains($postsGridHtml, 'Zebra excerpt with en...'), $postsGridHtml);
t('posts grid renders featured image when available', str_contains($postsGridHtml, cmsUploadsUrl($relativePath)), $postsGridHtml);

cleanupCmsBuilderPostsGridFixtures();

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
t('no app.log critical errors', !str_contains($appLog, '[critical]'), $appLog !== '' ? substr($appLog, 0, 200) : '');
t('no PHP errors in error.log', $errorLog === '', $errorLog !== '' ? substr($errorLog, 0, 200) : '');

echo "\n════════════════════════════════════════════\n";
echo "  Results: {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "\n  Failures:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
    exit(1);
}

exit(0);