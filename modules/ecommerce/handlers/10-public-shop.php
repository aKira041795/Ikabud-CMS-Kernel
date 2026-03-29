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
    $routeContext = [
        'public_render_origin' => 'ecommerce',
        'public_route_kind' => 'shop_index',
    ];

    ecWithPublicThemeRouteContext($routeContext, static function () use ($search, $categoryId, $perPage, $routeContext): void {
        $presentationMode = ecResolvePublicPresentationMode('shop_index', $routeContext);

        $categories = ecDb()->query(
            ecCmsCategorySelectSql('id, name, slug', 'name ASC')
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $availableCategories = [];
        foreach ($categories as $category) {
            $resolvedCategoryId = (int)($category['id'] ?? 0);
            $resolvedCategoryName = trim((string)($category['name'] ?? ''));
            if ($resolvedCategoryId <= 0 || $resolvedCategoryName === '') {
                continue;
            }

            $availableCategories[] = [
                'id' => $resolvedCategoryId,
                'slug' => trim((string)($category['slug'] ?? '')),
                'name' => $resolvedCategoryName,
                'url' => '/ecommerce/shop?cat=' . $resolvedCategoryId,
                'is_active' => $categoryId === $resolvedCategoryId,
            ];
        }

        executeModuleHandler('cms:cmsPublicEntityList', [
            'type' => 'product',
            'search' => $search,
            'category_id' => $categoryId ?: null,
            'per_page' => $perPage,
            'base_list_url' => '/ecommerce/shop',
            'item_base_url' => '/ecommerce/shop',
            'list_title' => trim((string)ecSettings('shop_page_title')),
            'available_categories' => $availableCategories,
            'all_items_url' => '/ecommerce/shop',
            'search_action_url' => '/ecommerce/shop',
            'public_render_origin' => 'ecommerce',
            'public_route_kind' => 'shop_index',
            'public_presentation_mode' => $presentationMode,
        ]);
    });
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

    $routeContext = [
        'public_render_origin' => 'ecommerce',
        'public_route_kind' => 'shop_category',
    ];

    ecWithPublicThemeRouteContext($routeContext, static function () use ($slug, $routeContext): void {
        $presentationMode = ecResolvePublicPresentationMode('shop_category', $routeContext);
        $perPage = (int)ecSettings('products_per_page');

        if (ecDispatchCanonicalEntityRoute('cms:cmsPublicEntityList', [
                'type'          => 'product',
                'category_slug' => $slug,
                'per_page'      => $perPage,
                'base_list_url' => '/ecommerce/shop/category/' . rawurlencode($slug),
                'item_base_url' => '/ecommerce/shop',
                'public_render_origin' => 'ecommerce',
                'public_route_kind' => 'shop_category',
            ], $routeContext)) {
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
            'public_route_kind' => 'shop_category',
            'public_presentation_mode' => $presentationMode,
        ]);
    });
}

/**
 * GET /shop/{slug}  — product detail page
 */
function ecPublicProduct(array $params = []): void
{
    $slug    = (string)($params['slug'] ?? '');
    $routeContext = [
        'public_render_origin' => 'ecommerce',
        'public_route_kind' => 'product_detail',
    ];

    ecWithPublicThemeRouteContext($routeContext, static function () use ($slug, $routeContext): void {
        $presentationMode = ecResolvePublicPresentationMode('product_detail', $routeContext);

        if (ecDispatchCanonicalEntityRoute('cms:cmsPublicEntityView', [
                'type' => 'product',
                'slug' => $slug,
                'public_render_origin' => 'ecommerce',
                'public_route_kind' => 'product_detail',
            ], $routeContext)) {
            return;
        }

        $product = ecProductGetBySlug($slug);

        if (!$product || $product['status'] !== 'published') {
            http_response_code(404);
            ecRender('pages/404.disyl', ['page_title' => 'Product Not Found']);
            return;
        }

        ecRender('modules/ecommerce/public/product.disyl', [
            'page_title'  => $product['title'],
            'product'     => $product,
            'cart_count'  => (int)(ecCartGet()['totals']['item_count'] ?? 0),
            'public_route_kind' => 'product_detail',
            'public_presentation_mode' => $presentationMode,
        ]);
    });
}
