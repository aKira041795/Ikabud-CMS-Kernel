<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Public Cart Handler (handlers/15-public-cart.php)
// ─────────────────────────────────────────────────────────────────────────

/**
 * GET /cart  — cart view page
 */
function ecPublicCart(): void
{
    $cart    = ecCartGet();
    $rates   = ecShippingRates();
    $cartStore = function_exists('ecCartResolvedStore') ? ecCartResolvedStore((array)($cart['items'] ?? [])) : null;
    $cartStoreSettings = is_array($cartStore) && function_exists('ecStoreSettingsArray') ? ecStoreSettingsArray($cartStore) : [];
    $message = $_SESSION['ec_message'] ?? null;
    if (!is_array($message) || trim((string)($message['text'] ?? '')) === '') {
        $message = null;
    }
    $relationSections = function_exists('ecCartRecommendationSections') ? ecCartRecommendationSections((array)($cart['items'] ?? [])) : [];
    unset($_SESSION['ec_message']);

    ecRender('modules/ecommerce/public/cart.disyl', [
        'page_title'     => 'Cart',
        'cart'           => $cart,
        'shipping_rates' => $rates,
        'message'        => $message,
        'store_currency_code' => (string)($cartStoreSettings['currency'] ?? ($cart['currency'] ?? '')),
        'store_currency_sym' => (string)($cartStoreSettings['currency_symbol'] ?? ($cart['currency_symbol'] ?? '')),
        'relation_sections' => $relationSections,
        'relation_display_variant' => 'compact',
    ]);
    
    if (function_exists('releaseSessionAfterRender')) {
        releaseSessionAfterRender();
    }
}

/**
 * POST /ecommerce/cart/add — storefront-friendly cart add that redirects to cart
 */
function ecPublicCartAdd(): void
{

    $input     = app()->input();
    $productId = (int)($input['product_id'] ?? $input['entity_id'] ?? 0);
    $qty       = max(1, (int)($input['qty'] ?? 1));
    $variantId = isset($input['variant_id']) ? (int)$input['variant_id'] : null;
    $options   = [
        'add_ons' => is_array($input['add_ons'] ?? null) ? $input['add_ons'] : [],
        'booking' => is_array($input['booking'] ?? null) ? $input['booking'] : [],
    ];

    if (!$productId) {
        $_SESSION['ec_message'] = ['type' => 'error', 'text' => 'Product could not be added to cart.'];
        header('Location: /ecommerce/cart');
        exit;
    }

    $result = ecCartAdd($productId, $qty, $variantId, $options);

    $cart = is_array($result['cart'] ?? null) ? $result['cart'] : ecCartGet();
    $itemCount = (int)($cart['totals']['item_count'] ?? 0);
    $totalFmt = trim((string)($cart['totals']['total_fmt'] ?? ''));
    $summaryText = $itemCount > 0 && $totalFmt !== ''
        ? 'Cart now has ' . $itemCount . ' item' . ($itemCount === 1 ? '' : 's') . ' totaling ' . $totalFmt . '.'
        : 'Item added to cart.';

    $_SESSION['ec_message'] = $result['ok']
        ? ['type' => 'success', 'text' => $summaryText]
        : ['type' => 'error', 'text' => (string)($result['error'] ?? 'Could not add item to cart.')];

    header('Location: /ecommerce/cart');
    exit;
}
