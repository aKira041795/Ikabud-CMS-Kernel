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

    // F19: Rate limit checkout to 3 submissions per 5 minutes per IP.
    try {
        if (PHP_SAPI !== 'cli') {
        $rlId = kernelLoginRateLimitIdentifier('ecommerce.checkout');
        $rlDb = app()->db();
        $rlCutoff = date('Y-m-d H:i:s', time() - 300);
        $rlDb->prepare(
            'INSERT INTO rate_limits (identifier, action, attempts, window_start)
             VALUES (:id, :act, 1, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE
                 attempts     = IF(window_start >= :c1, attempts + 1, 1),
                 window_start = IF(window_start >= :c2, window_start, CURRENT_TIMESTAMP)'
        )->execute([':id' => $rlId, ':act' => 'checkout_submit', ':c1' => $rlCutoff, ':c2' => $rlCutoff]);
        $rlRow = $rlDb->prepare('SELECT attempts, window_start FROM rate_limits WHERE identifier = :id AND action = :act LIMIT 1');
        $rlRow->execute([':id' => $rlId, ':act' => 'checkout_submit']);
        $rlData = $rlRow->fetch(\PDO::FETCH_ASSOC);
        if (is_array($rlData) && ($rlData['window_start'] ?? '') >= $rlCutoff && (int)($rlData['attempts'] ?? 0) > 3) {
            ecJsonError('Too many checkout attempts. Please wait a few minutes.', 429);
        }
        }
    } catch (\Throwable $ignored) {
        // Non-fatal: proceed if rate_limits table unavailable.
    }

    $cart = ecCartGet();
    if (empty($cart['items'])) {
        ecJsonError('Cart is empty', 422);
    }
    if (!empty($cart['subscription']) && empty($cart['subscription']['is_valid'])) {
        ecJsonError((string)($cart['subscription']['errors'][0] ?? 'Your cart contains an invalid subscription combination.'), 422);
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
    $user       = app()->user();
    $customerId = ($user && in_array($user['role'] ?? '', ['subscriber', 'customer', 'editor', 'administrator'], true))
        ? (int)$user['id'] : null;

    $shippingRateId = isset($input['shipping_rate_id']) ? (int)$input['shipping_rate_id'] : null;
    $couponCode     = $cart['coupon_code'] ?? null;
    $requiresShipping = function_exists('ecCartRequiresShipping') ? ecCartRequiresShipping($cart['items']) : false;

    if ($requiresShipping) {
        $shippingQuote = function_exists('ecShippingQuote')
            ? ecShippingQuote($cart['items'], $shipping, $couponCode, $shippingRateId, [
                'customer_id' => $customerId > 0 ? $customerId : null,
                'loyalty_points' => function_exists('ecCartSelectedLoyaltyPoints') ? ecCartSelectedLoyaltyPoints() : 0,
            ])
            : null;
        if (!is_array($shippingQuote) || empty($shippingQuote['rates'])) {
            ecJsonError('No shipping rates are available for this destination.', 422);
        }

        $resolvedRateId = isset($shippingQuote['selected_rate_id']) ? (int)$shippingQuote['selected_rate_id'] : 0;
        if ($shippingRateId !== null && $shippingRateId !== 0 && $resolvedRateId !== $shippingRateId) {
            ecJsonError('The selected shipping method is no longer available.', 422);
        }

        $shippingRateId = $resolvedRateId > 0 || $resolvedRateId < 0 ? $resolvedRateId : null;
        $totals = is_array($shippingQuote['totals'] ?? null)
            ? $shippingQuote['totals']
            : ecCalculateTotals($cart['items'], $couponCode, $shippingRateId, $shipping, [
                'customer_id' => $customerId > 0 ? $customerId : null,
                'loyalty_points' => function_exists('ecCartSelectedLoyaltyPoints') ? ecCartSelectedLoyaltyPoints() : 0,
            ]);
    } else {
        $shippingRateId = null;
        $totals = ecCalculateTotals($cart['items'], $couponCode, null, $shipping, [
            'customer_id' => $customerId > 0 ? $customerId : null,
            'loyalty_points' => function_exists('ecCartSelectedLoyaltyPoints') ? ecCartSelectedLoyaltyPoints() : 0,
        ]);
    }

    // Guest checkout — ensure allowed
    $checkoutStore = function_exists('ecCartResolvedStore') ? ecCartResolvedStore((array)($cart['items'] ?? [])) : null;
    if (!$customerId && !(bool)ecStoreAwareSetting('guest_checkout', $checkoutStore)) {
        ecJsonError('Guest checkout is not enabled', 403);
    }

    // Digital product enforcement — auto-register guest when required
    $cartHasDigital = ecCartHasDigitalItems($cart['items']);
    if ($cartHasDigital && !$customerId) {
        if ((bool)ecStoreAwareSetting('require_account_for_digital', $checkoutStore)) {
            // Rate-limit account creation from this IP to prevent bulk account floods.
            // Re-uses the kernel rate_limits table with a distinct 'checkout_register' action.
            try {
                $rlId  = kernelLoginRateLimitIdentifier('ecommerce');
                $rlDb  = app()->db();
                $rlCutoff = date('Y-m-d H:i:s', time() - 3600);
                $rlDb->prepare(
                    'INSERT INTO rate_limits (identifier, action, attempts, window_start)
                     VALUES (:id, :act, 1, CURRENT_TIMESTAMP)
                     ON DUPLICATE KEY UPDATE
                         attempts    = IF(window_start >= :c1, attempts + 1, 1),
                         window_start = IF(window_start >= :c2, window_start, CURRENT_TIMESTAMP)'
                )->execute([':id' => $rlId, ':act' => 'checkout_register', ':c1' => $rlCutoff, ':c2' => $rlCutoff]);
                $rlRow = $rlDb->prepare('SELECT attempts, window_start FROM rate_limits WHERE identifier = :id AND action = :act LIMIT 1');
                $rlRow->execute([':id' => $rlId, ':act' => 'checkout_register']);
                $rlData = $rlRow->fetch(\PDO::FETCH_ASSOC);
                if (is_array($rlData) && ($rlData['window_start'] ?? '') >= $rlCutoff && (int)($rlData['attempts'] ?? 0) > 10) {
                    ecJsonError('Too many registration attempts. Please try again later.', 429);
                }
            } catch (\Throwable $ignored) {
                // Non-fatal: proceed if rate_limits table unavailable
            }
            // Auto-register the guest using their billing email
            $autoId = ecAutoRegisterGuestAsCustomer(
                $billing['email'] ?? '',
                $billing['first_name'] ?? '',
                $billing['last_name'] ?? ''
            );
            if ($autoId !== null) {
                $customerId = $autoId;
            }
            // If auto-registration fails, order still proceeds as guest (email delivery only)
        }
    }

    $orderData = [
        'cart_items'       => $cart['items'],
        'subtotal'         => $totals['subtotal'],
        'discount_amount'  => $totals['discount'],
        'tax_amount'       => $totals['tax'],
        'shipping_amount'  => $totals['shipping'],
        'total'            => $totals['total'],
        'currency'         => (string)($cart['currency'] ?? $totals['currency'] ?? ecStoreBaseCurrencyCode()),
        'store_id'         => function_exists('ecCartResolvedStoreId') ? ecCartResolvedStoreId((array)($cart['items'] ?? [])) : 0,
        'coupon_code'      => $couponCode,
        'shipping_rate_id' => $shippingRateId,
        'source'           => 'web',
        'billing'          => $billing,
        'shipping'         => $shipping,
        'customer_id'      => $customerId,
        'guest_email'      => $customerId ? null : ($billing['email'] ?? null),
        'guest_name'       => $customerId ? null : trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? '')),
        'customer_note'    => trim((string)($input['customer_note'] ?? '')),
        'loyalty_points_redeemed' => (int)($totals['loyalty_points_applied'] ?? 0),
        'loyalty_discount_amount' => (float)($totals['loyalty_discount_amount'] ?? 0.0),
        'defer_created_event' => true,
    ];

    if (ecAbandonedCartEnabled()) {
        ecAbandonedCartCaptureLead([
            'guest_email' => (string)($billing['email'] ?? ''),
            'guest_name' => trim((string)($billing['first_name'] ?? '') . ' ' . (string)($billing['last_name'] ?? '')),
            'first_name' => (string)($billing['first_name'] ?? ''),
            'last_name' => (string)($billing['last_name'] ?? ''),
        ], $cart);
    }

    try {
        $result = ecOrderCreate($orderData);
    } catch (\Throwable $e) {
        // Stock-specific failure: return a clear message so the customer can adjust
        if ($e->getCode() === 409) {
            $errorData = @json_decode($e->getMessage(), true);
            if (is_array($errorData) && ($errorData['type'] ?? '') === 'insufficient_stock') {
                $title = $errorData['product_title'] ?? 'an item';
                write_log('ecApiCheckout stock gate: insufficient stock for product ' . ($errorData['product_id'] ?? '?'), 'info', ['module' => 'ecommerce']);
                ecJsonError("Sorry, \"{$title}\" is out of stock or has insufficient quantity. Please update your cart and try again.", 409);
            }
        }
        write_log('ecApiCheckout order creation failed: ' . $e->getMessage(), 'error', ['module' => 'ecommerce']);
        ecJsonError('Could not place order. Please try again.', 500);
    }

    // Clear cart
    ecCartClear();

    ecAbandonedCartMarkRecovered(
        (int)$result['order_id'],
        $customerId,
        (string)($billing['email'] ?? ''),
        ecAbandonedCartCurrentRecoveryToken()
    );

    try {
        \Ikabud\Kernel\IntegrationBridge::handle((array)($result['created_event_payload'] ?? []), 'ecommerce.order.created');
    } catch (\Throwable $e) {
        write_log('ecApiCheckout inline bridge dispatch failed: ' . $e->getMessage(), 'warning', [
            'module' => 'ecommerce',
            'order_id' => (int)$result['order_id'],
        ]);
    }

    $responseData = [
        'order_id'     => $result['order_id'],
        'order_number' => $result['order_number'],
        'token'        => $result['confirmation_token'],
        'redirect_url' => '/ecommerce/order/' . $result['confirmation_token'],
    ];

    // ── Payment gateway processing ──────────────────────────────────
    $gatewayConfig = ecPaymentGatewayConfig();

    if ($gatewayConfig['gateway'] !== 'manual') {
        $intentResult = ecPaymentGatewayCreateIntent(
            (int)$result['order_id'],
            (float)$totals['total'],
            $orderData['currency'],
            [
                'description'    => 'Order ' . $result['order_number'],
                'customer_email' => $billing['email'] ?? '',
                'return_url'     => ecGetBaseUrl() . '/ecommerce/payment/return?order_id=' . $result['order_id'] . '&token=' . urlencode($result['confirmation_token']),
            ]
        );

        if (!empty($intentResult['ok']) && !empty($intentResult['checkout_url'])) {
            $responseData['redirect_url'] = $intentResult['checkout_url'];
            $responseData['payment_gateway'] = $gatewayConfig['gateway'];
            $responseData['intent_id'] = $intentResult['intent_id'] ?? '';
        } elseif (!empty($intentResult['ok']) && !empty($intentResult['intent_id'])) {
            // Intent created but no redirect URL yet (client-side attach needed)
            $responseData['payment_gateway'] = $gatewayConfig['gateway'];
            $responseData['intent_id'] = $intentResult['intent_id'] ?? '';
            $responseData['client_key'] = $intentResult['client_key'] ?? '';
        } elseif (!($intentResult['ok'] ?? false)) {
            // Gateway failed — log but don't block the order (fall back to manual)
            write_log('Payment gateway intent creation failed, falling back to manual', 'warning', [
                'module'   => 'ecommerce',
                'order_id' => $result['order_id'],
                'error'    => $intentResult['error'] ?? 'unknown',
            ]);
        }
    }

    http_response_code(201);
    header('Content-Type: application/json');
    $response = json_encode(array_merge(['ok' => true], $responseData), JSON_UNESCAPED_UNICODE);
    echo $response;
    release_session_lock_if_active();
    finish_response_if_possible();

    try {
        if (function_exists('ecSendAdminOrderNotification')) {
            ecSendAdminOrderNotification((array)($result['created_event_payload'] ?? []));
        }
        if (function_exists('ecSendCustomerOrderConfirmation')) {
            ecSendCustomerOrderConfirmation((array)($result['created_event_payload'] ?? []));
        }
    } catch (\Throwable $e) {
        write_log('ecApiCheckout deferred order notifications failed: ' . $e->getMessage(), 'warning', [
            'module' => 'ecommerce',
            'order_id' => (int)$result['order_id'],
        ]);
    }

    exit;
}

