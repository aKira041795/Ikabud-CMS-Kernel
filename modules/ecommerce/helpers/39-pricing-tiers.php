<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Customer Segmentation & Tier Pricing (helpers/39-pricing-tiers.php)
// ─────────────────────────────────────────────────────────────────────────
//
// Pricing stack (most specific wins, evaluated left-to-right):
//   global price → store override (38-stores) → segment override (this file)
//
// Design rule: when ecSegmentCurrentUserId() returns 0 (no logged-in user or
// storage unavailable), all catalog and order paths behave exactly as before.
// ─────────────────────────────────────────────────────────────────────────

/**
 * Returns true if the ec_customer_segments table is queryable.
 */
function ecSegmentStorageAvailable(): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }
    try {
        ecDb()->query('SELECT 1 FROM ec_customer_segments LIMIT 1');
        $available = true;
    } catch (\Throwable $e) {
        $available = false;
    }
    return $available;
}

/**
 * Returns the current logged-in user ID for segment resolution, cached per request.
 * Returns 0 when not logged in or when app()->user() is unavailable.
 */
function ecSegmentCurrentUserId(): int
{
    static $userId = null;
    if ($userId !== null) {
        return $userId;
    }
    try {
        $user   = function_exists('app') ? app()->user() : null;
        $userId = (int)(is_array($user) ? ($user['id'] ?? 0) : 0);
    } catch (\Throwable $e) {
        $userId = 0;
    }
    return $userId;
}

/**
 * Returns all active segments a user belongs to, ordered by priority (high first).
 * Results are cached per request (per userId).
 *
 * @return array[] Each element is an ec_customer_segments row.
 */
function ecCustomerActiveSegments(int $userId): array
{
    static $cache = [];
    if ($userId <= 0 || !ecSegmentStorageAvailable()) {
        return [];
    }
    if (array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }
    try {
        $rows = ecDb()->query(
            "SELECT s.* FROM ec_customer_segments s
             INNER JOIN ec_customer_segment_members m ON m.segment_id = s.id
             WHERE m.user_id = ? AND s.is_active = 1
             ORDER BY s.priority DESC, s.id ASC",
            [$userId]
        )->fetchAll(\PDO::FETCH_ASSOC);
        $cache[$userId] = is_array($rows) ? $rows : [];
    } catch (\Throwable $e) {
        $cache[$userId] = [];
    }
    return $cache[$userId];
}

/**
 * Resolves the best segment price for a product given a list of segments.
 *
 * Evaluation order (highest priority segment first):
 *  1. price_list — look up ec_segment_product_prices; if a row exists, return it.
 *  2. percent    — apply discount_value % off the product's current price.
 *  3. fixed      — subtract discount_value from the product's current price.
 *
 * Returns ['price' => float, 'sale_price' => float|null] or null when no
 * segment pricing applies to this product.
 *
 * @param int   $productId  cms_content.id of the product
 * @param array $segments   Ordered list of segment rows (highest priority first)
 * @param float $basePrice  Current product price (after store overrides if any)
 */
function ecSegmentResolvePrice(int $productId, array $segments, float $basePrice = 0.0): ?array
{
    if ($productId <= 0 || empty($segments) || !ecSegmentStorageAvailable()) {
        return null;
    }

    foreach ($segments as $segment) {
        $type = (string)($segment['discount_type'] ?? 'price_list');

        if ($type === 'price_list') {
            try {
                $row = ecDb()->query(
                    'SELECT * FROM ec_segment_product_prices WHERE segment_id = ? AND product_id = ? LIMIT 1',
                    [(int)$segment['id'], $productId]
                )->fetch(\PDO::FETCH_ASSOC);
                if (is_array($row)) {
                    return [
                        'price'      => (float)$row['price'],
                        'sale_price' => $row['sale_price'] !== null ? (float)$row['sale_price'] : null,
                    ];
                }
            } catch (\Throwable $e) {
                continue;
            }
            // price_list segment has no row for this product; try next segment
            continue;
        }

        if ($basePrice <= 0.0) {
            continue;
        }

        $discountValue = (float)($segment['discount_value'] ?? 0);
        if ($discountValue <= 0.0) {
            continue;
        }

        if ($type === 'percent') {
            $discounted = round($basePrice * (1 - min($discountValue, 100) / 100), 2);
            return ['price' => max(0.0, $discounted), 'sale_price' => null];
        }

        if ($type === 'fixed') {
            $discounted = round($basePrice - $discountValue, 2);
            return ['price' => max(0.0, $discounted), 'sale_price' => null];
        }
    }

    return null;
}

