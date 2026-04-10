<?php

declare(strict_types=1);

define('EC_SESSION_COMPARE_KEY', 'ec_compare_products');
define('EC_COMPARE_MAX_ITEMS', 4);

function ecCompareGetIds(): array
{
    $ids = $_SESSION[EC_SESSION_COMPARE_KEY] ?? [];
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

function ecCompareGetIdLookup(): array
{
    return array_fill_keys(ecCompareGetIds(), true);
}

function ecCompareCount(): int
{
    return count(ecCompareGetIds());
}

function ecCompareContains(int $productId): bool
{
    return $productId > 0 && isset(ecCompareGetIdLookup()[$productId]);
}

function ecCompareSaveIds(array $ids): array
{
    $normalized = [];
    foreach ($ids as $value) {
        $id = (int)$value;
        if ($id > 0) {
            $normalized[] = $id;
        }
    }

    $normalized = array_values(array_unique($normalized));
    $_SESSION[EC_SESSION_COMPARE_KEY] = $normalized;

    return $normalized;
}

function ecCompareClear(): void
{
    unset($_SESSION[EC_SESSION_COMPARE_KEY]);
}

function ecCompareAddProduct(int $productId, int $maxItems = EC_COMPARE_MAX_ITEMS): array
{
    if ($productId < 1) {
        return ['ok' => false, 'error' => 'Choose a valid product to compare.'];
    }

    $product = ecProductGet($productId, false);
    if (!$product || (string)($product['status'] ?? '') !== 'published') {
        return ['ok' => false, 'error' => 'That product is unavailable for comparison.'];
    }

    $ids = ecCompareGetIds();
    $alreadyCompared = in_array($productId, $ids, true);
    $ids = array_values(array_filter($ids, static fn(int $id): bool => $id !== $productId));
    array_unshift($ids, $productId);
    $ids = array_slice($ids, 0, max(1, $maxItems));
    $ids = ecCompareSaveIds($ids);

    return [
        'ok' => true,
        'ids' => $ids,
        'count' => count($ids),
        'already_compared' => $alreadyCompared,
        'product' => $product,
    ];
}

function ecCompareRemoveProduct(int $productId): array
{
    $ids = ecCompareGetIds();
    $removed = in_array($productId, $ids, true);
    $ids = array_values(array_filter($ids, static fn(int $id): bool => $id !== $productId));
    $ids = ecCompareSaveIds($ids);

    return [
        'ok' => true,
        'ids' => $ids,
        'count' => count($ids),
        'removed' => $removed,
    ];
}

function ecCompareProducts(int $limit = EC_COMPARE_MAX_ITEMS): array
{
    $products = [];
    foreach (ecCompareGetIds() as $productId) {
        if (count($products) >= $limit) {
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

function ecCompareCatalogItems(int $limit = EC_COMPARE_MAX_ITEMS, array $options = []): array
{
    $itemBaseUrl = (string)($options['item_base_url'] ?? '/ecommerce/shop');
    $items = [];
    $products = ecCompareProducts($limit);
    ecWmsInventoryWarmProductCollection($products);
    foreach ($products as $product) {
        $items[] = ecBuildStorefrontCatalogItem($product, ['item_base_url' => $itemBaseUrl]);
    }

    return $items;
}

function ecCompareProductTypeLabel(array $product): string
{
    $type = trim((string)($product['product_type'] ?? 'physical'));
    if ($type === '') {
        $type = 'physical';
    }

    return ucwords(str_replace(['_', '-'], ' ', $type));
}

function ecCompareProductCategorySummary(array $product): string
{
    $names = [];
    foreach ((array)($product['categories'] ?? []) as $category) {
        if (!is_array($category)) {
            continue;
        }

        $name = trim((string)($category['name'] ?? ''));
        if ($name !== '') {
            $names[] = $name;
        }
    }

    return $names !== [] ? implode(', ', array_values(array_unique($names))) : '—';
}

function ecCompareProductAttributeSummary(array $product): string
{
    $parts = [];
    foreach ((array)($product['attributes'] ?? []) as $attribute) {
        if (!is_array($attribute)) {
            continue;
        }

        $name = trim((string)($attribute['name'] ?? $attribute['attribute_name'] ?? ''));
        $values = [];
        foreach ((array)($attribute['values'] ?? $attribute['options'] ?? []) as $value) {
            $valueText = trim((string)$value);
            if ($valueText !== '') {
                $values[] = $valueText;
            }
        }

        if ($values === []) {
            foreach (['value_label', 'value_name', 'value'] as $key) {
                $valueText = trim((string)($attribute[$key] ?? ''));
                if ($valueText !== '') {
                    $values[] = $valueText;
                }
            }
        }

        if ($values === []) {
            continue;
        }

        $parts[] = $name !== ''
            ? ($name . ': ' . implode(', ', array_values(array_unique($values))))
            : implode(', ', array_values(array_unique($values)));
    }

    return $parts !== [] ? implode('; ', $parts) : '—';
}

function ecCompareTableRows(array $products): array
{
    $catalogItems = [];
    ecWmsInventoryWarmProductCollection($products);
    foreach ($products as $product) {
        $catalogItems[] = ecBuildStorefrontCatalogItem($product, ['item_base_url' => '/ecommerce/shop']);
    }

    return [
        [
            'key' => 'price',
            'label' => 'Price',
            'values' => array_map(static fn(array $item): string => trim((string)($item['pricing']['formatted'] ?? '')) ?: 'Contact for price', $catalogItems),
        ],
        [
            'key' => 'availability',
            'label' => 'Availability',
            'values' => array_map(static fn(array $item): string => trim((string)($item['inventory']['badge']['label'] ?? '')) ?: '—', $catalogItems),
        ],
        [
            'key' => 'rating',
            'label' => 'Rating',
            'values' => array_map(static function (array $product): string {
                $summary = is_array($product['review_summary'] ?? null)
                    ? ecReviewNormalizeSummary($product['review_summary'])
                    : ecReviewDefaultSummary();
                if (empty($summary['has_reviews'])) {
                    return 'No reviews yet';
                }

                return (string)($summary['average_rating_formatted'] ?? '0.0') . '/5';
            }, $products),
        ],
        [
            'key' => 'sku',
            'label' => 'SKU',
            'values' => array_map(static function (array $product): string {
                $sku = trim((string)($product['inventory']['sku'] ?? $product['sku'] ?? ''));
                return $sku !== '' ? $sku : '—';
            }, $products),
        ],
        [
            'key' => 'type',
            'label' => 'Type',
            'values' => array_map('ecCompareProductTypeLabel', $products),
        ],
        [
            'key' => 'categories',
            'label' => 'Categories',
            'values' => array_map('ecCompareProductCategorySummary', $products),
        ],
        [
            'key' => 'attributes',
            'label' => 'Attributes',
            'values' => array_map('ecCompareProductAttributeSummary', $products),
        ],
        [
            'key' => 'summary',
            'label' => 'Summary',
            'values' => array_map(static function (array $product): string {
                $summary = trim((string)($product['excerpt'] ?? ''));
                return $summary !== '' ? $summary : '—';
            }, $products),
        ],
    ];
}