<?php

declare(strict_types=1);

function ecWishlistStorageAvailable(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    try {
        ecDb()->query('SELECT 1 FROM ec_wishlist LIMIT 1');
        $ready = true;
    } catch (\Throwable $e) {
        $ready = false;
    }

    return $ready;
}

function ecWishlistAllowedRoles(): array
{
    return ['subscriber', 'customer', 'editor', 'administrator'];
}

function ecWishlistResolveCustomerUser(?array $user = null): ?array
{
    $user = is_array($user) ? $user : app()->user();
    if (!$user) {
        return null;
    }

    $role = strtolower(trim((string)($user['role'] ?? '')));
    $source = strtolower(trim((string)($user['source'] ?? '')));
    if ($source === 'cms' || in_array($role, ecWishlistAllowedRoles(), true)) {
        return $user;
    }

    return null;
}

function &ecWishlistRuntimeCache(): array
{
    static $cache = [
        'ids' => [],
        'counts' => [],
    ];

    return $cache;
}

function ecWishlistInvalidateCustomerCache(int $customerId): void
{
    if ($customerId <= 0) {
        return;
    }

    $cache = &ecWishlistRuntimeCache();
    unset($cache['ids'][$customerId], $cache['counts'][$customerId]);
}

function ecWishlistCurrentCustomerId(?array $user = null): int
{
    $user = ecWishlistResolveCustomerUser($user);
    return $user ? (int)($user['id'] ?? 0) : 0;
}

function ecWishlistGetIdsForCustomer(int $customerId): array
{
    if ($customerId <= 0 || !ecWishlistStorageAvailable()) {
        return [];
    }

    $cache = &ecWishlistRuntimeCache();
    if (array_key_exists($customerId, $cache['ids'])) {
        return $cache['ids'][$customerId];
    }

    try {
        $rows = ecDb()->query(
            'SELECT product_id FROM ec_wishlist WHERE user_id = ? ORDER BY added_at DESC, id DESC',
            [$customerId]
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        $rows = [];
    }

    $ids = [];
    foreach ($rows as $row) {
        $productId = (int)($row['product_id'] ?? 0);
        if ($productId > 0) {
            $ids[] = $productId;
        }
    }

    $ids = array_values(array_unique($ids));
    $cache['ids'][$customerId] = $ids;
    $cache['counts'][$customerId] = count($ids);

    return $ids;
}

function ecWishlistGetLookupForCustomer(int $customerId): array
{
    $ids = ecWishlistGetIdsForCustomer($customerId);
    return $ids === [] ? [] : array_fill_keys($ids, true);
}

function ecWishlistCount(?int $customerId = null): int
{
    $customerId = $customerId ?? ecWishlistCurrentCustomerId();
    if ($customerId <= 0) {
        return 0;
    }

    $cache = &ecWishlistRuntimeCache();
    if (array_key_exists($customerId, $cache['counts'])) {
        return (int)$cache['counts'][$customerId];
    }

    return count(ecWishlistGetIdsForCustomer($customerId));
}

function ecWishlistContains(int $productId, ?int $customerId = null): bool
{
    if ($productId <= 0) {
        return false;
    }

    $customerId = $customerId ?? ecWishlistCurrentCustomerId();
    if ($customerId <= 0) {
        return false;
    }

    $lookup = ecWishlistGetLookupForCustomer($customerId);
    return isset($lookup[$productId]);
}

function ecWishlistAddProduct(int $customerId, int $productId): array
{
    if (!ecWishlistStorageAvailable()) {
        return ['ok' => false, 'error' => 'Wishlist storage is unavailable.'];
    }

    if ($customerId <= 0) {
        return ['ok' => false, 'error' => 'Please sign in to save products to your wishlist.'];
    }

    if ($productId <= 0) {
        return ['ok' => false, 'error' => 'Choose a valid product to save.'];
    }

    $product = ecProductGet($productId, false);
    if (!$product || (string)($product['status'] ?? '') !== 'published') {
        return ['ok' => false, 'error' => 'That product is unavailable.'];
    }

    $alreadyWishlisted = ecWishlistContains($productId, $customerId);

    try {
        ecDb()->execute(
            'INSERT INTO ec_wishlist (user_id, product_id, added_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE added_at = VALUES(added_at)',
            [$customerId, $productId]
        );
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Could not save that product right now.'];
    }

    ecWishlistInvalidateCustomerCache($customerId);

    return [
        'ok' => true,
        'count' => ecWishlistCount($customerId),
        'already_wishlisted' => $alreadyWishlisted,
        'product' => $product,
    ];
}

function ecWishlistRemoveProduct(int $customerId, int $productId): array
{
    if (!ecWishlistStorageAvailable()) {
        return ['ok' => false, 'error' => 'Wishlist storage is unavailable.'];
    }

    if ($customerId <= 0) {
        return ['ok' => false, 'error' => 'Please sign in to manage your wishlist.'];
    }

    if ($productId <= 0) {
        return ['ok' => false, 'error' => 'Choose a valid product to remove.'];
    }

    $removed = ecWishlistContains($productId, $customerId);

    try {
        ecDb()->execute('DELETE FROM ec_wishlist WHERE user_id = ? AND product_id = ?', [$customerId, $productId]);
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Could not update your wishlist right now.'];
    }

    ecWishlistInvalidateCustomerCache($customerId);

    return [
        'ok' => true,
        'count' => ecWishlistCount($customerId),
        'removed' => $removed,
    ];
}

function ecWishlistClearForCustomer(int $customerId): array
{
    if (!ecWishlistStorageAvailable()) {
        return ['ok' => false, 'error' => 'Wishlist storage is unavailable.'];
    }

    if ($customerId <= 0) {
        return ['ok' => false, 'error' => 'Please sign in to manage your wishlist.'];
    }

    try {
        ecDb()->execute('DELETE FROM ec_wishlist WHERE user_id = ?', [$customerId]);
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Could not clear your wishlist right now.'];
    }

    ecWishlistInvalidateCustomerCache($customerId);

    return [
        'ok' => true,
        'count' => 0,
    ];
}

function ecWishlistProductsForCustomer(int $customerId, int $limit = 100): array
{
    $products = [];
    foreach (ecWishlistGetIdsForCustomer($customerId) as $productId) {
        if (count($products) >= max(1, $limit)) {
            break;
        }

        $product = ecProductGet($productId, false);
        if (!$product || (string)($product['status'] ?? '') !== 'published') {
            continue;
        }

        $products[] = $product;
    }

    return $products;
}

function ecWishlistCatalogItemsForCustomer(int $customerId, int $limit = 100, array $options = []): array
{
    $products = ecWishlistProductsForCustomer($customerId, $limit);
    ecWmsInventoryWarmProductCollection($products);

    $items = [];
    foreach ($products as $product) {
        $items[] = ecBuildStorefrontCatalogItem($product, [
            'item_base_url' => (string)($options['item_base_url'] ?? '/ecommerce/shop'),
        ]);
    }

    return $items;
}