/**
 * Applies segment pricing to a product array (as used inside ecBuildStorefrontCatalogItem).
 * The product is modified in-place and returned.
 *
 * @param array   $product   Hydrated product array (after store overrides).
 * @param array[] $segments  Active segments for the current user, priority-ordered.
 */
function ecSegmentApplyProductPrice(array $product, array $segments): array
{
    if (empty($segments)) {
        return $product;
    }

    $productId = (int)($product['id'] ?? 0);
    $basePrice = (float)($product['price'] ?? 0.0);
    $resolved  = ecSegmentResolvePrice($productId, $segments, $basePrice);

    if ($resolved === null) {
        return $product;
    }

    $product['price']      = $resolved['price'];
    $product['sale_price'] = $resolved['sale_price'];
    $product['_segment_priced'] = true;

    if (is_array($product['pricing'] ?? null)) {
        $product['pricing']['price']      = $resolved['price'];
        $product['pricing']['sale_price'] = $resolved['sale_price'];
    }

    return $product;
}

// ─────────────────────────────────────────────────────────────────────────
// Segment member management helpers
// ─────────────────────────────────────────────────────────────────────────

/**
 * Assigns a user to a segment. Silently no-ops if already a member.
 */
function ecSegmentAddMember(int $segmentId, int $userId): bool
{
    if ($segmentId <= 0 || $userId <= 0 || !ecSegmentStorageAvailable()) {
        return false;
    }
    try {
        ecDb()->query(
            'INSERT IGNORE INTO ec_customer_segment_members (segment_id, user_id, added_at) VALUES (?, ?, NOW())',
            [$segmentId, $userId]
        );
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Removes a user from a segment.
 */
function ecSegmentRemoveMember(int $segmentId, int $userId): bool
{
    if ($segmentId <= 0 || $userId <= 0 || !ecSegmentStorageAvailable()) {
        return false;
    }
    try {
        ecDb()->query(
            'DELETE FROM ec_customer_segment_members WHERE segment_id = ? AND user_id = ?',
            [$segmentId, $userId]
        );
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Returns all segments a user belongs to (active only), ordered by priority desc.
 * Alias that caches via ecCustomerActiveSegments.
 */
function ecSegmentMembershipsForUser(int $userId): array
{
    return ecCustomerActiveSegments($userId);
}

/**
 * Returns the product price rows for a given segment (for admin price-list UI).
 *
 * @return array[] Rows from ec_segment_product_prices keyed by product_id.
 */
function ecSegmentProductPriceList(int $segmentId): array
{
    if ($segmentId <= 0 || !ecSegmentStorageAvailable()) {
        return [];
    }
    try {
        $rows = ecDb()->query(
            'SELECT * FROM ec_segment_product_prices WHERE segment_id = ? ORDER BY product_id ASC',
            [$segmentId]
        )->fetchAll(\PDO::FETCH_ASSOC);
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(int)$row['product_id']] = $row;
        }
        return $indexed;
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Upserts a price-list row for a segment + product.
 */
function ecSegmentUpsertProductPrice(int $segmentId, int $productId, float $price, ?float $salePrice = null): bool
{
    if ($segmentId <= 0 || $productId <= 0 || $price < 0 || !ecSegmentStorageAvailable()) {
        return false;
    }
    try {
        ecDb()->query(
            'INSERT INTO ec_segment_product_prices (segment_id, product_id, price, sale_price, created_at, updated_at)
             VALUES (?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE price = VALUES(price), sale_price = VALUES(sale_price), updated_at = NOW()',
            [$segmentId, $productId, round($price, 2), $salePrice !== null ? round($salePrice, 2) : null]
        );
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}
