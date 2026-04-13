<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Public Store Pages (handlers/73-public-stores.php)
// Phase 3 multi-store: per-store landing pages + store directory.
// ─────────────────────────────────────────────────────────────────────────

/**
 * GET /ecommerce/stores  — public store directory listing all active stores.
 */
function ecPublicStoreDirectory(): void
{
    $routeContext = [
        'public_render_origin' => 'ecommerce',
        'public_route_kind'    => 'store_directory',
    ];

    ecWithPublicThemeRouteContext($routeContext, static function () use ($routeContext): void {
        $stores = ecStoreList(['active_only' => true])['items'] ?? [];

        ecRender('modules/ecommerce/public/stores.disyl', [
            'page_title'         => 'Our Stores',
            'stores'             => $stores,
            'public_route_kind'  => 'store_directory',
        ]);
    });
}

/**
 * GET /store/{slug}  — public single-store landing page.
 * Displays featured products scoped to the store.
 */
function ecPublicStorePage(array $params = []): void
{
    $slug = trim((string)($params['slug'] ?? ''));
    if ($slug === '') {
        http_response_code(404);
        return;
    }

    $store = ecStoreBySlug($slug);
    if (!is_array($store) || empty($store['is_active'])) {
        http_response_code(404);
        return;
    }

    $storeId = (int)($store['id'] ?? 0);
    $routeContext = [
        'public_render_origin' => 'ecommerce',
        'public_route_kind'    => 'store_page',
    ];

    ecWithPublicThemeRouteContext($routeContext, static function () use ($store, $storeId, $routeContext): void {
        $perPage = min(24, max(4, (int)ecSettings('products_per_page')));
        $productResult = ecProductList([
            'store_id' => $storeId,
            'status'   => 'published',
            'limit'    => $perPage,
            'offset'   => 0,
        ]);
        $products = function_exists('ecPublicDecorateCatalogProducts')
            ? ecPublicDecorateCatalogProducts($productResult['items'])
            : $productResult['items'];

        $inventorySource = ecStoreInventorySource($storeId);

        ecRender('modules/ecommerce/public/store-page.disyl', [
            'page_title'       => (string)($store['name'] ?? 'Store'),
            'store'            => $store,
            'products'         => $products,
            'total'            => (int)$productResult['total'],
            'inventory_source' => $inventorySource,
            'shop_url'         => '/ecommerce/shop?store=' . $storeId,
            'public_route_kind' => 'store_page',
        ]);
    });
}
