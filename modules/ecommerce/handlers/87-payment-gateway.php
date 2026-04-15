<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Payment Gateway Handlers (handlers/87-payment-gateway.php)
// ─────────────────────────────────────────────────────────────────────────

/**
 * GET /ecommerce/payment/return
 *
 * Redirect target after a payment gateway (e.g. PayMongo 3DS, e-wallet).
 * Verifies the intent status and redirects to order confirmation.
 */
function ecPaymentReturn(): void
{
    $input   = ecInput();
    $orderId = (int)($input['order_id'] ?? 0);
    $token   = trim((string)($input['confirmation_token'] ?? $input['order_token'] ?? $input['token'] ?? ''));

    if ($orderId < 1 || $token === '') {
        header('Location: /ecommerce/shop');
        exit;
    }

    // Verify the token matches the order (basic tamper guard)
    $order = ecOrderGet($orderId);
    if (!$order || ($order['confirmation_token'] ?? '') !== $token) {
        header('Location: /ecommerce/shop');
        exit;
    }

    if (!empty($input['cancelled'])) {
        header('Location: /ecommerce/order/' . urlencode($token));
        exit;
    }

    $gateway = trim((string)($order['payment']['gateway'] ?? ''));
    $reference = '';
    if ($gateway === 'stripe') {
        $reference = trim((string)($input['session_id'] ?? ($order['payment']['client_key'] ?? '')));
    } elseif ($gateway === 'paypal') {
        $reference = trim((string)($input['token'] ?? ($order['payment']['payment_intent_id'] ?? '')));
    } elseif ($gateway !== '' && $gateway !== 'manual') {
        $reference = trim((string)($input['intent_id'] ?? ($order['payment']['payment_intent_id'] ?? '')));
    }

    if ($reference !== '') {
        $result = ecPaymentGatewayVerify($reference, [
            'gateway' => $gateway,
            'order' => $order,
            'query' => $input,
            'capture' => $gateway === 'paypal',
        ]);

        if (!empty($result['ok']) && ($result['status'] ?? '') === 'succeeded') {
            if (($order['payment_status'] ?? '') !== 'paid') {
                ecOrderMarkPaid($orderId, [
                    'source' => $gateway . '_return',
                    'note' => 'Paid via ' . $gateway . ' payment return.',
                ]);
            }
        }
    }

    header('Location: /ecommerce/order/' . urlencode($token));
    exit;
}

/**
 * POST /api/v1/ecommerce/webhooks/paymongo
 *
 * Receives async payment event notifications from PayMongo.
 * Verifies HMAC signature, processes event, returns 200.
 */
function ecPaymongoWebhook(): void
{
    $rawBody   = file_get_contents('php://input');
    $sigHeader = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';

    if ($rawBody === '' || $rawBody === false) {
        http_response_code(400);
        echo json_encode(['error' => 'Empty body']);
        exit;
    }

    $result = ecPaymentGatewayWebhookHandle('paymongo', $rawBody, $sigHeader);

    if (!($result['ok'] ?? false)) {
        write_log('PayMongo webhook rejected: ' . ($result['error'] ?? 'unknown'), 'warning', [
            'module' => 'ecommerce',
        ]);
        http_response_code(400);
        echo json_encode(['error' => $result['error'] ?? 'Webhook processing failed']);
        exit;
    }

    http_response_code(200);
    echo json_encode(['received' => true]);
    exit;
}

/**
 * POST /api/v1/ecommerce/webhooks/stripe
 */
function ecStripeWebhook(): void
{
    $rawBody = file_get_contents('php://input');
    $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

    if ($rawBody === '' || $rawBody === false) {
        http_response_code(400);
        echo json_encode(['error' => 'Empty body']);
        exit;
    }

    $result = ecPaymentGatewayWebhookHandle('stripe', $rawBody, $sigHeader);

    if (!($result['ok'] ?? false)) {
        write_log('Stripe webhook rejected: ' . ($result['error'] ?? 'unknown'), 'warning', [
            'module' => 'ecommerce',
        ]);
        http_response_code(400);
        echo json_encode(['error' => $result['error'] ?? 'Webhook processing failed']);
        exit;
    }

    http_response_code(200);
    echo json_encode(['received' => true]);
    exit;
}

/**
 * POST /api/v1/ecommerce/webhooks/paypal
 */
function ecPaypalWebhook(): void
{
    $rawBody = file_get_contents('php://input');
    if ($rawBody === '' || $rawBody === false) {
        http_response_code(400);
        echo json_encode(['error' => 'Empty body']);
        exit;
    }

    $signaturePayload = json_encode([
        'paypal-transmission-id' => (string)($_SERVER['HTTP_PAYPAL_TRANSMISSION_ID'] ?? ''),
        'paypal-transmission-time' => (string)($_SERVER['HTTP_PAYPAL_TRANSMISSION_TIME'] ?? ''),
        'paypal-transmission-sig' => (string)($_SERVER['HTTP_PAYPAL_TRANSMISSION_SIG'] ?? ''),
        'paypal-cert-url' => (string)($_SERVER['HTTP_PAYPAL_CERT_URL'] ?? ''),
        'paypal-auth-algo' => (string)($_SERVER['HTTP_PAYPAL_AUTH_ALGO'] ?? ''),
    ], JSON_UNESCAPED_SLASHES);

    $result = ecPaymentGatewayWebhookHandle('paypal', $rawBody, (string)$signaturePayload);
    if (!($result['ok'] ?? false)) {
        write_log('PayPal webhook rejected: ' . ($result['error'] ?? 'unknown'), 'warning', [
            'module' => 'ecommerce',
        ]);
        http_response_code(400);
        echo json_encode(['error' => $result['error'] ?? 'Webhook processing failed']);
        exit;
    }

    http_response_code(200);
    echo json_encode(['received' => true]);
    exit;
}
