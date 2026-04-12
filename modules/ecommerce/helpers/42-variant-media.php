<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Variant-Aware Merchandising (helpers/42-variant-media.php)
// ─────────────────────────────────────────────────────────────────────────
//
// Maps CMS media library images to specific product variants.
//
// Storefront usage:
//   $variantMediaMap = ecVariantMediaForProduct($productId);
//   // $variantMediaMap[$variantId] = [{url, thumb, caption, sort_order}]
//   // Falls back to parent product gallery when map is empty for a variant.
//
// Fallback rule (most specific wins):
//   ec_variant_media rows (sorted by sort_order) → parent product gallery
// ─────────────────────────────────────────────────────────────────────────

/**
 * Returns true if the ec_variant_media table is queryable.
 */
function ecVariantMediaStorageAvailable(): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }
    try {
        ecDb()->query('SELECT 1 FROM ec_variant_media LIMIT 1');
        $available = true;
    } catch (\Throwable $e) {
        $available = false;
    }
    return $available;
}

/**
 * Returns normalized gallery images for a single variant.
 * Each item: {url, thumb, caption, sort_order, media_id}.
 * Returns empty array when no media assigned.
 *
 * @return array[]
 */
function ecVariantMediaForVariant(int $variantId): array
{
    if ($variantId <= 0 || !ecVariantMediaStorageAvailable()) {
        return [];
    }
    try {
        $rows = ecDb()->query(
            "SELECT vm.id, vm.variant_id, vm.media_id, vm.sort_order,
                    m.file_path
             FROM ec_variant_media vm
             INNER JOIN cms_media m ON m.id = vm.media_id
             WHERE vm.variant_id = ?
             ORDER BY vm.sort_order ASC, vm.id ASC",
            [$variantId]
        )->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return [];
    }

    return ecVariantMediaNormalizeRows(is_array($rows) ? $rows : []);
}

/**
 * Returns all variant media for a product, keyed by variant_id.
 * Used in ecBuildStorefrontCatalogItem to populate the variant_media_map.
 *
 * @return array<int, array[]>  {variantId => [{url, thumb, caption, sort_order, media_id}]}
 */
function ecVariantMediaForProduct(int $productId): array
{
    if ($productId <= 0 || !ecVariantMediaStorageAvailable()) {
        return [];
    }
    try {
        $rows = ecDb()->query(
            "SELECT vm.id, vm.variant_id, vm.media_id, vm.sort_order,
                    m.file_path
             FROM ec_variant_media vm
             INNER JOIN cms_media m ON m.id = vm.media_id
             INNER JOIN ec_product_variants pv ON pv.id = vm.variant_id
             WHERE pv.product_id = ?
             ORDER BY vm.variant_id ASC, vm.sort_order ASC, vm.id ASC",
            [$productId]
        )->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return [];
    }

    $map = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        $vid = (int)$row['variant_id'];
        if (!isset($map[$vid])) {
            $map[$vid] = [];
        }
        $map[$vid][] = ecVariantMediaNormalizeRow($row);
    }
    return $map;
}

/**
 * Batch-loads variant media for multiple products.
 * Returns {productId => {variantId => [{url, thumb, caption, sort_order}]}}.
 *
 * @param int[] $productIds
 * @return array<int, array<int, array[]>>
 */
function ecVariantMediaForProducts(array $productIds): array
{
    $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
    if ($productIds === [] || !ecVariantMediaStorageAvailable()) {
        return [];
    }
    $placeholders = implode(', ', array_fill(0, count($productIds), '?'));
    try {
        $rows = ecDb()->query(
            "SELECT pv.product_id, vm.variant_id, vm.media_id, vm.sort_order, m.file_path
             FROM ec_variant_media vm
             INNER JOIN cms_media m ON m.id = vm.media_id
             INNER JOIN ec_product_variants pv ON pv.id = vm.variant_id
             WHERE pv.product_id IN ($placeholders)
             ORDER BY pv.product_id ASC, vm.variant_id ASC, vm.sort_order ASC, vm.id ASC",
            $productIds
        )->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return [];
    }

    $result = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        $pid = (int)$row['product_id'];
        $vid = (int)$row['variant_id'];
        if (!isset($result[$pid])) {
            $result[$pid] = [];
        }
        if (!isset($result[$pid][$vid])) {
            $result[$pid][$vid] = [];
        }
        $result[$pid][$vid][] = ecVariantMediaNormalizeRow($row);
    }
    return $result;
}

