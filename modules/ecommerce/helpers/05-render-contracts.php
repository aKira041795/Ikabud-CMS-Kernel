<?php

declare(strict_types=1);

function ecPublicRoutePageKind(string $routeKind): string
{
    return match ($routeKind) {
        'shop_index', 'shop_category' => 'catalog',
        'product_detail' => 'detail',
        'cart' => 'cart',
        'checkout' => 'checkout',
        'my_orders' => 'orders',
        'order_detail' => 'order_detail',
        'order_confirmation' => 'order_confirmation',
        default => 'page',
    };
}

function ecPublicRouteDefaultPageTitle(string $routeKind): string
{
    return match ($routeKind) {
        'shop_index', 'shop_category' => 'Shop',
        'product_detail' => 'Product',
        'cart' => 'Cart',
        'checkout' => 'Checkout',
        'my_orders' => 'My Orders',
        'order_detail' => 'Order Details',
        'order_confirmation' => 'Order Confirmed',
        default => 'Storefront',
    };
}

function ecNormalizeRenderArrayList(array $items, string $pathPrefix, array &$typeMismatches): array
{
    $normalized = [];
    foreach ($items as $index => $item) {
        if (!is_array($item)) {
            $typeMismatches[$pathPrefix . '[' . $index . ']'] = gettype($item);
            continue;
        }

        $normalized[] = $item;
    }

    return $normalized;
}

function ecNormalizeProductInventoryShape(array $inventory, array &$missingKeys, array &$typeMismatches, string $pathPrefix): array
{
    $inventory = kernelApplyRenderContextShape($inventory, [
        'in_stock' => false,
        'out_of_stock' => false,
        'track_stock' => false,
        'low_stock' => false,
        'stock_qty' => 0,
        'badge' => [],
    ], ['in_stock', 'out_of_stock', 'track_stock', 'low_stock', 'stock_qty', 'badge'], $missingKeys, $typeMismatches, $pathPrefix);

    $inventory['badge'] = kernelApplyRenderContextShape($inventory['badge'], [
        'label' => '',
        'tone' => '',
    ], ['label', 'tone'], $missingKeys, $typeMismatches, $pathPrefix . 'badge.');

    return $inventory;
}

function ecNormalizeProductPricingShape(array $pricing, array &$missingKeys, array &$typeMismatches, string $pathPrefix): array
{
    return kernelApplyRenderContextShape($pricing, [
        'on_sale' => false,
        'formatted' => '',
        'regular_fmt' => '',
    ], ['on_sale', 'formatted', 'regular_fmt'], $missingKeys, $typeMismatches, $pathPrefix);
}

function ecNormalizeCatalogProductShape(array $product, array &$missingKeys, array &$typeMismatches, string $pathPrefix): array
{
    $product = kernelApplyRenderContextShape($product, [
        'title' => '',
        'slug' => '',
        'excerpt' => '',
        'url' => '',
        'primary_image_url' => '',
        'featured_image_url' => '',
        'pricing' => [],
        'inventory' => [],
        'badges' => [],
    ], ['title', 'slug', 'pricing', 'inventory', 'badges'], $missingKeys, $typeMismatches, $pathPrefix);

    $product['pricing'] = ecNormalizeProductPricingShape($product['pricing'], $missingKeys, $typeMismatches, $pathPrefix . 'pricing.');
    $product['inventory'] = ecNormalizeProductInventoryShape($product['inventory'], $missingKeys, $typeMismatches, $pathPrefix . 'inventory.');
    $product['badges'] = kernelApplyRenderContextShape($product['badges'], [
        'sale' => '',
    ], ['sale'], $missingKeys, $typeMismatches, $pathPrefix . 'badges.');

    return $product;
}

function ecNormalizeDetailProductShape(array $product, array &$missingKeys, array &$typeMismatches, string $pathPrefix): array
{
    $product = ecNormalizeCatalogProductShape($product, $missingKeys, $typeMismatches, $pathPrefix);
    $product = kernelApplyRenderContextShape($product, [
        'body' => '',
        'gallery_images' => [],
        'categories' => [],
    ], ['body', 'gallery_images', 'categories'], $missingKeys, $typeMismatches, $pathPrefix);

    $product['gallery_images'] = ecNormalizeRenderArrayList($product['gallery_images'], $pathPrefix . 'gallery_images', $typeMismatches);
    $product['categories'] = ecNormalizeRenderArrayList($product['categories'], $pathPrefix . 'categories', $typeMismatches);

    return $product;
}

