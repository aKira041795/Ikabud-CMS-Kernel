<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Storefront rendering helpers (helpers/36-storefront.php)
//
// Extracted from 30-products.php. Contains:
//   - Image/gallery resolution  (ecProductResolveFeaturedImageUrl, ecProductPrimaryImageUrl, etc.)
//   - Storefront normalizers     (ecStorefrontNormalizePricing, ecStorefrontNormalizeInventory, etc.)
//   - Catalog/detail contexts    (ecBuildStorefrontCatalogContext, ecBuildStorefrontDetailContext)
//   - Hydration helpers          (ecStorefrontHydrateProduct, ecBuildStorefrontCatalogItem)
// ─────────────────────────────────────────────────────────────────────────

function ecProductResolveFeaturedImageUrl(string $relativePath): string
{
    $relativePath = trim($relativePath);
    if ($relativePath === '' || !function_exists('cmsResolveUploadUrl')) {
        return '';
    }

    return cmsResolveUploadUrl($relativePath);
}

function ecProductResolveMediaUrl(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }

    if (preg_match('#^(https?:)?//#i', $path) === 1 || str_starts_with($path, '/')) {
        return $path;
    }

    if (preg_match('#^t\d+/#', $path) === 1 && function_exists('cmsLegacyUploadsUrl')) {
        return cmsLegacyUploadsUrl($path);
    }

    if (function_exists('cmsResolveUploadUrl')) {
        return cmsResolveUploadUrl($path);
    }

    return $path;
}

function ecProductNormalizeGalleryImages(array $items): array
{
    $normalized = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $url = ecProductResolveMediaUrl((string)($item['url'] ?? ''));
        $src = ecProductResolveMediaUrl((string)($item['src'] ?? ''));
        $thumb = ecProductResolveMediaUrl((string)($item['thumb'] ?? ''));
        $fullUrl = $url !== '' ? $url : $src;
        $thumbUrl = $thumb !== '' ? $thumb : $fullUrl;

        if ($fullUrl === '') {
            continue;
        }

        $normalized[] = [
            'url' => $fullUrl,
            'thumb' => $thumbUrl,
            'caption' => trim((string)($item['caption'] ?? '')),
        ];
    }

    return $normalized;
}

function ecProductGalleryImages(int $productId): array
{
    if ($productId <= 0) {
        return [];
    }

    return ecProductGalleryImagesForProducts([$productId])[$productId] ?? [];
}

function ecProductGalleryImagesForProducts(array $productIds): array
{
    $productIds = array_values(array_filter(array_map(static fn($id): int => (int)$id, $productIds), static fn(int $id): bool => $id > 0));
    if ($productIds === []) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($productIds), '?'));

    try {
        $rows = ecDb()->query(
            "SELECT content_id, meta_value FROM cms_content_meta WHERE meta_key = '_gallery' AND content_id IN ($placeholders)",
            $productIds
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }

    $map = [];
    foreach ($rows as $row) {
        $productId = (int)($row['content_id'] ?? 0);
        if ($productId <= 0) {
            continue;
        }

        $decoded = json_decode((string)($row['meta_value'] ?? '[]'), true);
        $map[$productId] = ecProductNormalizeGalleryImages(is_array($decoded) ? $decoded : []);
    }

    return $map;
}

