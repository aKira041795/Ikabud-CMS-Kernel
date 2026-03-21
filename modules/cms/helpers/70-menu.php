<?php

declare(strict_types=1);

function cmsGetMenuLocations(): array
{
    try {
        $stmt = cmsDb()->query(
            "SELECT l.*, m.name AS menu_name
             FROM cms_menu_locations l
             LEFT JOIN cms_menus m ON m.id = l.menu_id
             ORDER BY l.sort_order ASC"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Assign a menu to a location.
 */

function cmsAssignMenuToLocation(string $locationSlug, ?int $menuId): bool
{
    try {
        $db = cmsDb();
        $db->prepare("UPDATE cms_menu_locations SET menu_id = ? WHERE slug = ?")
           ->execute([$menuId, $locationSlug]);
        // Also sync the legacy location field on cms_menus
        if ($menuId) {
            $db->prepare("UPDATE cms_menus SET location = NULL WHERE location = ?")->execute([$locationSlug]);
            $db->prepare("UPDATE cms_menus SET location = ? WHERE id = ?")->execute([$locationSlug, $menuId]);
        } else {
            $db->prepare("UPDATE cms_menus SET location = NULL WHERE location = ?")->execute([$locationSlug]);
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

// ── Menu CRUD ──────────────────────────────────────────────────────

/**
 * Get a single menu by ID.
 */

function cmsGetMenu(int $id): ?array
{
    try {
        $stmt = cmsDb()->prepare(
            "SELECT m.*, (SELECT COUNT(*) FROM cms_menu_items WHERE menu_id = m.id) AS item_count
             FROM cms_menus m WHERE m.id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Get a menu assigned to a location.
 */

function cmsGetMenuByLocation(string $location): ?array
{
    try {
        // Try location registry first
        $stmt = cmsDb()->prepare(
            "SELECT l.menu_id FROM cms_menu_locations l WHERE l.slug = ? AND l.menu_id IS NOT NULL LIMIT 1"
        );
        $stmt->execute([$location]);
        $locRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($locRow && $locRow['menu_id']) {
            return cmsGetMenu((int)$locRow['menu_id']);
        }
        // Fallback to legacy location field
        $stmt2 = cmsDb()->prepare("SELECT id, name, location FROM cms_menus WHERE location = ? LIMIT 1");
        $stmt2->execute([$location]);
        $row = $stmt2->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * List all menus with item counts.
 */

function cmsGetMenus(): array
{
    try {
        return cmsDb()->query(
            "SELECT m.*, (SELECT COUNT(*) FROM cms_menu_items WHERE menu_id = m.id) AS item_count
             FROM cms_menus m ORDER BY m.name ASC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Create a new menu.
 */

function cmsMenuCreate(string $name, string $description = '', ?string $location = null): array
{
    $name = trim($name);
    if ($name === '') return ['ok' => false, 'error' => 'Name is required'];
    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
    $slug = trim($slug, '-');
    try {
        $db = cmsDb();
        // Ensure unique slug
        $check = $db->prepare("SELECT id FROM cms_menus WHERE slug = ? LIMIT 1");
        $check->execute([$slug]);
        if ($check->fetch()) $slug .= '-' . substr(uniqid(), -6);

        $db->prepare(
            "INSERT INTO cms_menus (name, slug, description, location) VALUES (?, ?, ?, ?)"
        )->execute([$name, $slug, $description, $location]);
        $id = (int)$db->lastInsertId();
        // Assign to location if specified
        if ($location) {
            cmsAssignMenuToLocation($location, $id);
        }
        return ['ok' => true, 'id' => $id];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Failed to create menu'];
    }
}

/**
 * Update menu properties (name, description).
 */

function cmsMenuUpdate(int $id, string $name, string $description = ''): array
{
    $name = trim($name);
    if ($name === '') return ['ok' => false, 'error' => 'Name is required'];
    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
    try {
        cmsDb()->prepare("UPDATE cms_menus SET name = ?, slug = ?, description = ? WHERE id = ?")
               ->execute([$name, $slug, $description, $id]);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Update failed'];
    }
}

/**
 * Create or update a menu for a location (legacy compat).
 */

function cmsMenuSave(string $name, string $location): array
{
    $name = trim($name);
    $location = trim($location);
    if ($name === '' || $location === '') {
        return ['ok' => false, 'error' => 'Name and location are required'];
    }
    try {
        $db = cmsDb();
        $existing = cmsGetMenuByLocation($location);
        if ($existing) {
            $db->prepare("UPDATE cms_menus SET name = ? WHERE id = ?")->execute([$name, (int)$existing['id']]);
            return ['ok' => true, 'id' => (int)$existing['id']];
        }
        return cmsMenuCreate($name, '', $location);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Failed to save menu'];
    }
}

/**
 * Delete a menu, its items, and unassign from locations.
 */

function cmsMenuDelete(int $menuId): array
{
    try {
        $db = cmsDb();
        $db->prepare("DELETE FROM cms_menu_items WHERE menu_id = ?")->execute([$menuId]);
        $db->prepare("UPDATE cms_menu_locations SET menu_id = NULL WHERE menu_id = ?")->execute([$menuId]);
        $db->prepare("DELETE FROM cms_menus WHERE id = ?")->execute([$menuId]);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Delete failed'];
    }
}

// ── Menu Items ─────────────────────────────────────────────────────

/**
 * Get menu items for a menu ID, as a flat list sorted by sort_order.
 */

function cmsGetMenuItems(int $menuId): array
{
    try {
        $stmt = cmsDb()->prepare(
            "SELECT id, menu_id, parent_id, label, url, link_type, link_ref, target, css_class,
                    description, icon, title_attr, sort_order
             FROM cms_menu_items WHERE menu_id = ? ORDER BY sort_order ASC, id ASC"
        );
        $stmt->execute([$menuId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Get menu items as a nested tree.
 */

function cmsGetMenuItemsTree(int $menuId): array
{
    $flat = cmsGetMenuItems($menuId);
    $byParent = [];
    foreach ($flat as $item) {
        $pid = $item['parent_id'] ? (int)$item['parent_id'] : 0;
        $byParent[$pid][] = $item;
    }
    $build = function (int $parentId) use (&$build, $byParent): array {
        $out = [];
        foreach ($byParent[$parentId] ?? [] as $item) {
            $item['children'] = $build((int)$item['id']);
            $out[] = $item;
        }
        return $out;
    };
    return $build(0);
}

/**
 * Replace all items for a menu (full replace strategy).
 * $items: [{ label, url, link_type, link_ref, target, css_class, description, icon, title_attr, children? }]
 */

function cmsMenuItemsReplace(int $menuId, array $items): array
{
    $db = cmsDb();
    try {
        $db->prepare("DELETE FROM cms_menu_items WHERE menu_id = ?")->execute([$menuId]);
        $insert = $db->prepare(
            "INSERT INTO cms_menu_items (menu_id, parent_id, label, url, link_type, link_ref, target, css_class, description, icon, title_attr, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $saveItems = function (array $items, ?int $parentId, int &$order) use (&$saveItems, $menuId, $insert, $db): void {
            foreach ($items as $item) {
                $order++;
                $insert->execute([
                    $menuId,
                    $parentId,
                    trim((string)($item['label'] ?? '')),
                    trim((string)($item['url'] ?? '')),
                    trim((string)($item['link_type'] ?? 'custom')),
                    trim((string)($item['link_ref'] ?? '')),
                    trim((string)($item['target'] ?? '_self')),
                    trim((string)($item['css_class'] ?? '')),
                    trim((string)($item['description'] ?? '')),
                    trim((string)($item['icon'] ?? '')),
                    trim((string)($item['title_attr'] ?? '')),
                    $order,
                ]);
                $newId = (int)$db->lastInsertId();
                if (!empty($item['children']) && is_array($item['children'])) {
                    $saveItems($item['children'], $newId, $order);
                }
            }
        };
        $order = 0;
        $saveItems($items, null, $order);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Failed to save menu items'];
    }
}

// ── Menu URL Resolution + Active State ─────────────────────────────

/**
 * Resolve the actual URL for a menu item based on its link_type.
 */

function cmsResolveMenuItemUrl(array $item): string
{
    $baseUrl = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
    $type = (string)($item['link_type'] ?? 'custom');
    $ref = (string)($item['link_ref'] ?? '');
    $url = (string)($item['url'] ?? '');

    if ($type === 'page' && $ref !== '') return $baseUrl . '/cms/page/' . $ref;
    if ($type === 'post' && $ref !== '') return $baseUrl . '/cms/blog/' . $ref;
    if ($type === 'category' && $ref !== '') return $baseUrl . '/cms/category/' . $ref;
    if ($type === 'tag' && $ref !== '') return $baseUrl . '/cms/tag/' . $ref;
    if ($type === 'home') return $baseUrl . '/cms';
    return $url;
}

/**
 * Detect if a menu item is "active" (matches current URL).
 */

function cmsIsMenuItemActive(array $item): bool
{
    $currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $currentPath = rtrim((string)$currentPath, '/');
    $itemUrl = cmsResolveMenuItemUrl($item);
    $itemPath = parse_url($itemUrl, PHP_URL_PATH);
    $itemPath = rtrim((string)$itemPath, '/');

    if ($itemPath === '' && $currentPath === '') return true;
    if ($itemPath === '') return false;
    // Exact match or ancestor match
    return $currentPath === $itemPath || str_starts_with($currentPath . '/', $itemPath . '/');
}

// ── Menu Rendering (Walker pattern) ────────────────────────────────

/**
 * Render a menu as an HTML <nav> element (WordPress-style walker).
 *
 * Options:
 *   - css_class: nav element class (default 'cms-nav')
 *   - menu_class: ul class (default 'cms-menu')
 *   - submenu_class: nested ul class (default 'cms-submenu')
 *   - max_depth: max nesting levels (0 = unlimited, default 0)
 *   - show_description: render description under label (default false)
 *   - active_class: class for current item (default 'current-menu-item')
 *   - active_ancestor_class: class for ancestor of current item (default 'current-menu-ancestor')
 *   - item_tag: wrapping tag per item (default 'li')
 *   - link_before: HTML before <a> content (default '')
 *   - link_after: HTML after <a> content (default '')
 */

function cmsRenderMenu(string $location, $options = []): string
{
    // Accept string for backward compat: cmsRenderMenu('header', 'my-class')
    if (is_string($options)) {
        $options = ['css_class' => $options];
    }

    $opts = array_merge([
        'css_class'              => 'cms-nav',
        'menu_class'             => 'cms-menu',
        'submenu_class'          => 'cms-submenu',
        'max_depth'              => 0,
        'show_description'       => false,
        'active_class'           => 'current-menu-item',
        'active_ancestor_class'  => 'current-menu-ancestor',
        'item_tag'               => 'li',
        'link_before'            => '',
        'link_after'             => '',
    ], $options);

    $menu = cmsGetMenuByLocation($location);
    if (!$menu) return '';
    $tree = cmsGetMenuItemsTree((int)$menu['id']);
    if (empty($tree)) return '';

    $renderItems = function (array $items, int $depth = 0) use (&$renderItems, $opts): string {
        $maxD = (int)$opts['max_depth'];
        if ($maxD > 0 && $depth >= $maxD) return '';

        $ulCls = $depth === 0 ? $opts['menu_class'] : $opts['submenu_class'];
        $tag = $opts['item_tag'];
        $out = '<ul class="' . htmlspecialchars($ulCls, ENT_QUOTES) . '">';

        foreach ($items as $item) {
            $url = cmsResolveMenuItemUrl($item);
            $isActive = cmsIsMenuItemActive($item);
            $hasActiveChild = false;

            // Check if any descendant is active
            if (!empty($item['children'])) {
                $checkActive = function (array $children) use (&$checkActive): bool {
                    foreach ($children as $c) {
                        if (cmsIsMenuItemActive($c)) return true;
                        if (!empty($c['children']) && $checkActive($c['children'])) return true;
                    }
                    return false;
                };
                $hasActiveChild = $checkActive($item['children']);
            }

            // Build CSS classes
            $classes = [];
            $classes[] = 'menu-item';
            $classes[] = 'menu-item-type-' . ($item['link_type'] ?? 'custom');
            if (!empty($item['children'])) $classes[] = 'has-children';
            if ($isActive) $classes[] = $opts['active_class'];
            if ($hasActiveChild) $classes[] = $opts['active_ancestor_class'];
            $extra = trim((string)($item['css_class'] ?? ''));
            if ($extra !== '') $classes[] = $extra;

            $target = ((string)($item['target'] ?? '_self')) === '_blank' ? ' target="_blank" rel="noopener"' : '';
            $titleAttr = !empty($item['title_attr']) ? ' title="' . htmlspecialchars($item['title_attr'], ENT_QUOTES) . '"' : '';

            $out .= '<' . $tag . ' class="' . htmlspecialchars(implode(' ', $classes), ENT_QUOTES) . '">';

            // Icon
            $iconHtml = '';
            if (!empty($item['icon'])) {
                $iconHtml = '<span class="menu-item-icon ' . htmlspecialchars($item['icon'], ENT_QUOTES) . '"></span>';
            }

            $out .= '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '"' . $target . $titleAttr . '>';
            $out .= $opts['link_before'];
            $out .= $iconHtml;
            $out .= '<span class="menu-item-label">' . htmlspecialchars((string)$item['label'], ENT_QUOTES) . '</span>';
            $out .= $opts['link_after'];
            $out .= '</a>';

            // Description
            if ($opts['show_description'] && !empty($item['description'])) {
                $out .= '<span class="menu-item-description">' . htmlspecialchars($item['description'], ENT_QUOTES) . '</span>';
            }

            // Children
            if (!empty($item['children'])) {
                $out .= $renderItems($item['children'], $depth + 1);
            }
            $out .= '</' . $tag . '>';
        }
        $out .= '</ul>';
        return $out;
    };

    return '<nav class="' . htmlspecialchars($opts['css_class'], ENT_QUOTES) . '">' . $renderItems($tree) . '</nav>';
}

// ── Saved / Reusable Blocks ────────────────────────────────────────

/**
 * Get all saved blocks, optionally filtered by category.
 */

function cmsRenderMenuById(object $db, int $menuId, string $cssClass = 'nav-menu'): string
{
    $stmt = $db->prepare(
        "SELECT id, label, url, link_type, link_ref, target, parent_id, sort_order
         FROM cms_menu_items
         WHERE menu_id = :mid ORDER BY sort_order ASC"
    );
    $stmt->execute([':mid' => $menuId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (empty($items)) return '';

    $html = '<ul class="' . htmlspecialchars($cssClass) . '">';
    foreach ($items as $item) {
        if ((int)($item['parent_id'] ?? 0) !== 0) continue; // top-level only for footer
        $url = cmsResolveMenuItemUrl($item);
        $target = (string)($item['target'] ?? '') === '_blank' ? ' target="_blank" rel="noopener noreferrer"' : '';
        $html .= '<li><a href="' . htmlspecialchars($url) . '"' . $target . '>' . htmlspecialchars((string)($item['label'] ?? '')) . '</a></li>';
    }
    $html .= '</ul>';
    return $html;
}

/**
 * Get an SVG icon for a social network name.
 */