function ecNormalizeCartContextShape(array $cart, array &$missingKeys, array &$typeMismatches, string $pathPrefix = 'cart.'): array
{
    $cart = kernelApplyRenderContextShape($cart, [
        'items' => [],
        'coupon_code' => '',
        'totals' => [],
    ], ['items', 'coupon_code', 'totals'], $missingKeys, $typeMismatches, $pathPrefix);

    $cart['items'] = ecNormalizeRenderArrayList($cart['items'], $pathPrefix . 'items', $typeMismatches);
    $cart['totals'] = kernelApplyRenderContextShape($cart['totals'], [
        'subtotal' => 0.0,
        'subtotal_fmt' => '',
        'discount' => 0.0,
        'discount_fmt' => '',
        'tax' => 0.0,
        'tax_fmt' => '',
        'tax_label' => 'Tax',
        'tax_rate' => 0.0,
        'tax_breakdown' => [],
        'total' => 0.0,
        'total_fmt' => '',
        'item_count' => 0,
        'coupon' => [],
    ], ['subtotal_fmt', 'discount_fmt', 'tax_fmt', 'tax_label', 'tax_breakdown', 'total_fmt', 'item_count', 'coupon'], $missingKeys, $typeMismatches, $pathPrefix . 'totals.');

    return $cart;
}

function ecNormalizeOrderContextShape(array $order, array &$missingKeys, array &$typeMismatches, string $pathPrefix = 'order.'): array
{
    $order = kernelApplyRenderContextShape($order, [
        'order_number' => '',
        'status' => '',
        'created_at' => '',
        'currency_symbol' => '',
        'subtotal_amount' => 0.0,
        'discount_amount' => 0.0,
        'tax_amount' => 0.0,
        'shipping_amount' => 0.0,
        'total_amount' => 0.0,
        'customer_email' => '',
        'customer_note' => '',
        'payment_status' => '',
        'items' => [],
        'shipping' => [],
        'billing' => [],
        'payment' => [],
    ], ['order_number', 'status', 'currency_symbol', 'total_amount', 'items', 'shipping', 'billing', 'payment'], $missingKeys, $typeMismatches, $pathPrefix);

    $order['items'] = ecNormalizeRenderArrayList($order['items'], $pathPrefix . 'items', $typeMismatches);
    $order['shipping'] = kernelApplyRenderContextShape($order['shipping'], [
        'first_name' => '',
        'last_name' => '',
        'address_line1' => '',
        'address_line2' => '',
        'city' => '',
        'state' => '',
        'postal_code' => '',
        'country' => '',
        'phone' => '',
    ], ['first_name', 'last_name', 'address_line1', 'city', 'state', 'postal_code', 'country', 'phone'], $missingKeys, $typeMismatches, $pathPrefix . 'shipping.');

    $order['billing'] = kernelApplyRenderContextShape($order['billing'], [
        'first_name' => '',
        'last_name' => '',
        'email' => '',
        'address_line1' => '',
        'address_line2' => '',
        'city' => '',
        'state' => '',
        'postal_code' => '',
        'country' => '',
        'phone' => '',
    ], ['first_name', 'last_name', 'email', 'address_line1', 'city', 'state', 'postal_code', 'country', 'phone'], $missingKeys, $typeMismatches, $pathPrefix . 'billing.');

    $order['payment'] = kernelApplyRenderContextShape($order['payment'], [
        'gateway' => '',
        'label' => '',
    ], ['gateway', 'label'], $missingKeys, $typeMismatches, $pathPrefix . 'payment.');

    return $order;
}

