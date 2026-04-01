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
    $message = $_SESSION['ec_message'] ?? ['type' => '', 'text' => ''];
    unset($_SESSION['ec_message']);

    ecRender('modules/ecommerce/public/cart.disyl', [
        'page_title'     => 'Cart',
        'cart'           => $cart,
        'shipping_rates' => $rates,
        'message'        => $message,
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

    if (!$productId) {
        $_SESSION['ec_message'] = ['type' => 'error', 'text' => 'Product could not be added to cart.'];
        header('Location: /ecommerce/cart');
        exit;
    }

    $result = ecCartAdd($productId, $qty, $variantId);

    $_SESSION['ec_message'] = $result['ok']
        ? ['type' => 'success', 'text' => 'Item added to cart.']
        : ['type' => 'error', 'text' => (string)($result['error'] ?? 'Could not add item to cart.')];

    header('Location: /ecommerce/cart');
    exit;
}
