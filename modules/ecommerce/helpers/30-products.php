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

    // Phase 1 multi-store: optional store_id filter.
    // Returns global products (no override row) OR products explicitly visible in the given store.
    $storeIdFilter = isset($filters['store_id']) ? max(0, (int)$filters['store_id']) : 0;
    if ($storeIdFilter > 0) {
        $joinParts[] = 'LEFT JOIN ec_store_product_overrides store_po ON store_po.product_id = c.id AND store_po.store_id = ?';
        $joinParams[] = $storeIdFilter;
        $where[] = '(store_po.id IS NULL OR store_po.is_visible = 1)';
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

        // Read settings once before loop
        $currencySetting = ecSettings('currency');
        $currencySymbol = (string)ecSettings('currency_symbol');
        $lowStockThreshold = (int)ecSettings('low_stock_threshold');
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

        // Phase 1 multi-store: attach store assignment data when requested.
        if (!empty($filters['with_store']) && count($productIds) > 0) {
            $storeAssignmentMap = ecProductStoreAssignmentMap($productIds);
            foreach ($rows as &$row) {
                $row['stores'] = $storeAssignmentMap[(int)($row['id'] ?? 0)] ?? [];
            }
            unset($row);
        }

        return ['items' => $rows, 'total' => $total];
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
    $db = ecDb();
    try {
        $row = $db->query(
            "SELECT id FROM cms_content WHERE slug = ? AND type = 'product' AND deleted_at IS NULL LIMIT 1",
            [$slug]
        )->fetch(\PDO::FETCH_ASSOC);

        return $row ? ecProductGet((int)$row['id'], $includeRelations) : null;
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

function ecProductInventoryStateFromConfig(array $config, bool $isDigital, int $lowStockThreshold): array
{
    if (empty($config)) {
        return [
            'in_stock' => true,
            'out_of_stock' => false,
            'low_stock' => false,
            'stock_qty' => 0,
            'sku' => '',
            'track_stock' => false,
            'badge' => ['label' => '', 'tone' => ''],
        ];
    }

    if ($isDigital) {
        return [
            'in_stock' => true,
            'out_of_stock' => false,
            'low_stock' => false,
            'stock_qty' => 0,
            'sku' => $config['sku'] ?? '',
            'track_stock' => false,
            'badge' => ['label' => '', 'tone' => ''],
        ];
    }

    $trackStock = (bool)($config['track_stock'] ?? true);
    $stockQty = (int)($config['stock_qty'] ?? 0);
    $inventory = [
        'track_stock' => $trackStock,
        'stock_qty' => $stockQty,
        'badge' => [
            'label' => ($trackStock && $stockQty <= 0 ? 'Out of stock' : ''),
            'tone' => ($trackStock && $stockQty <= 0 ? 'negative' : ''),
        ],
        'sku' => $config['sku'] ?? '',
        'in_stock' => !$trackStock || $stockQty > 0,
        'out_of_stock' => $trackStock && $stockQty <= 0,
        'low_stock' => $trackStock && $stockQty > 0 && $stockQty <= $lowStockThreshold,
    ];

    return ecProductApplyWmsInventorySnapshot($inventory);
}

function ecProductApplyWmsInventorySnapshot(array $inventory): array
{
    $wmsSnapshot = ecWmsInventorySnapshotForSku((string)($inventory['sku'] ?? ''));
    if ($wmsSnapshot === []) {
        return $inventory;
    }

    return array_merge($inventory, $wmsSnapshot, [
        'badge' => [
            'label' => !empty($wmsSnapshot['out_of_stock']) ? 'Out of stock' : '',
            'tone' => !empty($wmsSnapshot['out_of_stock']) ? 'negative' : '',
        ],
    ]);
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

function ecWmsInventoryWarmProductCollection(array $products): void
{
    $skus = [];
    foreach ($products as $product) {
        if (!is_array($product)) {
            continue;
        }

        foreach ([
            (string)($product['sku'] ?? ''),
            (string)($product['inventory']['sku'] ?? ''),
            (string)($product['capability_data']['inventory']['sku'] ?? ''),
        ] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '') {
                $skus[] = $candidate;
            }
        }
    }

    ecWmsInventorySnapshotMapForSkus($skus);
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
            return ['in_stock' => true, 'out_of_stock' => false, 'low_stock' => false, 'stock_qty' => 0, 'sku' => '', 'track_stock' => false, 'badge' => ['label' => '', 'tone' => '']];
        }

        $config     = (array)json_decode($row['config'] ?? '{}', true);

        // Digital products are always available — never block on stock.
        $digitalMeta = $db->query(
            "SELECT meta_value FROM cms_content_meta WHERE content_id = ? AND meta_key = '_is_digital' LIMIT 1",
            [$productId]
        )->fetch(\PDO::FETCH_ASSOC);
        $threshold  = (int)ecSettings('low_stock_threshold');

        return ecProductInventoryStateFromConfig(
            $config,
            ($digitalMeta['meta_value'] ?? '') === '1',
            $threshold
        );
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
}

function ecProductUpdateInventory(int $productId, array $data): void
{
    if (!function_exists('cmsEntityAttachCapability')) {
        return;
    }
    $existing = ecProductInventory($productId);
    $prevQty  = (int)($existing['stock_qty'] ?? 0);
    $sku      = ecProductNormalizeSku($productId, $data['sku'] ?? ($existing['sku'] ?? ''));
    $config   = [
        'track_stock' => isset($data['track_stock']) ? (bool)$data['track_stock'] : ($existing['track_stock'] ?? true),
        'stock_qty'   => isset($data['stock_qty'])   ? (int)$data['stock_qty']    : $prevQty,
        'sku'         => $sku,
    ];
    $newQty = $config['stock_qty'];

    ecAttachCmsEntityCapability($productId, 'inventory', $config);

    // Fire back-in-stock notification when a manual update restocks from zero
    if (function_exists('ecStockNotificationCheckAndTrigger')) {
        ecStockNotificationCheckAndTrigger($productId, null, $prevQty, $newQty);
    }
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

function ecProductIncrementStock(int $productId, int $qty): void
{
    if ($qty < 1) {
        return;
    }

    $db = ecDb();

    $digitalMeta = $db->query(
        "SELECT meta_value FROM cms_content_meta WHERE content_id = ? AND meta_key = '_is_digital' LIMIT 1",
        [$productId]
    )->fetch(\PDO::FETCH_ASSOC);
    if (($digitalMeta['meta_value'] ?? '') === '1') {
        return;
    }

    $row = $db->query(
        "SELECT id, config FROM cms_entity_capabilities WHERE entity_id = ? AND capability_id = 'inventory' LIMIT 1",
        [$productId]
    )->fetch(\PDO::FETCH_ASSOC);

    if (!$row) {
        return;
    }

    $config = (array)json_decode($row['config'] ?? '{}', true);
    $trackStock = (bool)($config['track_stock'] ?? true);
    if (!$trackStock) {
        return;
    }

    $prevQty            = (int)($config['stock_qty'] ?? 0);
    $config['stock_qty'] = max(0, $prevQty + $qty);
    $newQty             = $config['stock_qty'];

    moduleWithContext('cms', static function () use ($config, $row): void {
        cmsDb()->execute(
            "UPDATE cms_entity_capabilities SET config = ?, updated_at = NOW() WHERE id = ?",
            [json_encode($config), (int)$row['id']]
        );
    });

    // Fire back-in-stock notification when restocked from zero
    if (function_exists('ecStockNotificationCheckAndTrigger')) {
        ecStockNotificationCheckAndTrigger($productId, null, $prevQty, $newQty);
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

function ecWmsInventorySnapshotFromRows(array $rows, int $threshold): array
{
    if ($rows === []) {
        return [];
    }

    $qtyOnHand = 0.0;
    $qtyReserved = 0.0;
    $qtyStaged = 0.0;
    $qtyAvailable = 0.0;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $qtyOnHand += (float)($row['qty_on_hand'] ?? 0);
        $qtyReserved += (float)($row['qty_reserved'] ?? 0);
        $qtyStaged += (float)($row['qty_staged'] ?? 0);
        $qtyAvailable += (float)($row['qty_available'] ?? 0);
    }

    return [
        'track_stock' => true,
        'stock_qty' => (int)round($qtyAvailable),
        'qty_on_hand' => $qtyOnHand,
        'qty_reserved' => $qtyReserved,
        'qty_staged' => $qtyStaged,
        'qty_available' => $qtyAvailable,
        'in_stock' => $qtyAvailable > 0,
        'out_of_stock' => $qtyAvailable <= 0,
        'low_stock' => $qtyAvailable > 0 && $qtyAvailable <= $threshold,
        'source' => 'wms',
    ];
}

function ecWmsInventorySnapshotMapForSkus(array $skus): array
{
    static $cache = [];

    $normalizedSkus = array_values(array_unique(array_filter(array_map(
        static fn(mixed $value): string => strtoupper(trim((string)$value)),
        $skus
    ), static fn(string $value): bool => $value !== '')));
    if ($normalizedSkus === []) {
        return [];
    }

    // Phase 7A: per-store inventory source resolution.
    // This MUST run before the global integration-mode guard so that stores with
    // source_type='wms' work even when the global mode is ecommerce_authoritative_products.
    $warehouseId = 0;
    if (function_exists('ecStoreResolveContext') && function_exists('ecStoreInventorySource')) {
        $storeCtx = ecStoreResolveContext();
        if ($storeCtx !== null) {
            $storeId = (int)($storeCtx['id'] ?? 0);
            if ($storeId > 0) {
                $invSrc = ecStoreInventorySource($storeId);
                if (is_array($invSrc)) {
                    if (($invSrc['source_type'] ?? '') === 'local') {
                        // Store uses local stock_qty — WMS snapshot not applicable for this store.
                        return [];
                    }
                    if (($invSrc['source_type'] ?? '') === 'wms') {
                        $warehouseId = max(0, (int)($invSrc['warehouse_id'] ?? 0));
                    }
                }
            }
        }
    }

    // Global integration-mode guard: only applies when no per-store warehouse was resolved.
    if ($warehouseId <= 0) {
        $integrationMode = ecActiveIntegrationMode();
        if ($integrationMode !== 'wms_authoritative_products') {
            $integrationMode = ecActiveIntegrationMode(true);
        }
        if ($integrationMode !== 'wms_authoritative_products') {
            return [];
        }
        $warehouseId = max(0, (int)ecSettings('default_wms_warehouse_id'));
    }

    $threshold = (int)ecSettings('low_stock_threshold');
    $warehouseKey = (string)$warehouseId;
    if (!isset($cache[$warehouseKey]) || !is_array($cache[$warehouseKey])) {
        $cache[$warehouseKey] = [];
    }

    $missingSkus = [];
    foreach ($normalizedSkus as $normalizedSku) {
        if (!array_key_exists($normalizedSku, $cache[$warehouseKey])) {
            $missingSkus[] = $normalizedSku;
        }
    }

    if ($missingSkus !== []) {
        try {
            if (!function_exists('wms_cap_wms_stock_query_1')) {
                foreach ($missingSkus as $missingSku) {
                    $cache[$warehouseKey][$missingSku] = [];
                }
            } else {
                $result = moduleWithContext('wms', static function () use ($missingSkus, $warehouseId): array {
                    return wms_cap_wms_stock_query_1([
                        'warehouse_id' => $warehouseId,
                        'filters' => ['skus' => $missingSkus],
                    ]);
                });
                $rows = is_array($result['data'] ?? null) ? $result['data'] : [];
                $groupedRows = [];
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $rowSku = strtoupper(trim((string)($row['sku'] ?? '')));
                    if ($rowSku === '' || !in_array($rowSku, $missingSkus, true)) {
                        continue;
                    }

                    if (!isset($groupedRows[$rowSku])) {
                        $groupedRows[$rowSku] = [];
                    }
                    $groupedRows[$rowSku][] = $row;
                }

                foreach ($missingSkus as $missingSku) {
                    $cache[$warehouseKey][$missingSku] = ecWmsInventorySnapshotFromRows($groupedRows[$missingSku] ?? [], $threshold);
                }
            }
        } catch (\Throwable $e) {
            foreach ($missingSkus as $missingSku) {
                $cache[$warehouseKey][$missingSku] = [];
            }
        }
    }

    $snapshots = [];
    foreach ($normalizedSkus as $normalizedSku) {
        $snapshots[$normalizedSku] = $cache[$warehouseKey][$normalizedSku] ?? [];
    }

    return $snapshots;
}

function ecWmsInventorySnapshotForSku(string $sku): array
{
    $normalizedSku = strtoupper(trim($sku));
    if ($normalizedSku === '') {
        return [];
    }

    $snapshots = ecWmsInventorySnapshotMapForSkus([$normalizedSku]);
    return $snapshots[$normalizedSku] ?? [];
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
