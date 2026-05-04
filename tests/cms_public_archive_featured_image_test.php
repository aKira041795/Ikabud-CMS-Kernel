<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/cms/blog';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/cms/handlers.php';

ob_start();

$db = app()->db();
$pass = 0;
$fail = 0;
$errors = [];

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

function captureOutput(callable $callback): string
{
    ob_start();
    try {
        $callback();
        return (string)ob_get_clean();
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== CMS PUBLIC ARCHIVE FEATURED IMAGES ===\n";

$suffix = strtolower(substr(bin2hex(random_bytes(5)), 0, 10));
$username = 'archive-img-' . $suffix;
$email = $username . '@example.test';
$passwordHash = password_hash('archive-test-' . $suffix, PASSWORD_DEFAULT);
$categorySlug = 'archive-category-' . $suffix;
$tagName = 'Archive Tag ' . $suffix;
$tagSlug = cmsSlugify($tagName);
$postSlug = 'archive-featured-post-' . $suffix;
$mediaPath = 'tests/archive-featured-' . $suffix . '.jpg';
$expectedImageUrl = cmsResolveUploadUrl($mediaPath);

$userId = 0;
$mediaId = 0;
$postId = 0;
$categoryId = 0;
$tagId = 0;

try {
    $db->prepare(
        'INSERT INTO cms_users (username, email, password_hash, display_name, role, is_active, created_at)'
        . ' VALUES (:username, :email, :password_hash, :display_name, :role, 1, NOW())'
    )->execute([
        ':username' => $username,
        ':email' => $email,
        ':password_hash' => $passwordHash,
        ':display_name' => 'Archive Image Tester',
        ':role' => 'administrator',
    ]);
    $userId = (int)$db->lastInsertId();

    $db->prepare(
        'INSERT INTO cms_media (filename, original_name, mime_type, file_size, file_path, uploaded_by, created_at)'
        . ' VALUES (:filename, :original_name, :mime_type, :file_size, :file_path, :uploaded_by, NOW())'
    )->execute([
        ':filename' => basename($mediaPath),
        ':original_name' => basename($mediaPath),
        ':mime_type' => 'image/jpeg',
        ':file_size' => 1024,
        ':file_path' => $mediaPath,
        ':uploaded_by' => $userId,
    ]);
    $mediaId = (int)$db->lastInsertId();

    $category = cmsCategoryCreate('Archive Image Category ' . $suffix, $categorySlug, 'Archive image regression category.');
    $categoryId = (int)($category['id'] ?? 0);

    $tag = cmsTagCreate($tagName);
    $tagId = (int)($tag['id'] ?? 0);

    $db->prepare(
        "INSERT INTO cms_content (uuid, title, slug, body, excerpt, type, status, author_id, featured_image_id, published_at, created_at, updated_at)\n"
        . " VALUES (:uuid, :title, :slug, :body, :excerpt, 'post', 'published', :author_id, :featured_image_id, NOW(), NOW(), NOW())"
    )->execute([
        ':uuid' => cmsUuid(),
        ':title' => 'Archive Featured Image ' . $suffix,
        ':slug' => $postSlug,
        ':body' => '<p>Archive image regression body.</p>',
        ':excerpt' => 'Archive image regression excerpt.',
        ':author_id' => $userId,
        ':featured_image_id' => $mediaId,
    ]);
    $postId = (int)$db->lastInsertId();

    cmsSyncContentCategories($postId, [$categoryId]);
    cmsSyncContentTags($postId, [$tagName]);

    $_SERVER['REQUEST_URI'] = '/cms/category/' . rawurlencode($categorySlug);
    $_GET = [];
    $categoryHtml = captureOutput(static function () use ($categorySlug): void {
        cmsPublicCategoryArchive(['slug' => $categorySlug]);
    });

    $_SERVER['REQUEST_URI'] = '/cms/tag/' . rawurlencode($tagSlug);
    $_GET = [];
    $tagHtml = captureOutput(static function () use ($tagSlug): void {
        cmsPublicTagArchive(['slug' => $tagSlug]);
    });

    $_SERVER['REQUEST_URI'] = '/cms/search?q=' . rawurlencode($suffix);
    $_GET = ['q' => $suffix];
    $searchHtml = captureOutput(static function (): void {
        cmsPublicSearch();
    });

    t('category archive renders featured image url', str_contains($categoryHtml, $expectedImageUrl), $categoryHtml);
    t('tag archive renders featured image url', str_contains($tagHtml, $expectedImageUrl), $tagHtml);
    t('search archive renders featured image url', str_contains($searchHtml, $expectedImageUrl), $searchHtml);

    $appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
    $errorLogLines = array_values(array_filter(
        preg_split('/\R/', (string)@file_get_contents(STORAGE_PATH . '/logs/error.log')) ?: [],
        static fn(string $line): bool => trim($line) !== '' && !str_contains($line, 'Ikabud Cache:')
    ));
    $errorLog = implode("\n", $errorLogLines);
    t('no app.log critical errors', !str_contains($appLog, '[critical]'), $appLog !== '' ? substr($appLog, 0, 200) : '');
    t('no PHP errors in error.log', $errorLog === '', $errorLog !== '' ? substr($errorLog, 0, 200) : '');
} finally {
    $_GET = [];
    $_SERVER['REQUEST_URI'] = '/cms/blog';

    if ($postId > 0) {
        try {
            $db->prepare('DELETE FROM cms_content WHERE id = ?')->execute([$postId]);
        } catch (Throwable $e) {
        }
    }
    if ($mediaId > 0) {
        try {
            $db->prepare('DELETE FROM cms_media WHERE id = ?')->execute([$mediaId]);
        } catch (Throwable $e) {
        }
    }
    if ($categoryId > 0) {
        try {
            $db->prepare('DELETE FROM cms_categories WHERE id = ?')->execute([$categoryId]);
        } catch (Throwable $e) {
        }
    }
    if ($tagId > 0) {
        try {
            $db->prepare('DELETE FROM cms_tags WHERE id = ?')->execute([$tagId]);
        } catch (Throwable $e) {
        }
    }
    if ($userId > 0) {
        try {
            $db->prepare('DELETE FROM cms_users WHERE id = ?')->execute([$userId]);
        } catch (Throwable $e) {
        }
    }
}

echo "\n════════════════════════════════════════════\n";
echo "  Results: {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "\n  Failures:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
    ob_end_flush();
    exit(1);
}

ob_end_flush();
exit(0);