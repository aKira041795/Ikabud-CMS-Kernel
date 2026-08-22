<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — API: Categories (handlers/81-api-categories.php)
// Product categories live in cms_categories with taxonomy = 'product'.
// Mirrors the admin category form handler (45-admin-categories.php) as JSON.
// ─────────────────────────────────────────────────────────────────────────

function ecApiCategoryCreate(): void
{
    ecRequireAdmin();
    $input = ecInput();
    $name  = trim((string)($input['name'] ?? ''));

    if ($name === '') {
        ecJsonError('name required', 422);
    }

    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
    $slug = trim((string)$slug, '-');
    if ($slug === '') {
        ecJsonError('name must produce a valid slug', 422);
    }

    $hasTaxonomy = ecHasCmsCategoryTaxonomy();

    try {
        $categoryId = 0;
        moduleWithContext('cms', static function () use ($name, $slug, $hasTaxonomy, &$categoryId): void {
            $cmsDb = cmsDb();
            if ($hasTaxonomy) {
                $cmsDb->execute(
                    "INSERT INTO cms_categories (name, slug, taxonomy, created_at, updated_at)
                     VALUES (?, ?, 'product', NOW(), NOW())
                     ON DUPLICATE KEY UPDATE name = VALUES(name), taxonomy = 'product'",
                    [$name, $slug]
                );
            } else {
                $cmsDb->execute(
                    "INSERT INTO cms_categories (name, slug, created_at, updated_at)
                     VALUES (?, ?, NOW(), NOW())
                     ON DUPLICATE KEY UPDATE name = VALUES(name)",
                    [$name, $slug]
                );
            }
            $categoryId = (int)$cmsDb->lastInsertId();
        });
        ecJsonOk(['category_id' => $categoryId], 201);
    } catch (\Throwable $e) {
        ecJsonError('Create failed: ' . $e->getMessage(), 422);
    }
}

function ecApiCategoryUpdate(array $params = []): void
{
    ecRequireAdmin();
    $id = (int)($params['id'] ?? 0);
    if ($id <= 0) {
        ecJsonError('id required', 422);
    }

    $input = ecInput();
    $name  = trim((string)($input['name'] ?? ''));
    if ($name === '') {
        ecJsonError('name required', 422);
    }

    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
    $slug = trim((string)$slug, '-');

    try {
        moduleWithContext('cms', static function () use ($id, $name, $slug): void {
            $cmsDb = cmsDb();
            $cmsDb->execute(
                'UPDATE cms_categories SET name = ?, slug = ?, updated_at = NOW() WHERE id = ?',
                [$name, $slug, $id]
            );
        });
        ecJsonOk(['ok' => true]);
    } catch (\Throwable $e) {
        ecJsonError('Update failed: ' . $e->getMessage(), 422);
    }
}

function ecApiCategoryDelete(array $params = []): void
{
    ecRequireAdmin();
    $id = (int)($params['id'] ?? 0);
    if ($id <= 0) {
        ecJsonError('id required', 422);
    }

    try {
        moduleWithContext('cms', static function () use ($id): void {
            $cmsDb = cmsDb();
            $cmsDb->execute('DELETE FROM cms_content_categories WHERE category_id = ?', [$id]);
            $cmsDb->execute('DELETE FROM cms_categories WHERE id = ?', [$id]);
        });
        ecJsonOk(['deleted' => true]);
    } catch (\Throwable $e) {
        ecJsonError('Delete failed: ' . $e->getMessage(), 422);
    }
}
