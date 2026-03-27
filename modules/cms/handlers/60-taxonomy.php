<?php

declare(strict_types=1);

function cmsApiCategoryList(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('categories.list');

    $input = cmsInput();
    $tree = (($input['tree'] ?? '') === '1');
    $options = [];
    $taxonomy = trim((string)($input['taxonomy'] ?? ''));
    $excludeTaxonomy = trim((string)($input['exclude_taxonomy'] ?? ''));
    if ($taxonomy !== '') {
        $options['taxonomy'] = $taxonomy;
    }
    if ($excludeTaxonomy !== '') {
        $options['exclude_taxonomy'] = $excludeTaxonomy;
    }

    $categories = cmsGetCategories($tree, $options);
    echo json_encode(['ok' => true, 'data' => $categories]);
    exit;
}

function cmsApiCategoryCreate(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('categories.create');

    $input = cmsInput();
    $name = trim((string)($input['name'] ?? ''));
    $slug = trim((string)($input['slug'] ?? ''));
    $description = isset($input['description']) ? trim((string)$input['description']) : null;
    $parentId = isset($input['parent_id']) && $input['parent_id'] !== '' ? (int)$input['parent_id'] : null;

    $result = cmsCategoryCreate($name, $slug, $description, $parentId);
    if (empty($result['ok'])) {
        http_response_code(422);
    }
    echo json_encode($result);
    exit;
}

function cmsApiCategoryUpdate(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('categories.edit');
    $id = (int)($params['id'] ?? 0);

    if ($id <= 0) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }

    $input = cmsInput();
    $result = cmsCategoryUpdate($id, $input);
    if (empty($result['ok'])) {
        http_response_code(422);
    }
    echo json_encode($result);
    exit;
}

function cmsApiCategoryDelete(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('categories.delete');
    $id = (int)($params['id'] ?? 0);

    if ($id <= 0) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }

    $result = cmsCategoryDelete($id);
    if (empty($result['ok'])) {
        http_response_code(500);
    }
    echo json_encode($result);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════
// PUBLIC HEADLESS API (GET)
// ═══════════════════════════════════════════════════════════════════════

function cmsApiTagList(array $params = []): void
{
    cmsRequireCap('tags.list');
    app()->json(['ok' => true, 'tags' => cmsGetTags()]);
}

function cmsApiTagCreate(array $params = []): void
{
    cmsRequireCap('tags.create');
    $input = cmsInput();
    $name = trim((string)($input['name'] ?? ''));
    $result = cmsTagCreate($name);
    app()->json($result);
}

function cmsApiTagDelete(array $params = []): void
{
    cmsRequireCap('tags.delete');
    $id = (int)($params['id'] ?? 0);
    if ($id <= 0) {
        app()->json(['ok' => false, 'error' => 'Invalid tag ID']);
        return;
    }
    app()->json(cmsTagDelete($id));
}

// ═══════════════════════════════════════════════════════════════════════
// CATEGORIES
// ═══════════════════════════════════════════════════════════════════════

function cmsAdminCategories(array $params = []): void
{
    $user = cmsRequireCap('categories.manage');

    $categories = cmsGetCategories();

    // Count posts per category
    $catCounts = [];
    try {
        $rows = cmsDb()->query(
            "SELECT cc.category_id, COUNT(*) as cnt FROM cms_content_categories cc
             JOIN cms_content c ON c.id = cc.content_id AND c.deleted_at IS NULL
             GROUP BY cc.category_id"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            $catCounts[(int)$r['category_id']] = (int)$r['cnt'];
        }
    } catch (Throwable $e) {}

    echo cmsRender('modules/cms/admin/categories.disyl', array_merge(cmsAdminContext($user, 'categories', [
        ['label' => 'Categories', 'url' => ''],
    ]), [
        'page_title'  => 'Categories',
        'categories'  => $categories,
        'cat_counts'  => $catCounts,
    ]));
}

// ═══════════════════════════════════════════════════════════════════════
// MENUS
// ═══════════════════════════════════════════════════════════════════════
