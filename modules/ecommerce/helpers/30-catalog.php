<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Product catalog, CRUD & relations (helpers/30-catalog.php)
//
// Products are cms_content rows with type='product'.
// The ecommerce preset (pricing + inventory + media_gallery) is
// auto-attached on product creation via cmsApplyEntityPreset().
//
// Image/gallery/storefront helpers → 36-storefront.php
// Inventory & WMS helpers           → 31-inventory.php
// ─────────────────────────────────────────────────────────────────────────

/**
 * List products with optional filters.
 *
 * @param array $filters  category_id, search, status, limit, offset, order_by
 * @return array  ['items' => [...], 'total' => int]
 */
function ecProductList(array $filters = []): array
{
    // ── Cache layer ──────────────────────────────────────────────
    if (function_exists('ecCacheEnabled') && ecCacheEnabled()) {
        $__cacheKey = ecCacheKeyForProductList($filters);
        $__cached   = ecCacheGet($__cacheKey);
        if ($__cached !== null) {
            return $__cached;
        }
    }

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
    $joinParts = [];
    $joinParams = [];

    if ($status !== '') {
        $where[]  = 'c.status = ?';
        $params[] = $status;
    }

    if ($search !== '') {
        $where[]  = '(c.title LIKE ? OR c.excerpt LIKE ?)';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }

    if (!empty($categoryIds)) {
        $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
        $joinParts[] = "INNER JOIN cms_content_categories cc ON cc.content_id = c.id AND cc.category_id IN ($placeholders)";
        $joinParams = array_merge($joinParams, $categoryIds);
    }

    $attributeFilterSql = function_exists('ecProductAttributeFilterSql')
        ? ecProductAttributeFilterSql($filters['attribute_filters'] ?? $filters['attributes'] ?? [])
        : ['join' => '', 'params' => []];
    if (($attributeFilterSql['join'] ?? '') !== '') {
        $joinParts[] = (string)$attributeFilterSql['join'];
        $joinParams = array_merge($joinParams, (array)($attributeFilterSql['params'] ?? []));
    }

    // Multi-store product filter.
    // store_id alone (storefront): global products + explicitly visible store products (LEFT JOIN).
    // store_id + store_owned_only (store admin): only products explicitly linked to the store (INNER JOIN).
    $storeIdFilter  = isset($filters['store_id']) ? max(0, (int)$filters['store_id']) : 0;
    $storeOwnedOnly = !empty($filters['store_owned_only']);
    if ($storeIdFilter > 0) {
        if ($storeOwnedOnly) {
            $joinParts[] = 'INNER JOIN ec_store_product_overrides store_po ON store_po.product_id = c.id AND store_po.store_id = ? AND store_po.is_visible = 1';
            $joinParams[] = $storeIdFilter;
        } else {
            $joinParts[] = 'LEFT JOIN ec_store_product_overrides store_po ON store_po.product_id = c.id AND store_po.store_id = ?';
            $joinParams[] = $storeIdFilter;
            $where[] = '(store_po.id IS NULL OR store_po.is_visible = 1)';
        }
    }

    $join = implode(' ', $joinParts);
    $params = array_merge($joinParams, $params);

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
        $reviewSummaryMap = function_exists('ecReviewSummaryForProducts')
            ? ecReviewSummaryForProducts($productIds)
            : [];

        // Batch load capabilities and digital meta
        $pricingMap = [];
        $inventoryMap = [];
        $digitalMap = [];
        $externalMetaMap = [];
        $subscriptionMetaMap = [];
        
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

            $externalRows = $db->query(
                "SELECT content_id, meta_key, meta_value
                 FROM cms_content_meta
                 WHERE meta_key IN ('_is_external_product','_external_product_url','_external_product_button_text')
                   AND content_id IN ($idsCsv)",
                $productIds
            )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            foreach ($externalRows as $externalRow) {
                $contentId = (int)($externalRow['content_id'] ?? 0);
                if ($contentId < 1) {
                    continue;
                }
                if (!isset($externalMetaMap[$contentId])) {
                    $externalMetaMap[$contentId] = [];
                }
                $externalMetaMap[$contentId][(string)($externalRow['meta_key'] ?? '')] = (string)($externalRow['meta_value'] ?? '');
            }

            $subscriptionRows = $db->query(
                "SELECT content_id, meta_key, meta_value
                   FROM cms_content_meta
                  WHERE meta_key IN ('_is_subscription','_subscription_interval_unit','_subscription_interval_count','_subscription_trial_days','_subscription_max_cycles','_subscription_grace_period_days')
                    AND content_id IN ($idsCsv)",
                $productIds
            )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            foreach ($subscriptionRows as $subscriptionRow) {
                $contentId = (int)($subscriptionRow['content_id'] ?? 0);
                if ($contentId < 1) {
                    continue;
                }
                if (!isset($subscriptionMetaMap[$contentId])) {
                    $subscriptionMetaMap[$contentId] = [];
                }
                $subscriptionMetaMap[$contentId][(string)($subscriptionRow['meta_key'] ?? '')] = (string)($subscriptionRow['meta_value'] ?? '');
            }
        }

        // Read settings once before loop — resolve per-store when a store filter is active
        $catalogStoreId = isset($filters['store_id']) ? max(0, (int)$filters['store_id']) : 0;
        $catalogStore = $catalogStoreId > 0 && function_exists('ecStoreById') ? ecStoreById($catalogStoreId) : null;
        $currencySetting = ecStoreAwareSetting('currency', $catalogStore, 'USD');
        $currencySymbol = (string)ecStoreAwareCurrencySymbol($catalogStore);
        $lowStockThreshold = (int)ecStoreAwareSetting('low_stock_threshold', $catalogStore, 5);
        ecWmsInventorySnapshotMapForSkus(array_map(
            static fn(array $config): string => (string)($config['sku'] ?? ''),
            $inventoryMap
        ));

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

            $row['inventory'] = ecProductInventoryStateFromConfig(
                $inventoryMap[$id] ?? [],
                (bool)($digitalMap[$id] ?? false),
                $lowStockThreshold
            );
            $external = ecProductExternalMetaFromMetaMap($externalMetaMap[$id] ?? []);
            $subscription = ecProductSubscriptionMetaFromMetaMap($subscriptionMetaMap[$id] ?? [], $row['pricing']);
            $row['is_external_product'] = $external['is_external_product'];
            $row['external_product_url'] = $external['external_product_url'];
            $row['external_product_button_text'] = $external['external_product_button_text'];
            $row['product_type'] = $external['product_type'];
            $row['is_subscription'] = $subscription['is_subscription'];
            $row['subscription_interval_unit'] = $subscription['subscription_interval_unit'];
            $row['subscription_interval_count'] = $subscription['subscription_interval_count'];
            $row['subscription_trial_days'] = $subscription['subscription_trial_days'];
            $row['subscription_max_cycles'] = $subscription['subscription_max_cycles'];
            $row['subscription_grace_period_days'] = $subscription['subscription_grace_period_days'];
            $row['subscription_summary'] = $subscription['subscription_summary'];
            if (!$row['is_external_product'] && $subscription['is_subscription']) {
                $row['product_type'] = 'subscription';
            }
            $row['review_summary'] = $reviewSummaryMap[$id] ?? ecReviewDefaultSummary();
        }
        unset($row);

        // Phase 1 multi-store: attach store assignment data.
        if (count($productIds) > 0) {
            $storeAssignmentMap = ecProductStoreAssignmentMap($productIds);
            foreach ($rows as &$row) {
                $myStores = $storeAssignmentMap[(int)($row['id'] ?? 0)] ?? [];
                $row['store_id'] = $myStores[0]['id'] ?? 0;
                if (!empty($filters['with_store'])) {
                    $row['stores'] = $myStores;
                }
            }
            unset($row);
        }

        $result = ['items' => $rows, 'total' => $total];

        // ── Cache store ──────────────────────────────────────────
        if (function_exists('ecCacheEnabled') && ecCacheEnabled() && isset($__cacheKey)) {
            $categoryId = $categoryIds[0] ?? null;
            $storeId    = isset($filters['store_id']) ? (int)$filters['store_id'] : null;
            ecCacheSet($__cacheKey, $result, ecCacheTagsForListing($categoryId, $storeId));
        }

        return $result;
    } catch (\Throwable $e) {
        write_log('ecProductList error: ' . $e->getMessage(), 'error', ['module' => 'ecommerce']);
        return ['items' => [], 'total' => 0];
    }
}

