<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Public Shop Handlers (handlers/10-public-shop.php)
// ─────────────────────────────────────────────────────────────────────────

function ecPublicStorefrontCategories(int $activeCategoryId = 0, array $query = []): array
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
        $categoryBaseUrl = $categorySlug !== ''
            ? '/ecommerce/shop/category/' . rawurlencode($categorySlug)
            : '/ecommerce/shop';
        $categoryQuery = $query;
        if ($categorySlug === '') {
            $categoryQuery['cat'] = $categoryId;
        }
        $resolved[] = [
            'id' => $categoryId,
            'slug' => $categorySlug,
            'name' => $categoryName,
            'url' => ecPublicStorefrontPageUrl($categoryBaseUrl, 1, $categoryQuery),
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

function ecPublicStorefrontRequestedStoreFilter(bool $autoScope = true): int
{
    $storeInput = trim((string)(ecInput()['store'] ?? ''));
    $storeFilter = 0;
    if ($storeInput !== '') {
        if (is_numeric($storeInput)) {
            $storeFilter = (int)$storeInput;
        } elseif (function_exists('ecStoreBySlug')) {
            $storeBySlug = ecStoreBySlug($storeInput);
            if (is_array($storeBySlug) && !empty($storeBySlug['id'])) {
                $storeFilter = (int)$storeBySlug['id'];
            }
        }
    }

    if (!$autoScope || $storeFilter !== 0 || !function_exists('ecStoresForUser') || !function_exists('ecStoreStorageAvailable') || !ecStoreStorageAvailable()) {
        return $storeFilter;
    }

    $shopUser = app()->user();
    if (!is_array($shopUser)) {
        return $storeFilter;
    }

    $shopRole   = (string)($shopUser['role'] ?? '');
    $shopSource = (string)($shopUser['source'] ?? '');
    $isCmsAdmin = $shopSource === 'kernel'
        || (function_exists('cmsRoleAtLeast') && cmsRoleAtLeast($shopRole, 'administrator'));
    if ($isCmsAdmin) {
        return $storeFilter;
    }

    $shopUserId = (int)($shopUser['id'] ?? 0);
    if ($shopUserId <= 0) {
        return $storeFilter;
    }

    $userStores = ecStoresForUser($shopUserId);
    if (count($userStores) === 1) {
        return (int)($userStores[0]['store_id'] ?? 0);
    }

    return $storeFilter;
}

function ecPublicDecorateCatalogProducts(array $products): array
{
    $resolved = [];

    foreach ($products as $product) {
        if (!is_array($product)) {
            continue;
        }

        $storefrontItem = ecBuildStorefrontCatalogItem($product, ['item_base_url' => '/ecommerce/shop']);
        $product['pricing'] = $storefrontItem['pricing'] ?? (array)($product['pricing'] ?? []);
        $product['bundle_summary'] = $storefrontItem['bundle_summary'] ?? (array)($product['bundle_summary'] ?? []);
        $product['subscription_summary'] = $storefrontItem['subscription_summary'] ?? (array)($product['subscription_summary'] ?? []);
        $product['detail_url'] = (string)($storefrontItem['url'] ?? '/ecommerce/shop');
        $product['sale_badge_text'] = (string)($storefrontItem['badges']['sale'] ?? '');
        $product['inventory_badge_text'] = (string)($storefrontItem['inventory']['badge']['label'] ?? '');
        $product['inventory_badge_tone'] = (string)($storefrontItem['inventory']['badge']['tone'] ?? 'muted');
        $product['is_wishlisted'] = !empty($storefrontItem['is_wishlisted']);
        $product['is_compared'] = !empty($storefrontItem['is_compared']);

        $resolved[] = $product;
    }

    return $resolved;
}

/**
 * GET /ecommerce/shop  — product grid
 * Delegates to CMS universal entity list (capability-driven) when available.
 */
function ecPublicShop(): void
{
    $search     = trim((string)(ecInput()['search'] ?? ''));
    $categoryId = (int)(ecInput()['cat'] ?? 0);
    $storeFilter = function_exists('ecPublicStorefrontRequestedStoreFilter') ? ecPublicStorefrontRequestedStoreFilter() : 0;

    $attributeFilters = function_exists('ecProductAttributeFiltersFromInput') ? ecProductAttributeFiltersFromInput(ecInput()) : [];
    $perPage    = (int)ecSettings('products_per_page');
    $routeContext = [
        'public_render_origin' => 'ecommerce',
        'public_route_kind' => 'shop_index',
        'store_id' => $storeFilter > 0 ? $storeFilter : null,
    ];

    ecWithPublicThemeRouteContext($routeContext, static function () use ($search, $categoryId, $storeFilter, $attributeFilters, $perPage, $routeContext): void {
        $presentationMode = ecResolvePublicPresentationMode('shop_index', $routeContext);
        $activeStore = $storeFilter > 0 ? ecStoreById($storeFilter) : null;
        $canonicalAllItemsUrl = ecPublicStorefrontPageUrl('/ecommerce/shop', 1, [
            'store' => $storeFilter ?: null,
        ]);
        $canonicalSearchActionUrl = $canonicalAllItemsUrl;

        $availableCategories = ecPublicStorefrontCategories($categoryId, [
            'store' => $storeFilter ?: null,
        ]);
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
            'attribute_filters' => $attributeFilters,
            'all_items_url' => $canonicalAllItemsUrl,
            'search_action_url' => $canonicalSearchActionUrl,
            'store_id' => $storeFilter ?: null,
            'public_render_origin' => 'ecommerce',
            'public_route_kind' => 'shop_index',
            'public_presentation_mode' => $presentationMode,
        ], $routeContext)) {
            return;
        }

        $page = max(1, (int)(ecInput()['page'] ?? 1));
        $offset = ($page - 1) * $perPage;
        $attributeFacets = function_exists('ecProductAttributeFacetSummary')
            ? ecProductAttributeFacetSummary([
                'search' => $search,
                'category_id' => $categoryId ?: null,
                'status' => 'published',
                'attribute_filters' => $attributeFilters,
            ])
            : [];
        $productResult = ecProductList([
            'search' => $search,
            'category_id' => $categoryId ?: null,
            'attribute_filters' => $attributeFilters,
            'store_id' => $storeFilter ?: null,
            'store_owned_only' => $storeFilter > 0,
            'status' => 'published',
            'limit' => $perPage,
            'offset' => $offset,
        ]);
        $products = ecPublicDecorateCatalogProducts($productResult['items']);
        $totalPages = $perPage > 0 ? max(1, (int)ceil($productResult['total'] / $perPage)) : 1;
        $cartCount = (int)(ecCartGet()['totals']['item_count'] ?? 0);

        // Phase 2 multi-store: load active stores for sidebar facet.
        $activeStores = (function_exists('ecIsMultiStoreEnabled') ? ecIsMultiStoreEnabled() : ecStoreIsMultiStoreActive())
            ? (ecStoreList(['active_only' => true])['items'] ?? [])
            : [];
        $paginationFirstUrl = ecPublicStorefrontPageUrl('/ecommerce/shop', 1, [
            'search' => $search,
            'cat' => $categoryId,
            'store' => $storeFilter ?: null,
            'attr' => $attributeFilters,
        ]);
        $paginationPrevUrl = $page > 1
            ? ecPublicStorefrontPageUrl('/ecommerce/shop', $page - 1, [
                'search' => $search,
                'cat' => $categoryId,
                'store' => $storeFilter ?: null,
                'attr' => $attributeFilters,
            ])
            : '';
        $paginationNextUrl = $page < $totalPages
            ? ecPublicStorefrontPageUrl('/ecommerce/shop', $page + 1, [
                'search' => $search,
                'cat' => $categoryId,
                'store' => $storeFilter ?: null,
                'attr' => $attributeFilters,
            ])
            : '';
        $storefront = ecBuildStorefrontCatalogContext($products, [
            'route_kind' => 'shop_index',
            'presentation_mode' => $presentationMode,
            'page_title' => trim((string)ecSettings('shop_page_title')) ?: 'Shop',
            'current_category' => $currentCategory ?? [],
            'categories' => $availableCategories,
            'search' => $search,
            'category_id' => $categoryId,
            'attribute_filters' => $attributeFilters,
            'attribute_facets' => $attributeFacets,
            'search_action_url' => '/ecommerce/shop',
            'all_items_url' => '/ecommerce/shop',
            'base_list_url' => '/ecommerce/shop',
            'item_base_url' => '/ecommerce/shop',
            'total' => (int)$productResult['total'],
            'cart_count' => $cartCount,
            'wishlist_count' => function_exists('ecWishlistCount') ? ecWishlistCount() : 0,
            'compare_count' => function_exists('ecCompareCount') ? ecCompareCount() : 0,
            'pagination' => [
                'current' => $page,
                'total' => $totalPages,
                'first_url' => $paginationFirstUrl,
                'prev_url' => $paginationPrevUrl,
                'next_url' => $paginationNextUrl,
            ],
        ]);

        ecRender('modules/ecommerce/public/shop.disyl', array_merge([
            'page_title' => trim((string)ecSettings('shop_page_title')) ?: 'Shop',
            'products' => $products,
            'total' => $productResult['total'],
            'categories' => $availableCategories,
            'available_categories' => $availableCategories,
            'current_cat' => $currentCategory,
            'search' => $search,
            'category_id' => $categoryId,
            'attribute_filters' => $attributeFilters,
            'attribute_facets' => $attributeFacets,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
            'all_items_url' => '/ecommerce/shop',
            'search_action_url' => '/ecommerce/shop',
            'visible_count' => count($products),
            'catalog_category_count' => count($availableCategories),
            'pagination_first_url' => $paginationFirstUrl,
            'pagination_prev_url' => $paginationPrevUrl,
            'pagination_next_url' => $paginationNextUrl,
            'cart_count' => $cartCount,
            'wishlist_count' => function_exists('ecWishlistCount') ? ecWishlistCount() : 0,
            'compare_count' => function_exists('ecCompareCount') ? ecCompareCount() : 0,
            'current_request_uri' => (string)($_SERVER['REQUEST_URI'] ?? '/ecommerce/shop'),
            'storefront' => $storefront,
            'public_route_kind' => 'shop_index',
            'public_presentation_mode' => $presentationMode,
            'active_stores' => $activeStores,
            'store_filter' => $storeFilter,
            'active_store' => $activeStore,
        ], function_exists('ecStorefrontRenderContext') ? ecStorefrontRenderContext($activeStore) : []));
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
    $storeFilter = function_exists('ecPublicStorefrontRequestedStoreFilter') ? ecPublicStorefrontRequestedStoreFilter() : 0;
    $attributeFilters = function_exists('ecProductAttributeFiltersFromInput') ? ecProductAttributeFiltersFromInput(ecInput()) : [];
    if (!$slug) {
        header('Location: /ecommerce/shop');
        exit;
    }

    $routeContext = [
        'public_render_origin' => 'ecommerce',
        'public_route_kind' => 'shop_category',
        'store_id' => $storeFilter > 0 ? $storeFilter : null,
    ];

    ecWithPublicThemeRouteContext($routeContext, static function () use ($slug, $attributeFilters, $routeContext, $storeFilter): void {
        $presentationMode = ecResolvePublicPresentationMode('shop_category', $routeContext);
        $search = trim((string)(ecInput()['search'] ?? ''));
        $perPage = (int)ecSettings('products_per_page');
        $activeStore = $storeFilter > 0 ? ecStoreById($storeFilter) : null;
        $canonicalAllItemsUrl = ecPublicStorefrontPageUrl('/ecommerce/shop', 1, [
            'store' => $storeFilter ?: null,
        ]);
        $canonicalSearchActionUrl = ecPublicStorefrontPageUrl('/ecommerce/shop/category/' . rawurlencode($slug), 1, [
            'store' => $storeFilter ?: null,
        ]);
        $availableCategories = ecPublicStorefrontCategories(0, [
            'store' => $storeFilter ?: null,
        ]);

        if (ecDispatchCanonicalEntityRoute('cms:cmsPublicEntityList', [
                'type' => 'product',
                'category_slug' => $slug,
                'search' => $search,
                'per_page' => $perPage,
                'base_list_url' => '/ecommerce/shop/category/' . rawurlencode($slug),
                'item_base_url' => '/ecommerce/shop',
                'all_items_url' => $canonicalAllItemsUrl,
                'search_action_url' => $canonicalSearchActionUrl,
                'available_categories' => $availableCategories,
                'attribute_filters' => $attributeFilters,
                'store_id' => $storeFilter ?: null,
                'public_render_origin' => 'ecommerce',
                'public_route_kind' => 'shop_category',
                'public_presentation_mode' => $presentationMode,
            ], $routeContext)) {
            return;
        }

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
        $availableCategories = ecPublicStorefrontCategories((int)$cat['id'], [
            'store' => $storeFilter ?: null,
        ]);
        $attributeFacets = function_exists('ecProductAttributeFacetSummary')
            ? ecProductAttributeFacetSummary([
                'category_id' => (int)$cat['id'],
                'search' => $search,
                'status' => 'published',
                'attribute_filters' => $attributeFilters,
            ])
            : [];

        $productResult = ecProductList([
            'category_id' => (int)$cat['id'],
            'search'      => $search,
            'attribute_filters' => $attributeFilters,
            'store_id' => $storeFilter ?: null,
            'store_owned_only' => $storeFilter > 0,
            'status'      => 'published',
            'limit'       => $perPage,
            'offset'      => $offset,
        ]);
        $products = ecPublicDecorateCatalogProducts($productResult['items']);

        $totalPages = $perPage > 0 ? (int)ceil($productResult['total'] / $perPage) : 1;
        $cartCount = (int)(ecCartGet()['totals']['item_count'] ?? 0);
        $activeStores = (function_exists('ecIsMultiStoreEnabled') ? ecIsMultiStoreEnabled() : ecStoreIsMultiStoreActive())
            ? (ecStoreList(['active_only' => true])['items'] ?? [])
            : [];
        $paginationFirstUrl = ecPublicStorefrontPageUrl('/ecommerce/shop/category/' . rawurlencode($slug), 1, [
            'search' => $search,
            'store' => $storeFilter ?: null,
            'attr' => $attributeFilters,
        ]);
        $paginationPrevUrl = $page > 1
            ? ecPublicStorefrontPageUrl('/ecommerce/shop/category/' . rawurlencode($slug), $page - 1, [
                'search' => $search,
                'store' => $storeFilter ?: null,
                'attr' => $attributeFilters,
            ])
            : '';
        $paginationNextUrl = $page < $totalPages
            ? ecPublicStorefrontPageUrl('/ecommerce/shop/category/' . rawurlencode($slug), $page + 1, [
                'search' => $search,
                'store' => $storeFilter ?: null,
                'attr' => $attributeFilters,
            ])
            : '';
        $storefront = ecBuildStorefrontCatalogContext($products, [
            'route_kind' => 'shop_category',
            'presentation_mode' => $presentationMode,
            'page_title' => (string)($cat['name'] ?? 'Shop'),
            'current_category' => $cat,
            'categories' => $availableCategories,
            'search' => $search,
            'category_id' => (int)($cat['id'] ?? 0),
            'category_slug' => $slug,
            'attribute_filters' => $attributeFilters,
            'attribute_facets' => $attributeFacets,
            'search_action_url' => '/ecommerce/shop/category/' . rawurlencode($slug),
            'all_items_url' => '/ecommerce/shop',
            'base_list_url' => '/ecommerce/shop/category/' . rawurlencode($slug),
            'item_base_url' => '/ecommerce/shop',
            'total' => (int)$productResult['total'],
            'cart_count' => $cartCount,
            'wishlist_count' => function_exists('ecWishlistCount') ? ecWishlistCount() : 0,
            'compare_count' => function_exists('ecCompareCount') ? ecCompareCount() : 0,
            'pagination' => [
                'current' => $page,
                'total' => $totalPages,
                'first_url' => $paginationFirstUrl,
                'prev_url' => $paginationPrevUrl,
                'next_url' => $paginationNextUrl,
            ],
        ]);

        ecRender('modules/ecommerce/public/shop.disyl', array_merge([
            'page_title'  => $cat['name'],
            'products'    => $products,
            'total'       => $productResult['total'],
            'categories'  => $availableCategories,
            'available_categories' => $availableCategories,
            'current_cat' => $cat,
            'search'      => $search,
            'category_id' => (int)$cat['id'],
            'attribute_filters' => $attributeFilters,
            'attribute_facets' => $attributeFacets,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
            'all_items_url' => '/ecommerce/shop',
            'search_action_url' => '/ecommerce/shop/category/' . rawurlencode($slug),
            'visible_count' => count($products),
            'catalog_category_count' => count($availableCategories),
            'pagination_first_url' => $paginationFirstUrl,
            'pagination_prev_url' => $paginationPrevUrl,
            'pagination_next_url' => $paginationNextUrl,
            'cart_count'  => $cartCount,
            'wishlist_count' => function_exists('ecWishlistCount') ? ecWishlistCount() : 0,
            'compare_count' => function_exists('ecCompareCount') ? ecCompareCount() : 0,
            'current_request_uri' => (string)($_SERVER['REQUEST_URI'] ?? ('/ecommerce/shop/category/' . rawurlencode($slug))),
            'storefront' => $storefront,
            'public_route_kind' => 'shop_category',
            'public_presentation_mode' => $presentationMode,
            'active_stores' => $activeStores,
            'store_filter' => $storeFilter,
            'active_store' => $activeStore,
        ], function_exists('ecStorefrontRenderContext') ? ecStorefrontRenderContext($activeStore) : []));
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

        // Delegate to canonical entity-view before loading the product — the CMS
        // handler self-loads review_summary, relation_sections, and other ecommerce
        // extras so there is no need to preload them here.
        if (ecDispatchCanonicalEntityRoute('cms:cmsPublicEntityView', [
                'type' => 'product',
                'slug' => $slug,
                'disable_cache' => true,
                'public_render_origin' => 'ecommerce',
                'public_route_kind' => 'product_detail',
                'template_context' => [
                    'current_request_uri' => (string)($_SERVER['REQUEST_URI'] ?? ('/ecommerce/shop/' . rawurlencode($slug))),
                ],
            ], $routeContext)) {
            return;
        }

        $product = ecProductGetBySlug($slug);

        if (!$product || $product['status'] !== 'published') {
            http_response_code(404);
            ecRender('pages/404.disyl', ['page_title' => 'Product Not Found']);
            return;
        }

        if (function_exists('ecRecentlyViewedRememberProduct')) {
            ecRecentlyViewedRememberProduct((int)($product['id'] ?? 0));
        }

        $cartCount = (int)(ecCartGet()['totals']['item_count'] ?? 0);
        $reviewsEnabled = function_exists('ecReviewsEnabled') ? ecReviewsEnabled() : true;
        $reviewSummary = ($reviewsEnabled && function_exists('ecReviewSummary')) ? ecReviewSummary((int)($product['id'] ?? 0)) : ecReviewDefaultSummary();
        $reviews = ($reviewsEnabled && function_exists('ecReviewList'))
            ? (ecReviewList([
                'product_id' => (int)($product['id'] ?? 0),
                'status' => 'approved',
                'limit' => 10,
                'offset' => 0,
            ])['items'] ?? [])
            : [];
        $relationSections = function_exists('ecProductRecommendationSectionsForProduct')
            ? ecProductRecommendationSectionsForProduct((int)($product['id'] ?? 0))
            : [];
        $recentlyViewedItems = function_exists('ecRecentlyViewedCatalogItems')
            ? ecRecentlyViewedCatalogItems((int)($product['id'] ?? 0), 4, ['item_base_url' => '/ecommerce/shop'])
            : [];
        $wishlistCount = function_exists('ecWishlistCount') ? ecWishlistCount() : 0;
        $wishlistSelected = function_exists('ecWishlistContains') ? ecWishlistContains((int)($product['id'] ?? 0)) : false;
        $compareCount = function_exists('ecCompareCount') ? ecCompareCount() : 0;
        $compareSelected = function_exists('ecCompareContains') ? ecCompareContains((int)($product['id'] ?? 0)) : false;
        $seoContent = function_exists('ecProductSeoContent') ? ecProductSeoContent($product) : [];
        $headCode = function_exists('cmsGetPublicHeadHtml') ? cmsGetPublicHeadHtml($seoContent) : '';
        $seoPageTitle = function_exists('cmsResolveSeoTitle') ? cmsResolveSeoTitle($seoContent) : (string)($product['seo_title'] ?? $product['title'] ?? '');

        $storefront = ecBuildStorefrontDetailContext($product, [
            'route_kind' => 'product_detail',
            'presentation_mode' => $presentationMode,
            'page_title' => $seoPageTitle,
            'shop_url' => '/ecommerce/shop',
            'all_items_url' => '/ecommerce/shop',
            'item_base_url' => '/ecommerce/shop',
            'cart_count' => $cartCount,
        ]);
        $product['pricing'] = (array)($storefront['product']['pricing'] ?? $product['pricing'] ?? []);
        $product['bundle_summary'] = (array)($storefront['product']['bundle_summary'] ?? $product['bundle_summary'] ?? []);
        $product['subscription_summary'] = (array)($storefront['product']['subscription_summary'] ?? $product['subscription_summary'] ?? []);
        $productStoreId = max(0, (int)($product['store_id'] ?? 0));
        $productStore = $productStoreId > 0 && function_exists('ecStoreById') ? ecStoreById($productStoreId) : null;

        ecRender('modules/ecommerce/public/product.disyl', array_merge([
            'page_title'  => $seoPageTitle,
            'product'     => $product,
            'reviews_enabled' => $reviewsEnabled,
            'review_summary' => $reviewSummary,
            'reviews' => $reviews,
            'relation_sections' => $relationSections,
            'recently_viewed_items' => $recentlyViewedItems,
            'recently_viewed_display_variant' => 'horizontal',
            'cart_count'  => $cartCount,
            'wishlist_count' => $wishlistCount,
            'wishlist_product_is_selected' => $wishlistSelected,
            'compare_count' => $compareCount,
            'compare_product_is_selected' => $compareSelected,
            'current_request_uri' => (string)($_SERVER['REQUEST_URI'] ?? ('/ecommerce/shop/' . rawurlencode($slug))),
            'head_code' => $headCode,
            'storefront' => $storefront,
            'public_route_kind' => 'product_detail',
            'public_presentation_mode' => $presentationMode,
        ], function_exists('ecStorefrontRenderContext') ? ecStorefrontRenderContext($productStore) : []));
    });
}
