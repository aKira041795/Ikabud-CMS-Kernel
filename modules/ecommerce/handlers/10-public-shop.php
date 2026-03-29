<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Public Shop Handlers (handlers/10-public-shop.php)
// ─────────────────────────────────────────────────────────────────────────

function ecPublicStorefrontCategories(int $activeCategoryId = 0): array
{
    $categories = ecDb()->query(
        ecCmsCategorySelectSql('id, name, slug', 'name ASC')
    )->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $resolved = [];
    foreach ($categories as $category) {
        $categoryId = (int)($category['id'] ?? 0);
        $categoryName = trim((string)($category['name'] ?? ''));
        if ($categoryId <= 0 || $categoryName === '') {
            continue;
        }

        $categorySlug = trim((string)($category['slug'] ?? ''));
        $resolved[] = [
            'id' => $categoryId,
            'slug' => $categorySlug,
            'name' => $categoryName,
            'url' => $categorySlug !== ''
                ? '/ecommerce/shop/category/' . rawurlencode($categorySlug)
                : '/ecommerce/shop?cat=' . $categoryId,
            'is_active' => $activeCategoryId === $categoryId,
        ];
    }

    return $resolved;
}

function ecPublicStorefrontPageUrl(string $basePath, int $page, array $query = []): string
{
    $params = [];
    foreach ($query as $key => $value) {
        if ($value === null || $value === '' || $value === false || $value === 0) {
            continue;
        }
        $params[$key] = $value;
    }

    if ($page > 1) {
        $params['page'] = $page;
    }

    $queryString = http_build_query($params);
    if ($queryString === '') {
        return $basePath;
    }

    return $basePath . '?' . $queryString;
}

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

        $availableCategories = ecPublicStorefrontCategories($categoryId);
        $currentCategory = null;
        foreach ($availableCategories as $category) {
            if ((int)($category['id'] ?? 0) !== $categoryId) {
                continue;
            }

            $currentCategory = [
                'id' => (int)($category['id'] ?? 0),
                'slug' => trim((string)($category['slug'] ?? '')),
                'name' => trim((string)($category['name'] ?? '')),
            ];
            break;
        }

        if (ecDispatchCanonicalEntityRoute('cms:cmsPublicEntityList', [
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
        ], $routeContext)) {
            return;
        }

        $page = max(1, (int)(ecInput()['page'] ?? 1));
        $offset = ($page - 1) * $perPage;
        $productResult = ecProductList([
            'search' => $search,
            'category_id' => $categoryId ?: null,
            'status' => 'published',
            'limit' => $perPage,
            'offset' => $offset,
        ]);
        $totalPages = $perPage > 0 ? max(1, (int)ceil($productResult['total'] / $perPage)) : 1;

        ecRender('modules/ecommerce/public/shop.disyl', [
            'page_title' => trim((string)ecSettings('shop_page_title')) ?: 'Shop',
            'products' => $productResult['items'],
            'total' => $productResult['total'],
            'categories' => $availableCategories,
            'available_categories' => $availableCategories,
            'current_cat' => $currentCategory,
            'search' => $search,
            'category_id' => $categoryId,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
            'all_items_url' => '/ecommerce/shop',
            'search_action_url' => '/ecommerce/shop',
            'pagination_first_url' => ecPublicStorefrontPageUrl('/ecommerce/shop', 1, [
                'search' => $search,
                'cat' => $categoryId,
            ]),
            'pagination_prev_url' => $page > 1
                ? ecPublicStorefrontPageUrl('/ecommerce/shop', $page - 1, [
                    'search' => $search,
                    'cat' => $categoryId,
                ])
                : '',
            'pagination_next_url' => $page < $totalPages
                ? ecPublicStorefrontPageUrl('/ecommerce/shop', $page + 1, [
                    'search' => $search,
                    'cat' => $categoryId,
                ])
                : '',
            'cart_count' => (int)(ecCartGet()['totals']['item_count'] ?? 0),
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
    $search = trim((string)(ecInput()['search'] ?? ''));
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
        $search = trim((string)(ecInput()['search'] ?? ''));
        $perPage = (int)ecSettings('products_per_page');

        if (ecDispatchCanonicalEntityRoute('cms:cmsPublicEntityList', [
                'type'          => 'product',
                'category_slug' => $slug,
                'search'        => $search,
                'per_page'      => $perPage,
                'base_list_url' => '/ecommerce/shop/category/' . rawurlencode($slug),
                'item_base_url' => '/ecommerce/shop',
                'all_items_url' => '/ecommerce/shop',
                'search_action_url' => '/ecommerce/shop/category/' . rawurlencode($slug),
                'available_categories' => ecPublicStorefrontCategories(),
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
        $availableCategories = ecPublicStorefrontCategories((int)$cat['id']);

        $productResult = ecProductList([
            'category_id' => (int)$cat['id'],
            'search'      => $search,
            'status'      => 'published',
            'limit'       => $perPage,
            'offset'      => $offset,
        ]);

        $totalPages = $perPage > 0 ? (int)ceil($productResult['total'] / $perPage) : 1;

        ecRender('modules/ecommerce/public/shop.disyl', [
            'page_title'  => $cat['name'],
            'products'    => $productResult['items'],
            'total'       => $productResult['total'],
            'categories'  => $availableCategories,
            'available_categories' => $availableCategories,
            'current_cat' => $cat,
            'search'      => $search,
            'category_id' => (int)$cat['id'],
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
            'all_items_url' => '/ecommerce/shop',
            'search_action_url' => '/ecommerce/shop/category/' . rawurlencode($slug),
            'pagination_first_url' => ecPublicStorefrontPageUrl('/ecommerce/shop/category/' . rawurlencode($slug), 1, [
                'search' => $search,
            ]),
            'pagination_prev_url' => $page > 1
                ? ecPublicStorefrontPageUrl('/ecommerce/shop/category/' . rawurlencode($slug), $page - 1, [
                    'search' => $search,
                ])
                : '',
            'pagination_next_url' => $page < $totalPages
                ? ecPublicStorefrontPageUrl('/ecommerce/shop/category/' . rawurlencode($slug), $page + 1, [
                    'search' => $search,
                ])
                : '',
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