/**
 * Batch-load store assignments for a list of product IDs.
 * Returns a map of product_id => [['id', 'name', 'code', 'slug', 'is_visible'], ...]
 */
function ecProductStoreAssignmentMap(array $productIds): array
{
    if (empty($productIds)) {
        return [];
    }
    $ids = array_values(array_map('intval', $productIds));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    try {
        $rows = ecDb()->query(
            "SELECT po.product_id, po.store_id, po.is_visible,
                    s.name, s.code, s.slug
             FROM ec_store_product_overrides po
             INNER JOIN ec_stores s ON s.id = po.store_id
             WHERE po.product_id IN ($placeholders)
             ORDER BY s.name ASC",
            $ids
        )->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable) {
        return [];
    }
    $map = [];
    foreach ($ids as $pid) {
        $map[$pid] = [];
    }
    foreach ($rows as $row) {
        $pid = (int)($row['product_id'] ?? 0);
        if (!isset($map[$pid])) {
            $map[$pid] = [];
        }
        $map[$pid][] = [
            'id'         => (int)($row['store_id'] ?? 0),
            'name'       => (string)($row['name'] ?? ''),
            'code'       => (string)($row['code'] ?? ''),
            'slug'       => (string)($row['slug'] ?? ''),
            'is_visible' => (bool)($row['is_visible'] ?? true),
        ];
    }
    return $map;
}

/**
 * Save store assignments for a product.
 * $storeIds — array of store IDs the product should be visible in.
 * Products assigned to no store are visible in all stores (global default).
 * Passing an empty array removes all store-specific overrides.
 */
function ecProductSaveStoreAssignments(int $productId, array $storeIds): void
{
    $db = ecDb();
    // Delete all existing overrides for this product.
    $db->query('DELETE FROM ec_store_product_overrides WHERE product_id = ?', [$productId]);
    // Re-insert selected stores as visible.
    foreach ($storeIds as $storeId) {
        $sid = (int)$storeId;
        if ($sid <= 0) {
            continue;
        }
        $db->query(
            'INSERT INTO ec_store_product_overrides (store_id, product_id, is_visible) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE is_visible = 1',
            [$sid, $productId]
        );
    }
}

/**
 * Get a single product by ID with all context.
 */
