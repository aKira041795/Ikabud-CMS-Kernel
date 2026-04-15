<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — API: Products (handlers/80-api-products.php)
// ─────────────────────────────────────────────────────────────────────────

function ecCatalogSearchEnsureCmsHandlersLoaded(): void
{
    if (function_exists('cmsPublicCanonicalRenderEntityList')) {
        return;
    }

    $handlersFile = BASE_PATH . '/modules/cms/handlers.php';
    if (!is_file($handlersFile)) {
        return;
    }

    moduleWithContext('cms', static function () use ($handlersFile): void {
        require_once $handlersFile;
    });
}

function ecCatalogSearchResolveCategory(array $input): array
{
    $categoryId = (int)($input['category_id'] ?? $input['cat'] ?? 0);
    $categorySlug = trim((string)($input['category_slug'] ?? ''));

    if ($categorySlug !== '' && $categoryId <= 0) {
        try {
            $row = ecDb()->query(
                'SELECT id, name, slug FROM cms_categories WHERE slug = ? LIMIT 1',
                [$categorySlug]
            )->fetch(\PDO::FETCH_ASSOC) ?: null;
            if (is_array($row)) {
                $categoryId = (int)($row['id'] ?? 0);
            }
        } catch (\Throwable $e) {
        }
    }

    $availableCategories = ecPublicStorefrontCategories($categoryId);
    $activeCategory = null;
    foreach ($availableCategories as $category) {
        $isActive = (int)($category['id'] ?? 0) === $categoryId;
        if (!$isActive && $categorySlug !== '') {
            $isActive = trim((string)($category['slug'] ?? '')) === $categorySlug;
        }
        if (!$isActive) {
            continue;
        }

        $activeCategory = [
            'id' => (int)($category['id'] ?? 0),
            'slug' => trim((string)($category['slug'] ?? '')),
            'name' => trim((string)($category['name'] ?? '')),
        ];
        break;
    }

    if ($activeCategory !== null) {
        $categoryId = (int)($activeCategory['id'] ?? 0);
        if ($categorySlug === '') {
            $categorySlug = trim((string)($activeCategory['slug'] ?? ''));
        }
    }

    return [
        'category_id' => $categoryId,
        'category_slug' => $categorySlug,
        'available_categories' => $availableCategories,
        'active_category' => $activeCategory,
    ];
}

function ecCatalogSearchExtractSectionHtml(string $html): string
{
    if (preg_match('/<section\b(?=[^>]*data-storefront-page-kind=("|\')catalog\1)[^>]*>.*?<\/section>/is', $html, $matches) === 1) {
        return (string)$matches[0];
    }

    return $html;
}

