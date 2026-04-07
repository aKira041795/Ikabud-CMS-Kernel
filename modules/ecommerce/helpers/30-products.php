<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Products (helpers/30-products.php)
//
// Products are cms_content rows with type='product'.
// The ecommerce preset (pricing + inventory + media_gallery) is
// auto-attached on product creation via cmsApplyEntityPreset().
// ─────────────────────────────────────────────────────────────────────────

/**
 * List products with optional filters.
 *
 * @param array $filters  category_id, search, status, limit, offset, order_by
 * @return array  ['items' => [...], 'total' => int]
 */
function ecProductList(array $filters = []): array
{
    $db = ecDb();

    // Support category_ids (array) or legacy category_id (single int).
    $categoryIds = [];
    if (!empty($filters['category_ids']) && is_array($filters['category_ids'])) {
        $categoryIds = array_values(array_unique(array_map('intval', $filters['category_ids'])));
    } elseif (isset($filters['category_id']) && $filters['category_id'] !== null) {
        $categoryIds = [(int)$filters['category_id']];
    }

    $search     = trim((string)($filters['search'] ?? ''));
    $status     = trim((string)($filters['status'] ?? 'published'));
    $limit      = min(100, max(1, (int)($filters['limit']  ?? (int)ecSettings('products_per_page'))));
    $offset     = max(0, (int)($filters['offset'] ?? 0));
    $orderInput = (string)($filters['order_by'] ?? 'created_at');
    $orderInput = match ($orderInput) {
        'date' => 'created_at',
        default => $orderInput,
    };
    $orderBy    = in_array($orderInput, ['created_at', 'title', 'updated_at', 'price', 'random'], true)
        ? $orderInput : 'created_at';
    $orderDir   = strtolower((string)($filters['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

    $where  = ["c.type = 'product'", 'c.deleted_at IS NULL'];
    $params = [];

    if ($status !== '') {
        $where[]  = 'c.status = ?';
        $params[] = $status;
    }

    if ($search !== '') {
        $where[]  = '(c.title LIKE ? OR c.excerpt LIKE ?)';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }

    $join = '';
    if (!empty($categoryIds)) {
        $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
        $join     = "INNER JOIN cms_content_categories cc ON cc.content_id = c.id AND cc.category_id IN ($placeholders)";
        $params   = array_merge($categoryIds, $params);
    }

    $whereClause = implode(' AND ', $where);
    $pricingJoin = '';
    $orderClause = 'c.' . $orderBy . ' ' . $orderDir;

    if ($orderBy === 'price') {
        $pricingJoin = " LEFT JOIN cms_entity_capabilities pricing_cap ON pricing_cap.entity_id = c.id AND pricing_cap.capability_id = 'pricing'";
        $orderClause = "COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(pricing_cap.config, '$.sale_price')) AS DECIMAL(12,2)), CAST(JSON_UNQUOTE(JSON_EXTRACT(pricing_cap.config, '$.price')) AS DECIMAL(12,2)), 0) " . $orderDir;
    } elseif ($orderBy === 'random') {
        $orderClause = 'RAND()';
    }

    try {
        $total = (int)$db->query(
            "SELECT COUNT(DISTINCT c.id) FROM cms_content c $join WHERE $whereClause",
            $params
        )->fetchColumn();

        $rows = $db->query(
            "SELECT c.id, c.uuid, c.title, c.slug, c.excerpt, c.status,
                    c.featured_image_id, c.author_id, c.created_at, c.updated_at,
                    m.file_path AS featured_image
             FROM cms_content c
             $join
             $pricingJoin
             LEFT JOIN cms_media m ON m.id = c.featured_image_id
             WHERE $whereClause
             ORDER BY $orderClause
             LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        )->fetchAll(\PDO::FETCH_ASSOC);

        $productIds = array_values(array_filter(array_map(
            static fn(array $row): int => (int)($row['id'] ?? 0),
            is_array($rows) ? $rows : []
        )));
        
        $galleryMap = ecProductGalleryImagesForProducts($productIds);

        // Batch load capabilities and digital meta
        $pricingMap = [];
        $inventoryMap = [];
        $digitalMap = [];
        
        if (!empty($productIds)) {
            $idsCsv = implode(',', array_fill(0, count($productIds), '?'));
            
            // Pricing cap
            $pricingRows = $db->query(
                "SELECT entity_id, config FROM cms_entity_capabilities WHERE capability_id = 'pricing' AND entity_id IN ($idsCsv)",
                $productIds
            )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            foreach ($pricingRows as $pr) {
                $pricingMap[(int)$pr['entity_id']] = (array)json_decode($pr['config'] ?? '{}', true);
            }
            
            // Inventory cap
            $inventoryRows = $db->query(
                "SELECT entity_id, config FROM cms_entity_capabilities WHERE capability_id = 'inventory' AND entity_id IN ($idsCsv)",
                $productIds
            )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            foreach ($inventoryRows as $ir) {
                $inventoryMap[(int)$ir['entity_id']] = (array)json_decode($ir['config'] ?? '{}', true);
            }
            
            // Digital meta
            $digitalRows = $db->query(
                "SELECT content_id, meta_value FROM cms_content_meta WHERE meta_key = '_is_digital' AND content_id IN ($idsCsv)",
                $productIds
            )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            foreach ($digitalRows as $dr) {
                $digitalMap[(int)$dr['content_id']] = ($dr['meta_value'] ?? '') === '1';
            }
        }

        // Read settings once before loop
        $currencySetting = ecSettings('currency');
        $currencySymbol = (string)ecSettings('currency_symbol');
        $lowStockThreshold = (int)ecSettings('low_stock_threshold');

        // Attach pricing + inventory capability data to each product
        foreach ($rows as &$row) {
            $id = (int)($row['id'] ?? 0);
            $galleryImages = $galleryMap[$id] ?? [];
            $row['gallery_images'] = $galleryImages;
            $row['featured_image_url'] = ecProductResolveFeaturedImageUrl((string)($row['featured_image'] ?? ''));
            $row['primary_image_url'] = ecProductPrimaryImageUrl($row['featured_image_url'], $galleryImages);

            // Inline pricing parsing
            $pConfig = $pricingMap[$id] ?? [];
            if (empty($pConfig)) {
                $row['pricing'] = ['price' => null, 'currency' => $currencySetting, 'on_sale' => false, 'formatted' => null];
            } else {
                $price     = isset($pConfig['price'])      ? (float)$pConfig['price']      : null;
                $salePrice = isset($pConfig['sale_price']) ? (float)$pConfig['sale_price'] : null;
                $currency  = $pConfig['currency'] ?? $currencySetting;
                $onSale    = $price !== null && $salePrice !== null && $salePrice > 0 && $salePrice < $price;
                $active    = $onSale ? $salePrice : $price;
                $row['pricing'] = [
                    'price'        => $price,
                    'sale_price'   => $salePrice,
                    'active_price' => $active,
                    'currency'     => $currency,
                    'on_sale'      => $onSale,
                    'formatted'    => $price !== null ? ($currencySymbol . number_format($active, 2)) : null,
                    'regular_fmt'  => $price !== null ? ($currencySymbol . number_format($price, 2)) : null,
                ];
            }

            // Inline inventory parsing
            $iConfig = $inventoryMap[$id] ?? [];
            $isDigital = $digitalMap[$id] ?? false;
            
            if (empty($iConfig)) {
                $row['inventory'] = ['in_stock' => true, 'out_of_stock' => false, 'low_stock' => false, 'stock_qty' => null, 'sku' => '', 'track_stock' => false, 'badge' => ['label' => '', 'tone' => '']];
            } elseif ($isDigital) {
                $row['inventory'] = ['in_stock' => true, 'out_of_stock' => false, 'low_stock' => false, 'stock_qty' => null, 'sku' => $iConfig['sku'] ?? '', 'track_stock' => false, 'badge' => ['label' => '', 'tone' => '']];
            } else {
                $trackStock = (bool)($iConfig['track_stock'] ?? true);
                $stockQty   = (int)($iConfig['stock_qty']   ?? 0);
                
                $row['inventory'] = [
                    'track_stock' => $trackStock,
                    'stock_qty'   => $stockQty,
                    'badge'       => ['label' => ($trackStock && $stockQty <= 0 ? 'Out of stock' : ''), 'tone' => ($trackStock && $stockQty <= 0 ? 'negative' : '')],
                    'sku'         => $iConfig['sku'] ?? '',
                    'in_stock'    => !$trackStock || $stockQty > 0,
                    'out_of_stock' => $trackStock && $stockQty <= 0,
                    'low_stock'   => $trackStock && $stockQty > 0 && $stockQty <= $lowStockThreshold,
                ];
            }
        }
        unset($row);

        return ['items' => $rows, 'total' => $total];
    } catch (\Throwable $e) {
        write_log('ecProductList error: ' . $e->getMessage(), 'error', ['module' => 'ecommerce']);
        return ['items' => [], 'total' => 0];
    }
}

/**
 * Get a single product by ID with all context.
 */
function ecProductGet(int $id): ?array
{
    $db = ecDb();
    try {
        $row = $db->query(
            "SELECT c.id, c.uuid, c.title, c.slug, c.excerpt, c.body,
                    c.status, c.featured_image_id, c.author_id, c.parent_id,
                    c.created_at, c.updated_at,
                    m.file_path AS featured_image
             FROM cms_content c
             LEFT JOIN cms_media m ON m.id = c.featured_image_id
             WHERE c.id = ? AND c.type = 'product' AND c.deleted_at IS NULL
             LIMIT 1",
            [$id]
        )->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $row['gallery_images'] = ecProductGalleryImages($id);
        $row['featured_image_url'] = ecProductResolveFeaturedImageUrl((string)($row['featured_image'] ?? ''));
        $row['primary_image_url'] = ecProductPrimaryImageUrl((string)$row['featured_image_url'], $row['gallery_images']);

        $row['pricing']   = ecProductPricing($id);
        $row['inventory'] = ecProductInventory($id);
        $row['categories'] = ecProductCategories($id);
        $row['variants']   = ecProductVariants($id);
        $row['badges']     = ['sale' => ($row['pricing']['on_sale'] ?? false) ? 'Sale' : ''];

        // Digital license meta
        try {
            $metaStmt = $db->query(
                "SELECT meta_key, meta_value FROM cms_content_meta
                 WHERE content_id = ? AND meta_key IN ('_is_digital','_license_module','_license_tier','_license_duration_days','_download_file_path','_download_file_name')",
                [$id]
            );
            $metaRows = $metaStmt ? $metaStmt->fetchAll(\PDO::FETCH_ASSOC) : [];
            $metaMap = [];
            foreach ($metaRows as $mr) {
                $metaMap[$mr['meta_key']] = $mr['meta_value'];
            }
        } catch (\Throwable $e) {
            $metaMap = [];
        }
        $row['is_digital']            = ($metaMap['_is_digital'] ?? '0') === '1';
        $row['license_module']        = (string)($metaMap['_license_module'] ?? '');
        $row['license_tier']          = (string)($metaMap['_license_tier'] ?? 'pro');
        $row['license_duration_days'] = (int)($metaMap['_license_duration_days'] ?? 365);
        $row['download_file_path']    = (string)($metaMap['_download_file_path'] ?? '');
        $row['download_file_name']    = (string)($metaMap['_download_file_name'] ?? '');

        return $row;
    } catch (\Throwable $e) {
        write_log('ecProductGet error: ' . $e->getMessage(), 'error', ['module' => 'ecommerce']);
        return null;
    }
}

/**
 * Get a product by slug.
 */
function ecProductGetBySlug(string $slug): ?array
{
    $db = ecDb();
    try {
        $row = $db->query(
            "SELECT id FROM cms_content WHERE slug = ? AND type = 'product' AND deleted_at IS NULL LIMIT 1",
            [$slug]
        )->fetch(\PDO::FETCH_ASSOC);

        return $row ? ecProductGet((int)$row['id']) : null;
    } catch (\Throwable $e) {
        return null;
    }
}

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
    $price = isset($pricing['price']) ? (float)$pricing['price'] : null;
    $salePrice = isset($pricing['sale_price']) ? (float)$pricing['sale_price'] : null;
    $onSale = array_key_exists('on_sale', $pricing)
        ? (bool)$pricing['on_sale']
        : ($price !== null && $salePrice !== null && $salePrice > 0 && $salePrice < $price);
    $activePrice = isset($pricing['active_price'])
        ? (float)$pricing['active_price']
        : ($onSale && $salePrice !== null ? $salePrice : $price);

    $symbol = (string)ecSettings('currency_symbol');
    $formatted = trim((string)($pricing['formatted'] ?? ''));
    if ($formatted === '' && $activePrice !== null) {
        $formatted = $symbol . number_format($activePrice, 2);
    }

    $regularFormatted = trim((string)($pricing['regular_fmt'] ?? ''));
    if ($regularFormatted === '' && $price !== null) {
        $regularFormatted = $symbol . number_format($price, 2);
    }

    return [
        'price' => $price,
        'sale_price' => $salePrice,
        'active_price' => $activePrice,
        'currency' => trim((string)($pricing['currency'] ?? ecSettings('currency'))),
        'on_sale' => $onSale,
        'formatted' => $formatted !== '' ? $formatted : null,
        'regular_fmt' => $regularFormatted !== '' ? $regularFormatted : null,
    ];
}

function ecStorefrontNormalizeInventory(array $inventory): array
{
    $trackStock = (bool)($inventory['track_stock'] ?? false);
    $stockQty = array_key_exists('stock_qty', $inventory) ? (int)$inventory['stock_qty'] : null;
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
    $slug = trim((string)($product['slug'] ?? ''));
    $itemBaseUrl = rtrim((string)($options['item_base_url'] ?? '/ecommerce/shop'), '/');
    $detailUrl = trim((string)($product['detail_url'] ?? $product['url'] ?? ''));
    if ($detailUrl === '') {
        $detailUrl = $slug !== ''
            ? (($itemBaseUrl !== '' ? $itemBaseUrl : '/ecommerce/shop') . '/' . rawurlencode($slug))
            : '/ecommerce/shop';
    }

    $pricing = ecStorefrontNormalizePricing(is_array($product['pricing'] ?? null) ? $product['pricing'] : []);
    $inventory = ecStorefrontNormalizeInventory(is_array($product['inventory'] ?? null) ? $product['inventory'] : []);
    $inventoryBadge = ecStorefrontInventoryBadge($inventory);
    $saleBadgeText = trim((string)($product['sale_badge_text'] ?? ''));
    if ($saleBadgeText === '') {
        $saleBadgeText = ecStorefrontSaleBadgeText($pricing);
    }

    return [
        'id' => (int)($product['id'] ?? 0),
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
        'categories' => ecStorefrontNormalizeCategories(is_array($product['categories'] ?? null) ? $product['categories'] : []),
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
        ],
        'product' => array_merge($catalogItem, [
            'body' => (string)($product['body'] ?? ''),
            'gallery_images' => $galleryImages,
            'categories' => $categories,
        ]),
        'cart' => [
            'count' => ecStorefrontCurrentCartCount(isset($options['cart_count']) ? (int)$options['cart_count'] : null),
        ],
    ];
}

/**
 * Create a new product (cms_content row) and apply the ecommerce preset.
 *
 * @param array $data  title, slug, excerpt, body, status, featured_image_id, author_id
 * @return int  New product's cms_content.id
 */
function ecProductCreate(array $data, int $authorId = 0): int
{
    $title    = trim((string)($data['title'] ?? 'New Product'));
    $slug     = ecProductSlug(ecProductResolveSlugSource($data));
    $excerpt  = trim((string)($data['excerpt'] ?? ''));
    $body     = $data['body'] ?? '';
    $status   = in_array($data['status'] ?? '', ['draft', 'published', 'private'], true)
        ? $data['status'] : 'draft';
    $authorId = $authorId ?: ((int)($data['author_id'] ?? 0));
    $featuredImageId = ($data['featured_image_id'] ?? null) ? (int)$data['featured_image_id'] : null;

    // Write to CMS-owned table requires CMS module context
    $productId = moduleWithContext('cms', static function () use ($title, $slug, $excerpt, $body, $status, $authorId, $featuredImageId): int {
        $db = cmsDb();
        $uuid = function_exists('cmsUuid') ? cmsUuid() : bin2hex(random_bytes(16));
        $db->execute(
            "INSERT INTO cms_content (uuid, title, slug, excerpt, body, type, status, author_id, featured_image_id, content_mode, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 'product', ?, ?, ?, 'standard', NOW(), NOW())",
            [$uuid, $title, $slug, $excerpt, $body, $status, $authorId, $featuredImageId]
        );
        return (int)$db->lastInsertId();
    });

    // Apply ecommerce preset: attaches pricing + inventory + media_gallery capabilities
    if (function_exists('cmsApplyEntityPreset')) {
        moduleWithContext('cms', static function () use ($productId): void {
            cmsApplyEntityPreset($productId, 'ecommerce');
        });
    }

    // Set initial pricing/inventory if provided
    if (!empty($data['price'])) {
        ecProductUpdatePricing($productId, [
            'price'      => (float)$data['price'],
            'currency'   => $data['currency']   ?? ecSettings('currency'),
            'sale_price' => isset($data['sale_price']) ? (float)$data['sale_price'] : null,
        ]);
    }

    ecProductUpdateInventory($productId, [
        'track_stock' => (bool)($data['track_stock'] ?? true),
        'stock_qty'   => (int)($data['stock_qty']   ?? 0),
        'sku'         => $data['sku'] ?? '',
    ]);

    // Assign category if provided
    if (array_key_exists('category_id', $data)) {
        ecProductAssignCategory($productId, (int)($data['category_id'] ?? 0));
    }

    if (function_exists('cmsSyncMediaUsage')) {
        moduleWithContext('cms', static function () use ($productId, $featuredImageId): void {
            cmsSyncMediaUsage($productId, ['featured_image_id' => $featuredImageId], null);
        });
    }

    return $productId;
}

/**
 * Update a product's basic cms_content fields.
 */
function ecProductUpdate(int $id, array $data): void
{
    $fields = [];
    $params = [];

    if (isset($data['title'])) {
        $fields[]  = 'title = ?';
        $params[]  = trim((string)$data['title']);
    }
    if (isset($data['slug'])) {
        $fields[]  = 'slug = ?';
        $params[]  = ecProductSlug(ecProductResolveSlugSource($data, $id), $id);
    }
    if (isset($data['excerpt'])) {
        $fields[]  = 'excerpt = ?';
        $params[]  = trim((string)$data['excerpt']);
    }
    if (isset($data['body'])) {
        $fields[]  = 'body = ?';
        $params[]  = $data['body'];
    }
    if (isset($data['status'])) {
        $fields[]  = 'status = ?';
        $params[]  = $data['status'];
    }
    if (array_key_exists('featured_image_id', $data)) {
        $fields[]  = 'featured_image_id = ?';
        $params[]  = $data['featured_image_id'] ? (int)$data['featured_image_id'] : null;
    }

    if (!empty($fields)) {
        $fields[]  = 'updated_at = NOW()';
        $params[]  = $id;

        // Write to CMS-owned table requires CMS module context
        moduleWithContext('cms', static function () use ($fields, $params): void {
            cmsDb()->execute(
                'UPDATE cms_content SET ' . implode(', ', $fields) . ' WHERE id = ? AND type = \'product\'',
                $params
            );
        });
    }

    if (!empty($data['price']) || isset($data['sale_price'])) {
        ecProductUpdatePricing($id, $data);
    }

    if (isset($data['stock_qty']) || isset($data['sku']) || isset($data['track_stock'])) {
        ecProductUpdateInventory($id, $data);
    }

    if (isset($data['category_id'])) {
        ecProductAssignCategory($id, (int)$data['category_id']);
    }

    if (array_key_exists('featured_image_id', $data) && function_exists('cmsSyncMediaUsage')) {
        moduleWithContext('cms', static function () use ($id, $data): void {
            cmsSyncMediaUsage($id, ['featured_image_id' => $data['featured_image_id'] ?? null], null);
        });
    }
}

/**
 * Save digital license meta fields for a product.
 * Reads _is_digital, _license_module, _license_tier, _license_duration_days from $input.
 */
function ecProductSaveDigitalMeta(int $productId, array $input): void
{
    $isDigital      = !empty($input['is_digital']) ? '1' : '0';
    $licenseModule  = trim((string)($input['license_module'] ?? ''));
    $licenseTier    = trim((string)($input['license_tier'] ?? 'pro'));
    $licenseDays    = max(1, (int)($input['license_duration_days'] ?? 365));

    $meta = [
        '_is_digital'           => $isDigital,
        '_license_module'       => $licenseModule,
        '_license_tier'         => $licenseTier !== '' ? $licenseTier : 'pro',
        '_license_duration_days' => (string)$licenseDays,
    ];

    // Persist a new digital file path when one was just uploaded.
    if (!empty($input['_download_file_path'])) {
        $meta['_download_file_path'] = (string)$input['_download_file_path'];
        $meta['_download_file_name'] = (string)($input['_download_file_name'] ?? basename($input['_download_file_path']));
    }

    // Allow explicit removal via the admin form.
    if (!empty($input['remove_digital_file'])) {
        $meta['_download_file_path'] = '';
        $meta['_download_file_name'] = '';
    }

    try {
        moduleWithContext('cms', static function () use ($productId, $meta): void {
            $db = cmsDb();
            foreach ($meta as $key => $value) {
                $db->execute(
                    "INSERT INTO cms_content_meta (content_id, meta_key, meta_value)
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
                    [$productId, $key, $value]
                );
            }
        });
    } catch (\Throwable $e) {
        write_log('ecProductSaveDigitalMeta error: ' . $e->getMessage(), 'error', [
            'module'     => 'ecommerce',
            'product_id' => $productId,
        ]);
    }
}

function ecProductResolveSlugSource(array $data, int $productId = 0): string
{
    $slug = trim((string)($data['slug'] ?? ''));
    if ($slug !== '') {
        return $slug;
    }

    $title = trim((string)($data['title'] ?? ''));
    if ($title !== '') {
        return $title;
    }

    if ($productId > 0) {
        $row = ecDb()->query(
            "SELECT title, slug FROM cms_content WHERE id = ? AND type = 'product' LIMIT 1",
            [$productId]
        )->fetch(\PDO::FETCH_ASSOC) ?: [];

        $fallback = trim((string)($row['title'] ?? $row['slug'] ?? ''));
        if ($fallback !== '') {
            return $fallback;
        }
    }

    return 'product';
}

/**
 * Soft-delete a product.
 */
function ecProductDelete(int $id): void
{
    // Write to CMS-owned table requires CMS module context
    moduleWithContext('cms', static function () use ($id): void {
        cmsDb()->execute(
            "UPDATE cms_content SET deleted_at = NOW() WHERE id = ? AND type = 'product'",
            [$id]
        );
    });
}

/**
 * Get pricing config for a product.
 */
function ecProductPricing(int $productId): array
{
    try {
        $db  = ecDb();
        $row = $db->query(
            "SELECT config FROM cms_entity_capabilities WHERE entity_id = ? AND capability_id = 'pricing' LIMIT 1",
            [$productId]
        )->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return ['price' => null, 'currency' => ecSettings('currency'), 'on_sale' => false, 'formatted' => null];
        }

        $config    = (array)json_decode($row['config'] ?? '{}', true);
        $price     = isset($config['price'])      ? (float)$config['price']      : null;
        $salePrice = isset($config['sale_price']) ? (float)$config['sale_price'] : null;
        $currency  = $config['currency'] ?? ecSettings('currency');
        $symbol    = (string)ecSettings('currency_symbol');
        $onSale    = $price !== null && $salePrice !== null && $salePrice > 0 && $salePrice < $price;
        $active    = $onSale ? $salePrice : $price;

        return [
            'price'        => $price,
            'sale_price'   => $salePrice,
            'active_price' => $active,
            'currency'     => $currency,
            'on_sale'      => $onSale,
            'formatted'    => $price !== null ? ($symbol . number_format($active, 2)) : null,
            'regular_fmt'  => $price !== null ? ($symbol . number_format($price, 2)) : null,
        ];
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Get inventory config for a product.
 */
function ecProductInventory(int $productId): array
{
    try {
        $db  = ecDb();
        $row = $db->query(
            "SELECT config FROM cms_entity_capabilities WHERE entity_id = ? AND capability_id = 'inventory' LIMIT 1",
            [$productId]
        )->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return ['in_stock' => true, 'out_of_stock' => false, 'low_stock' => false, 'stock_qty' => null, 'sku' => '', 'track_stock' => false, 'badge' => ['label' => '', 'tone' => '']];
        }

        $config     = (array)json_decode($row['config'] ?? '{}', true);

        // Digital products are always available — never block on stock.
        $digitalMeta = $db->query(
            "SELECT meta_value FROM cms_content_meta WHERE content_id = ? AND meta_key = '_is_digital' LIMIT 1",
            [$productId]
        )->fetch(\PDO::FETCH_ASSOC);
        if (($digitalMeta['meta_value'] ?? '') === '1') {
            return ['in_stock' => true, 'out_of_stock' => false, 'low_stock' => false, 'stock_qty' => null, 'sku' => $config['sku'] ?? '', 'track_stock' => false, 'badge' => ['label' => '', 'tone' => '']];
        }

        $trackStock = (bool)($config['track_stock'] ?? true);
        $stockQty   = (int)($config['stock_qty']   ?? 0);
        $threshold  = (int)ecSettings('low_stock_threshold');

        return [
            'track_stock' => $trackStock,
            'stock_qty'   => $stockQty,
            'badge'       => ['label' => ($trackStock && $stockQty <= 0 ? 'Out of stock' : ''), 'tone' => ($trackStock && $stockQty <= 0 ? 'negative' : '')],
            'sku'         => $config['sku'] ?? '',
            'in_stock'    => !$trackStock || $stockQty > 0,
            'out_of_stock' => $trackStock && $stockQty <= 0,
            'low_stock'   => $trackStock && $stockQty > 0 && $stockQty <= $threshold,
        ];
    } catch (\Throwable $e) {
        return [];
    }
}

function ecProductUpdatePricing(int $productId, array $data): void
{
    if (!function_exists('cmsEntityAttachCapability')) {
        return;
    }
    $existing = ecProductPricing($productId);
    $config = array_filter([
        'price'      => isset($data['price'])      ? (float)$data['price']      : ($existing['price']      ?? null),
        'currency'   => $data['currency']           ?? ($existing['currency']     ?? ecSettings('currency')),
        'sale_price' => isset($data['sale_price']) && (float)$data['sale_price'] > 0 ? (float)$data['sale_price'] : ($existing['sale_price'] ?? null),
    ], fn($v) => $v !== null);

    ecAttachCmsEntityCapability($productId, 'pricing', $config);
}

function ecProductUpdateInventory(int $productId, array $data): void
{
    if (!function_exists('cmsEntityAttachCapability')) {
        return;
    }
    $existing = ecProductInventory($productId);
    $sku = ecProductNormalizeSku($productId, $data['sku'] ?? ($existing['sku'] ?? ''));
    $config = [
        'track_stock' => isset($data['track_stock']) ? (bool)$data['track_stock'] : ($existing['track_stock'] ?? true),
        'stock_qty'   => isset($data['stock_qty'])   ? (int)$data['stock_qty']    : ($existing['stock_qty']   ?? 0),
        'sku'         => $sku,
    ];

    ecAttachCmsEntityCapability($productId, 'inventory', $config);
}

function ecProductDecrementStock(int $productId, int $qty): void
{
    $db = ecDb();

    // Digital products have unlimited availability — skip stock decrement.
    $digitalMeta = $db->query(
        "SELECT meta_value FROM cms_content_meta WHERE content_id = ? AND meta_key = '_is_digital' LIMIT 1",
        [$productId]
    )->fetch(\PDO::FETCH_ASSOC);
    if (($digitalMeta['meta_value'] ?? '') === '1') {
        return;
    }

    // Read is fine via ecDb() (reads_tables), but update needs CMS context
    $row = $db->query(
        "SELECT id, config FROM cms_entity_capabilities WHERE entity_id = ? AND capability_id = 'inventory' LIMIT 1",
        [$productId]
    )->fetch(\PDO::FETCH_ASSOC);

    if (!$row) {
        return;
    }

    $config     = (array)json_decode($row['config'] ?? '{}', true);
    $trackStock = (bool)($config['track_stock'] ?? true);
    if (!$trackStock) {
        return;
    }

    $newQty = max(0, (int)($config['stock_qty'] ?? 0) - $qty);
    $config['stock_qty'] = $newQty;

    // Write to CMS-owned table requires CMS module context
    moduleWithContext('cms', static function () use ($config, $row): void {
        cmsDb()->execute(
            "UPDATE cms_entity_capabilities SET config = ?, updated_at = NOW() WHERE id = ?",
            [json_encode($config), (int)$row['id']]
        );
    });

    // Fire out-of-stock event if reached zero
    if ($newQty === 0) {
        $product = ecProductGet($productId);
        app()->events()->fire('ecommerce.product.out_of_stock', [
            'product_id'    => $productId,
            'product_title' => $product['title'] ?? '',
            'sku'           => $config['sku']    ?? '',
        ]);
    }
}

function ecProductCategories(int $productId): array
{
    try {
        return ecDb()->query(
            "SELECT cat.id, cat.name, cat.slug
             FROM cms_categories cat
             INNER JOIN cms_content_categories cc ON cc.category_id = cat.id
             WHERE cc.content_id = ?",
            [$productId]
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }
}

function ecProductAssignCategory(int $productId, int $categoryId): void
{
    try {
        // Write to CMS-owned table requires CMS module context
        moduleWithContext('cms', static function () use ($productId, $categoryId): void {
            $db = cmsDb();
            $db->execute("DELETE FROM cms_content_categories WHERE content_id = ?", [$productId]);
            if ($categoryId > 0) {
                $db->execute(
                    "INSERT IGNORE INTO cms_content_categories (content_id, category_id) VALUES (?, ?)",
                    [$productId, $categoryId]
                );
            }
        });
    } catch (\Throwable $e) {
        write_log('ecProductAssignCategory error: ' . $e->getMessage(), 'error', ['module' => 'ecommerce']);
    }
}

function ecProductVariants(int $productId): array
{
    try {
        return ecDb()->query(
            "SELECT id, sku, label, attributes_json, price_override, stock_qty, is_active, sort_order
             FROM ec_product_variants
             WHERE product_id = ? AND is_active = 1
             ORDER BY sort_order ASC",
            [$productId]
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Generate a unique product slug. Appends -N suffix if slug is taken.
 */
function ecProductSlug(string $slug, int $excludeId = 0): string
{
    $base = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($slug)));
    $base = trim($base, '-') ?: 'product';

    $candidate = $base;
    $n = 1;

    while (true) {
        $existing = ecDb()->query(
            "SELECT id FROM cms_content WHERE slug = ? AND type = 'product' AND deleted_at IS NULL LIMIT 1",
            [$candidate]
        )->fetchColumn();

        if (!$existing || (int)$existing === $excludeId) {
            return $candidate;
        }

        $candidate = $base . '-' . $n;
        $n++;
    }
}

function ecProductNormalizeSku(int $productId, string $sku = ''): string
{
    $base = strtoupper(trim($sku));
    $base = preg_replace('/[^A-Z0-9]+/', '-', $base);
    $base = trim((string)$base, '-');

    if ($base === '') {
        $product = ecDb()->query(
            "SELECT title, slug FROM cms_content WHERE id = ? AND type = 'product' LIMIT 1",
            [$productId]
        )->fetch(\PDO::FETCH_ASSOC) ?: [];
        $seed = (string)($product['slug'] ?? $product['title'] ?? 'product');
        $base = strtoupper(trim((string)preg_replace('/[^a-z0-9]+/i', '-', $seed), '-'));
    }

    $base = substr($base !== '' ? $base : 'PRODUCT', 0, 80);
    $candidate = $base;
    $suffix = 1;

    while (ecProductSkuExists($candidate, $productId)) {
        $candidate = substr($base, 0, 72) . '-' . $suffix;
        $suffix++;
    }

    return $candidate;
}

function ecProductSkuExists(string $sku, int $excludeProductId = 0): bool
{
    $existing = ecDb()->query(
        "SELECT entity_id
         FROM cms_entity_capabilities
         WHERE capability_id = 'inventory'
           AND JSON_UNQUOTE(JSON_EXTRACT(config, '$.sku')) = ?
         LIMIT 1",
        [$sku]
    )->fetchColumn();

    return $existing !== false && (int)$existing !== $excludeProductId;
}

function ecAttachCmsEntityCapability(int $entityId, string $capabilityId, array $config): void
{
    moduleWithContext('cms', static function () use ($entityId, $capabilityId, $config): void {
        cmsEntityAttachCapability($entityId, $capabilityId, $config);
    });
}

function ecUploadProductFeaturedImage(array $file, int $uploadedBy): ?array
{
    if (empty($file) || !is_array($file)) {
        return null;
    }

    $errorCode = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorCode === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($errorCode !== UPLOAD_ERR_OK) {
        throw new \RuntimeException('Upload error: ' . $errorCode);
    }

    $tmpName = (string)($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new \RuntimeException('Uploaded file was not received correctly.');
    }

    $cmsSettings = function_exists('readCmsSettings') ? readCmsSettings() : [];
    $maxMb = max(1, min(64, (int)($cmsSettings['max_upload_mb'] ?? 2)));
    $maxSize = $maxMb * 1024 * 1024;
    $size = (int)($file['size'] ?? 0);
    if ($size > $maxSize) {
        throw new \RuntimeException('Image exceeds ' . $maxMb . 'MB limit.');
    }

    $finfo = new \finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string)$finfo->file($tmpName);
    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
    if (!in_array($mimeType, $allowedMimes, true)) {
        throw new \RuntimeException('Unsupported image type: ' . $mimeType);
    }

    if (function_exists('cmsCheckDangerousFileSignature')) {
        $danger = cmsCheckDangerousFileSignature($tmpName);
        if ($danger !== null) {
            throw new \RuntimeException($danger);
        }
    }

    if (!function_exists('cmsUploadsPath') || !function_exists('cmsResolveUploadUrl')) {
        throw new \RuntimeException('CMS uploads helpers are unavailable.');
    }

    $ext = strtolower((string)pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    $safeExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
    if (!in_array($ext, $safeExts, true)) {
        $ext = $mimeType === 'image/jpeg' ? 'jpg' : ($mimeType === 'image/png' ? 'png' : ($mimeType === 'image/gif' ? 'gif' : ($mimeType === 'image/webp' ? 'webp' : 'svg')));
    }

    $filename = date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;
    $subDir = date('Y') . '/' . date('m');
    $uploadDir = cmsUploadsPath() . '/' . $subDir;
    if (!is_dir($uploadDir)) {
        kernelEnsureDirectory($uploadDir);
    }

    $destPath = $uploadDir . '/' . $filename;
    if (!move_uploaded_file($tmpName, $destPath)) {
        throw new \RuntimeException('Failed to save uploaded image.');
    }

    $relativePath = $subDir . '/' . $filename;
    if (function_exists('cmsGenerateThumbnails') && in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
        $filenameBase = pathinfo($filename, PATHINFO_FILENAME);
        cmsGenerateThumbnails($destPath, $subDir, $filenameBase, $ext);
    }

    $mediaId = moduleWithContext('cms', static function () use ($filename, $file, $mimeType, $size, $relativePath, $uploadedBy): int {
        $db = cmsDb();
        $stmt = $db->prepare(
            "INSERT INTO cms_media (filename, original_name, mime_type, file_size, file_path, uploaded_by, created_at)
             VALUES (:fname, :oname, :mime, :size, :path, :uid, NOW())"
        );
        $stmt->execute([
            ':fname' => $filename,
            ':oname' => (string)($file['name'] ?? $filename),
            ':mime'  => $mimeType,
            ':size'  => $size,
            ':path'  => $relativePath,
            ':uid'   => $uploadedBy,
        ]);

        return (int)$db->lastInsertId();
    });

    if ($ctx = module('cms')) {
        $ctx->fireEvent('cms.media.uploaded', [
            'media_id' => $mediaId,
            'filename' => $filename,
            'mime_type' => $mimeType,
        ]);
    }

    return [
        'id' => $mediaId,
        'url' => cmsResolveUploadUrl($relativePath),
        'file_path' => $relativePath,
    ];
}

/**
 * Upload a digital product file (zip, pdf, epub, etc.) to a private
 * storage directory that is NOT web-accessible.
 *
 * Returns ['file_path' => string, 'original_name' => string, 'mime_type' => string, 'size' => int]
 * or throws \RuntimeException on error.
 * Returns null when no file was provided.
 */
function ecUploadProductDigitalFile(array $file, int $uploadedBy): ?array
{
    if (empty($file) || !is_array($file)) {
        return null;
    }

    $errorCode = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorCode === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($errorCode !== UPLOAD_ERR_OK) {
        throw new \RuntimeException('Upload error code: ' . $errorCode);
    }

    $tmpName = (string)($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new \RuntimeException('Digital file upload was not received correctly.');
    }

    $cmsSettings = function_exists('readCmsSettings') ? readCmsSettings() : [];
    $maxMb = max(1, min(512, (int)($cmsSettings['max_upload_mb'] ?? 64)));
    $maxSize = $maxMb * 1024 * 1024;
    $size = (int)($file['size'] ?? 0);
    if ($size > $maxSize) {
        throw new \RuntimeException('File exceeds ' . $maxMb . 'MB limit.');
    }

    // Detect real MIME type from the binary content.
    $finfo = new \finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string)$finfo->file($tmpName);

    // Allowlist: common digital product delivery types.
    $allowedMimes = [
        'application/zip',
        'application/x-zip-compressed',
        'application/octet-stream',
        'application/pdf',
        'application/epub+zip',
        'audio/mpeg',
        'audio/mp4',
        'audio/ogg',
        'video/mp4',
        'video/x-m4v',
        'image/svg+xml',
        'text/plain',
        'application/json',
        'application/x-tar',
        'application/gzip',
        'application/x-bzip2',
        'application/x-7z-compressed',
        'application/x-rar-compressed',
    ];
    if (!in_array($mimeType, $allowedMimes, true)) {
        throw new \RuntimeException('File type not allowed for digital products: ' . $mimeType);
    }

    // Reject obviously dangerous file signatures (PHP headers, shell scripts, etc.).
    if (function_exists('cmsCheckDangerousFileSignature')) {
        $danger = cmsCheckDangerousFileSignature($tmpName);
        if ($danger !== null) {
            throw new \RuntimeException($danger);
        }
    }

    $originalName = basename((string)($file['name'] ?? 'file'));
    $ext = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
    $safeExts = ['zip', 'pdf', 'epub', 'mp3', 'm4a', 'ogg', 'mp4', 'm4v', 'svg', 'txt', 'json', 'tar', 'gz', 'bz2', '7z', 'rar'];
    if (!in_array($ext, $safeExts, true)) {
        $ext = 'bin';
    }

    // Store in a private directory: storage/digital/{tenantId?}/{year}/{month}/
    $tid = app()->tenant()->current();
    $subDir = ($tid !== null ? 't' . $tid . '/' : '') . date('Y') . '/' . date('m');
    $storageBase = STORAGE_PATH . '/digital';
    $uploadDir = $storageBase . '/' . $subDir;

    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0750, true) && !is_dir($uploadDir)) {
            throw new \RuntimeException('Could not create digital file storage directory.');
        }
    }

    // Protect the directory from direct web access.
    $htaccess = $storageBase . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Require all denied\n");
    }

    $filename = date('Ymd_His') . '_' . substr(bin2hex(random_bytes(8)), 0, 12) . '.' . $ext;
    $relativePath = $subDir . '/' . $filename;
    $destPath = $storageBase . '/' . $relativePath;

    if (!move_uploaded_file($tmpName, $destPath)) {
        throw new \RuntimeException('Failed to save digital file.');
    }

    write_log('Digital product file uploaded: ' . $relativePath, 'info', [
        'module'        => 'ecommerce',
        'uploaded_by'   => $uploadedBy,
        'original_name' => $originalName,
    ]);

    return [
        'file_path'     => $relativePath,
        'original_name' => $originalName,
        'mime_type'     => $mimeType,
        'size'          => $size,
    ];
}
