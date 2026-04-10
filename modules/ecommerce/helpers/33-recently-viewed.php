<?php

declare(strict_types=1);

define('EC_SESSION_RECENTLY_VIEWED_KEY', 'ec_recently_viewed_products');

function ecRecentlyViewedGetIds(): array
{
    $ids = $_SESSION[EC_SESSION_RECENTLY_VIEWED_KEY] ?? [];
    if (!is_array($ids)) {
        return [];
    }

    $normalized = [];
    foreach ($ids as $value) {
        $id = (int)$value;
        if ($id > 0) {
            $normalized[] = $id;
        }
    }

    return array_values(array_unique($normalized));
}

function ecRecentlyViewedClear(): void
{
    unset($_SESSION[EC_SESSION_RECENTLY_VIEWED_KEY]);
}

function ecRecentlyViewedRememberProduct(int $productId, int $maxItems = 8): array
{
    if ($productId < 1) {
        return ecRecentlyViewedGetIds();
    }

    $maxItems = max(1, $maxItems);
    $ids = ecRecentlyViewedGetIds();
    $ids = array_values(array_filter($ids, static fn(int $id): bool => $id !== $productId));
    array_unshift($ids, $productId);
    $ids = array_slice($ids, 0, $maxItems);

    $_SESSION[EC_SESSION_RECENTLY_VIEWED_KEY] = $ids;

    return $ids;
}

function ecRecentlyViewedProducts(int $excludeProductId = 0, int $limit = 4): array
{
    $ids = ecRecentlyViewedGetIds();
    if ($excludeProductId > 0) {
        $ids = array_values(array_filter($ids, static fn(int $id): bool => $id !== $excludeProductId));
    }
    if ($ids === []) {
        return [];
    }

    $products = [];
    foreach ($ids as $id) {
        if (count($products) >= $limit) {
            break;
        }

        $product = ecProductGet($id, false);
        if (!$product || (string)($product['status'] ?? '') !== 'published') {
            continue;
        }

        $products[] = $product;
    }

    return $products;
}

function ecRecentlyViewedCatalogItems(int $excludeProductId = 0, int $limit = 4, array $options = []): array
{
    $products = ecRecentlyViewedProducts($excludeProductId, $limit);
    $itemBaseUrl = (string)($options['item_base_url'] ?? '/ecommerce/shop');
    $items = [];

    foreach ($products as $product) {
        $items[] = ecBuildStorefrontCatalogItem($product, ['item_base_url' => $itemBaseUrl]);
    }

    return $items;
}