function ecBuildCatalogSearchPayload(array $input = []): array
{
    ecCatalogSearchEnsureCmsHandlersLoaded();

    $search = trim((string)($input['search'] ?? ''));
    $attributeFilters = function_exists('ecProductAttributeFiltersFromInput')
        ? ecProductAttributeFiltersFromInput($input)
        : [];
    $page = max(1, (int)($input['page'] ?? 1));
    $storeId = (int)($input['store_id'] ?? 0);
    $apiStore = $storeId > 0 && function_exists('ecStoreById') ? ecStoreById($storeId) : null;
    $perPage = min(60, max(1, (int)($input['limit'] ?? ecStoreAwareSetting('products_per_page', $apiStore, 12))));
    $offset = ($page - 1) * $perPage;
    $routeKind = trim((string)($input['route_kind'] ?? 'shop_index')) ?: 'shop_index';
    $presentationMode = trim((string)($input['presentation_mode'] ?? 'traditional')) ?: 'traditional';
    $baseListUrl = trim((string)($input['base_list_url'] ?? '/ecommerce/shop')) ?: '/ecommerce/shop';
    $searchActionUrl = trim((string)($input['search_action_url'] ?? $baseListUrl)) ?: $baseListUrl;
    $allItemsUrl = trim((string)($input['all_items_url'] ?? '/ecommerce/shop')) ?: '/ecommerce/shop';
    $itemBaseUrl = trim((string)($input['item_base_url'] ?? '/ecommerce/shop')) ?: '/ecommerce/shop';
    $requestedListTitle = trim((string)($input['list_title'] ?? (string)ecStoreAwareSetting('shop_page_title', $apiStore, 'Shop')));
    if ($requestedListTitle === '') {
        $requestedListTitle = 'Shop';
    }

    $category = ecCatalogSearchResolveCategory($input);
    $categoryId = (int)($category['category_id'] ?? 0);
    $categorySlug = trim((string)($category['category_slug'] ?? ''));
    $availableCategories = is_array($category['available_categories'] ?? null)
        ? $category['available_categories']
        : [];
    $activeCategory = is_array($category['active_category'] ?? null)
        ? $category['active_category']
        : null;

    $attributeFacets = function_exists('ecProductAttributeFacetSummary')
        ? ecProductAttributeFacetSummary([
            'search' => $search,
            'category_id' => $categoryId > 0 ? $categoryId : null,
            'status' => 'published',
            'attribute_filters' => $attributeFilters,
        ])
        : [];

    $productResult = ecProductList([
        'search' => $search,
        'category_id' => $categoryId > 0 ? $categoryId : null,
        'attribute_filters' => $attributeFilters,
        'status' => 'published',
        'limit' => $perPage,
        'offset' => $offset,
    ]);
    $items = ecPublicDecorateCatalogProducts((array)($productResult['items'] ?? []));
    $total = (int)($productResult['total'] ?? 0);
    $totalPages = max(1, (int)ceil($total / $perPage));
    $resultLabel = function_exists('cmsEntityListResultLabel')
        ? cmsEntityListResultLabel($total)
        : ($total === 1 ? '1 result' : ($total . ' results'));

    $activeFilterCount = 0;
    if ($search !== '') {
        $activeFilterCount++;
    }
    if (is_array($activeCategory)) {
        $activeFilterCount++;
    }
    if (function_exists('ecProductAttributeSelectedValueCount')) {
        $activeFilterCount += ecProductAttributeSelectedValueCount($attributeFilters);
    }

    $listTitle = $requestedListTitle;
    if ($listTitle === '' && is_array($activeCategory)) {
        $listTitle = trim((string)($activeCategory['name'] ?? '')) ?: 'Shop';
    }
    if ($listTitle === '') {
        $listTitle = 'Shop';
    }

    if ($search !== '' && is_array($activeCategory)) {
        $listDescription = $resultLabel . ' in ' . (string)($activeCategory['name'] ?? $listTitle) . ' for "' . $search . '"';
    } elseif ($search !== '') {
        $listDescription = $resultLabel . ' for "' . $search . '"';
    } elseif (is_array($activeCategory)) {
        $listDescription = $resultLabel . ' in ' . (string)($activeCategory['name'] ?? $listTitle);
    } else {
        $listDescription = $resultLabel . ' in ' . $listTitle;
    }

    $paginationQuery = [];
    if ($search !== '') {
        $paginationQuery['search'] = $search;
    }
    if ($categoryId > 0 && $categorySlug === '') {
        $paginationQuery['cat'] = $categoryId;
    }
    if ($attributeFilters !== []) {
        $paginationQuery['attr'] = $attributeFilters;
    }

    $pagination = [
        'current' => $page,
        'total' => $totalPages,
        'first_url' => ecPublicStorefrontPageUrl($baseListUrl, 1, $paginationQuery),
        'prev_url' => $page > 1 ? ecPublicStorefrontPageUrl($baseListUrl, $page - 1, $paginationQuery) : '',
        'next_url' => $page < $totalPages ? ecPublicStorefrontPageUrl($baseListUrl, $page + 1, $paginationQuery) : '',
    ];

    $listContext = [
        'content_type' => 'product',
        'base_list_url' => $baseListUrl,
        'item_base_url' => $itemBaseUrl,
        'search' => $search,
        'category_id' => $categoryId,
        'category_slug' => $categorySlug !== '' ? $categorySlug : (string)($activeCategory['slug'] ?? ''),
        'category_name' => is_array($activeCategory) ? (string)($activeCategory['name'] ?? '') : '',
        'attribute_filters' => $attributeFilters,
        'attribute_facets' => $attributeFacets,
        'result_count' => $total,
        'result_label' => $resultLabel,
        'active_filter_count' => $activeFilterCount,
        'summary_text' => $listDescription,
        'available_categories' => $availableCategories,
        'all_items_url' => $allItemsUrl,
        'search_action_url' => $searchActionUrl,
        'search_placeholder' => 'Search products',
        'search_button_label' => 'Search',
        'category_navigation_label' => 'Shop Categories',
        'all_items_label' => 'All Products',
        'category_submit_label' => 'Browse',
        'empty_title' => 'No items found.',
        'empty_link_label' => 'Browse all products',
    ];

    $storefront = function_exists('ecBuildStorefrontCatalogContext')
        ? ecBuildStorefrontCatalogContext($items, [
            'route_kind' => $routeKind,
            'presentation_mode' => $presentationMode,
            'page_title' => $listTitle,
            'page_description' => $listDescription,
            'base_list_url' => $baseListUrl,
            'item_base_url' => $itemBaseUrl,
            'search' => $search,
            'search_action_url' => $searchActionUrl,
            'all_items_url' => $allItemsUrl,
            'category_id' => $categoryId,
            'category_slug' => $categorySlug,
            'current_category' => is_array($activeCategory) ? $activeCategory : [],
            'categories' => $availableCategories,
            'attribute_filters' => $attributeFilters,
            'attribute_facets' => $attributeFacets,
            'pagination' => $pagination,
            'total' => $total,
            'cart_count' => (int)(ecCartGet()['totals']['item_count'] ?? 0),
        ])
        : [];

    $html = '';
    if (function_exists('cmsPublicCanonicalRenderEntityList')) {
        $html = moduleWithContext('cms', static function () use ($items, $listTitle, $listDescription, $pagination, $listContext, $storefront, $routeKind, $presentationMode): string {
            return cmsPublicCanonicalRenderEntityList($items, [
                'default_type' => 'product',
                'page_title' => $listTitle,
                'list_title' => $listTitle,
                'list_description' => $listDescription,
                'pagination' => $pagination,
                'entity_list_context' => $listContext,
                'template_context' => ['storefront' => $storefront],
                'cart_count' => (int)(ecCartGet()['totals']['item_count'] ?? 0),
                'public_render_origin' => 'ecommerce',
                'public_route_kind' => $routeKind,
                'public_presentation_mode' => $presentationMode,
            ]);
        });
    }

    $sectionHtml = $html !== '' ? ecCatalogSearchExtractSectionHtml($html) : '';

    return [
        'html' => $sectionHtml,
        'total' => $total,
        'page' => $page,
        'total_pages' => $totalPages,
        'result_label' => $resultLabel,
        'summary_text' => $listDescription,
        'active_filter_count' => $activeFilterCount,
        'pagination' => $pagination,
        'route_kind' => $routeKind,
        'presentation_mode' => $presentationMode,
    ];
}

