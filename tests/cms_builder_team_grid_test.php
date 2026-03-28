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
$createdContentType = false;

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

function cmsBuilderTeamGridTestUserId(): int
{
    static $userId = 0;
    if ($userId > 0) {
        return $userId;
    }

    $userId = (int)app()->db()->query('SELECT id FROM cms_users ORDER BY id ASC LIMIT 1')->fetchColumn();
    if ($userId < 1) {
        throw new RuntimeException('No cms_users row available for team grid test');
    }

    return $userId;
}

function cleanupCmsBuilderTeamGridFixtures(): void
{
    global $cleanupContentIds, $cleanupCategoryIds, $createdContentType;

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

    if ($createdContentType) {
        $db->prepare("DELETE FROM cms_content_types WHERE slug = 'team_member'")->execute();
    }
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== CMS BUILDER TEAM GRID ===\n";

$seed = substr(bin2hex(random_bytes(4)), 0, 8);
$db = app()->db();
$userId = cmsBuilderTeamGridTestUserId();

$existingTeamType = (string)$db->query("SELECT slug FROM cms_content_types WHERE slug = 'team_member' LIMIT 1")->fetchColumn();
if ($existingTeamType === '') {
    $db->prepare(
        "INSERT INTO cms_content_types (slug, label, icon, supports, is_active, sort_order, created_at)
         VALUES ('team_member', 'Team Members', 'users', '[\"title\",\"excerpt\",\"featured_image\",\"slug\"]', 1, 90, NOW())"
    )->execute();
    $createdContentType = true;
}

$hasTaxonomyColumn = cmsCategoriesHasTaxonomyNamespace();
if ($hasTaxonomyColumn) {
    $db->prepare("INSERT INTO cms_categories (name, slug, taxonomy, created_at) VALUES (?, ?, 'default', NOW())")
        ->execute(['Leadership ' . $seed, 'leadership-' . $seed]);
} else {
    $db->prepare("INSERT INTO cms_categories (name, slug, created_at) VALUES (?, ?, NOW())")
        ->execute(['Leadership ' . $seed, 'leadership-' . $seed]);
}
$departmentId = (int)$db->lastInsertId();
$cleanupCategoryIds[] = $departmentId;

if ($hasTaxonomyColumn) {
    $db->prepare("INSERT INTO cms_categories (name, slug, taxonomy, created_at) VALUES (?, ?, 'product', NOW())")
        ->execute(['Store Category ' . $seed, 'store-category-' . $seed]);
    $productCategoryId = (int)$db->lastInsertId();
    $cleanupCategoryIds[] = $productCategoryId;
} else {
    $productCategoryId = 0;
}

$insertMember = $db->prepare(
    "INSERT INTO cms_content (uuid, title, slug, excerpt, body, type, status, author_id, created_at, published_at)
     VALUES (?, ?, ?, ?, ?, 'team_member', 'published', ?, NOW(), NOW())"
);

$insertMember->execute([bin2hex(random_bytes(16)), 'Alice Team ' . $seed, 'alice-team-' . $seed, 'Creative Director', 'Alice bio', $userId]);
$aliceId = (int)$db->lastInsertId();
$insertMember->execute([bin2hex(random_bytes(16)), 'Brian Team ' . $seed, 'brian-team-' . $seed, 'Operations Lead', 'Brian bio', $userId]);
$brianId = (int)$db->lastInsertId();
$cleanupContentIds = [$aliceId, $brianId];

$db->prepare('INSERT INTO cms_content_categories (content_id, category_id) VALUES (?, ?)')->execute([$aliceId, $departmentId]);
if ($productCategoryId > 0) {
    $db->prepare('INSERT INTO cms_content_categories (content_id, category_id) VALUES (?, ?)')->execute([$brianId, $productCategoryId]);
}

$filteredCategories = cmsGetCategories(false, ['exclude_taxonomy' => 'product']);
$filteredCategoryIds = array_map(static fn (array $category): int => (int)($category['id'] ?? 0), $filteredCategories);
t('category filter excludes product taxonomy from department list', !in_array($productCategoryId, $filteredCategoryIds, true) && in_array($departmentId, $filteredCategoryIds, true), json_encode($filteredCategories));

$teamGridHtml = cmsBuilderRenderNode([
    'id' => 'team-grid-test',
    'type' => 'team_grid',
    'props' => [
        'teamType' => 'team_member',
        'itemCount' => 4,
        'departmentIds' => [$departmentId],
        'showImage' => false,
        'showTitle' => true,
        'showExcerpt' => true,
        'showAction' => true,
        'gridColumns' => 3,
        'orderBy' => 'name',
        'order' => 'asc',
    ],
    'style' => [],
    'children' => [],
    'meta' => [],
], []);

t('team grid renders matching department member', str_contains($teamGridHtml, 'Alice Team ' . $seed), $teamGridHtml);
t('team grid excludes non-matching members', !str_contains($teamGridHtml, 'Brian Team ' . $seed), $teamGridHtml);
t('team grid renders role from excerpt', str_contains($teamGridHtml, 'Creative Director'), $teamGridHtml);
t('team grid renders team member permalink', str_contains($teamGridHtml, '/cms/team_member/alice-team-' . $seed), $teamGridHtml);
t('team grid renders view profile action', str_contains($teamGridHtml, 'View Profile'), $teamGridHtml);

cleanupCmsBuilderTeamGridFixtures();

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