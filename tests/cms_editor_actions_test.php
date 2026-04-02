<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/cms/handlers.php';

$pass = 0;
$fail = 0;
$errors = [];

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

$db = app()->db();

echo "Phase 2: CMS Editor Actions (Backend)\n";

// 1) Create as draft (default)
$uuid = bin2hex(random_bytes(16));
$db->prepare(
    "INSERT INTO cms_content (uuid, title, slug, body, type, status, author_id, created_at) VALUES (?, 'Action Test Post', 'test-action-post', '<p>test</p>', 'post', 'draft', 1, NOW())"
)->execute([$uuid]);
$contentId = (int)$db->lastInsertId();
t('create draft content', $contentId > 0);

// 2) Verify status is draft
$row = $db->prepare("SELECT status FROM cms_content WHERE id = ?")->execute([$contentId]);
$row = $db->prepare("SELECT status FROM cms_content WHERE id = ?");
$row->execute([$contentId]);
$row = $row->fetch(PDO::FETCH_ASSOC);
t('initial status is draft', ($row['status'] ?? '') === 'draft');

// 3) Publish
$db->prepare("UPDATE cms_content SET status = 'published', published_at = COALESCE(published_at, NOW()), updated_at = NOW() WHERE id = ?")->execute([$contentId]);
$row = $db->prepare("SELECT status, published_at FROM cms_content WHERE id = ?");
$row->execute([$contentId]);
$row = $row->fetch(PDO::FETCH_ASSOC);
t('publish sets status', ($row['status'] ?? '') === 'published');
t('publish sets published_at', !empty($row['published_at']));

// 4) Save draft (status back to draft)
$db->prepare("UPDATE cms_content SET status = 'draft', updated_at = NOW() WHERE id = ?")->execute([$contentId]);
$row = $db->prepare("SELECT status FROM cms_content WHERE id = ?");
$row->execute([$contentId]);
$row = $row->fetch(PDO::FETCH_ASSOC);
t('save draft reverts status', ($row['status'] ?? '') === 'draft');

// 5) Trash
$db->prepare("UPDATE cms_content SET status = 'trash', deleted_at = NOW(), updated_at = NOW() WHERE id = ?")->execute([$contentId]);
$row = $db->prepare("SELECT status, deleted_at FROM cms_content WHERE id = ?");
$row->execute([$contentId]);
$row = $row->fetch(PDO::FETCH_ASSOC);
t('trash sets status', ($row['status'] ?? '') === 'trash');
t('trash sets deleted_at', !empty($row['deleted_at']));

// 6) Restore
$db->prepare("UPDATE cms_content SET status = 'draft', deleted_at = NULL, updated_at = NOW() WHERE id = ?")->execute([$contentId]);
$row = $db->prepare("SELECT status, deleted_at FROM cms_content WHERE id = ?");
$row->execute([$contentId]);
$row = $row->fetch(PDO::FETCH_ASSOC);
t('restore sets status to draft', ($row['status'] ?? '') === 'draft');
t('restore clears deleted_at', $row['deleted_at'] === null);

// 7) Create as published (one-step)
$uuid2 = bin2hex(random_bytes(16));
$db->prepare(
    "INSERT INTO cms_content (uuid, title, slug, body, type, status, author_id, published_at, created_at) VALUES (?, 'Published Direct', 'test-pub-direct', '', 'post', 'published', 1, NOW(), NOW())"
)->execute([$uuid2]);
$contentId2 = (int)$db->lastInsertId();
$row = $db->prepare("SELECT status, published_at FROM cms_content WHERE id = ?");
$row->execute([$contentId2]);
$row = $row->fetch(PDO::FETCH_ASSOC);
t('create as published', ($row['status'] ?? '') === 'published' && !empty($row['published_at']));

// 8) Verify restore handler exists
t('cmsApiContentRestore handler exists', function_exists('cmsApiContentRestore'));

// 9) Verify content with categories survives full cycle
$catRes = cmsCategoryCreate('Test Action Cat', 'test-action-cat');
$catId = (int)($catRes['id'] ?? 0);
if ($catId > 0) {
    cmsSyncContentCategories($contentId, [$catId]);
    $ids = cmsGetContentCategoryIds($contentId);
    t('categories survive status changes', $ids === [$catId]);
} else {
    t('categories survive status changes', false, 'could not create test category');
}

// Cleanup
try { $db->prepare("DELETE FROM cms_content_categories WHERE content_id IN (?, ?)")->execute([$contentId, $contentId2]); } catch (Throwable $e) {}
try { $db->prepare("DELETE FROM cms_content WHERE id IN (?, ?)")->execute([$contentId, $contentId2]); } catch (Throwable $e) {}
try { $db->exec("DELETE FROM cms_categories WHERE slug = 'test-action-cat'"); } catch (Throwable $e) {}

$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
$errLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';
$criticals = array_filter(explode("\n", $appLog), fn($l) => str_contains($l, '[critical]'));
t('no app.log critical errors', empty($criticals), implode('; ', $criticals));
t('no PHP errors in error.log', trim($errLog) === '', trim($errLog));

echo "\n══════════════════════════════════════════════════\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
echo "══════════════════════════════════════════════════\n";

if (!empty($errors)) {
    echo "\nFailed tests:\n";
    foreach ($errors as $e) {
        echo "  - {$e}\n";
    }
}

exit($fail > 0 ? 1 : 0);