function ecApiProductsList(): void
{
    $input  = ecInput();

    // Accept ?cats[]=1&cats[]=2 (multi-category) or legacy ?cat=1 (single).
    $categoryIds = [];
    if (!empty($input['cats'])) {
        $categoryIds = array_values(array_unique(array_map('intval', (array)$input['cats'])));
    } elseif (isset($input['cat'])) {
        $categoryIds = [(int)$input['cat']];
    }

    $result = ecProductList([
        'search'       => $input['search']  ?? '',
        'category_ids' => $categoryIds,
        'attribute_filters' => function_exists('ecProductAttributeFiltersFromInput') ? ecProductAttributeFiltersFromInput($input) : [],
        'status'       => $input['status']  ?? 'published',
        'order_by'     => $input['orderBy'] ?? ($input['order_by'] ?? 'created_at'),
        'order'        => $input['order'] ?? 'desc',
        'limit'        => min(50, (int)($input['limit']  ?? 12)),
        'offset'       => max(0,  (int)($input['offset'] ?? 0)),
    ]);

    ecJsonOk($result);
}

function ecApiCatalogSearch(): void
{
    ecJsonOk(ecBuildCatalogSearchPayload(ecInput()));
}

function ecApiCategoryList(): void
{
    try {
        $db = ecDb();
        $rows = $db->query(ecCmsCategorySelectSql('id, name, slug'), [])->fetchAll(\PDO::FETCH_ASSOC);
        ecJsonOk(['categories' => is_array($rows) ? $rows : []]);
    } catch (\Throwable $e) {
        write_log('ecApiCategoryList error: ' . $e->getMessage(), 'error', ['module' => 'ecommerce']);
        ecJsonOk(['categories' => []]);
    }
}

function ecApiProductGet(array $params = []): void
{
    $id      = (int)($params['id'] ?? 0);
    $product = ecProductGet($id);

    if (!$product) {
        ecJsonError('Product not found', 404);
    }
    ecJsonOk(['product' => $product]);
}

function ecApiProductCreate(): void
{
    $user = ecRequireAdmin();
    $data = ecInput();

    try {
        $id = ecProductCreate($data, (int)$user['id']);
        ecJsonOk(['product_id' => $id], 201);
    } catch (\Throwable $e) {
        write_log('ecApiProductCreate: ' . $e->getMessage(), 'error', ['module' => 'ecommerce']);
        ecJsonError('Create failed: ' . $e->getMessage(), 422);
    }
}

function ecApiProductUpdate(array $params = []): void
{
    ecRequireAdmin();
    $id   = (int)($params['id'] ?? 0);
    $data = ecInput();

    if (!ecProductGet($id)) {
        ecJsonError('Product not found', 404);
    }

    try {
        ecProductUpdate($id, $data);
        ecJsonOk(['product' => ecProductGet($id)]);
    } catch (\Throwable $e) {
        ecJsonError('Update failed: ' . $e->getMessage(), 422);
    }
}

function ecApiProductDelete(array $params = []): void
{
    ecRequireAdmin();
    $id = (int)($params['id'] ?? 0);

    if (!ecProductGet($id)) {
        ecJsonError('Product not found', 404);
    }

    ecProductDelete($id);
    ecJsonOk(['deleted' => true]);
}
