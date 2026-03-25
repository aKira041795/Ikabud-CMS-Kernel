<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Public Shop Handlers (handlers/10-public-shop.php)
// ─────────────────────────────────────────────────────────────────────────

/**
 * GET /ecommerce/shop  — product grid
 */
function ecPublicShop(): void
{
    $search     = trim((string)(ecInput()['search'] ?? ''));
    $categoryId = (int)(ecInput()['cat'] ?? 0);
    $page       = max(1, (int)(ecInput()['page'] ?? 1));
    $perPage    = (int)ecSettings('products_per_page', 12);
    $offset     = ($page - 1) * $perPage;

    $productResult = ecProductList([
        'search'      => $search,
        'category_id' => $categoryId ?: null,
        'status'      => 'published',
        'limit'       => $perPage,
        'offset'      => $offset,
    ]);

    // Categories for filter sidebar (product taxonomy)
    $categories = ecDb()->query(
        ecCmsCategorySelectSql('id, name, slug', 'name ASC')
    )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $totalPages = $perPage > 0 ? (int)ceil($productResult['total'] / $perPage) : 1;

    ecRender('modules/ecommerce/public/shop.disyl', [
        'page_title'  => ecSettings('shop_page_title', 'Shop'),
        'products'    => $productResult['items'],
        'total'       => $productResult['total'],
        'categories'  => $categories,
        'search'      => $search,
        'category_id' => $categoryId,
        'page'        => $page,
        'per_page'    => $perPage,
        'total_pages' => $totalPages,
        'cart_count'  => (int)(ecCartGet()['totals']['item_count'] ?? 0),
    ]);
}

/**
 * GET /shop/category/{slug}  — product grid filtered by category
 */
function ecPublicCategory(): void
{
    $slug = ecCtx()['params']['slug'] ?? '';
    if (!$slug) {
        header('Location: /ecommerce/shop');
        exit;
    }

    $db  = ecDb();
    $cat = $db->query(
        "SELECT * FROM cms_categories WHERE slug = ? LIMIT 1",
        [$slug]
    )->fetch(\PDO::FETCH_ASSOC);

    if (!$cat) {
        http_response_code(404);
        ecRender('modules/ecommerce/public/404.disyl', ['message' => 'Category not found']);
        return;
    }

    $page    = max(1, (int)(ecInput()['page'] ?? 1));
    $perPage = (int)ecSettings('products_per_page', 12);
    $offset  = ($page - 1) * $perPage;

    $productResult = ecProductList([
        'category_id' => (int)$cat['id'],
        'status'      => 'published',
        'limit'       => $perPage,
        'offset'      => $offset,
    ]);

    $totalPages = $perPage > 0 ? (int)ceil($productResult['total'] / $perPage) : 1;

    ecRender('modules/ecommerce/public/shop.disyl', [
        'page_title'  => $cat['name'],
        'products'    => $productResult['items'],
        'total'       => $productResult['total'],
        'categories'  => [],
        'current_cat' => $cat,
        'search'      => '',
        'category_id' => (int)$cat['id'],
        'page'        => $page,
        'per_page'    => $perPage,
        'total_pages' => $totalPages,
        'cart_count'  => (int)(ecCartGet()['totals']['item_count'] ?? 0),
    ]);
}

/**
 * GET /shop/{slug}  — product detail page
 */
function ecPublicProduct(): void
{
    $slug    = ecCtx()['params']['slug'] ?? '';
    $product = ecProductGetBySlug($slug);

    if (!$product || $product['status'] !== 'published') {
        http_response_code(404);
        ecRender('modules/ecommerce/public/404.disyl', ['message' => 'Product not found']);
        return;
    }

    if (function_exists('cmsPublicEntityView') && function_exists('moduleWithContext')) {
        moduleWithContext('cms', static function () use ($slug): void {
            cmsPublicEntityView(['type' => 'product', 'slug' => $slug]);
        });
        return;
    }

    ecRender('modules/ecommerce/public/product.disyl', [
        'page_title'  => $product['title'],
        'product'     => $product,
        'cart_count'  => (int)(ecCartGet()['totals']['item_count'] ?? 0),
    ]);
}