function ecApiShippingRates(): void
{
    $cart = ecCartGet();
    $input = ecInput();
    $address = (array)($input['shipping'] ?? $input['billing'] ?? $input['address'] ?? []);
    $selectedRateId = isset($input['selected_rate_id']) ? (int)$input['selected_rate_id'] : null;
    $quote = function_exists('ecShippingQuote')
        ? ecShippingQuote((array)($cart['items'] ?? []), $address, $cart['coupon_code'] ?? null, $selectedRateId, [
            'customer_id' => (int)(app()->user()['id'] ?? 0),
            'loyalty_points' => function_exists('ecCartSelectedLoyaltyPoints') ? ecCartSelectedLoyaltyPoints() : 0,
        ])
        : [
            'requires_shipping' => false,
            'rates' => [],
            'selected_rate_id' => null,
            'selected_rate' => null,
            'totals' => ecCalculateTotals((array)($cart['items'] ?? []), $cart['coupon_code'] ?? null, null, $address, [
                'customer_id' => (int)(app()->user()['id'] ?? 0),
                'loyalty_points' => function_exists('ecCartSelectedLoyaltyPoints') ? ecCartSelectedLoyaltyPoints() : 0,
            ]),
        ];

    ecJsonOk([
        'requires_shipping' => !empty($quote['requires_shipping']),
        'rates' => array_values((array)($quote['rates'] ?? [])),
        'selected_rate_id' => $quote['selected_rate_id'] ?? null,
        'selected_rate' => $quote['selected_rate'] ?? null,
        'totals' => $quote['totals'] ?? [],
    ]);
}

function ecCheckoutApiDeferredEventEnabled(): bool
{
    return true;
}