function ecNormalizeStorefrontShellContext(array $storefront, array $context, string $routeKind, string $presentationMode, array &$missingKeys, array &$typeMismatches): array
{
    $storefront = kernelApplyRenderContextShape($storefront, [
        'route' => [],
        'page' => [],
        'navigation' => [],
        'cart' => [],
    ], [], $missingKeys, $typeMismatches, 'storefront.');

    $storefront['route'] = kernelApplyRenderContextShape($storefront['route'], [
        'kind' => $routeKind,
        'mode' => $presentationMode,
    ], [], $missingKeys, $typeMismatches, 'storefront.route.');
    $storefront['route']['kind'] = trim((string)($storefront['route']['kind'] ?? '')) ?: $routeKind;
    $storefront['route']['mode'] = trim((string)($storefront['route']['mode'] ?? '')) ?: $presentationMode;

    $pageKind = ecPublicRoutePageKind($routeKind);
    $pageTitle = trim((string)($storefront['page']['title'] ?? $context['page_title'] ?? ''));
    if ($pageTitle === '') {
        $pageTitle = ecPublicRouteDefaultPageTitle($routeKind);
    }

    $storefront['page'] = kernelApplyRenderContextShape($storefront['page'], [
        'kind' => $pageKind,
        'title' => $pageTitle,
        'description' => '',
    ], [], $missingKeys, $typeMismatches, 'storefront.page.');
    $storefront['page']['kind'] = trim((string)($storefront['page']['kind'] ?? '')) ?: $pageKind;
    $storefront['page']['title'] = trim((string)($storefront['page']['title'] ?? '')) ?: $pageTitle;

    $defaultAllItemsUrl = trim((string)($context['all_items_url'] ?? '')) ?: '/ecommerce/shop';
    $defaultSearchActionUrl = trim((string)($context['search_action_url'] ?? '')) ?: '/ecommerce/shop';
    $storefront['navigation'] = kernelApplyRenderContextShape($storefront['navigation'], [
        'shop_url' => '/ecommerce/shop',
        'all_items_url' => $defaultAllItemsUrl,
        'search_action_url' => $defaultSearchActionUrl,
        'categories' => [],
    ], [], $missingKeys, $typeMismatches, 'storefront.navigation.');
    $storefront['navigation']['categories'] = ecNormalizeRenderArrayList($storefront['navigation']['categories'], 'storefront.navigation.categories', $typeMismatches);

    $storefront['cart'] = kernelApplyRenderContextShape($storefront['cart'], [
        'count' => (int)($context['cart_count'] ?? 0),
    ], [], $missingKeys, $typeMismatches, 'storefront.cart.');
    $storefront['cart']['count'] = max(0, (int)($storefront['cart']['count'] ?? $context['cart_count'] ?? 0));

    return $storefront;
}

function ecNormalizePublicShellRenderContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    $routeKind = ecNormalizePublicRouteKind(ecInferPublicRouteKind($template, $context));
    $presentationMode = ecResolvePublicPresentationMode($routeKind, $context);

    $context = kernelApplyRenderContextShape($context, [
        'page_title' => '',
        'storefront' => [],
        'cart_count' => 0,
        'ec_settings' => [],
        'public_render_origin' => 'ecommerce',
        'public_route_kind' => $routeKind,
        'public_presentation_mode' => $presentationMode,
        'theme_style_url' => '',
        'colors_style' => '',
        'theme_layout_style' => '',
        'custom_css' => '',
        'head_code' => '',
        'active_theme_slug' => '',
        'has_customized_header' => false,
        'customized_header' => '',
        'has_customized_footer' => false,
        'customized_footer' => '',
        'theme_script_url' => '',
        'body_end_code' => '',
        'year' => date('Y'),
    ], ['page_title', 'public_render_origin', 'public_route_kind', 'public_presentation_mode'], $missingKeys, $typeMismatches);

    if ($context['ec_settings'] === [] && function_exists('ecSettings')) {
        $settings = ecSettings();
        $context['ec_settings'] = is_array($settings) ? $settings : [];
    }

    $context['ec_settings'] = kernelApplyRenderContextShape($context['ec_settings'], [
        'currency_symbol' => '$',
    ], ['currency_symbol'], $missingKeys, $typeMismatches, 'ec_settings.');

    $context['cart_count'] = max(0, (int)($context['cart_count'] ?? 0));
    $context['public_render_origin'] = 'ecommerce';
    $context['public_route_kind'] = $routeKind;
    $context['public_presentation_mode'] = $presentationMode;
    $context['page_title'] = trim((string)($context['page_title'] ?? '')) ?: ecPublicRouteDefaultPageTitle($routeKind);

    $storefront = is_array($context['storefront']) ? $context['storefront'] : [];
    $context['storefront'] = ecNormalizeStorefrontShellContext($storefront, $context, $routeKind, $presentationMode, $missingKeys, $typeMismatches);

    return $context;
}

function ecNormalizeCatalogRenderContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    $context = ecNormalizePublicShellRenderContext($context, $template, $missingKeys, $typeMismatches);

    $context = kernelApplyRenderContextShape($context, [
        'products' => [],
        'categories' => [],
        'available_categories' => [],
        'current_cat' => [],
        'search' => '',
        'category_id' => 0,
        'page' => 1,
        'per_page' => 0,
        'total' => 0,
        'total_pages' => 1,
        'all_items_url' => '/ecommerce/shop',
        'search_action_url' => '/ecommerce/shop',
        'visible_count' => 0,
        'catalog_category_count' => 0,
        'pagination_first_url' => '',
        'pagination_prev_url' => '',
        'pagination_next_url' => '',
    ], ['products', 'available_categories', 'search', 'category_id', 'page', 'total', 'total_pages'], $missingKeys, $typeMismatches);

    $context['products'] = ecNormalizeRenderArrayList($context['products'], 'products', $typeMismatches);
    $context['categories'] = ecNormalizeRenderArrayList($context['categories'], 'categories', $typeMismatches);
    $context['available_categories'] = ecNormalizeRenderArrayList($context['available_categories'], 'available_categories', $typeMismatches);
    if ($context['available_categories'] === [] && $context['categories'] !== []) {
        $context['available_categories'] = $context['categories'];
    }
    if ($context['categories'] === [] && $context['available_categories'] !== []) {
        $context['categories'] = $context['available_categories'];
    }

    if ((int)$context['visible_count'] <= 0 && $context['products'] !== []) {
        $context['visible_count'] = count($context['products']);
    }
    if ((int)$context['catalog_category_count'] <= 0 && $context['available_categories'] !== []) {
        $context['catalog_category_count'] = count($context['available_categories']);
    }

    $storefront = is_array($context['storefront']) ? $context['storefront'] : [];
    $storefront = kernelApplyRenderContextShape($storefront, [
        'collection' => [],
        'filters' => [],
    ], ['collection', 'filters'], $missingKeys, $typeMismatches, 'storefront.');

    if (empty($storefront['navigation']['categories']) && $context['available_categories'] !== []) {
        $storefront['navigation']['categories'] = $context['available_categories'];
    }
    $storefront['navigation']['all_items_url'] = trim((string)($storefront['navigation']['all_items_url'] ?? '')) ?: (string)$context['all_items_url'];
    $storefront['navigation']['search_action_url'] = trim((string)($storefront['navigation']['search_action_url'] ?? '')) ?: (string)$context['search_action_url'];

    $storefront['filters'] = kernelApplyRenderContextShape($storefront['filters'], [
        'search' => (string)$context['search'],
        'category_id' => (int)$context['category_id'],
        'category_slug' => trim((string)(is_array($context['current_cat']) ? ($context['current_cat']['slug'] ?? '') : '')),
        'attribute_filters' => is_array($context['attribute_filters'] ?? null) ? $context['attribute_filters'] : [],
        'attribute_facets' => is_array($context['attribute_facets'] ?? null) ? $context['attribute_facets'] : [],
    ], ['search', 'category_id', 'category_slug'], $missingKeys, $typeMismatches, 'storefront.filters.');

    $storefront['collection'] = kernelApplyRenderContextShape($storefront['collection'], [
        'items' => [],
        'total' => (int)$context['total'],
        'pagination' => [],
    ], ['items', 'total', 'pagination'], $missingKeys, $typeMismatches, 'storefront.collection.');
    if ($storefront['collection']['items'] === [] && $context['products'] !== []) {
        $storefront['collection']['items'] = $context['products'];
    }
    $storefront['collection']['items'] = ecNormalizeRenderArrayList($storefront['collection']['items'], 'storefront.collection.items', $typeMismatches);
    $normalizedItems = [];
    foreach ($storefront['collection']['items'] as $index => $item) {
        $normalizedItems[] = ecNormalizeCatalogProductShape($item, $missingKeys, $typeMismatches, 'storefront.collection.items[' . $index . '].');
    }
    $storefront['collection']['items'] = $normalizedItems;
    $storefront['collection']['total'] = max(0, (int)($storefront['collection']['total'] ?? $context['total'] ?? 0));

    $storefront['collection']['pagination'] = kernelApplyRenderContextShape($storefront['collection']['pagination'], [
        'current' => (int)$context['page'],
        'total' => max(1, (int)$context['total_pages']),
        'first_url' => (string)$context['pagination_first_url'],
        'prev_url' => (string)$context['pagination_prev_url'],
        'next_url' => (string)$context['pagination_next_url'],
    ], ['current', 'total', 'first_url', 'prev_url', 'next_url'], $missingKeys, $typeMismatches, 'storefront.collection.pagination.');

    $context['storefront'] = $storefront;
    return $context;
}

function ecNormalizeProductRenderContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    $context = ecNormalizePublicShellRenderContext($context, $template, $missingKeys, $typeMismatches);

    $context = kernelApplyRenderContextShape($context, [
        'product' => [],
    ], ['product'], $missingKeys, $typeMismatches);

    $context['product'] = ecNormalizeDetailProductShape(
        is_array($context['product']) ? $context['product'] : [],
        $missingKeys,
        $typeMismatches,
        'product.'
    );

    $storefront = is_array($context['storefront']) ? $context['storefront'] : [];
    $storefront = kernelApplyRenderContextShape($storefront, [
        'product' => [],
    ], ['product'], $missingKeys, $typeMismatches, 'storefront.');

    $storefrontProduct = is_array($storefront['product']) ? $storefront['product'] : [];
    if ($storefrontProduct === [] && $context['product'] !== []) {
        $storefrontProduct = $context['product'];
    }
    $storefrontProduct = ecNormalizeDetailProductShape($storefrontProduct, $missingKeys, $typeMismatches, 'storefront.product.');
    $storefront['product'] = $storefrontProduct;
    $storefront['navigation']['shop_url'] = trim((string)($storefront['navigation']['shop_url'] ?? '')) ?: '/ecommerce/shop';

    if (trim((string)($storefront['page']['title'] ?? '')) === '') {
        $storefront['page']['title'] = trim((string)($storefrontProduct['title'] ?? $context['page_title'] ?? '')) ?: ecPublicRouteDefaultPageTitle('product_detail');
    }

    $context['storefront'] = $storefront;
    return $context;
}

function ecNormalizeCartRenderContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    $context = ecNormalizePublicShellRenderContext($context, $template, $missingKeys, $typeMismatches);

    $context = kernelApplyRenderContextShape($context, [
        'cart' => [],
        'shipping_rates' => [],
        'message' => [],
    ], ['cart', 'shipping_rates'], $missingKeys, $typeMismatches);

    $context['cart'] = ecNormalizeCartContextShape(is_array($context['cart']) ? $context['cart'] : [], $missingKeys, $typeMismatches);
    $context['shipping_rates'] = ecNormalizeRenderArrayList($context['shipping_rates'], 'shipping_rates', $typeMismatches);
    $message = is_array($context['message']) ? $context['message'] : [];
    $messageText = trim((string)($message['text'] ?? ''));
    if ($messageText === '') {
        $context['message'] = [];
        return $context;
    }

    $context['message'] = kernelApplyRenderContextShape(
        $message,
        ['type' => 'info', 'text' => $messageText],
        ['text'],
        $missingKeys,
        $typeMismatches,
        'message.'
    );
    $context['message']['text'] = $messageText;
    $context['message']['type'] = trim((string)($context['message']['type'] ?? '')) ?: 'info';

    return $context;
}

function ecNormalizeCheckoutRenderContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    $context = ecNormalizePublicShellRenderContext($context, $template, $missingKeys, $typeMismatches);

    $context = kernelApplyRenderContextShape($context, [
        'cart' => [],
        'shipping_rates' => [],
        'payment_label' => '',
        'is_customer' => false,
    ], ['cart', 'shipping_rates'], $missingKeys, $typeMismatches);

    $context['cart'] = ecNormalizeCartContextShape(is_array($context['cart']) ? $context['cart'] : [], $missingKeys, $typeMismatches);
    $context['shipping_rates'] = ecNormalizeRenderArrayList($context['shipping_rates'], 'shipping_rates', $typeMismatches);

    return $context;
}

function ecNormalizeOrdersListRenderContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    $context = ecNormalizePublicShellRenderContext($context, $template, $missingKeys, $typeMismatches);

    $context = kernelApplyRenderContextShape($context, [
        'orders' => [],
        'total' => 0,
        'page' => 1,
        'total_pages' => 1,
    ], ['orders', 'total', 'page', 'total_pages'], $missingKeys, $typeMismatches);

    $context['orders'] = ecNormalizeRenderArrayList($context['orders'], 'orders', $typeMismatches);

    return $context;
}

function ecNormalizeOrderDetailRenderContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    $context = ecNormalizePublicShellRenderContext($context, $template, $missingKeys, $typeMismatches);

    $context = kernelApplyRenderContextShape($context, [
        'order' => [],
    ], ['order'], $missingKeys, $typeMismatches);

    $context['order'] = ecNormalizeOrderContextShape(is_array($context['order']) ? $context['order'] : [], $missingKeys, $typeMismatches);

    return $context;
}

function ecNormalizeOrderConfirmationRenderContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    $context = ecNormalizeOrderDetailRenderContext($context, $template, $missingKeys, $typeMismatches);
    $context = kernelApplyRenderContextShape($context, [
        'is_logged_in' => false,
        'payment_label' => '',
    ], ['is_logged_in'], $missingKeys, $typeMismatches);

    if (trim((string)($context['payment_label'] ?? '')) === '') {
        $context['payment_label'] = trim((string)($context['order']['payment']['label'] ?? ''));
    }

    return $context;
}

kernelRegisterRenderContextContract('ecommerce.public.shell', [
    'prefix' => 'modules/ecommerce/public/',
    'priority' => 10,
    'normalize' => 'ecNormalizePublicShellRenderContext',
    'schema_id' => 'ecommerce.public.shell@1',
    'schema_version' => 1,
    'profile_hint' => 'commerce_public',
    'log_event' => 'ecommerce.render_context.contract_mismatch',
]);

kernelRegisterRenderContextContract('ecommerce.public.catalog', [
    'template' => 'modules/ecommerce/public/shop.disyl',
    'priority' => 20,
    'normalize' => 'ecNormalizeCatalogRenderContext',
    'schema_id' => 'ecommerce.public.catalog@1',
    'schema_version' => 1,
    'profile_hint' => 'commerce_public',
    'log_event' => 'ecommerce.render_context.contract_mismatch',
]);

kernelRegisterRenderContextContract('ecommerce.public.product', [
    'template' => 'modules/ecommerce/public/product.disyl',
    'priority' => 20,
    'normalize' => 'ecNormalizeProductRenderContext',
    'schema_id' => 'ecommerce.public.product@1',
    'schema_version' => 1,
    'profile_hint' => 'commerce_public',
    'log_event' => 'ecommerce.render_context.contract_mismatch',
]);

kernelRegisterRenderContextContract('ecommerce.public.cart', [
    'template' => 'modules/ecommerce/public/cart.disyl',
    'priority' => 20,
    'normalize' => 'ecNormalizeCartRenderContext',
    'schema_id' => 'ecommerce.public.cart@1',
    'schema_version' => 1,
    'profile_hint' => 'commerce_public',
    'log_event' => 'ecommerce.render_context.contract_mismatch',
]);

kernelRegisterRenderContextContract('ecommerce.public.checkout', [
    'template' => 'modules/ecommerce/public/checkout.disyl',
    'priority' => 20,
    'normalize' => 'ecNormalizeCheckoutRenderContext',
    'schema_id' => 'ecommerce.public.checkout@1',
    'schema_version' => 1,
    'profile_hint' => 'commerce_public',
    'log_event' => 'ecommerce.render_context.contract_mismatch',
]);

kernelRegisterRenderContextContract('ecommerce.public.orders', [
    'template' => 'modules/ecommerce/public/my-orders.disyl',
    'priority' => 20,
    'normalize' => 'ecNormalizeOrdersListRenderContext',
    'schema_id' => 'ecommerce.public.orders@1',
    'schema_version' => 1,
    'profile_hint' => 'commerce_public',
    'log_event' => 'ecommerce.render_context.contract_mismatch',
]);

kernelRegisterRenderContextContract('ecommerce.public.order.detail', [
    'template' => 'modules/ecommerce/public/order-detail.disyl',
    'priority' => 20,
    'normalize' => 'ecNormalizeOrderDetailRenderContext',
    'schema_id' => 'ecommerce.public.order.detail@1',
    'schema_version' => 1,
    'profile_hint' => 'commerce_public',
    'log_event' => 'ecommerce.render_context.contract_mismatch',
]);

kernelRegisterRenderContextContract('ecommerce.public.order.confirmation', [
    'template' => 'modules/ecommerce/public/order-confirmation.disyl',
    'priority' => 20,
    'normalize' => 'ecNormalizeOrderConfirmationRenderContext',
    'schema_id' => 'ecommerce.public.order.confirmation@1',
    'schema_version' => 1,
    'profile_hint' => 'commerce_public',
    'log_event' => 'ecommerce.render_context.contract_mismatch',
]);
