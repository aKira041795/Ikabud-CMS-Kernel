<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Payment Gateway Abstraction (helpers/70-payment-gateways.php)
//
// Provides a gateway-agnostic interface for creating payment intents,
// verifying payment status, and processing webhook callbacks.
// ─────────────────────────────────────────────────────────────────────────

/**
 * Returns the active payment gateway configuration from ecommerce settings.
 */
function ecPaymentGatewayConfig(): array
{
    $gateway = trim((string)ecSettings('payment_gateway'));
    if ($gateway === '') {
        $gateway = 'manual';
    }

    return [
        'gateway'          => $gateway,
        'mode'             => trim((string)ecSettings('payment_gateway_mode')) ?: 'sandbox',
        'allowed_methods'  => array_filter(array_map('trim', explode(',', (string)ecSettings('paymongo_allowed_methods')))),
    ];
}

/**
 * Create a payment intent for an order via the configured gateway.
 *
 * @param int   $orderId   The local order ID.
 * @param float $amount    Total amount (in standard currency units, e.g. 100.50).
 * @param string $currency ISO currency code (e.g. PHP, USD).
 * @param array $options   Extra options: description, customer_email, return_url.
 * @return array {ok: bool, intent_id?: string, client_key?: string, checkout_url?: string, error?: string}
 */
function ecPaymentGatewayCreateIntent(int $orderId, float $amount, string $currency, array $options = []): array
{
    $config = ecPaymentGatewayConfig();

    if ($config['gateway'] === 'manual') {
        return ['ok' => true, 'gateway' => 'manual'];
    }

    if ($config['gateway'] === 'paymongo') {
        return _ecGatewayPaymongoCreateIntent($orderId, $amount, $currency, $config, $options);
    }

    return ['ok' => false, 'error' => 'Unknown payment gateway: ' . $config['gateway']];
}

/**
 * Verify/retrieve the current status of a payment intent.
 *
 * @return array {ok: bool, status: string, gateway_txn_id?: string, raw?: array}
 */
function ecPaymentGatewayVerify(string $intentId): array
{
    $config = ecPaymentGatewayConfig();

    if ($config['gateway'] === 'paymongo') {
        return _ecGatewayPaymongoVerify($intentId);
    }

    return ['ok' => false, 'error' => 'Unknown payment gateway: ' . $config['gateway']];
}

/**
 * Handle an incoming webhook payload from the gateway.
 *
 * @param string $gateway   The gateway identifier (e.g. 'paymongo').
 * @param string $rawBody   Raw request body.
 * @param string $signature Signature header value.
 * @return array {ok: bool, action?: string, order_id?: int, error?: string}
 */
function ecPaymentGatewayWebhookHandle(string $gateway, string $rawBody, string $signature): array
{
    if ($gateway === 'paymongo') {
        return _ecGatewayPaymongoWebhookHandle($rawBody, $signature);
    }

    return ['ok' => false, 'error' => 'Unknown webhook gateway: ' . $gateway];
}

// ── PayMongo gateway bridge (delegates to 71-gateway-paymongo.php) ──

function _ecGatewayPaymongoCreateIntent(int $orderId, float $amount, string $currency, array $config, array $options): array
{
    $methods = !empty($config['allowed_methods']) ? $config['allowed_methods'] : ['card', 'gcash', 'maya'];
    $description = $options['description'] ?? ('Order #' . $orderId);
    $returnUrl = $options['return_url'] ?? (ecGetBaseUrl() . '/ecommerce/payment/return');

    // PayMongo expects amount in centavos (smallest currency unit)
    $amountCentavos = (int)round($amount * 100);

    $result = ecPaymongoCreateIntent($amountCentavos, strtoupper($currency), $methods, $description);
    if (!$result['ok']) {
        return $result;
    }

    $intentId = $result['intent_id'];
    $clientKey = $result['client_key'] ?? '';

    // Persist the intent on the payment transaction record
    $db = ecDb();
    $db->execute(
        "UPDATE ec_payment_transactions SET gateway = 'paymongo', payment_intent_id = ?, client_key = ?, updated_at = NOW() WHERE order_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1",
        [$intentId, $clientKey, $orderId]
    );

    return [
        'ok'           => true,
        'gateway'      => 'paymongo',
        'intent_id'    => $intentId,
        'client_key'   => $clientKey,
        'checkout_url' => $result['checkout_url'] ?? '',
        'return_url'   => $returnUrl,
    ];
}

function _ecGatewayPaymongoVerify(string $intentId): array
{
    $result = ecPaymongoRetrieveIntent($intentId);
    if (!$result['ok']) {
        return $result;
    }

    $attrs = $result['data']['attributes'] ?? [];
    $status = (string)($attrs['status'] ?? 'unknown');

    // Map PayMongo status to our internal status
    $payments = $attrs['payments'] ?? [];
    $gatewayTxnId = '';
    if (!empty($payments)) {
        $lastPayment = end($payments);
        $gatewayTxnId = (string)($lastPayment['id'] ?? '');
    }

    return [
        'ok'             => true,
        'status'         => $status,
        'gateway_txn_id' => $gatewayTxnId,
        'raw'            => $result['data'],
    ];
}

function _ecGatewayPaymongoWebhookHandle(string $rawBody, string $signature): array
{
    $webhookSecret = trim((string)ecSettings('paymongo_webhook_secret'));
    if ($webhookSecret === '') {
        return ['ok' => false, 'error' => 'Webhook secret not configured'];
    }

    if (!ecPaymongoVerifyWebhook($rawBody, $signature, $webhookSecret)) {
        return ['ok' => false, 'error' => 'Invalid webhook signature'];
    }

    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'Invalid webhook payload'];
    }

    $eventType = (string)($payload['data']['attributes']['type'] ?? '');
    $paymentData = $payload['data']['attributes']['data'] ?? [];
    $paymentAttrs = $paymentData['attributes'] ?? [];
    $intentId = (string)($paymentAttrs['payment_intent_id'] ?? '');

    if ($eventType === 'payment.paid' && $intentId !== '') {
        // Look up order by payment_intent_id
        $db = ecDb();
        $row = $db->query(
            "SELECT order_id FROM ec_payment_transactions WHERE payment_intent_id = ? LIMIT 1",
            [$intentId]
        );

        if (!$row) {
            return ['ok' => false, 'error' => 'No order found for intent: ' . $intentId];
        }

        $orderId = (int)$row['order_id'];

        // Update transaction with gateway details
        $gatewayTxnId = (string)($paymentData['id'] ?? '');
        $db->execute(
            "UPDATE ec_payment_transactions SET gateway_txn_id = ?, gateway_response = ?, updated_at = NOW() WHERE payment_intent_id = ?",
            [$gatewayTxnId, json_encode($paymentData), $intentId]
        );

        // Mark order as paid (idempotent — ecOrderMarkPaid checks current status)
        $order = ecOrderGet($orderId);
        if ($order && ($order['payment_status'] ?? '') !== 'paid') {
            ecOrderMarkPaid($orderId);
        }

        try {
            app()->events()->fire('ecommerce.payment.webhook_received', [
                'gateway'    => 'paymongo',
                'event_type' => $eventType,
                'order_id'   => $orderId,
                'intent_id'  => $intentId,
            ]);
        } catch (\Throwable $e) {}

        return ['ok' => true, 'action' => 'order_paid', 'order_id' => $orderId];
    }

    return ['ok' => true, 'action' => 'ignored', 'event_type' => $eventType];
}
