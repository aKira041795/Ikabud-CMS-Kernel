<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — API: Checkout (handlers/86-api-checkout.php)
//
// POST /api/v1/ecommerce/checkout
// Enforces CSRF, validates input, locks stock, creates order.
// ─────────────────────────────────────────────────────────────────────────

function ecApiCheckout(): void
{
    // CSRF check
    csrf_verify();

    $cart = ecCartGet();
    if (empty($cart['items'])) {
        ecJsonError('Cart is empty', 422);
    }

    $input = ecInput();

    // Validate required billing fields
    $billingRequired = ['first_name', 'last_name', 'email', 'address_line1', 'city', 'country'];
    $billing         = (array)($input['billing'] ?? []);
    foreach ($billingRequired as $field) {
        if (empty($billing[$field])) {
            ecJsonError('Billing field required: ' . $field, 422);
        }
    }

    // Validate email
    if (!filter_var($billing['email'], FILTER_VALIDATE_EMAIL)) {
        ecJsonError('Invalid email address', 422);
    }

    $shipping = (array)($input['shipping'] ?? $billing);

    $shippingRateId = isset($input['shipping_rate_id']) ? (int)$input['shipping_rate_id'] : null;
    $couponCode     = $cart['coupon_code'] ?? null;
    $totals         = ecCalculateTotals($cart['items'], $couponCode, $shippingRateId);

    $user       = app()->user();
    $customerId = ($user && in_array($user['role'] ?? '', ['subscriber', 'customer', 'editor', 'administrator'], true))
        ? (int)$user['id'] : null;

    // Guest checkout — ensure allowed
    if (!$customerId && !(bool)ecSettings('guest_checkout')) {
        ecJsonError('Guest checkout is not enabled', 403);
    }

    $orderData = [
        'cart_items'       => $cart['items'],
        'subtotal'         => $totals['subtotal'],
        'discount_amount'  => $totals['discount'],
        'tax_amount'       => $totals['tax'],
        'shipping_amount'  => $totals['shipping'],
        'total'            => $totals['total'],
        'currency'         => ecSettings('currency'),
        'coupon_code'      => $couponCode,
        'shipping_rate_id' => $shippingRateId,
        'source'           => 'web',
        'billing'          => $billing,
        'shipping'         => $shipping,
        'customer_id'      => $customerId,
        'guest_email'      => $customerId ? null : ($billing['email'] ?? null),
        'guest_name'       => $customerId ? null : trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? '')),
        'customer_note'    => trim((string)($input['customer_note'] ?? '')),
    ];

    try {
        $result = ecOrderCreate($orderData);
    } catch (\Throwable $e) {
        write_log('ecApiCheckout order creation failed: ' . $e->getMessage(), 'error', ['module' => 'ecommerce']);
        ecJsonError('Could not place order. Please try again.', 500);
    }

    // Clear cart
    ecCartClear();

    ecJsonOk([
        'order_id'     => $result['order_id'],
        'order_number' => $result['order_number'],
        'token'        => $result['confirmation_token'],
        'redirect_url' => '/ecommerce/order/' . $result['confirmation_token'],
    ], 201);
}
