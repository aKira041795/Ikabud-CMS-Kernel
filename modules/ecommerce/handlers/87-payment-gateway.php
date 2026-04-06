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
    $token   = trim((string)($input['token'] ?? ''));

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

    // Ask the gateway to verify the intent status
    $result = ecPaymentGatewayVerify($orderId);

    if (!empty($result['ok']) && ($result['status'] ?? '') === 'succeeded') {
        // Mark as paid (idempotent)
        if (($order['payment_status'] ?? '') !== 'paid') {
            ecOrderMarkPaid($orderId);
        }
    }

    // Always redirect to confirmation page (status shown there)
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