function ecProductGet(int $id, bool $includeRelations = true): ?array
{
    // ── Cache layer ──────────────────────────────────────────────
    $__ecCacheKey = 'ec:product:id:' . $id . ':rel:' . ($includeRelations ? '1' : '0');
    if (function_exists('ecCacheEnabled') && ecCacheEnabled()) {
        $__cached = ecCacheGet($__ecCacheKey);
        if ($__cached !== null) {
            return $__cached;
        }
    }

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
        $row['review_summary'] = function_exists('ecReviewSummary') ? ecReviewSummary($id) : ecReviewDefaultSummary();
        $row['reviews'] = function_exists('ecReviewList')
            ? (ecReviewList([
                'product_id' => $id,
                'status' => 'approved',
                'limit' => 10,
                'offset' => 0,
            ])['items'] ?? [])
            : [];
        $row['attributes'] = function_exists('ecProductAttributes') ? ecProductAttributes($id) : [];
        $row['relation_ids'] = ecProductRelationIds($id);
        $row['bundle_children'] = $includeRelations ? ecProductBundleChildren($id, ['published_only' => false]) : [];
        $row['grouped_children'] = $includeRelations ? ecProductGroupedChildren($id, ['published_only' => false]) : [];
        $row['related_products'] = [];
        $row['upsell_products'] = [];
        $row['cross_sell_products'] = [];
        $row['relation_sections'] = [];

        if ($includeRelations) {
            $row['related_products'] = ecProductRecommendationCatalogItems([$id], 'related', [
                'exclude_ids' => [$id],
                'limit' => 4,
            ]);
            $row['upsell_products'] = ecProductRecommendationCatalogItems([$id], 'upsell', [
                'exclude_ids' => [$id],
                'limit' => 4,
            ]);
            $row['cross_sell_products'] = ecProductRecommendationCatalogItems([$id], 'cross_sell', [
                'exclude_ids' => [$id],
                'limit' => 4,
            ]);
            $row['relation_sections'] = ecProductBuildRecommendationSections([
                'upsell' => $row['upsell_products'],
                'related' => $row['related_products'],
            ], ['upsell', 'related']);
        }

        // Digital license meta
        try {
            $metaStmt = $db->query(
                "SELECT meta_key, meta_value FROM cms_content_meta
                 WHERE content_id = ? AND meta_key IN ('_is_digital','_license_module','_license_tier','_license_duration_days','_download_file_path','_download_file_name','_tax_class','seo_title','seo_description','_builder_seo_settings','_is_external_product','_external_product_url','_external_product_button_text','_is_subscription','_subscription_interval_unit','_subscription_interval_count','_subscription_trial_days','_subscription_max_cycles','_subscription_grace_period_days','_is_membership_product','_membership_tier','_membership_duration_days','_required_membership_tiers','_product_addons','_booking_enabled','_booking_duration_minutes','_booking_notice_hours','_booking_available_weekdays','_booking_time_slots')",
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
        $row['tax_class']             = ecProductNormalizeTaxClass((string)($metaMap['_tax_class'] ?? 'standard'));
        $external = ecProductExternalMetaFromMetaMap($metaMap);
        $subscription = ecProductSubscriptionMetaFromMetaMap($metaMap, $row['pricing']);
        $membership = function_exists('ecProductMembershipMetaFromMetaMap')
            ? ecProductMembershipMetaFromMetaMap($metaMap)
            : ['is_membership_product' => false, 'membership_tier' => '', 'membership_duration_days' => 365, 'required_membership_tiers' => [], 'membership_summary' => []];
        $addons = function_exists('ecProductAddonConfigFromMetaMap')
            ? ecProductAddonConfigFromMetaMap($metaMap)
            : [];
        $booking = function_exists('ecProductBookingConfigFromMetaMap')
            ? ecProductBookingConfigFromMetaMap($metaMap)
            : ['enabled' => false];
        $row['is_external_product']   = $external['is_external_product'];
        $row['external_product_url']  = $external['external_product_url'];
        $row['external_product_button_text'] = $external['external_product_button_text'];
        $row['product_type']          = $external['product_type'];
        $row['is_subscription']       = $subscription['is_subscription'];
        $row['subscription_interval_unit'] = $subscription['subscription_interval_unit'];
        $row['subscription_interval_count'] = $subscription['subscription_interval_count'];
        $row['subscription_trial_days'] = $subscription['subscription_trial_days'];
        $row['subscription_max_cycles'] = $subscription['subscription_max_cycles'];
        $row['subscription_grace_period_days'] = $subscription['subscription_grace_period_days'];
        $row['subscription_summary']   = $subscription['subscription_summary'];
        $row['is_membership_product'] = $membership['is_membership_product'];
        $row['membership_tier'] = $membership['membership_tier'];
        $row['membership_duration_days'] = $membership['membership_duration_days'];
        $row['required_membership_tiers'] = $membership['required_membership_tiers'];
        $row['membership_summary'] = $membership['membership_summary'];
        $row['addons'] = $addons;
        $row['booking'] = $booking;
        if (!$row['is_external_product'] && is_array($row['bundle_children'] ?? null) && $row['bundle_children'] !== []) {
            $row['product_type'] = 'bundle';
        } elseif (!$row['is_external_product'] && $row['is_membership_product']) {
            $row['product_type'] = 'membership';
        } elseif (!$row['is_external_product'] && $row['is_subscription']) {
            $row['product_type'] = 'subscription';
        }
        $row['bundle_summary']        = ecProductBundleSummary($row);
        $seo = ecProductSeoFromMetaMap($metaMap);
        $row['seo_title']             = $seo['seo_title'];
        $row['seo_description']       = $seo['seo_description'];
        $row['seo_canonical_url']     = $seo['seo_canonical_url'];
        $row['seo_og_image']          = $seo['seo_og_image'];
        $row['seo_builder_settings']  = $seo['seo_builder_settings'];

                $storeMap = function_exists("ecProductStoreAssignmentMap") ? ecProductStoreAssignmentMap([$id]) : [];
        $row["store_assignments"] = $storeMap[$id] ?? [];
        $row["store_id"] = $row["store_assignments"][0]["id"] ?? 0;

        // ── Cache store ──────────────────────────────────────────
        if (function_exists('ecCacheEnabled') && ecCacheEnabled()) {
            ecCacheSet($__ecCacheKey, $row, ecCacheTagsForProduct($id, $row['slug'] ?? null));
        }

        return $row;
    } catch (\Throwable $e) {
        write_log('ecProductGet error: ' . $e->getMessage(), 'error', ['module' => 'ecommerce']);
        return null;
    }
}

/**
 * Get a product by slug.
 */
function ecProductGetBySlug(string $slug, bool $includeRelations = true): ?array
{
    // ── Cache layer (slug alias) ─────────────────────────────────
    $__ecSlugKey = 'ec:product:slug:' . $slug . ':rel:' . ($includeRelations ? '1' : '0');
    if (function_exists('ecCacheEnabled') && ecCacheEnabled()) {
        $__cached = ecCacheGet($__ecSlugKey);
        if ($__cached !== null) {
            return $__cached;
        }
    }

    $db = ecDb();
    try {
        $row = $db->query(
            "SELECT id FROM cms_content WHERE slug = ? AND type = 'product' AND deleted_at IS NULL LIMIT 1",
            [$slug]
        )->fetch(\PDO::FETCH_ASSOC);

        $result = $row ? ecProductGet((int)$row['id'], $includeRelations) : null;

        // Cache the slug-based result separately so slug lookups hit cache too
        if ($result !== null && function_exists('ecCacheEnabled') && ecCacheEnabled()) {
            ecCacheSet($__ecSlugKey, $result, ecCacheTagsForProduct((int)$row['id'], $slug));
        }

        return $result;
    } catch (\Throwable $e) {
        return null;
    }
}

function ecProductDefaultRelationIds(): array
{
    return [
        'related' => [],
        'upsell' => [],
        'cross_sell' => [],
    ];
}

function ecProductRelationMetadata(): array
{
    return [
        'upsell' => [
            'title' => 'You may also like',
            'description' => 'Higher-value suggestions that fit this product.',
        ],
        'related' => [
            'title' => 'Related products',
            'description' => 'Similar items customers browse next.',
        ],
        'cross_sell' => [
            'title' => 'Pair with your cart',
            'description' => 'Complementary products for the current cart.',
        ],
    ];
}

function ecProductNormalizeRelationType(string $relationType): ?string
{
    $relationType = trim($relationType);
    return array_key_exists($relationType, ecProductRelationMetadata()) ? $relationType : null;
}

function ecProductNormalizeRelationIds(mixed $ids, int $excludeId = 0): array
{
    if (!is_array($ids)) {
        $ids = $ids === null || $ids === '' ? [] : [$ids];
    }

    $normalized = [];
    foreach ($ids as $id) {
        $value = (int)$id;
        if ($value < 1 || $value === $excludeId || in_array($value, $normalized, true)) {
            continue;
        }
        $normalized[] = $value;
    }

    return $normalized;
}

function ecProductRelationTableExistsDirect(): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }

    try {
        $stmt = app()->db()->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->execute(['ec_product_relations']);
        $count = (int)$stmt->fetchColumn();
        $exists = $count > 0;
    } catch (\Throwable $e) {
        $exists = false;
    }

    return $exists;
}

function ecProductRelationStorageAvailable(): bool
{
    return ecProductRelationTableExistsDirect();
}

function ecProductRelationSelectionsFromInput(array $input, int $excludeProductId = 0): array
{
    return [
        'related' => ecProductNormalizeRelationIds($input['related_product_ids'] ?? [], $excludeProductId),
        'upsell' => ecProductNormalizeRelationIds($input['upsell_product_ids'] ?? [], $excludeProductId),
        'cross_sell' => ecProductNormalizeRelationIds($input['cross_sell_product_ids'] ?? [], $excludeProductId),
    ];
}

function ecProductGroupedTableExistsDirect(): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }

    try {
        $stmt = app()->db()->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->execute(['ec_product_group_items']);
        $exists = (int)$stmt->fetchColumn() > 0;
    } catch (\Throwable $e) {
        $exists = false;
    }

    return $exists;
}

function ecProductGroupedStorageAvailable(): bool
{
    return ecProductGroupedTableExistsDirect();
}

function ecProductNormalizeGroupedChildren(mixed $selectedIds, mixed $qtyByProduct = [], int $excludeProductId = 0): array
{
    $normalizedIds = ecProductNormalizeRelationIds($selectedIds, $excludeProductId);
    $qtyByProduct = is_array($qtyByProduct) ? $qtyByProduct : [];
    $children = [];

    foreach ($normalizedIds as $sortOrder => $productId) {
        $children[] = [
            'product_id' => $productId,
            'qty' => max(1, (int)($qtyByProduct[$productId] ?? 1)),
            'sort_order' => $sortOrder,
        ];
    }

    return $children;
}

function ecProductGroupedSelectionsFromInput(array $input, int $excludeProductId = 0): array
{
    return ecProductNormalizeGroupedChildren(
        $input['grouped_product_ids'] ?? [],
        $input['grouped_product_qty'] ?? [],
        $excludeProductId
    );
}

function ecProductGroupedSelectionLookup(array $children): array
{
    $lookup = [];
    foreach ($children as $child) {
        $productId = (int)($child['product_id'] ?? 0);
        if ($productId < 1) {
            continue;
        }

        $lookup[$productId] = max(1, (int)($child['qty'] ?? 1));
    }

    return $lookup;
}

