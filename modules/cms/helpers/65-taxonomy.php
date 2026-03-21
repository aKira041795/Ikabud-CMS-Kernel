<?php

declare(strict_types=1);

function cmsGetCategories(bool $tree = false): array
{
    try {
        $rows = cmsDb()->query(
            "SELECT id, name, slug, description, parent_id, sort_order FROM cms_categories ORDER BY sort_order ASC, name ASC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
    if (!$tree) {
        return $rows;
    }
    $byParent = [];
    foreach ($rows as $r) {
        $pid = $r['parent_id'] ? (int)$r['parent_id'] : 0;
        $byParent[$pid][] = $r;
    }
    $build = function (int $parentId, int $depth) use (&$build, $byParent): array {
        $out = [];
        foreach ($byParent[$parentId] ?? [] as $cat) {
            $cat['depth'] = $depth;
            $cat['children'] = $build((int)$cat['id'], $depth + 1);
            $out[] = $cat;
        }
        return $out;
    };
    return $build(0, 0);
}

/**
 * Get category IDs assigned to a content item.
 */

function cmsGetContentCategoryIds(int $contentId): array
{
    try {
        $stmt = cmsDb()->prepare("SELECT category_id FROM cms_content_categories WHERE content_id = ?");
        $stmt->execute([$contentId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Get full category objects (id, name, slug) assigned to a content item.
 */
function cmsGetContentCategories(int $contentId): array
{
    try {
        $stmt = cmsDb()->prepare(
            "SELECT cat.id, cat.name, cat.slug
             FROM cms_categories cat
             INNER JOIN cms_content_categories cc ON cc.category_id = cat.id
             WHERE cc.content_id = ?
             ORDER BY cat.name"
        );
        $stmt->execute([$contentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Sync category assignments for a content item (replace strategy).
 */

function cmsSyncContentCategories(int $contentId, array $categoryIds): void
{
    $db = cmsDb();
    try {
        $db->prepare("DELETE FROM cms_content_categories WHERE content_id = ?")->execute([$contentId]);
        if (empty($categoryIds)) {
            return;
        }
        $stmt = $db->prepare("INSERT IGNORE INTO cms_content_categories (content_id, category_id) VALUES (?, ?)");
        foreach ($categoryIds as $catId) {
            $catId = (int)$catId;
            if ($catId > 0) {
                $stmt->execute([$contentId, $catId]);
            }
        }
    } catch (Throwable $e) {
        // best-effort
    }
}

/**
 * Create a category. Returns ['ok'=>true, 'id'=>int] or ['ok'=>false, 'error'=>string].
 */

function cmsCategoryCreate(string $name, string $slug = '', ?string $description = null, ?int $parentId = null): array
{
    $name = trim($name);
    if ($name === '') {
        return ['ok' => false, 'error' => 'Name is required'];
    }
    if ($slug === '') {
        $slug = cmsSlugify($name);
    }
    try {
        $db = cmsDb();
        $stmt = $db->prepare(
            "INSERT INTO cms_categories (name, slug, description, parent_id, created_at) VALUES (?, ?, ?, ?, NOW())"
        );
        $stmt->execute([$name, $slug, $description, $parentId]);
        return ['ok' => true, 'id' => (int)$db->lastInsertId()];
    } catch (Throwable $e) {
        $msg = str_contains($e->getMessage(), 'Duplicate') ? 'Category slug already exists' : 'Failed to create category';
        return ['ok' => false, 'error' => $msg];
    }
}

/**
 * Update a category.
 */

function cmsCategoryUpdate(int $id, array $data): array
{
    $fields = [];
    $bind = [':id' => $id];
    foreach (['name', 'slug', 'description'] as $f) {
        if (array_key_exists($f, $data)) {
            $fields[] = "{$f} = :{$f}";
            $bind[":{$f}"] = trim((string)$data[$f]);
        }
    }
    if (array_key_exists('parent_id', $data)) {
        $fields[] = 'parent_id = :parent_id';
        $bind[':parent_id'] = $data['parent_id'] !== null && $data['parent_id'] !== '' ? (int)$data['parent_id'] : null;
    }
    if (empty($fields)) {
        return ['ok' => true];
    }
    try {
        $setStr = implode(', ', $fields);
        cmsDb()->prepare("UPDATE cms_categories SET {$setStr} WHERE id = :id")->execute($bind);
        return ['ok' => true];
    } catch (Throwable $e) {
        $msg = str_contains($e->getMessage(), 'Duplicate') ? 'Category slug already exists' : 'Update failed';
        return ['ok' => false, 'error' => $msg];
    }
}

/**
 * Delete a category (content assignments removed via FK CASCADE).
 */

function cmsCategoryDelete(int $id): array
{
    try {
        cmsDb()->prepare("DELETE FROM cms_categories WHERE id = ?")->execute([$id]);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Delete failed'];
    }
}

// ── Tags ──────────────────────────────────────────────────────────

/**
 * List all tags ordered by name.
 */

function cmsGetTags(): array
{
    try {
        return cmsDb()->query(
            "SELECT id, name, slug FROM cms_tags ORDER BY name ASC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Get tag IDs assigned to a content item.
 */

function cmsGetContentTagIds(int $contentId): array
{
    try {
        $stmt = cmsDb()->prepare("SELECT tag_id FROM cms_content_tags WHERE content_id = ?");
        $stmt->execute([$contentId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Get tag names assigned to a content item.
 */

function cmsGetContentTagNames(int $contentId): array
{
    try {
        $stmt = cmsDb()->prepare(
            "SELECT t.name FROM cms_tags t INNER JOIN cms_content_tags ct ON ct.tag_id = t.id WHERE ct.content_id = ? ORDER BY t.name"
        );
        $stmt->execute([$contentId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Sync tag assignments for a content item.
 * Accepts an array of tag names (strings). Creates tags that don't exist yet.
 */

function cmsSyncContentTags(int $contentId, array $tagNames): void
{
    $db = cmsDb();
    try {
        $db->prepare("DELETE FROM cms_content_tags WHERE content_id = ?")->execute([$contentId]);
        if (empty($tagNames)) {
            return;
        }
        $insertTag = $db->prepare("INSERT IGNORE INTO cms_tags (name, slug, created_at) VALUES (?, ?, NOW())");
        $findTag = $db->prepare("SELECT id FROM cms_tags WHERE slug = ? LIMIT 1");
        $link = $db->prepare("INSERT IGNORE INTO cms_content_tags (content_id, tag_id) VALUES (?, ?)");
        foreach ($tagNames as $name) {
            $name = trim((string)$name);
            if ($name === '') continue;
            $slug = cmsSlugify($name);
            if ($slug === '') continue;
            $insertTag->execute([$name, $slug]);
            $findTag->execute([$slug]);
            $tagId = $findTag->fetchColumn();
            if ($tagId) {
                $link->execute([$contentId, (int)$tagId]);
            }
        }
    } catch (Throwable $e) {
        // best-effort
    }
}

/**
 * Create a tag manually. Returns ['ok'=>true, 'id'=>int] or error.
 */

function cmsTagCreate(string $name): array
{
    $name = trim($name);
    if ($name === '') {
        return ['ok' => false, 'error' => 'Name is required'];
    }
    $slug = cmsSlugify($name);
    try {
        $db = cmsDb();
        $db->prepare("INSERT INTO cms_tags (name, slug, created_at) VALUES (?, ?, NOW())")->execute([$name, $slug]);
        return ['ok' => true, 'id' => (int)$db->lastInsertId()];
    } catch (Throwable $e) {
        if (str_contains($e->getMessage(), 'Duplicate')) {
            $stmt = cmsDb()->prepare("SELECT id FROM cms_tags WHERE slug = ? LIMIT 1");
            $stmt->execute([$slug]);
            $id = $stmt->fetchColumn();
            return $id ? ['ok' => true, 'id' => (int)$id] : ['ok' => false, 'error' => 'Tag already exists'];
        }
        return ['ok' => false, 'error' => 'Failed to create tag'];
    }
}

/**
 * Delete a tag.
 */

function cmsTagDelete(int $id): array
{
    try {
        cmsDb()->prepare("DELETE FROM cms_content_tags WHERE tag_id = ?")->execute([$id]);
        cmsDb()->prepare("DELETE FROM cms_tags WHERE id = ?")->execute([$id]);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Delete failed'];
    }
}

// ── Menus (WordPress-style) ────────────────────────────────────────

// ── Menu Locations ─────────────────────────────────────────────────

/**
 * Get all registered menu locations with assigned menus.
 */
