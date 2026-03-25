<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Admin Categories (handlers/45-admin-categories.php)
// ─────────────────────────────────────────────────────────────────────────

/**
 * GET  /admin/products/categories  — category management
 * POST /admin/products/categories  — create / delete category
 */
function ecAdminCategories(): void
{
    $user = ecRequireAdmin();
    $db   = ecDb();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify();
        $input  = ecInput();
        $action = $input['action'] ?? 'create';

        if ($action === 'create') {
            $name = trim((string)($input['name'] ?? ''));
            $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
            $slug = trim($slug, '-');

            if ($name !== '' && $slug !== '') {
                // Ensure product taxonomy
                try {
                    if (ecHasCmsCategoryTaxonomy()) {
                        $db->execute(
                            "INSERT INTO cms_categories (name, slug, taxonomy, created_at, updated_at)
                             VALUES (?, ?, 'product', NOW(), NOW())
                             ON DUPLICATE KEY UPDATE name = VALUES(name), taxonomy = 'product'",
                            [$name, $slug]
                        );
                    } else {
                        $db->execute(
                            "INSERT INTO cms_categories (name, slug, created_at, updated_at)
                             VALUES (?, ?, NOW(), NOW())
                             ON DUPLICATE KEY UPDATE name = VALUES(name)",
                            [$name, $slug]
                        );
                    }
                    $_SESSION['ec_message'] = ['type' => 'success', 'text' => 'Category created.'];
                } catch (\Throwable $e) {
                    $_SESSION['ec_message'] = ['type' => 'error', 'text' => 'Could not create: ' . $e->getMessage()];
                }
            } else {
                $_SESSION['ec_message'] = ['type' => 'error', 'text' => 'Name is required.'];
            }
        } elseif ($action === 'delete') {
            $catId = (int)($input['id'] ?? 0);
            if ($catId > 0) {
                $db->execute("DELETE FROM cms_content_categories WHERE category_id = ?", [$catId]);
                $db->execute("DELETE FROM cms_categories WHERE id = ?", [$catId]);
                $_SESSION['ec_message'] = ['type' => 'success', 'text' => 'Category deleted.'];
            }
        }

        header('Location: /ecommerce/admin/categories');
        exit;
    }

    $categoryWhere = ecHasCmsCategoryTaxonomy() ? "WHERE cat.taxonomy = 'product' OR cat.taxonomy IS NULL" : '';
    $categories = $db->query(
        "SELECT cat.id, cat.name, cat.slug,
                COUNT(cc.content_id) as product_count
         FROM cms_categories cat
         LEFT JOIN cms_content_categories cc ON cc.category_id = cat.id
         LEFT JOIN cms_content c ON c.id = cc.content_id AND c.type = 'product' AND c.deleted_at IS NULL
         {$categoryWhere}
         GROUP BY cat.id
         ORDER BY cat.name"
    )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $ctx = ecAdminContext($user, 'categories', [
        'categories' => $categories,
        'message'    => $_SESSION['ec_message'] ?? null,
    ]);
    unset($_SESSION['ec_message']);

    ecRender('modules/ecommerce/admin/categories.disyl', $ctx);
}