function ecProductGroupedChildSelections(int $productId): array
{
    if ($productId < 1 || !ecProductGroupedStorageAvailable()) {
        return [];
    }

    try {
        $rows = ecDb()->query(
            'SELECT child_product_id, child_qty, sort_order FROM ec_product_group_items WHERE product_id = ? ORDER BY sort_order ASC, id ASC',
            [$productId]
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }

    $children = [];
    foreach ($rows as $row) {
        $childProductId = (int)($row['child_product_id'] ?? 0);
        if ($childProductId < 1) {
            continue;
        }

        $children[] = [
            'product_id' => $childProductId,
            'qty' => max(1, (int)($row['child_qty'] ?? 1)),
            'sort_order' => max(0, (int)($row['sort_order'] ?? 0)),
        ];
    }

    return $children;
}

function ecProductGroupedChildren(int $productId, array $options = []): array
{
    $children = ecProductGroupedChildSelections($productId);
    if ($children === []) {
        return [];
    }

    $publishedOnly = !array_key_exists('published_only', $options) || (bool)$options['published_only'];
    $resolved = [];

    foreach ($children as $child) {
        $childProduct = ecProductGet((int)$child['product_id'], false);
        if (!is_array($childProduct)) {
            continue;
        }
        if ($publishedOnly && (string)($childProduct['status'] ?? '') !== 'published') {
            continue;
        }

        $childProduct['grouped_qty'] = max(1, (int)($child['qty'] ?? 1));
        $childProduct['grouped_parent_id'] = $productId;
        $resolved[] = $childProduct;
    }

    return $resolved;
}

function ecProductSaveGroupedChildren(int $productId, array $children): void
{
    if ($productId < 1 || !ecProductGroupedStorageAvailable()) {
        return;
    }

    $qtyByProduct = [];
    $selectedIds = [];
    foreach ($children as $child) {
        if (!is_array($child)) {
            continue;
        }

        $childProductId = (int)($child['product_id'] ?? 0);
        if ($childProductId < 1) {
            continue;
        }

        $selectedIds[] = $childProductId;
        $qtyByProduct[$childProductId] = max(1, (int)($child['qty'] ?? 1));
    }

    $normalized = ecProductNormalizeGroupedChildren($selectedIds, $qtyByProduct, $productId);
    $db = ecDb();
    $db->execute('DELETE FROM ec_product_group_items WHERE product_id = ?', [$productId]);

    foreach ($normalized as $sortOrder => $child) {
        $db->execute(
            'INSERT INTO ec_product_group_items (product_id, child_product_id, child_qty, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())',
            [$productId, (int)$child['product_id'], max(1, (int)$child['qty']), $sortOrder]
        );
    }
}

function ecProductBundleTableExistsDirect(): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }

    try {
        $stmt = app()->db()->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->execute(['ec_product_bundle_items']);
        $exists = (int)$stmt->fetchColumn() > 0;
    } catch (\Throwable $e) {
        $exists = false;
    }

    return $exists;
}

function ecProductBundleStorageAvailable(): bool
{
    return ecProductBundleTableExistsDirect();
}

function ecProductNormalizeBundleChildren(mixed $selectedIds, mixed $qtyByProduct = [], int $excludeProductId = 0): array
{
    $normalizedIds = ecProductNormalizeRelationIds($selectedIds, $excludeProductId);
    $qtyByProduct = is_array($qtyByProduct) ? $qtyByProduct : [];
    $children = [];

    foreach ($normalizedIds as $sortOrder => $productId) {
        $children[] = [
            'product_id' => $productId,
            'qty' => max(1, (int)($qtyByProduct[$productId] ?? 1)),
            'sort_order' => $sortOrder,
        ];
    }

    return $children;
}

function ecProductBundleSelectionsFromInput(array $input, int $excludeProductId = 0): array
{
    return ecProductNormalizeBundleChildren(
        $input['bundle_product_ids'] ?? [],
        $input['bundle_product_qty'] ?? [],
        $excludeProductId
    );
}

function ecProductBundleSelectionLookup(array $children): array
{
    $lookup = [];
    foreach ($children as $child) {
        $productId = (int)($child['product_id'] ?? 0);
        if ($productId < 1) {
            continue;
        }

        $lookup[$productId] = max(1, (int)($child['qty'] ?? 1));
    }

    return $lookup;
}

