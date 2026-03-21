<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../modules/cms/helpers.php';

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

// ── Cleanup ──
try { $db->exec("DELETE FROM cms_content_categories WHERE category_id IN (SELECT id FROM cms_categories WHERE slug LIKE 'test-%')"); } catch (Throwable $e) {}
try { $db->exec("DELETE FROM cms_categories WHERE slug LIKE 'test-%'"); } catch (Throwable $e) {}

echo "Phase 1: CMS Categories\n";

// 1) Create category
$res = cmsCategoryCreate('Test Alpha', 'test-alpha', 'Alpha desc');
t('create category', !empty($res['ok']) && ($res['id'] ?? 0) > 0);
$catAlphaId = (int)($res['id'] ?? 0);

// 2) Create child category
$res2 = cmsCategoryCreate('Test Beta', 'test-beta', 'Beta desc', $catAlphaId);
t('create child category', !empty($res2['ok']) && ($res2['id'] ?? 0) > 0);
$catBetaId = (int)($res2['id'] ?? 0);

// 3) Duplicate slug rejected
$dup = cmsCategoryCreate('Duplicate', 'test-alpha');
t('duplicate slug rejected', empty($dup['ok']) && str_contains((string)($dup['error'] ?? ''), 'already exists'));

// 4) List categories flat
$flat = cmsGetCategories(false);
$found = array_filter($flat, fn($c) => in_array((int)$c['id'], [$catAlphaId, $catBetaId], true));
t('list flat contains both', count($found) === 2);

// 5) List categories tree
$tree = cmsGetCategories(true);
$alphaNode = null;
foreach ($tree as $node) {
    if ((int)$node['id'] === $catAlphaId) { $alphaNode = $node; break; }
}
t('tree: alpha at root', $alphaNode !== null);
$betaChild = false;
if ($alphaNode) {
    foreach ($alphaNode['children'] ?? [] as $ch) {
        if ((int)$ch['id'] === $catBetaId) { $betaChild = true; break; }
    }
}
t('tree: beta is child of alpha', $betaChild);

// 6) Update category
$upd = cmsCategoryUpdate($catAlphaId, ['name' => 'Test Alpha Updated', 'description' => 'Updated desc']);
t('update category', !empty($upd['ok']));
$updatedRow = $db->prepare("SELECT name, description FROM cms_categories WHERE id = ?")->execute([$catAlphaId]);
$updatedRow = $db->prepare("SELECT name, description FROM cms_categories WHERE id = ?");
$updatedRow->execute([$catAlphaId]);
$updatedRow = $updatedRow->fetch(PDO::FETCH_ASSOC);
t('update persisted', ($updatedRow['name'] ?? '') === 'Test Alpha Updated');

// 7) Create a test content item
$contentId = 0;
try {
    $db->prepare(
        "INSERT INTO cms_content (uuid, title, slug, body, type, status, author_id, created_at) VALUES (?, 'Cat Test Post', 'test-cat-post', '', 'post', 'draft', 1, NOW())"
    )->execute([bin2hex(random_bytes(16))]);
    $contentId = (int)$db->lastInsertId();
} catch (Throwable $e) {}
t('test content created', $contentId > 0);

// 8) Sync categories
cmsSyncContentCategories($contentId, [$catAlphaId, $catBetaId]);
$assignedIds = cmsGetContentCategoryIds($contentId);
sort($assignedIds);
$expected = [$catAlphaId, $catBetaId];
sort($expected);
t('sync categories assigned', $assignedIds === $expected);

// 9) Re-sync removes old
cmsSyncContentCategories($contentId, [$catBetaId]);
$assignedIds2 = cmsGetContentCategoryIds($contentId);
t('re-sync replaces', $assignedIds2 === [$catBetaId]);

// 10) Delete category (FK cascade removes assignments)
$del = cmsCategoryDelete($catBetaId);
t('delete category', !empty($del['ok']));
$assignedIds3 = cmsGetContentCategoryIds($contentId);
t('cascade removed assignment', empty($assignedIds3));

// Cleanup
try { $db->prepare("DELETE FROM cms_content WHERE id = ?")->execute([$contentId]); } catch (Throwable $e) {}
try { $db->exec("DELETE FROM cms_categories WHERE slug LIKE 'test-%'"); } catch (Throwable $e) {}

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
