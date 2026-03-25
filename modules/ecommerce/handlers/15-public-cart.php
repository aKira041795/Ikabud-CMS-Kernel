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
    $message = $_SESSION['ec_message'] ?? null;
    unset($_SESSION['ec_message']);

    ecRender('modules/ecommerce/public/cart.disyl', [
        'page_title'     => 'Cart',
        'cart'           => $cart,
        'shipping_rates' => $rates,
        'message'        => $message,
    ]);
}