function ecProductBundleChildSelections(int $productId): array
{
    if ($productId < 1 || !ecProductBundleStorageAvailable()) {
        return [];
    }

    try {
        $rows = ecDb()->query(
            'SELECT child_product_id, child_qty, sort_order FROM ec_product_bundle_items WHERE product_id = ? ORDER BY sort_order ASC, id ASC',
            [$productId]
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }

    $children = [];
    foreach ($rows as $row) {
        $childProductId = (int)($row['child_product_id'] ?? 0);
        if ($childProductId < 1) {
            continue;
        }

        $children[] = [
            'product_id' => $childProductId,
            'qty' => max(1, (int)($row['child_qty'] ?? 1)),
            'sort_order' => max(0, (int)($row['sort_order'] ?? 0)),
        ];
    }

    return $children;
}

function ecProductBundleChildren(int $productId, array $options = []): array
{
    $children = ecProductBundleChildSelections($productId);
    if ($children === []) {
        return [];
    }

    $publishedOnly = !array_key_exists('published_only', $options) || (bool)$options['published_only'];
    $resolved = [];

    foreach ($children as $child) {
        $childProduct = ecProductGet((int)$child['product_id'], false);
        if (!is_array($childProduct)) {
            continue;
        }
        if ($publishedOnly && (string)($childProduct['status'] ?? '') !== 'published') {
            continue;
        }

        $childProduct['bundle_qty'] = max(1, (int)($child['qty'] ?? 1));
        $childProduct['bundle_parent_id'] = $productId;
        $resolved[] = $childProduct;
    }

    return $resolved;
}

function ecProductSaveBundleChildren(int $productId, array $children): void
{
    if ($productId < 1 || !ecProductBundleStorageAvailable()) {
        return;
    }

    $qtyByProduct = [];
    $selectedIds = [];
    foreach ($children as $child) {
        if (!is_array($child)) {
            continue;
        }

        $childProductId = (int)($child['product_id'] ?? 0);
        if ($childProductId < 1) {
            continue;
        }

        $selectedIds[] = $childProductId;
        $qtyByProduct[$childProductId] = max(1, (int)($child['qty'] ?? 1));
    }

    $normalized = ecProductNormalizeBundleChildren($selectedIds, $qtyByProduct, $productId);
    $db = ecDb();
    $db->execute('DELETE FROM ec_product_bundle_items WHERE product_id = ?', [$productId]);

    foreach ($normalized as $sortOrder => $child) {
        $db->execute(
            'INSERT INTO ec_product_bundle_items (product_id, child_product_id, child_qty, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())',
            [$productId, (int)$child['product_id'], max(1, (int)$child['qty']), $sortOrder]
        );
    }
}

function ecProductBundleSummary(array $product): array
{
    $currencySymbol = (string)ecSettings('currency_symbol');
    $children = is_array($product['bundle_children'] ?? null) ? $product['bundle_children'] : [];
    $childSubtotal = 0.0;
    $itemCount = 0;
    $unavailableCount = 0;

    foreach ($children as $child) {
        if (!is_array($child)) {
            continue;
        }

        $childQty = max(1, (int)($child['bundle_qty'] ?? $child['qty'] ?? 1));
        $pricing = is_array($child['pricing'] ?? null) ? $child['pricing'] : [];
        $inventory = is_array($child['inventory'] ?? null) ? $child['inventory'] : [];
        $activePrice = (float)($pricing['active_price'] ?? $pricing['price'] ?? 0);
        $childSubtotal += $activePrice * $childQty;
        $itemCount += $childQty;

        if (!empty($inventory['out_of_stock']) || (array_key_exists('in_stock', $inventory) && empty($inventory['in_stock']))) {
            $unavailableCount++;
        }
    }

    $childSubtotal = round($childSubtotal, 2);
    $pricing = is_array($product['pricing'] ?? null) ? $product['pricing'] : [];
    $bundleTotal = (float)($pricing['active_price'] ?? $pricing['price'] ?? 0);
    if ($bundleTotal <= 0 || ($childSubtotal > 0 && $bundleTotal > $childSubtotal)) {
        $bundleTotal = $childSubtotal;
    }

    $bundleTotal = round($bundleTotal, 2);
    $savings = max(0.0, round($childSubtotal - $bundleTotal, 2));

    return [
        'child_count' => count($children),
        'item_count' => $itemCount,
        'child_subtotal' => $childSubtotal,
        'child_subtotal_fmt' => $currencySymbol . number_format($childSubtotal, 2),
        'bundle_total' => $bundleTotal,
        'bundle_total_fmt' => $currencySymbol . number_format($bundleTotal, 2),
        'savings' => $savings,
        'savings_fmt' => $currencySymbol . number_format($savings, 2),
        'has_savings' => $savings > 0,
        'unavailable_count' => $unavailableCount,
        'all_in_stock' => count($children) > 0 && $unavailableCount === 0,
    ];
}

function ecProductRelationIds(int $productId): array
{
    $relations = ecProductDefaultRelationIds();
    if ($productId < 1 || !ecProductRelationStorageAvailable()) {
        return $relations;
    }

    try {
        $rows = ecDb()->query(
            'SELECT relation_type, related_product_id FROM ec_product_relations WHERE product_id = ? ORDER BY relation_type ASC, sort_order ASC, id ASC',
            [$productId]
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return $relations;
    }

    foreach ($rows as $row) {
        $relationType = ecProductNormalizeRelationType((string)($row['relation_type'] ?? ''));
        $relatedProductId = (int)($row['related_product_id'] ?? 0);
        if ($relationType === null || $relatedProductId < 1) {
            continue;
        }
        $relations[$relationType][] = $relatedProductId;
    }

    return $relations;
}

function ecProductSaveRelations(int $productId, array $relations): void
{
    if ($productId < 1 || !ecProductRelationStorageAvailable()) {
        return;
    }

    $db = ecDb();
    $normalized = ecProductDefaultRelationIds();
    foreach ($normalized as $relationType => $_unused) {
        $normalized[$relationType] = ecProductNormalizeRelationIds($relations[$relationType] ?? [], $productId);
    }

    foreach ($normalized as $relationType => $relationIds) {
        $db->execute(
            'DELETE FROM ec_product_relations WHERE product_id = ? AND relation_type = ?',
            [$productId, $relationType]
        );

        foreach ($relationIds as $sortOrder => $relatedProductId) {
            $db->execute(
                'INSERT INTO ec_product_relations (product_id, related_product_id, relation_type, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())',
                [$productId, $relatedProductId, $relationType, $sortOrder]
            );
        }
    }
}

function ecProductRecommendationCatalogItems(array $sourceProductIds, string $relationType, array $options = []): array
{
    $normalizedType = ecProductNormalizeRelationType($relationType);
    $sourceProductIds = ecProductNormalizeRelationIds($sourceProductIds);
    if ($normalizedType === null || $sourceProductIds === [] || !ecProductRelationStorageAvailable()) {
        return [];
    }

    $limit = min(12, max(1, (int)($options['limit'] ?? 4)));
    $excludeIds = ecProductNormalizeRelationIds($options['exclude_ids'] ?? []);
    $publishedOnly = !array_key_exists('published_only', $options) || (bool)$options['published_only'];
    $itemBaseUrl = trim((string)($options['item_base_url'] ?? '/ecommerce/shop'));

    $params = $sourceProductIds;
    $where = [
        'r.product_id IN (' . implode(',', array_fill(0, count($sourceProductIds), '?')) . ')',
        'r.relation_type = ?',
        "c.type = 'product'",
        'c.deleted_at IS NULL',
    ];
    $params[] = $normalizedType;

    if ($publishedOnly) {
        $where[] = "c.status = 'published'";
    }
    if ($excludeIds !== []) {
        $where[] = 'r.related_product_id NOT IN (' . implode(',', array_fill(0, count($excludeIds), '?')) . ')';
        $params = array_merge($params, $excludeIds);
    }

    try {
        $rows = ecDb()->query(
            'SELECT r.related_product_id FROM ec_product_relations r '
            . 'INNER JOIN cms_content c ON c.id = r.related_product_id '
            . 'WHERE ' . implode(' AND ', $where) . ' '
            . 'ORDER BY r.sort_order ASC, r.id ASC',
            $params
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }

    $relatedIds = [];
    foreach ($rows as $row) {
        $relatedProductId = (int)($row['related_product_id'] ?? 0);
        if ($relatedProductId < 1 || in_array($relatedProductId, $relatedIds, true)) {
            continue;
        }
        $relatedIds[] = $relatedProductId;
        if (count($relatedIds) >= $limit) {
            break;
        }
    }

    $products = [];
    foreach ($relatedIds as $relatedProductId) {
        $product = ecProductGet($relatedProductId, false);
        if (!is_array($product)) {
            continue;
        }
        if ($publishedOnly && ($product['status'] ?? 'draft') !== 'published') {
            continue;
        }
        $products[] = $product;
    }

    ecWmsInventoryWarmProductCollection($products);

    $items = [];
    foreach ($products as $product) {
        $items[] = ecBuildStorefrontCatalogItem($product, ['item_base_url' => $itemBaseUrl]);
    }

    return $items;
}

function ecProductBuildRecommendationSections(array $groups, array $orderedTypes = []): array
{
    $metadata = ecProductRelationMetadata();
    $orderedTypes = $orderedTypes !== [] ? $orderedTypes : array_keys($metadata);
    $sections = [];

    foreach ($orderedTypes as $relationType) {
        $normalizedType = ecProductNormalizeRelationType((string)$relationType);
        if ($normalizedType === null) {
            continue;
        }
        $items = is_array($groups[$normalizedType] ?? null) ? $groups[$normalizedType] : [];
        if ($items === []) {
            continue;
        }

        $sections[] = [
            'type' => $normalizedType,
            'title' => $metadata[$normalizedType]['title'],
            'description' => $metadata[$normalizedType]['description'],
            'items' => $items,
        ];
    }

    return $sections;
}

function ecProductRecommendationSectionsForProduct(int $productId): array
{
    if ($productId < 1) {
        return [];
    }

    return ecProductBuildRecommendationSections([
        'upsell' => ecProductRecommendationCatalogItems([$productId], 'upsell', [
            'exclude_ids' => [$productId],
            'limit' => 4,
        ]),
        'related' => ecProductRecommendationCatalogItems([$productId], 'related', [
            'exclude_ids' => [$productId],
            'limit' => 4,
        ]),
    ], ['upsell', 'related']);
}

function ecCartRecommendationSections(array $cartItems): array
{
    $productIds = [];
    foreach ($cartItems as $item) {
        $productId = (int)($item['product_id'] ?? 0);
        if ($productId > 0 && !in_array($productId, $productIds, true)) {
            $productIds[] = $productId;
        }
    }

    if ($productIds === []) {
        return [];
    }

    return ecProductBuildRecommendationSections([
        'cross_sell' => ecProductRecommendationCatalogItems($productIds, 'cross_sell', [
            'exclude_ids' => $productIds,
            'limit' => 4,
        ]),
    ], ['cross_sell']);
}

function ecProductAdminRelationOptions(int $excludeProductId = 0, array $selectedIds = []): array
{
    $selectedBundleChildren = is_array($selectedIds['bundle_children'] ?? null) ? $selectedIds['bundle_children'] : [];
    $selectedGroupedChildren = is_array($selectedIds['grouped_children'] ?? null) ? $selectedIds['grouped_children'] : [];
    $selectedIds = array_merge(ecProductDefaultRelationIds(), array_intersect_key($selectedIds, ecProductDefaultRelationIds()));
    $selectedBundleLookup = ecProductBundleSelectionLookup($selectedBundleChildren);
    $selectedGroupedLookup = ecProductGroupedSelectionLookup($selectedGroupedChildren);
    $selectedLookup = [
        'related' => array_fill_keys(ecProductNormalizeRelationIds($selectedIds['related'] ?? [], $excludeProductId), true),
        'upsell' => array_fill_keys(ecProductNormalizeRelationIds($selectedIds['upsell'] ?? [], $excludeProductId), true),
        'cross_sell' => array_fill_keys(ecProductNormalizeRelationIds($selectedIds['cross_sell'] ?? [], $excludeProductId), true),
    ];

    $params = [];
    $where = ["type = 'product'", 'deleted_at IS NULL'];
    if ($excludeProductId > 0) {
        $where[] = 'id <> ?';
        $params[] = $excludeProductId;
    }

    try {
        $rows = ecDb()->query(
            'SELECT id, title, slug, status FROM cms_content WHERE ' . implode(' AND ', $where) . ' ORDER BY title ASC, id ASC LIMIT 250',
            $params
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }

    $options = [];
    foreach ($rows as $row) {
        $productId = (int)($row['id'] ?? 0);
        if ($productId < 1) {
            continue;
        }

        $options[] = [
            'id' => $productId,
            'label' => trim((string)($row['title'] ?? 'Product')) . ' (' . trim((string)($row['status'] ?? 'draft')) . ')',
            'selected_bundle' => array_key_exists($productId, $selectedBundleLookup),
            'selected_bundle_qty' => (int)($selectedBundleLookup[$productId] ?? 1),
            'selected_related' => !empty($selectedLookup['related'][$productId]),
            'selected_upsell' => !empty($selectedLookup['upsell'][$productId]),
            'selected_cross_sell' => !empty($selectedLookup['cross_sell'][$productId]),
            'selected_grouped' => array_key_exists($productId, $selectedGroupedLookup),
            'selected_grouped_qty' => (int)($selectedGroupedLookup[$productId] ?? 1),
        ];
    }

    return $options;
}

function ecProductBridgeEventPayload(int $productId): array
{
    $product = ecProductGet($productId);
    if (!is_array($product)) {
        return [
            'id' => $productId,
            'product_id' => $productId,
        ];
    }

    $pricing = is_array($product['pricing'] ?? null) ? $product['pricing'] : [];
    $inventory = is_array($product['inventory'] ?? null) ? $product['inventory'] : [];
    $status = trim((string)($product['status'] ?? 'draft'));

    return [
        'id' => $productId,
        'product_id' => $productId,
        'title' => trim((string)($product['title'] ?? '')),
        'slug' => trim((string)($product['slug'] ?? '')),
        'excerpt' => trim((string)($product['excerpt'] ?? '')),
        'status' => $status,
        'is_active' => $status === 'published' ? 1 : 0,
        'sku' => trim((string)($inventory['sku'] ?? '')),
        'product_type' => trim((string)($product['product_type'] ?? 'physical')),
        'is_membership_product' => !empty($product['is_membership_product']),
        'membership_tier' => trim((string)($product['membership_tier'] ?? '')),
        'required_membership_tiers' => is_array($product['required_membership_tiers'] ?? null) ? array_values($product['required_membership_tiers']) : [],
        'booking_enabled' => !empty($product['booking']['enabled']),
        'addon_count' => is_array($product['addons'] ?? null) ? count($product['addons']) : 0,
        'track_stock' => (bool)($inventory['track_stock'] ?? false),
        'stock_qty' => array_key_exists('stock_qty', $inventory) ? (int)$inventory['stock_qty'] : 0,
        'price' => array_key_exists('price', $pricing) && $pricing['price'] !== null ? (float)$pricing['price'] : null,
        'sale_price' => array_key_exists('sale_price', $pricing) && $pricing['sale_price'] !== null ? (float)$pricing['sale_price'] : null,
    ];
}

function ecEmitProductEvent(string $eventKey, int $productId): void
{
    try {
        app()->events()->fire($eventKey, ecProductBridgeEventPayload($productId), 'ecommerce');
    } catch (\Throwable $e) {
        write_log('ecEmitProductEvent error: ' . $e->getMessage(), 'warning', [
            'module' => 'ecommerce',
            'event' => $eventKey,
            'product_id' => $productId,
        ]);
    }
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
    if (array_key_exists('price', $data) || array_key_exists('sale_price', $data)) {
        ecProductUpdatePricing($productId, [
            'price'      => isset($data['price']) ? (float)$data['price'] : null,
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

    if (is_array($data['attributes'] ?? null) && function_exists('ecProductSaveAttributes')) {
        ecProductSaveAttributes($productId, $data['attributes']);
    }

    if (is_array($data['relations'] ?? null)) {
        ecProductSaveRelations($productId, $data['relations']);
    }

    if (is_array($data['bundle_children'] ?? null)) {
        ecProductSaveBundleChildren($productId, $data['bundle_children']);
    }

    if (is_array($data['grouped_children'] ?? null)) {
        ecProductSaveGroupedChildren($productId, $data['grouped_children']);
    }

    ecProductSaveTaxClass($productId, $data['tax_class'] ?? 'standard');
    if (
        array_key_exists('is_external_product', $data)
        || array_key_exists('external_product_url', $data)
        || array_key_exists('external_product_button_text', $data)
    ) {
        ecProductSaveExternalMeta($productId, $data);
    }
    if (
        array_key_exists('is_subscription', $data)
        || array_key_exists('subscription_interval_unit', $data)
        || array_key_exists('subscription_interval_count', $data)
        || array_key_exists('subscription_trial_days', $data)
        || array_key_exists('subscription_max_cycles', $data)
        || array_key_exists('subscription_grace_period_days', $data)
    ) {
        ecProductSaveSubscriptionMeta($productId, $data);
    }
    if (
        array_key_exists('is_membership_product', $data)
        || array_key_exists('membership_tier', $data)
        || array_key_exists('membership_duration_days', $data)
        || array_key_exists('required_membership_tiers', $data)
        || array_key_exists('required_membership_tiers_text', $data)
    ) {
        ecProductSaveMembershipMeta($productId, $data);
    }
    if (array_key_exists('addon_lines', $data)) {
        ecProductSaveAddonMeta($productId, $data);
    }
    if (
        array_key_exists('booking_enabled', $data)
        || array_key_exists('booking_duration_minutes', $data)
        || array_key_exists('booking_notice_hours', $data)
        || array_key_exists('booking_available_weekdays', $data)
        || array_key_exists('booking_time_slots', $data)
    ) {
        ecProductSaveBookingMeta($productId, $data);
    }
    ecProductSaveSeoMeta($productId, $data);

    if (function_exists('cmsSyncMediaUsage')) {
        moduleWithContext('cms', static function () use ($productId, $featuredImageId): void {
            cmsSyncMediaUsage($productId, ['featured_image_id' => $featuredImageId], null);
        });
    }

    ecEmitProductEvent('ecommerce.product.created', $productId);

    // Invalidate listing caches (new product affects any listing)
    if (function_exists('ecCacheInvalidateByTags')) {
        ecCacheInvalidateByTags(['ec:type:product', 'ec:catalog']);
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

    if (array_key_exists('price', $data) || array_key_exists('sale_price', $data)) {
        ecProductUpdatePricing($id, $data);
    }

    if (isset($data['stock_qty']) || isset($data['sku']) || isset($data['track_stock'])) {
        ecProductUpdateInventory($id, $data);
    }

    if (isset($data['category_id'])) {
        ecProductAssignCategory($id, (int)$data['category_id']);
    }

    if (array_key_exists('attributes', $data) && is_array($data['attributes'] ?? null) && function_exists('ecProductSaveAttributes')) {
        ecProductSaveAttributes($id, $data['attributes']);
    }

    if (is_array($data['relations'] ?? null)) {
        ecProductSaveRelations($id, $data['relations']);
    }

    if (is_array($data['bundle_children'] ?? null)) {
        ecProductSaveBundleChildren($id, $data['bundle_children']);
    }

    if (is_array($data['grouped_children'] ?? null)) {
        ecProductSaveGroupedChildren($id, $data['grouped_children']);
    }

    if (array_key_exists('tax_class', $data)) {
        ecProductSaveTaxClass($id, $data['tax_class']);
    }

    if (
        array_key_exists('is_subscription', $data)
        || array_key_exists('subscription_interval_unit', $data)
        || array_key_exists('subscription_interval_count', $data)
        || array_key_exists('subscription_trial_days', $data)
        || array_key_exists('subscription_max_cycles', $data)
        || array_key_exists('subscription_grace_period_days', $data)
    ) {
        ecProductSaveSubscriptionMeta($id, $data);
    }
    if (
        array_key_exists('is_membership_product', $data)
        || array_key_exists('membership_tier', $data)
        || array_key_exists('membership_duration_days', $data)
        || array_key_exists('required_membership_tiers', $data)
        || array_key_exists('required_membership_tiers_text', $data)
    ) {
        ecProductSaveMembershipMeta($id, $data);
    }
    if (array_key_exists('addon_lines', $data)) {
        ecProductSaveAddonMeta($id, $data);
    }
    if (
        array_key_exists('booking_enabled', $data)
        || array_key_exists('booking_duration_minutes', $data)
        || array_key_exists('booking_notice_hours', $data)
        || array_key_exists('booking_available_weekdays', $data)
        || array_key_exists('booking_time_slots', $data)
    ) {
        ecProductSaveBookingMeta($id, $data);
    }

    if (
        array_key_exists('is_external_product', $data)
        || array_key_exists('external_product_url', $data)
        || array_key_exists('external_product_button_text', $data)
    ) {
        ecProductSaveExternalMeta($id, $data);
    }

    if (
        array_key_exists('seo_title', $data)
        || array_key_exists('seo_description', $data)
        || array_key_exists('seo_canonical_url', $data)
        || array_key_exists('seo_og_image', $data)
    ) {
        ecProductSaveSeoMeta($id, $data);
    }

    if (array_key_exists('featured_image_id', $data) && function_exists('cmsSyncMediaUsage')) {
        moduleWithContext('cms', static function () use ($id, $data): void {
            cmsSyncMediaUsage($id, ['featured_image_id' => $data['featured_image_id'] ?? null], null);
        });
    }

    ecEmitProductEvent('ecommerce.product.updated', $id);

    // Invalidate caches for this product and listings
    if (function_exists('ecCacheInvalidateProduct')) {
        ecCacheInvalidateProduct($id);
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

function ecProductExternalDefaults(): array
{
    return [
        'is_external_product' => false,
        'external_product_url' => '',
        'external_product_button_text' => 'Buy Externally',
        'product_type' => 'physical',
    ];
}

function ecProductExternalMetaFromMetaMap(array $metaMap): array
{
    $defaults = ecProductExternalDefaults();
    $isExternal = ($metaMap['_is_external_product'] ?? '0') === '1';
    $externalUrl = trim((string)($metaMap['_external_product_url'] ?? ''));
    $buttonText = trim((string)($metaMap['_external_product_button_text'] ?? ''));

    return [
        'is_external_product' => $isExternal && $externalUrl !== '',
        'external_product_url' => $externalUrl,
        'external_product_button_text' => $buttonText !== '' ? $buttonText : $defaults['external_product_button_text'],
        'product_type' => ($isExternal && $externalUrl !== '') ? 'external' : 'physical',
    ];
}

function ecProductSaveExternalMeta(int $productId, array $input): void
{
    $externalUrl = trim((string)($input['external_product_url'] ?? ''));
    $isExternal = !empty($input['is_external_product']) && $externalUrl !== '';
    $buttonText = trim((string)($input['external_product_button_text'] ?? ''));
    if ($buttonText === '') {
        $buttonText = (string)ecProductExternalDefaults()['external_product_button_text'];
    }

    $meta = [
        '_is_external_product' => $isExternal ? '1' : '0',
        '_external_product_url' => $externalUrl,
        '_external_product_button_text' => $buttonText,
    ];

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
        write_log('ecProductSaveExternalMeta error: ' . $e->getMessage(), 'error', [
            'module' => 'ecommerce',
            'product_id' => $productId,
        ]);
    }
}

function ecProductSeoDefaults(): array
{
    return [
        'seo_title' => '',
        'seo_description' => '',
        'seo_canonical_url' => '',
        'seo_og_image' => '',
    ];
}

function ecProductSeoFromMetaMap(array $metaMap): array
{
    $seo = ecProductSeoDefaults();
    $seo['seo_title'] = trim((string)($metaMap['seo_title'] ?? ''));
    $seo['seo_description'] = trim((string)($metaMap['seo_description'] ?? ''));

    $builderSeo = [];
    $rawBuilderSeo = trim((string)($metaMap['_builder_seo_settings'] ?? ''));
    if ($rawBuilderSeo !== '') {
        $decoded = json_decode($rawBuilderSeo, true);
        if (is_array($decoded)) {
            $builderSeo = $decoded;
        }
    }

    if ($seo['seo_title'] === '') {
        $seo['seo_title'] = trim((string)($builderSeo['metaTitle'] ?? ''));
    }
    if ($seo['seo_description'] === '') {
        $seo['seo_description'] = trim((string)($builderSeo['metaDescription'] ?? ''));
    }

    $seo['seo_canonical_url'] = trim((string)($builderSeo['canonicalUrl'] ?? ''));
    $seo['seo_og_image'] = trim((string)($builderSeo['ogImage'] ?? ''));
    $seo['seo_builder_settings'] = $builderSeo;

    return $seo;
}

function ecProductSaveSeoMeta(int $productId, array $input): void
{
    $seoTitle = trim((string)($input['seo_title'] ?? ''));
    $seoDescription = trim((string)($input['seo_description'] ?? ''));
    $seoCanonicalUrl = trim((string)($input['seo_canonical_url'] ?? ''));
    $seoOgImage = trim((string)($input['seo_og_image'] ?? ''));

    try {
        moduleWithContext('cms', static function () use ($productId, $seoTitle, $seoDescription, $seoCanonicalUrl, $seoOgImage): void {
            $db = cmsDb();
            $existingBuilderSeo = [];

            $existingRow = $db->query(
                "SELECT meta_value FROM cms_content_meta WHERE content_id = ? AND meta_key = '_builder_seo_settings' LIMIT 1",
                [$productId]
            )->fetch(\PDO::FETCH_ASSOC) ?: [];
            $rawExisting = trim((string)($existingRow['meta_value'] ?? ''));
            if ($rawExisting !== '') {
                $decoded = json_decode($rawExisting, true);
                if (is_array($decoded)) {
                    $existingBuilderSeo = $decoded;
                }
            }

            $builderSeo = array_merge($existingBuilderSeo, [
                'metaTitle' => $seoTitle,
                'metaDescription' => $seoDescription,
                'canonicalUrl' => $seoCanonicalUrl,
                'ogImage' => $seoOgImage,
            ]);

            foreach ([
                'seo_title' => $seoTitle,
                'seo_description' => $seoDescription,
                '_builder_seo_settings' => json_encode($builderSeo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ] as $key => $value) {
                $db->execute(
                    "INSERT INTO cms_content_meta (content_id, meta_key, meta_value)
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
                    [$productId, $key, $value]
                );
            }
        });
    } catch (\Throwable $e) {
        write_log('ecProductSaveSeoMeta error: ' . $e->getMessage(), 'error', [
            'module' => 'ecommerce',
            'product_id' => $productId,
        ]);
    }
}

function ecProductSeoContent(array $product): array
{
    $builderSeo = is_array($product['seo_builder_settings'] ?? null) ? $product['seo_builder_settings'] : [];

    return [
        'type' => 'product',
        'slug' => trim((string)($product['slug'] ?? '')),
        'title' => trim((string)($product['title'] ?? '')),
        'excerpt' => trim((string)($product['excerpt'] ?? '')),
        'featured_image' => trim((string)($product['featured_image'] ?? '')),
        'featured_image_path' => trim((string)($product['featured_image'] ?? '')),
        'meta' => [
            'seo_title' => trim((string)($product['seo_title'] ?? '')),
            'seo_description' => trim((string)($product['seo_description'] ?? '')),
            '_builder_seo_settings' => json_encode($builderSeo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ],
    ];
}

function ecProductTaxClassLabels(): array
{
    return [
        'standard' => 'Standard rate',
        'reduced' => 'Reduced rate',
        'zero' => 'Zero rate',
    ];
}

function ecProductNormalizeTaxClass(mixed $taxClass): string
{
    $normalized = strtolower(trim((string)$taxClass));
    return array_key_exists($normalized, ecProductTaxClassLabels()) ? $normalized : 'standard';
}

function ecProductTaxClassOptions(?string $selectedTaxClass = null): array
{
    $selectedTaxClass = ecProductNormalizeTaxClass($selectedTaxClass ?? 'standard');
    $options = [];
    foreach (ecProductTaxClassLabels() as $value => $label) {
        $options[] = [
            'value' => $value,
            'label' => $label,
            'selected' => $value === $selectedTaxClass,
        ];
    }

    return $options;
}

function ecProductTaxClass(int $productId): string
{
    try {
        $row = ecDb()->query(
            "SELECT meta_value FROM cms_content_meta WHERE content_id = ? AND meta_key = '_tax_class' LIMIT 1",
            [$productId]
        )->fetch(\PDO::FETCH_ASSOC) ?: [];
        return ecProductNormalizeTaxClass((string)($row['meta_value'] ?? 'standard'));
    } catch (\Throwable $e) {
        return 'standard';
    }
}

function ecProductSaveTaxClass(int $productId, mixed $taxClass): void
{
    $normalizedTaxClass = ecProductNormalizeTaxClass($taxClass);

    try {
        moduleWithContext('cms', static function () use ($productId, $normalizedTaxClass): void {
            cmsDb()->execute(
                "INSERT INTO cms_content_meta (content_id, meta_key, meta_value)
                 VALUES (?, '_tax_class', ?)
                 ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)",
                [$productId, $normalizedTaxClass]
            );
        });
    } catch (\Throwable $e) {
        write_log('ecProductSaveTaxClass error: ' . $e->getMessage(), 'error', [
            'module' => 'ecommerce',
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

    // Invalidate caches for this product and listings
    if (function_exists('ecCacheInvalidateProduct')) {
        ecCacheInvalidateProduct($id);
    }
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

function ecProductUpdatePricing(int $productId, array $data): void
{
    if (!function_exists('cmsEntityAttachCapability')) {
        return;
    }
    $existing = ecProductPricing($productId);
    $hasPrice = array_key_exists('price', $data);
    $hasSalePrice = array_key_exists('sale_price', $data);

    $priceValue = $hasPrice
        ? (($data['price'] === '' || $data['price'] === null) ? null : (float)$data['price'])
        : ($existing['price'] ?? null);
    $salePriceValue = $hasSalePrice
        ? (($data['sale_price'] === '' || $data['sale_price'] === null || (float)$data['sale_price'] <= 0) ? null : (float)$data['sale_price'])
        : ($existing['sale_price'] ?? null);

    $config = array_filter([
        'price' => $priceValue,
        'currency' => $data['currency'] ?? ($existing['currency'] ?? ecSettings('currency')),
        'sale_price' => $salePriceValue,
    ], static fn($value) => $value !== null);

    ecAttachCmsEntityCapability($productId, 'pricing', $config);

    // Invalidate caches — pricing change affects product detail + listings
    if (function_exists('ecCacheInvalidateProduct')) {
        ecCacheInvalidateProduct($productId);
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

function ecProductFindIdBySku(string $sku): int
{
    $normalizedSku = strtoupper(trim($sku));
    if ($normalizedSku === '') {
        return 0;
    }

    $existing = ecDb()->query(
        "SELECT entity_id
         FROM cms_entity_capabilities
         WHERE capability_id = 'inventory'
           AND JSON_UNQUOTE(JSON_EXTRACT(config, '$.sku')) = ?
         LIMIT 1",
        [$normalizedSku]
    )->fetchColumn();

    return $existing !== false ? (int)$existing : 0;
}

function ecProductDefaultAuthorId(): int
{
    try {
        return (int)(ecDb()->query('SELECT id FROM cms_users ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
    } catch (\Throwable $e) {
        return 0;
    }
}

function ec_cap_product_upsert_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'Invalid payload. Array expected.'];
    }

    $sku = trim((string)($payload['sku'] ?? ''));
    if ($sku === '') {
        return ['ok' => false, 'error' => 'Product SKU is required.'];
    }

    $productId = ecProductFindIdBySku($sku);
    $status = trim((string)($payload['status'] ?? ''));
    if ($status === '' && array_key_exists('is_active', $payload)) {
        $status = !empty($payload['is_active']) ? 'published' : 'draft';
    }
    if (!in_array($status, ['draft', 'published', 'private'], true)) {
        $status = 'draft';
    }

    $data = [
        'title' => trim((string)($payload['title'] ?? $payload['name'] ?? $sku)),
        'excerpt' => trim((string)($payload['excerpt'] ?? $payload['description'] ?? '')),
        'body' => array_key_exists('body', $payload)
            ? (string)$payload['body']
            : (string)($payload['description'] ?? ''),
        'status' => $status,
        'sku' => $sku,
    ];

    if (array_key_exists('price', $payload) && is_numeric($payload['price'])) {
        $data['price'] = (float)$payload['price'];
    }
    if (array_key_exists('sale_price', $payload) && is_numeric($payload['sale_price'])) {
        $data['sale_price'] = (float)$payload['sale_price'];
    }
    if (array_key_exists('track_stock', $payload)) {
        $data['track_stock'] = (bool)$payload['track_stock'];
    }
    if (array_key_exists('stock_qty', $payload) && is_numeric($payload['stock_qty'])) {
        $data['stock_qty'] = (int)$payload['stock_qty'];
    }

    try {
        if ($productId > 0) {
            ecProductUpdate($productId, $data);
            return ['ok' => true, 'product_id' => $productId, 'sku' => $sku, 'action' => 'updated'];
        }

        $productId = ecProductCreate($data, ecProductDefaultAuthorId());
        return ['ok' => true, 'product_id' => $productId, 'sku' => $sku, 'action' => 'created'];
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage(), 'sku' => $sku];
    }
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