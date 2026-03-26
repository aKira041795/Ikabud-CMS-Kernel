<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Public Shop Handlers (handlers/10-public-shop.php)
// ─────────────────────────────────────────────────────────────────────────

/**
 * GET /ecommerce/shop  — product grid
 * Delegates to CMS universal entity list (capability-driven) when available.
 */
function ecPublicShop(): void
{
    $search     = trim((string)(ecInput()['search'] ?? ''));
    $categoryId = (int)(ecInput()['cat'] ?? 0);
    $perPage    = (int)ecSettings('products_per_page');

    if (function_exists('executeModuleHandler')) {
        executeModuleHandler('cms:cmsPublicEntityList', [
            'type'          => 'product',
            'search'        => $search,
            'category_id'   => $categoryId ?: null,
            'per_page'      => $perPage,
            'base_list_url' => '/ecommerce/shop',
            'item_base_url' => '/ecommerce/shop',
        ]);
        return;
    }

    // Fallback: parallel ecommerce shop template
    $page   = max(1, (int)(ecInput()['page'] ?? 1));
    $offset = ($page - 1) * $perPage;

    $productResult = ecProductList([
        'search'      => $search,
        'category_id' => $categoryId ?: null,
        'status'      => 'published',
        'limit'       => $perPage,
        'offset'      => $offset,
    ]);

    $categories = ecDb()->query(
        ecCmsCategorySelectSql('id, name, slug', 'name ASC')
    )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $totalPages = $perPage > 0 ? (int)ceil($productResult['total'] / $perPage) : 1;

    ecRender('modules/ecommerce/public/shop.disyl', [
        'page_title'  => ecSettings('shop_page_title'),
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
 * GET /ecommerce/shop/category/{slug}  — product grid filtered by category
 * Delegates to CMS universal entity list (capability-driven) when available.
 */
function ecPublicCategory(array $params = []): void
{
    $slug = (string)($params['slug'] ?? '');
    if (!$slug) {
        header('Location: /ecommerce/shop');
        exit;
    }

    if (function_exists('executeModuleHandler')) {
        $perPage = (int)ecSettings('products_per_page');
        executeModuleHandler('cms:cmsPublicEntityList', [
            'type'          => 'product',
            'category_slug' => $slug,
            'per_page'      => $perPage,
            'base_list_url' => '/ecommerce/shop/category/' . rawurlencode($slug),
            'item_base_url' => '/ecommerce/shop',
        ]);
        return;
    }

    // Fallback
    $db  = ecDb();
    $cat = $db->query(
        "SELECT * FROM cms_categories WHERE slug = ? LIMIT 1",
        [$slug]
    )->fetch(\PDO::FETCH_ASSOC);

    if (!$cat) {
        http_response_code(404);
        ecRender('pages/404.disyl', ['page_title' => 'Category Not Found']);
        return;
    }

    $page    = max(1, (int)(ecInput()['page'] ?? 1));
    $perPage = (int)ecSettings('products_per_page');
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
function ecPublicProduct(array $params = []): void
{
    $slug    = (string)($params['slug'] ?? '');
    $product = ecProductGetBySlug($slug);

    if (!$product || $product['status'] !== 'published') {
        http_response_code(404);
        ecRender('pages/404.disyl', ['page_title' => 'Product Not Found']);
        return;
    }

    if (function_exists('executeModuleHandler')) {
        executeModuleHandler('cms:cmsPublicEntityView', ['type' => 'product', 'slug' => $slug]);
        return;
    }

    ecRender('modules/ecommerce/public/product.disyl', [
        'page_title'  => $product['title'],
        'product'     => $product,
        'cart_count'  => (int)(ecCartGet()['totals']['item_count'] ?? 0),
    ]);
}
