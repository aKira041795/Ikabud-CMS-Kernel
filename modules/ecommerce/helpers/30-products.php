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
    $limit      = min(100, max(1, (int)($filters['limit']  ?? (int)ecSettings('products_per_page', 12))));
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

        // Attach pricing + inventory capability data to each product
        foreach ($rows as &$row) {
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

/**
 * Create a new product (cms_content row) and apply the ecommerce preset.
 *
 * @param array $data  title, slug, excerpt, body, status, featured_image_id, author_id
 * @return int  New product's cms_content.id
 */
function ecProductCreate(array $data, int $authorId = 0): int
{
    $title    = trim((string)($data['title'] ?? 'New Product'));
    $slug     = ecProductSlug($data['slug'] ?? $title);
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
        cmsApplyEntityPreset($productId, 'ecommerce');
    }

    // Set initial pricing/inventory if provided
    if (!empty($data['price'])) {
        ecProductUpdatePricing($productId, [
            'price'      => (float)$data['price'],
            'currency'   => $data['currency']   ?? ecSettings('currency', 'USD'),
            'sale_price' => isset($data['sale_price']) ? (float)$data['sale_price'] : null,
        ]);
    }

    if (isset($data['stock_qty']) || !empty($data['sku'])) {
        ecProductUpdateInventory($productId, [
            'track_stock' => (bool)($data['track_stock'] ?? true),
            'stock_qty'   => (int)($data['stock_qty']   ?? 0),
            'sku'         => $data['sku'] ?? '',
        ]);
    }

    // Assign category if provided
    if (!empty($data['category_id'])) {
        ecProductAssignCategory($productId, (int)$data['category_id']);
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
        $params[]  = ecProductSlug($data['slug'], $id);
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
            return ['price' => null, 'currency' => ecSettings('currency', 'USD'), 'on_sale' => false, 'formatted' => null];
        }

        $config    = (array)json_decode($row['config'] ?? '{}', true);
        $price     = isset($config['price'])      ? (float)$config['price']      : null;
        $salePrice = isset($config['sale_price']) ? (float)$config['sale_price'] : null;
        $currency  = $config['currency'] ?? ecSettings('currency', 'USD');
        $symbol    = ecSettings('currency_symbol', '$');
        $onSale    = $price !== null && $salePrice !== null && $salePrice < $price;
        $active    = $onSale ? $salePrice : $price;

        return [
            'price'       => $price,
            'sale_price'  => $salePrice,
            'currency'    => $currency,
            'on_sale'     => $onSale,
            'formatted'   => $price !== null ? ($symbol . number_format($active, 2)) : null,
            'regular_fmt' => $price !== null ? ($symbol . number_format($price, 2)) : null,
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
        $threshold  = (int)ecSettings('low_stock_threshold', 5);

        return [
            'track_stock' => $trackStock,
            'stock_qty'   => $stockQty,
            'sku'         => $config['sku'] ?? '',
            'in_stock'    => !$trackStock || $stockQty > 0,
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
        'currency'   => $data['currency']           ?? ($existing['currency']     ?? ecSettings('currency', 'USD')),
        'sale_price' => isset($data['sale_price'])  ? (float)$data['sale_price'] : ($existing['sale_price'] ?? null),
    ], fn($v) => $v !== null);

    cmsEntityAttachCapability($productId, 'pricing', $config);
}

function ecProductUpdateInventory(int $productId, array $data): void
{
    if (!function_exists('cmsEntityAttachCapability')) {
        return;
    }
    $existing = ecProductInventory($productId);
    $config = [
        'track_stock' => isset($data['track_stock']) ? (bool)$data['track_stock'] : ($existing['track_stock'] ?? true),
        'stock_qty'   => isset($data['stock_qty'])   ? (int)$data['stock_qty']    : ($existing['stock_qty']   ?? 0),
        'sku'         => $data['sku']                ?? ($existing['sku']         ?? ''),
    ];

    cmsEntityAttachCapability($productId, 'inventory', $config);
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
        app()->events()->publish('ecommerce.product.out_of_stock', [
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
            cmsDb()->execute(
                "INSERT IGNORE INTO cms_content_categories (content_id, category_id) VALUES (?, ?)",
                [$productId, $categoryId]
            );
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