/**
 * Returns the gallery image list for a given variant, falling back to the
 * parent product gallery when no variant-specific media is assigned.
 *
 * @param array[] $productGallery  Normalized gallery from ecProductGalleryImages()
 * @return array[]
 */
function ecVariantMediaFallbackGallery(int $productId, ?int $variantId, array $productGallery): array
{
    if ($variantId !== null && $variantId > 0 && ecVariantMediaStorageAvailable()) {
        $variantMedia = ecVariantMediaForVariant($variantId);
        if (!empty($variantMedia)) {
            return $variantMedia;
        }
    }
    return $productGallery;
}

// ─────────────────────────────────────────────────────────────────────────
// Admin management helpers
// ─────────────────────────────────────────────────────────────────────────

/**
 * Attaches a CMS media item to a variant. Idempotent (IGNORE on duplicate).
 */
function ecVariantMediaAttach(int $variantId, int $mediaId, int $sortOrder = 0): bool
{
    if ($variantId <= 0 || $mediaId <= 0 || !ecVariantMediaStorageAvailable()) {
        return false;
    }
    try {
        ecDb()->query(
            'INSERT IGNORE INTO ec_variant_media (variant_id, media_id, sort_order) VALUES (?, ?, ?)',
            [$variantId, $mediaId, $sortOrder]
        );
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Detaches a specific media item from a variant.
 */
function ecVariantMediaDetach(int $variantId, int $mediaId): bool
{
    if ($variantId <= 0 || $mediaId <= 0 || !ecVariantMediaStorageAvailable()) {
        return false;
    }
    try {
        ecDb()->query(
            'DELETE FROM ec_variant_media WHERE variant_id = ? AND media_id = ?',
            [$variantId, $mediaId]
        );
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Removes all media assignments for a variant.
 */
function ecVariantMediaDetachAll(int $variantId): void
{
    if ($variantId <= 0 || !ecVariantMediaStorageAvailable()) {
        return;
    }
    try {
        ecDb()->query('DELETE FROM ec_variant_media WHERE variant_id = ?', [$variantId]);
    } catch (\Throwable $e) {}
}

/**
 * Re-orders media for a variant by updating sort_order values.
 * $sortedMediaIds is an ordered list of media_id values (first = lowest sort_order).
 *
 * @param int[] $sortedMediaIds
 */
function ecVariantMediaReorder(int $variantId, array $sortedMediaIds): void
{
    if ($variantId <= 0 || empty($sortedMediaIds) || !ecVariantMediaStorageAvailable()) {
        return;
    }
    foreach ($sortedMediaIds as $sortOrder => $mediaId) {
        try {
            ecDb()->query(
                'UPDATE ec_variant_media SET sort_order = ? WHERE variant_id = ? AND media_id = ?',
                [(int)$sortOrder, $variantId, (int)$mediaId]
            );
        } catch (\Throwable $e) {}
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Internal normalizers
// ─────────────────────────────────────────────────────────────────────────

/**
 * Normalizes a single ec_variant_media + cms_media joined row.
 *
 * @return array{url: string, thumb: string, caption: string, sort_order: int, media_id: int}
 */
function ecVariantMediaNormalizeRow(array $row): array
{
    $url = function_exists('ecProductResolveMediaUrl')
        ? ecProductResolveMediaUrl((string)($row['file_path'] ?? ''))
        : (string)($row['file_path'] ?? '');

    return [
        'url'        => $url,
        'thumb'      => $url,
        'caption'    => '',
        'sort_order' => (int)($row['sort_order'] ?? 0),
        'media_id'   => (int)($row['media_id'] ?? 0),
    ];
}

/**
 * Normalizes an array of ec_variant_media rows.
 *
 * @return array[]
 */
function ecVariantMediaNormalizeRows(array $rows): array
{
    return array_values(array_map('ecVariantMediaNormalizeRow', $rows));
}