function ecProductPrimaryImageUrl(string $featuredImageUrl, array $galleryImages): string
{
    $featuredImageUrl = trim($featuredImageUrl);
    if ($featuredImageUrl !== '') {
        return $featuredImageUrl;
    }

    foreach ($galleryImages as $image) {
        if (!is_array($image)) {
            continue;
        }

        $candidate = trim((string)($image['thumb'] ?? $image['url'] ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return '';
}

function ecStorefrontCurrentCartCount(?int $cartCount = null): int
{
    if ($cartCount !== null) {
        return max(0, $cartCount);
    }

    return max(0, (int)(ecCartGet()['totals']['item_count'] ?? 0));
}

function ecStorefrontNormalizePricing(array $pricing): array
{
    return ecCurrencyPresentPricing($pricing);
}

function ecStorefrontNormalizeBundleSummary(array $summary, ?string $currencyCode = null): array
{
    $currencyCode = ecCurrencyNormalizeCode($currencyCode ?? ecCurrentCurrencyCode()) ?: ecStoreBaseCurrencyCode();
    $symbol = ecCurrencySymbolFor($currencyCode);
    $childSubtotal = ecCurrencyConvertAmount((float)($summary['child_subtotal'] ?? 0), ecStoreBaseCurrencyCode(), $currencyCode);
    $bundleTotal = ecCurrencyConvertAmount((float)($summary['bundle_total'] ?? 0), ecStoreBaseCurrencyCode(), $currencyCode);
    $savings = ecCurrencyConvertAmount((float)($summary['savings'] ?? 0), ecStoreBaseCurrencyCode(), $currencyCode);

    $summary['child_subtotal'] = $childSubtotal;
    $summary['child_subtotal_fmt'] = ecCurrencyFormatAmount($childSubtotal, $currencyCode, $symbol);
    $summary['bundle_total'] = $bundleTotal;
    $summary['bundle_total_fmt'] = ecCurrencyFormatAmount($bundleTotal, $currencyCode, $symbol);
    $summary['savings'] = $savings;
    $summary['savings_fmt'] = ecCurrencyFormatAmount($savings, $currencyCode, $symbol);

    return $summary;
}

function ecStorefrontNormalizeInventory(array $inventory): array
{
    $trackStock = (bool)($inventory['track_stock'] ?? false);
    $stockQty = array_key_exists('stock_qty', $inventory) ? (int)$inventory['stock_qty'] : 0;
    $inStock = array_key_exists('in_stock', $inventory)
        ? (bool)$inventory['in_stock']
        : (!$trackStock || (($stockQty ?? 0) > 0));
    $outOfStock = array_key_exists('out_of_stock', $inventory)
        ? (bool)$inventory['out_of_stock']
        : ($trackStock && (($stockQty ?? 0) <= 0));
    $lowStock = array_key_exists('low_stock', $inventory)
        ? (bool)$inventory['low_stock']
        : ($trackStock && !$outOfStock && $stockQty !== null && $stockQty <= (int)ecSettings('low_stock_threshold'));

    return [
        'track_stock' => $trackStock,
        'stock_qty' => $stockQty,
        'sku' => trim((string)($inventory['sku'] ?? '')),
        'in_stock' => $inStock,
        'out_of_stock' => $outOfStock,
        'low_stock' => $lowStock,
    ];
}

function ecStorefrontSaleBadgeText(array $pricing): string
{
    $pricing = ecStorefrontNormalizePricing($pricing);
    $price = $pricing['price'];
    $salePrice = $pricing['sale_price'];
    if (!$pricing['on_sale'] || $price === null || $salePrice === null || $price <= 0) {
        return '';
    }

    $discountPercent = (int)round((($price - $salePrice) / $price) * 100);
    if ($discountPercent <= 0) {
        return '';
    }

    return $discountPercent . '% off';
}

function ecStorefrontInventoryBadge(array $inventory): array
{
    $inventory = ecStorefrontNormalizeInventory($inventory);
    if (!$inventory['track_stock']) {
        return ['label' => '', 'tone' => 'muted'];
    }

    if ($inventory['out_of_stock'] || !$inventory['in_stock']) {
        return ['label' => 'Sold out', 'tone' => 'danger'];
    }

    if ($inventory['low_stock']) {
        $remaining = max(0, (int)($inventory['stock_qty'] ?? 0));
        return [
            'label' => $remaining > 0 ? ($remaining . ' left') : 'Low stock',
            'tone' => 'warning',
        ];
    }

    return ['label' => 'In stock', 'tone' => 'success'];
}

function ecStorefrontNormalizeCategories(array $categories, int $activeCategoryId = 0, string $activeCategorySlug = ''): array
{
    $normalized = [];

    foreach ($categories as $category) {
        if (!is_array($category)) {
            continue;
        }

        $categoryId = (int)($category['id'] ?? 0);
        $categorySlug = trim((string)($category['slug'] ?? ''));
        $categoryName = trim((string)($category['name'] ?? ''));
        if ($categoryName === '' || ($categoryId <= 0 && $categorySlug === '')) {
            continue;
        }

        $url = trim((string)($category['url'] ?? ''));
        if ($url === '') {
            if ($categorySlug !== '') {
                $url = '/ecommerce/shop/category/' . rawurlencode($categorySlug);
            } elseif ($categoryId > 0) {
                $url = '/ecommerce/shop?cat=' . $categoryId;
            }
        }

        $normalized[] = [
            'id' => $categoryId,
            'slug' => $categorySlug,
            'name' => $categoryName,
            'url' => $url,
            'is_active' => (bool)($category['is_active'] ?? false)
                || ($activeCategoryId > 0 && $categoryId === $activeCategoryId)
                || ($activeCategorySlug !== '' && $categorySlug === $activeCategorySlug),
        ];
    }

    return $normalized;
}

function ecStorefrontFindActiveCategory(array $categories, array $currentCategory = [], int $categoryId = 0, string $categorySlug = ''): ?array
{
    $candidate = ecStorefrontNormalizeCategories([$currentCategory], $categoryId, $categorySlug);
    if ($candidate !== []) {
        return $candidate[0];
    }

    foreach ($categories as $category) {
        if (!is_array($category)) {
            continue;
        }

        if (!empty($category['is_active'])) {
            return $category;
        }

        if ($categoryId > 0 && (int)($category['id'] ?? 0) === $categoryId) {
            return $category;
        }

        if ($categorySlug !== '' && trim((string)($category['slug'] ?? '')) === $categorySlug) {
            return $category;
        }
    }

    return null;
}

function ecStorefrontHydrateProduct(array $product): array
{
    $productId = (int)($product['id'] ?? 0);
    $needsHydration = $productId > 0 && (
        !is_array($product['pricing'] ?? null)
        || !is_array($product['inventory'] ?? null)
        || !is_array($product['categories'] ?? null)
        || !is_array($product['gallery_images'] ?? null)
        || trim((string)($product['primary_image_url'] ?? '')) === ''
    );

    if ($needsHydration) {
        $hydrated = ecProductGet($productId);
        if (is_array($hydrated)) {
            $product = array_merge($hydrated, $product);
        }
    }

    if (!is_array($product['pricing'] ?? null) && is_array($product['capability_data']['pricing'] ?? null)) {
        $product['pricing'] = $product['capability_data']['pricing'];
    }
    if (!is_array($product['inventory'] ?? null) && is_array($product['capability_data']['inventory'] ?? null)) {
        $product['inventory'] = $product['capability_data']['inventory'];
    }
    if (!is_array($product['categories'] ?? null)) {
        $product['categories'] = [];
    }
    if (!is_array($product['gallery_images'] ?? null)) {
        $product['gallery_images'] = [];
    }
    if (!is_array($product['addons'] ?? null)) {
        $product['addons'] = [];
    }
    if (!is_array($product['booking'] ?? null)) {
        $product['booking'] = function_exists('ecProductBookingDefaults') ? ecProductBookingDefaults() : ['enabled' => false];
    }
    if (!is_array($product['required_membership_tiers'] ?? null)) {
        $product['required_membership_tiers'] = [];
    }
    if (!is_array($product['membership_summary'] ?? null)) {
        $product['membership_summary'] = function_exists('ecProductMembershipDefaults') ? ecProductMembershipDefaults()['membership_summary'] : [];
    }

    $featuredImageUrl = trim((string)($product['featured_image_url'] ?? ''));
    if ($featuredImageUrl === '' && !empty($product['featured_image'])) {
        $featuredImageUrl = ecProductResolveFeaturedImageUrl((string)$product['featured_image']);
    }
    $product['featured_image_url'] = $featuredImageUrl;
    if (trim((string)($product['primary_image_url'] ?? '')) === '') {
        $product['primary_image_url'] = ecProductPrimaryImageUrl($featuredImageUrl, is_array($product['gallery_images']) ? $product['gallery_images'] : []);
    }

    return $product;
}

function ecBuildStorefrontCatalogItem(array $product, array $options = []): array
{
    $product = ecStorefrontHydrateProduct($product);
    $productId = (int)($product['id'] ?? 0);
    $slug = trim((string)($product['slug'] ?? ''));
    $itemBaseUrl = rtrim((string)($options['item_base_url'] ?? '/ecommerce/shop'), '/');
    $detailUrl = trim((string)($product['detail_url'] ?? $product['url'] ?? ''));
    if ($detailUrl === '') {
        $detailUrl = $slug !== ''
            ? (($itemBaseUrl !== '' ? $itemBaseUrl : '/ecommerce/shop') . '/' . rawurlencode($slug))
            : '/ecommerce/shop';
    }

    // Apply per-store price / visibility overrides when a store context is active
    $storeCtx = function_exists('ecStoreResolveContext') ? ecStoreResolveContext() : null;
    if ($storeCtx !== null && function_exists('ecStoreApplyProductOverrides')) {
        $product = ecStoreApplyProductOverrides($product, $storeCtx);
        if ($product === null) {
            return ['id' => $productId, 'slug' => $slug, 'title' => '', 'url' => $detailUrl, 'is_visible' => false];
        }
    }
    $product['is_visible'] = true;

    // Apply segment / tier pricing when a logged-in customer has active segments
    $segmentUserId = function_exists('ecSegmentCurrentUserId') ? ecSegmentCurrentUserId() : 0;
    if ($segmentUserId > 0 && function_exists('ecCustomerActiveSegments') && function_exists('ecSegmentApplyProductPrice')) {
        $activeSegments = ecCustomerActiveSegments($segmentUserId);
        if (!empty($activeSegments)) {
            $product = ecSegmentApplyProductPrice($product, $activeSegments);
        }
    }

    $pricing = ecStorefrontNormalizePricing(is_array($product['pricing'] ?? null) ? $product['pricing'] : []);
    $inventory = ecStorefrontNormalizeInventory(is_array($product['inventory'] ?? null) ? $product['inventory'] : []);
    $inventoryBadge = ecStorefrontInventoryBadge($inventory);
    $bundleSummary = is_array($product['bundle_summary'] ?? null)
        ? $product['bundle_summary']
        : ecProductBundleSummary($product);
    $bundleSummary = ecStorefrontNormalizeBundleSummary($bundleSummary, (string)($pricing['currency'] ?? ecCurrentCurrencyCode()));
    $subscriptionSummary = is_array($product['subscription_summary'] ?? null)
        ? $product['subscription_summary']
        : ecProductSubscriptionDefaults()['subscription_summary'];
    if (!empty($product['is_subscription'])) {
        $subscriptionSummary = ecProductSubscriptionMetaFromMetaMap([
            '_is_subscription' => '1',
            '_subscription_interval_unit' => (string)($product['subscription_interval_unit'] ?? 'month'),
            '_subscription_interval_count' => (string)($product['subscription_interval_count'] ?? 1),
            '_subscription_trial_days' => (string)($product['subscription_trial_days'] ?? 0),
            '_subscription_max_cycles' => (string)($product['subscription_max_cycles'] ?? 0),
            '_subscription_grace_period_days' => (string)($product['subscription_grace_period_days'] ?? 7),
        ], $pricing)['subscription_summary'];
    }
    $saleBadgeText = trim((string)($product['sale_badge_text'] ?? ''));
    if ($saleBadgeText === '') {
        $saleBadgeText = ecStorefrontSaleBadgeText($pricing);
    }
    $membershipGate = function_exists('ecMembershipGateForProduct')
        ? ecMembershipGateForProduct($product)
        : ['allowed' => true, 'requires_membership' => false, 'login_required' => false, 'required_tiers' => [], 'active_tiers' => [], 'message' => ''];

    return [
        'id' => $productId,
        'slug' => $slug,
        'title' => trim((string)($product['title'] ?? '')),
        'excerpt' => trim((string)($product['excerpt'] ?? '')),
        'url' => $detailUrl,
        'primary_image_url' => trim((string)($product['primary_image_url'] ?? '')),
        'featured_image_url' => trim((string)($product['featured_image_url'] ?? '')),
        'pricing' => $pricing,
        'inventory' => array_merge($inventory, ['badge' => $inventoryBadge]),
        'badges' => [
            'sale' => $saleBadgeText,
            'inventory' => $inventoryBadge,
        ],
        'product_type' => trim((string)($product['product_type'] ?? 'physical')),
        'is_external_product' => !empty($product['is_external_product']),
        'external_product_url' => trim((string)($product['external_product_url'] ?? '')),
        'external_product_button_text' => trim((string)($product['external_product_button_text'] ?? '')),
        'is_membership_product' => !empty($product['is_membership_product']),
        'membership_tier' => trim((string)($product['membership_tier'] ?? '')),
        'membership_summary' => is_array($product['membership_summary'] ?? null) ? $product['membership_summary'] : [],
        'required_membership_tiers' => is_array($product['required_membership_tiers'] ?? null) ? array_values($product['required_membership_tiers']) : [],
        'membership_gate' => $membershipGate,
        'addons' => is_array($product['addons'] ?? null) ? array_values($product['addons']) : [],
        'booking' => is_array($product['booking'] ?? null) ? $product['booking'] : ['enabled' => false],
        'bundle_summary' => $bundleSummary,
        'subscription_summary' => $subscriptionSummary,
        'categories' => ecStorefrontNormalizeCategories(is_array($product['categories'] ?? null) ? $product['categories'] : []),
        'review_summary' => is_array($product['review_summary'] ?? null)
            ? ecReviewNormalizeSummary($product['review_summary'])
            : ecReviewDefaultSummary(),
        'is_wishlisted' => function_exists('ecWishlistContains') ? ecWishlistContains($productId) : false,
        'is_compared' => function_exists('ecCompareContains') ? ecCompareContains($productId) : false,
        'variant_media_map' => (function_exists('ecVariantMediaStorageAvailable') && ecVariantMediaStorageAvailable() && function_exists('ecVariantMediaForProduct'))
            ? ecVariantMediaForProduct($productId)
            : [],
    ];
}

function ecBuildStorefrontCatalogContext(array $products, array $options = []): array
{
    $routeKind = trim((string)($options['route_kind'] ?? 'shop_index'));
    if ($routeKind === '') {
        $routeKind = 'shop_index';
    }

    $presentationMode = trim((string)($options['presentation_mode'] ?? 'traditional'));
    if ($presentationMode === '') {
        $presentationMode = 'traditional';
    }

    $categoryId = (int)($options['category_id'] ?? 0);
    $categorySlug = trim((string)($options['category_slug'] ?? ''));
    $categories = ecStorefrontNormalizeCategories(
        is_array($options['categories'] ?? null)
            ? $options['categories']
            : (is_array($options['available_categories'] ?? null) ? $options['available_categories'] : []),
        $categoryId,
        $categorySlug
    );
    $activeCategory = ecStorefrontFindActiveCategory(
        $categories,
        is_array($options['current_category'] ?? null) ? $options['current_category'] : [],
        $categoryId,
        $categorySlug
    );

    if ($activeCategory !== null) {
        if ($categoryId <= 0) {
            $categoryId = (int)($activeCategory['id'] ?? 0);
        }
        if ($categorySlug === '') {
            $categorySlug = trim((string)($activeCategory['slug'] ?? ''));
        }
    }

    $items = [];
    ecWmsInventoryWarmProductCollection($products);
    foreach ($products as $product) {
        if (!is_array($product)) {
            continue;
        }

        $items[] = ecBuildStorefrontCatalogItem($product, [
            'item_base_url' => (string)($options['item_base_url'] ?? '/ecommerce/shop'),
        ]);
    }

    $paginationRaw = is_array($options['pagination'] ?? null) ? $options['pagination'] : [];
    $pagination = [
        'current' => max(1, (int)($paginationRaw['current'] ?? $options['page'] ?? 1)),
        'total' => max(1, (int)($paginationRaw['total'] ?? $options['total_pages'] ?? 1)),
        'first_url' => trim((string)($paginationRaw['first_url'] ?? $options['pagination_first_url'] ?? '')),
        'prev_url' => trim((string)($paginationRaw['prev_url'] ?? $options['pagination_prev_url'] ?? '')),
        'next_url' => trim((string)($paginationRaw['next_url'] ?? $options['pagination_next_url'] ?? '')),
    ];

    $search = trim((string)($options['search'] ?? ''));
    $pageTitle = trim((string)($options['page_title'] ?? ''));
    if ($pageTitle === '') {
        $pageTitle = 'Shop';
    }
    $pageDescription = trim((string)($options['page_description'] ?? $options['list_description'] ?? ''));
    if ($pageDescription === '') {
        if ($search !== '' && $activeCategory !== null) {
            $pageDescription = 'Showing results for "' . $search . '" in ' . (string)($activeCategory['name'] ?? $pageTitle) . '.';
        } elseif ($search !== '') {
            $pageDescription = 'Showing results for "' . $search . '" across the current catalog.';
        } elseif ($activeCategory !== null) {
            $pageDescription = 'Browse products filed under ' . (string)($activeCategory['name'] ?? $pageTitle) . '.';
        }
    }

    return [
        'route' => [
            'origin' => trim((string)($options['origin'] ?? 'ecommerce')),
            'kind' => $routeKind,
            'mode' => $presentationMode,
        ],
        'page' => [
            'kind' => 'catalog',
            'title' => $pageTitle,
            'description' => $pageDescription,
        ],
        'navigation' => [
            'shop_url' => trim((string)($options['shop_url'] ?? '/ecommerce/shop')),
            'search_action_url' => trim((string)($options['search_action_url'] ?? $options['base_list_url'] ?? '/ecommerce/shop')),
            'all_items_url' => trim((string)($options['all_items_url'] ?? $options['base_list_url'] ?? '/ecommerce/shop')),
            'categories' => $categories,
        ],
        'filters' => [
            'search' => $search,
            'category_id' => $categoryId,
            'category_slug' => $categorySlug,
            'active_category' => $activeCategory,
            'attribute_filters' => function_exists('ecProductNormalizeAttributeFilters')
                ? ecProductNormalizeAttributeFilters($options['attribute_filters'] ?? [])
                : [],
            'attribute_facets' => is_array($options['attribute_facets'] ?? null) ? $options['attribute_facets'] : [],
        ],
        'collection' => [
            'total' => max(0, (int)($options['total'] ?? count($items))),
            'visible_count' => count($items),
            'category_count' => count($categories),
            'items' => $items,
            'pagination' => $pagination,
        ],
        'cart' => [
            'count' => ecStorefrontCurrentCartCount(isset($options['cart_count']) ? (int)$options['cart_count'] : null),
        ],
        'wishlist' => [
            'count' => function_exists('ecWishlistCount') ? ecWishlistCount() : 0,
            'url' => '/ecommerce/my-wishlist',
        ],
        'compare' => [
            'count' => function_exists('ecCompareCount') ? ecCompareCount() : 0,
            'url' => '/ecommerce/compare',
        ],
    ];
}

function ecBuildStorefrontDetailContext(array $product, array $options = []): array
{
    $routeKind = trim((string)($options['route_kind'] ?? 'product_detail'));
    if ($routeKind === '') {
        $routeKind = 'product_detail';
    }

    $presentationMode = trim((string)($options['presentation_mode'] ?? 'traditional'));
    if ($presentationMode === '') {
        $presentationMode = 'traditional';
    }

    $product = ecStorefrontHydrateProduct($product);
    $categories = ecStorefrontNormalizeCategories(is_array($product['categories'] ?? null) ? $product['categories'] : []);
    $activeCategory = $categories[0] ?? null;
    $galleryImages = ecProductNormalizeGalleryImages(is_array($product['gallery_images'] ?? null) ? $product['gallery_images'] : []);
    $bundleChildren = is_array($product['bundle_children'] ?? null) ? $product['bundle_children'] : [];
    $groupedChildren = is_array($product['grouped_children'] ?? null) ? $product['grouped_children'] : [];
    ecWmsInventoryWarmProductCollection($bundleChildren);
    ecWmsInventoryWarmProductCollection($groupedChildren);
    $catalogItem = ecBuildStorefrontCatalogItem($product, [
        'item_base_url' => (string)($options['item_base_url'] ?? '/ecommerce/shop'),
    ]);

    return [
        'route' => [
            'origin' => trim((string)($options['origin'] ?? 'ecommerce')),
            'kind' => $routeKind,
            'mode' => $presentationMode,
        ],
        'page' => [
            'kind' => 'detail',
            'title' => trim((string)($options['page_title'] ?? $product['title'] ?? '')),
            'description' => trim((string)($options['page_description'] ?? $product['excerpt'] ?? '')),
        ],
        'navigation' => [
            'shop_url' => trim((string)($options['shop_url'] ?? '/ecommerce/shop')),
            'search_action_url' => trim((string)($options['search_action_url'] ?? '/ecommerce/shop')),
            'all_items_url' => trim((string)($options['all_items_url'] ?? $options['shop_url'] ?? '/ecommerce/shop')),
            'categories' => $categories,
        ],
        'filters' => [
            'search' => '',
            'category_id' => (int)($activeCategory['id'] ?? 0),
            'category_slug' => trim((string)($activeCategory['slug'] ?? '')),
            'active_category' => $activeCategory,
            'attribute_filters' => function_exists('ecProductNormalizeAttributeFilters')
                ? ecProductNormalizeAttributeFilters($options['attribute_filters'] ?? [])
                : [],
            'attribute_facets' => is_array($options['attribute_facets'] ?? null) ? $options['attribute_facets'] : [],
        ],
        'product' => array_merge($catalogItem, [
            'body' => (string)($product['body'] ?? ''),
            'gallery_images' => $galleryImages,
            'categories' => $categories,
            'attributes' => is_array($product['attributes'] ?? null) ? $product['attributes'] : [],
            'addons' => is_array($product['addons'] ?? null) ? array_values($product['addons']) : [],
            'booking' => is_array($product['booking'] ?? null) ? $product['booking'] : ['enabled' => false],
            'membership_gate' => is_array($catalogItem['membership_gate'] ?? null) ? $catalogItem['membership_gate'] : ['allowed' => true],
            'reviews' => is_array($product['reviews'] ?? null) ? $product['reviews'] : [],
            'bundle_children' => array_map(static function (array $child): array {
                $storefrontChild = ecBuildStorefrontCatalogItem($child, ['item_base_url' => '/ecommerce/shop']);
                $storefrontChild['bundle_qty'] = max(1, (int)($child['bundle_qty'] ?? 1));
                return $storefrontChild;
            }, $bundleChildren),
            'bundle_summary' => is_array($product['bundle_summary'] ?? null) ? $product['bundle_summary'] : ecProductBundleSummary($product),
            'grouped_children' => array_map(static function (array $child): array {
                $storefrontChild = ecBuildStorefrontCatalogItem($child, ['item_base_url' => '/ecommerce/shop']);
                $storefrontChild['grouped_qty'] = max(1, (int)($child['grouped_qty'] ?? 1));
                return $storefrontChild;
            }, $groupedChildren),
            'related_products' => is_array($product['related_products'] ?? null) ? $product['related_products'] : [],
            'upsell_products' => is_array($product['upsell_products'] ?? null) ? $product['upsell_products'] : [],
            'cross_sell_products' => is_array($product['cross_sell_products'] ?? null) ? $product['cross_sell_products'] : [],
            'relation_sections' => is_array($product['relation_sections'] ?? null) ? $product['relation_sections'] : [],
        ]),
        'cart' => [
            'count' => ecStorefrontCurrentCartCount(isset($options['cart_count']) ? (int)$options['cart_count'] : null),
        ],
        'wishlist' => [
            'count' => function_exists('ecWishlistCount') ? ecWishlistCount() : 0,
            'url' => '/ecommerce/my-wishlist',
        ],
        'compare' => [
            'count' => function_exists('ecCompareCount') ? ecCompareCount() : 0,
            'url' => '/ecommerce/compare',
        ],
    ];
}