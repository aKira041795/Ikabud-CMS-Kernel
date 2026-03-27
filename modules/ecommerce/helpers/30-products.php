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

    $categoryId = isset($filters['category_id']) ? (int)$filters['category_id'] : null;
    $search     = trim((string)($filters['search'] ?? ''));
    $status     = trim((string)($filters['status'] ?? 'published'));
    $limit      = min(100, max(1, (int)($filters['limit']  ?? (int)ecSettings('products_per_page'))));
    $offset     = max(0, (int)($filters['offset'] ?? 0));
    $orderBy    = in_array($filters['order_by'] ?? '', ['created_at', 'title', 'updated_at'], true)
        ? $filters['order_by'] : 'created_at';

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
    if ($categoryId !== null) {
        $join     = 'INNER JOIN cms_content_categories cc ON cc.content_id = c.id AND cc.category_id = ?';
        $params   = array_merge([$categoryId], $params);
    }

    $whereClause = implode(' AND ', $where);

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
             LEFT JOIN cms_media m ON m.id = c.featured_image_id
             WHERE $whereClause
             ORDER BY c.$orderBy DESC
             LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        )->fetchAll(\PDO::FETCH_ASSOC);

        $galleryMap = ecProductGalleryImagesForProducts(array_map(
            static fn(array $row): int => (int)($row['id'] ?? 0),
            is_array($rows) ? $rows : []
        ));

        // Attach pricing + inventory capability data to each product
        foreach ($rows as &$row) {
            $galleryImages = $galleryMap[(int)($row['id'] ?? 0)] ?? [];
            $row['gallery_images'] = $galleryImages;
            $row['featured_image_url'] = ecProductResolveFeaturedImageUrl((string)($row['featured_image'] ?? ''));
            $row['primary_image_url'] = ecProductPrimaryImageUrl($row['featured_image_url'], $galleryImages);

            $row['pricing']   = ecProductPricing((int)$row['id']);
            $row['inventory'] = ecProductInventory((int)$row['id']);
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
        $onSale    = $price !== null && $salePrice !== null && $salePrice < $price;
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
            return ['in_stock' => true, 'stock_qty' => null, 'sku' => '', 'track_stock' => false];
        }

        $config     = (array)json_decode($row['config'] ?? '{}', true);
        $trackStock = (bool)($config['track_stock'] ?? true);
        $stockQty   = (int)($config['stock_qty']   ?? 0);
        $threshold  = (int)ecSettings('low_stock_threshold');

        return [
            'track_stock' => $trackStock,
            'stock_qty'   => $stockQty,
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
        'sale_price' => isset($data['sale_price'])  ? (float)$data['sale_price'] : ($existing['sale_price'] ?? null),
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
    // Read is fine via ecDb() (reads_tables), but update needs CMS context
    $row = ecDb()->query(
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
