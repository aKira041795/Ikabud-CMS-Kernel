<?php

declare(strict_types=1);

function cmsAdminMenus(array $params = []): void
{
    $user = cmsRequireCap('menus.manage');

    $pages = [];
    $posts = [];
    $categories = [];
    $tags = [];
    try {
        $pages = cmsDb()->query(
            "SELECT id, title, slug FROM cms_content WHERE type = 'page' AND deleted_at IS NULL ORDER BY title ASC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $posts = cmsDb()->query(
            "SELECT id, title, slug FROM cms_content WHERE type = 'post' AND deleted_at IS NULL ORDER BY title ASC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $categories = cmsGetCategories();
        $tags = cmsGetTags();
    } catch (Throwable $e) {}

    echo cmsRender('modules/cms/admin/menus.disyl', array_merge(cmsAdminContext($user, 'menus', [
        ['label' => 'Menus', 'url' => ''],
    ]), [
        'page_title'      => 'Menu Manager',
        'menus'           => cmsGetMenus(),
        'locations'       => cmsGetMenuLocations(),
        'link_pages'      => $pages,
        'link_posts'      => $posts,
        'link_categories' => $categories,
        'link_tags'       => $tags,
    ]));
}

function cmsApiMenuList(array $params = []): void
{
    cmsRequireCap('menus.manage');
    $menus = cmsGetMenus();
    foreach ($menus as &$m) {
        $m['items'] = cmsGetMenuItemsTree((int)$m['id']);
    }
    app()->json(['ok' => true, 'menus' => $menus]);
}

function cmsApiMenuGet(array $params = []): void
{
    $location = trim((string)($params['location'] ?? ''));
    $menu = cmsGetMenuByLocation($location);
    if (!$menu) {
        app()->json(['ok' => false, 'error' => 'Menu not found']);
        return;
    }
    $menu['items'] = cmsGetMenuItemsTree((int)$menu['id']);
    app()->json(['ok' => true, 'menu' => $menu]);
}

function cmsApiMenuCreate(array $params = []): void
{
    cmsRequireCap('menus.manage');
    $input = cmsInput();
    $name = trim((string)($input['name'] ?? ''));
    $desc = trim((string)($input['description'] ?? ''));
    $loc = isset($input['location']) ? trim((string)$input['location']) : null;
    $result = cmsMenuCreate($name, $desc, $loc ?: null);
    app()->json($result);
}

function cmsApiMenuSave(array $params = []): void
{
    cmsRequireCap('menus.manage');
    $input = cmsInput();
    $menuId = (int)($params['id'] ?? $input['id'] ?? 0);
    $name = trim((string)($input['name'] ?? ''));
    $location = trim((string)($input['location'] ?? ''));
    $items = $input['items'] ?? [];

    if ($menuId > 0) {
        // Update existing menu
        if ($name !== '') {
            cmsMenuUpdate($menuId, $name, trim((string)($input['description'] ?? '')));
        }
    } else {
        // Create new (legacy compat)
        $result = cmsMenuSave($name, $location);
        if (!($result['ok'] ?? false)) {
            app()->json($result);
            return;
        }
        $menuId = (int)$result['id'];
    }

    if (is_array($items) && !empty($items)) {
        $itemResult = cmsMenuItemsReplace($menuId, $items);
        if (!($itemResult['ok'] ?? false)) {
            app()->json($itemResult);
            return;
        }
    }

    app()->json(['ok' => true, 'id' => $menuId]);
}

function cmsApiMenuDelete(array $params = []): void
{
    cmsRequireCap('menus.manage');
    $id = (int)($params['id'] ?? 0);
    if ($id <= 0) {
        app()->json(['ok' => false, 'error' => 'Invalid menu ID']);
        return;
    }
    app()->json(cmsMenuDelete($id));
}

function cmsApiMenuLocations(array $params = []): void
{
    cmsRequireCap('menus.manage');
    app()->json(['ok' => true, 'locations' => cmsGetMenuLocations(), 'menus' => cmsGetMenus()]);
}

function cmsApiMenuLocationAssign(array $params = []): void
{
    cmsRequireCap('menus.manage');
    $input = cmsInput();
    $assignments = $input['assignments'] ?? [];
    if (!is_array($assignments)) {
        app()->json(['ok' => false, 'error' => 'Invalid assignments']);
        return;
    }
    foreach ($assignments as $locSlug => $menuId) {
        cmsAssignMenuToLocation((string)$locSlug, $menuId ? (int)$menuId : null);
    }
    app()->json(['ok' => true]);
}
