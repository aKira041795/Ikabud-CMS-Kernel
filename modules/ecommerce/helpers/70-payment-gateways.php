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
function ecPaymentGatewayConfig(?string $gatewayOverride = null): array
{
    $gateway = trim((string)($gatewayOverride ?? ecSettings('payment_gateway')));
    if ($gateway === '') {
        $gateway = 'manual';
    }

    $allowedMethodsSettingKey = match ($gateway) {
        'paymongo' => 'paymongo_allowed_methods',
        'stripe' => 'stripe_allowed_payment_methods',
        'paypal' => '',
        default => '',
    };

    $allowedMethods = [];
    if ($allowedMethodsSettingKey !== '') {
        $allowedMethods = array_values(array_filter(array_map(
            'trim',
            explode(',', (string)ecSettings($allowedMethodsSettingKey))
        )));
    }

    return [
        'gateway'          => $gateway,
        'mode'             => trim((string)ecSettings('payment_gateway_mode')) ?: 'sandbox',
        'allowed_methods'  => $allowedMethods,
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

    if ($config['gateway'] === 'stripe') {
        return _ecGatewayStripeCreateIntent($orderId, $amount, $currency, $config, $options);
    }

    if ($config['gateway'] === 'paypal') {
        return _ecGatewayPaypalCreateIntent($orderId, $amount, $currency, $config, $options);
    }

    return ['ok' => false, 'error' => 'Unknown payment gateway: ' . $config['gateway']];
}

/**
 * Verify/retrieve the current status of a payment intent.
 *
 * @return array {ok: bool, status: string, gateway_txn_id?: string, raw?: array}
 */
function ecPaymentGatewayVerify(string $reference, array $options = []): array
{
    $config = ecPaymentGatewayConfig(trim((string)($options['gateway'] ?? '')) ?: null);

    if ($config['gateway'] === 'paymongo') {
        return _ecGatewayPaymongoVerify($reference);
    }

    if ($config['gateway'] === 'stripe') {
        return _ecGatewayStripeVerify($reference, $options);
    }

    if ($config['gateway'] === 'paypal') {
        return _ecGatewayPaypalVerify($reference, $options);
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

    if ($gateway === 'stripe') {
        return _ecGatewayStripeWebhookHandle($rawBody, $signature);
    }

    if ($gateway === 'paypal') {
        return _ecGatewayPaypalWebhookHandle($rawBody, $signature);
    }

    return ['ok' => false, 'error' => 'Unknown webhook gateway: ' . $gateway];
}

/**
 * Request a gateway-native refund for an already-paid order.
 *
 * This is intentionally separate from the refund record workflow so future
 * refund automation can call into gateway-specific reversal logic.
 */
function ecPaymentGatewayRefund(int $orderId, float $amount, string $currency, array $options = []): array
{
    $order = ecOrderGet($orderId);
    if (!$order) {
        return ['ok' => false, 'error' => 'Order not found.'];
    }

    $gateway = trim((string)($options['gateway'] ?? ($order['payment']['gateway'] ?? '')));
    if ($gateway === '') {
        $gateway = trim((string)ecSettings('payment_gateway'));
    }
    if ($gateway === '' || $gateway === 'manual') {
        return ['ok' => false, 'error' => 'Manual payments do not support gateway refunds.'];
    }

    if ($gateway === 'stripe') {
        return _ecGatewayStripeRefund($order, $amount, $currency, $options);
    }

    if ($gateway === 'paypal') {
        return _ecGatewayPaypalRefund($order, $amount, $currency, $options);
    }

    return ['ok' => false, 'error' => 'Gateway refunds are not supported for: ' . $gateway];
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
            ecOrderMarkPaid($orderId, [
                'source' => 'paymongo_webhook',
                'event' => $eventType,
                'note' => 'Paid via PayMongo webhook (intent: ' . $intentId . ')',
            ]);
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

// ── Stripe gateway bridge (delegates to 72-gateway-stripe.php) ──

function _ecGatewayStripeCreateIntent(int $orderId, float $amount, string $currency, array $config, array $options): array
{
    $description = trim((string)($options['description'] ?? ('Order #' . $orderId))) ?: ('Order #' . $orderId);
    $returnUrl = trim((string)($options['return_url'] ?? (ecGetBaseUrl() . '/ecommerce/payment/return?order_id=' . $orderId)));
    $separator = str_contains($returnUrl, '?') ? '&' : '?';
    $successUrl = $returnUrl . $separator . 'session_id={CHECKOUT_SESSION_ID}';
    $cancelUrl = $returnUrl . $separator . 'cancelled=1';
    $amountMinor = (int)round($amount * 100);

    $result = ecStripeCreateCheckoutSession($amountMinor, strtoupper($currency), [
        'description' => $description,
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
        'customer_email' => (string)($options['customer_email'] ?? ''),
        'order_id' => $orderId,
        'order_number' => (string)($options['order_number'] ?? ''),
        'payment_method_types' => !empty($config['allowed_methods']) ? $config['allowed_methods'] : ['card'],
    ]);
    if (!$result['ok']) {
        return $result;
    }

    $paymentIntentId = trim((string)($result['payment_intent_id'] ?? ''));
    $sessionId = trim((string)($result['session_id'] ?? ''));

    ecDb()->execute(
        "UPDATE ec_payment_transactions SET gateway = 'stripe', payment_intent_id = ?, client_key = ?, updated_at = NOW() WHERE order_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1",
        [$paymentIntentId !== '' ? $paymentIntentId : null, $sessionId !== '' ? $sessionId : null, $orderId]
    );

    return [
        'ok' => true,
        'gateway' => 'stripe',
        'intent_id' => $paymentIntentId !== '' ? $paymentIntentId : $sessionId,
        'client_key' => $sessionId,
        'checkout_url' => (string)($result['checkout_url'] ?? ''),
        'return_url' => $returnUrl,
    ];
}

function _ecGatewayStripeVerify(string $reference, array $options = []): array
{
    if (str_starts_with($reference, 'cs_')) {
        $result = ecStripeRetrieveCheckoutSession($reference);
        if (!$result['ok']) {
            return $result;
        }

        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $status = (string)($data['payment_status'] ?? $data['status'] ?? 'unknown');
        if (($data['payment_status'] ?? '') === 'paid' || ($data['status'] ?? '') === 'complete') {
            $status = 'succeeded';
        }

        return [
            'ok' => true,
            'status' => $status,
            'gateway_txn_id' => (string)($data['payment_intent'] ?? $data['id'] ?? ''),
            'raw' => $data,
        ];
    }

    $result = ecStripeRetrievePaymentIntent($reference);
    if (!$result['ok']) {
        return $result;
    }

    $data = is_array($result['data'] ?? null) ? $result['data'] : [];
    return [
        'ok' => true,
        'status' => (string)($data['status'] ?? 'unknown'),
        'gateway_txn_id' => (string)($data['latest_charge'] ?? $data['id'] ?? ''),
        'raw' => $data,
    ];
}

function _ecGatewayPaymentTransactionOrderIdByField(string $field, string $value): int
{
    if ($value === '' || !in_array($field, ['payment_intent_id', 'client_key', 'gateway_txn_id'], true)) {
        return 0;
    }

    try {
        return (int)(ecDb()->query(
            'SELECT order_id FROM ec_payment_transactions WHERE ' . $field . ' = ? ORDER BY id DESC LIMIT 1',
            [$value]
        )->fetchColumn() ?: 0);
    } catch (\Throwable $e) {
        return 0;
    }
}

function _ecGatewayMarkOrderPaidFromWebhook(string $gateway, int $orderId, string $eventType, string $referenceId): array
{
    $order = ecOrderGet($orderId);
    if ($order && ($order['payment_status'] ?? '') !== 'paid') {
        ecOrderMarkPaid($orderId, [
            'source' => $gateway . '_webhook',
            'event' => $eventType,
            'note' => 'Paid via ' . $gateway . ' webhook (ref: ' . $referenceId . ')',
        ]);
    }

    try {
        app()->events()->fire('ecommerce.payment.webhook_received', [
            'gateway' => $gateway,
            'event_type' => $eventType,
            'order_id' => $orderId,
            'intent_id' => $referenceId,
        ]);
    } catch (\Throwable $e) {
    }

    return ['ok' => true, 'action' => 'order_paid', 'order_id' => $orderId];
}

function _ecGatewayStripeWebhookHandle(string $rawBody, string $signature): array
{
    $webhookSecret = trim((string)ecSettings('stripe_webhook_secret'));
    if ($webhookSecret === '') {
        return ['ok' => false, 'error' => 'Webhook secret not configured'];
    }

    if (!ecStripeVerifyWebhook($rawBody, $signature, $webhookSecret)) {
        return ['ok' => false, 'error' => 'Invalid webhook signature'];
    }

    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'Invalid webhook payload'];
    }

    $eventType = (string)($payload['type'] ?? '');
    $object = is_array($payload['data']['object'] ?? null) ? $payload['data']['object'] : [];

    if (in_array($eventType, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
        $sessionId = trim((string)($object['id'] ?? ''));
        $paymentIntentId = trim((string)($object['payment_intent'] ?? ''));
        $orderId = (int)($object['metadata']['order_id'] ?? $object['client_reference_id'] ?? 0);
        if ($orderId < 1 && $sessionId !== '') {
            $orderId = _ecGatewayPaymentTransactionOrderIdByField('client_key', $sessionId);
        }
        if ($orderId < 1 && $paymentIntentId !== '') {
            $orderId = _ecGatewayPaymentTransactionOrderIdByField('payment_intent_id', $paymentIntentId);
        }
        if ($orderId < 1) {
            return ['ok' => false, 'error' => 'No order found for Stripe checkout session'];
        }

        ecDb()->execute(
            "UPDATE ec_payment_transactions
             SET gateway = 'stripe',
                 payment_intent_id = COALESCE(?, payment_intent_id),
                 client_key = COALESCE(?, client_key),
                 gateway_txn_id = ?,
                 gateway_response = ?,
                 updated_at = NOW()
             WHERE order_id = ?
             ORDER BY id DESC LIMIT 1",
            [
                $paymentIntentId !== '' ? $paymentIntentId : null,
                $sessionId !== '' ? $sessionId : null,
                $paymentIntentId !== '' ? $paymentIntentId : $sessionId,
                json_encode($object, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $orderId,
            ]
        );

        if (($object['payment_status'] ?? '') === 'paid' || ($object['status'] ?? '') === 'complete') {
            return _ecGatewayMarkOrderPaidFromWebhook('stripe', $orderId, $eventType, $paymentIntentId !== '' ? $paymentIntentId : $sessionId);
        }

        return ['ok' => true, 'action' => 'ignored', 'event_type' => $eventType];
    }

    if ($eventType === 'payment_intent.succeeded') {
        $paymentIntentId = trim((string)($object['id'] ?? ''));
        $orderId = (int)($object['metadata']['order_id'] ?? 0);
        if ($orderId < 1 && $paymentIntentId !== '') {
            $orderId = _ecGatewayPaymentTransactionOrderIdByField('payment_intent_id', $paymentIntentId);
        }
        if ($orderId < 1) {
            return ['ok' => false, 'error' => 'No order found for Stripe payment intent'];
        }

        ecDb()->execute(
            "UPDATE ec_payment_transactions
             SET gateway = 'stripe',
                 payment_intent_id = COALESCE(?, payment_intent_id),
                 gateway_txn_id = ?,
                 gateway_response = ?,
                 updated_at = NOW()
             WHERE order_id = ?
             ORDER BY id DESC LIMIT 1",
            [
                $paymentIntentId !== '' ? $paymentIntentId : null,
                trim((string)($object['latest_charge'] ?? $paymentIntentId)),
                json_encode($object, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $orderId,
            ]
        );

        return _ecGatewayMarkOrderPaidFromWebhook('stripe', $orderId, $eventType, $paymentIntentId);
    }

    return ['ok' => true, 'action' => 'ignored', 'event_type' => $eventType];
}

function _ecGatewayStripeRefund(array $order, float $amount, string $currency, array $options = []): array
{
    $paymentIntentId = trim((string)($options['payment_intent_id'] ?? ($order['payment']['payment_intent_id'] ?? '')));
    if ($paymentIntentId === '') {
        return ['ok' => false, 'error' => 'No Stripe payment intent is stored for this order.'];
    }

    $amountMinor = (int)round(max(0.0, $amount) * 100);
    if ($amountMinor < 1) {
        return ['ok' => false, 'error' => 'Refund amount must be greater than zero.'];
    }

    $result = ecStripeCreateRefund($paymentIntentId, $amountMinor, [
        'reason' => (string)($options['reason'] ?? ''),
    ]);
    if (!$result['ok']) {
        return $result;
    }

    return [
        'ok' => true,
        'gateway' => 'stripe',
        'refund_id' => (string)($result['refund_id'] ?? ''),
        'status' => (string)($result['status'] ?? ''),
        'raw' => $result['raw'] ?? [],
    ];
}

// ── PayPal gateway bridge (delegates to 73-gateway-paypal.php) ──

function _ecGatewayPaypalCreateIntent(int $orderId, float $amount, string $currency, array $config, array $options): array
{
    $description = trim((string)($options['description'] ?? ('Order #' . $orderId))) ?: ('Order #' . $orderId);
    $returnUrl = trim((string)($options['return_url'] ?? (ecGetBaseUrl() . '/ecommerce/payment/return?order_id=' . $orderId)));
    $paypalReturnUrl = preg_replace('/([?&])token=/', '$1confirmation_token=', $returnUrl, 1) ?: $returnUrl;
    $separator = str_contains($paypalReturnUrl, '?') ? '&' : '?';
    $cancelUrl = $paypalReturnUrl . $separator . 'cancelled=1';

    $result = ecPaypalCreateOrder($amount, strtoupper($currency), [
        'description' => $description,
        'return_url' => $paypalReturnUrl,
        'cancel_url' => $cancelUrl,
        'customer_email' => (string)($options['customer_email'] ?? ''),
        'order_id' => $orderId,
        'order_number' => (string)($options['order_number'] ?? ''),
    ]);
    if (!$result['ok']) {
        return $result;
    }

    $paypalOrderId = trim((string)($result['order_id'] ?? ''));
    ecDb()->execute(
        "UPDATE ec_payment_transactions SET gateway = 'paypal', payment_intent_id = ?, updated_at = NOW() WHERE order_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1",
        [$paypalOrderId !== '' ? $paypalOrderId : null, $orderId]
    );

    return [
        'ok' => true,
        'gateway' => 'paypal',
        'intent_id' => $paypalOrderId,
        'checkout_url' => (string)($result['approve_url'] ?? ''),
        'return_url' => $paypalReturnUrl,
    ];
}

function _ecGatewayPaypalVerify(string $reference, array $options = []): array
{
    $capture = !array_key_exists('capture', $options) || !empty($options['capture']);
    $retrieve = ecPaypalRetrieveOrder($reference);
    if (!$retrieve['ok']) {
        return $retrieve;
    }

    $data = is_array($retrieve['data'] ?? null) ? $retrieve['data'] : [];
    $status = trim((string)($data['status'] ?? 'unknown'));

    if ($status !== 'COMPLETED' && $capture && in_array($status, ['APPROVED', 'PAYER_ACTION_REQUIRED'], true)) {
        $captureResult = ecPaypalCaptureOrder($reference);
        if (!$captureResult['ok']) {
            return $captureResult;
        }
        $data = is_array($captureResult['data'] ?? null) ? $captureResult['data'] : [];
        $status = trim((string)($data['status'] ?? $status));
    }

    $captureId = '';
    foreach ((array)($data['purchase_units'] ?? []) as $unit) {
        foreach ((array)($unit['payments']['captures'] ?? []) as $captureRow) {
            $captureId = trim((string)($captureRow['id'] ?? ''));
            if ($captureId !== '') {
                break 2;
            }
        }
    }

    if (!empty($options['order']['id'])) {
        ecDb()->execute(
            "UPDATE ec_payment_transactions
             SET gateway = 'paypal',
                 payment_intent_id = COALESCE(?, payment_intent_id),
                 gateway_txn_id = COALESCE(?, gateway_txn_id),
                 gateway_response = ?,
                 updated_at = NOW()
             WHERE order_id = ?
             ORDER BY id DESC LIMIT 1",
            [
                $reference !== '' ? $reference : null,
                $captureId !== '' ? $captureId : null,
                json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                (int)$options['order']['id'],
            ]
        );
    }

    return [
        'ok' => true,
        'status' => $status === 'COMPLETED' ? 'succeeded' : strtolower($status),
        'gateway_txn_id' => $captureId,
        'raw' => $data,
    ];
}

function _ecGatewayPaypalWebhookHandle(string $rawBody, string $signature): array
{
    $headers = json_decode($signature, true);
    if (!is_array($headers)) {
        return ['ok' => false, 'error' => 'Invalid webhook header payload'];
    }

    $verification = ecPaypalVerifyWebhookSignature($headers, $rawBody);
    if (!($verification['ok'] ?? false) || !($verification['verified'] ?? false)) {
        return ['ok' => false, 'error' => $verification['error'] ?? 'Invalid webhook signature'];
    }

    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'Invalid webhook payload'];
    }

    $eventType = trim((string)($payload['event_type'] ?? ''));
    $resource = is_array($payload['resource'] ?? null) ? $payload['resource'] : [];

    if ($eventType === 'PAYMENT.CAPTURE.COMPLETED') {
        $captureId = trim((string)($resource['id'] ?? ''));
        $paypalOrderId = trim((string)($resource['supplementary_data']['related_ids']['order_id'] ?? ''));
        $orderId = _ecGatewayPaymentTransactionOrderIdByField('payment_intent_id', $paypalOrderId);
        if ($orderId < 1 && $captureId !== '') {
            $orderId = _ecGatewayPaymentTransactionOrderIdByField('gateway_txn_id', $captureId);
        }
        if ($orderId < 1) {
            return ['ok' => false, 'error' => 'No order found for PayPal capture'];
        }

        ecDb()->execute(
            "UPDATE ec_payment_transactions
             SET gateway = 'paypal',
                 payment_intent_id = COALESCE(?, payment_intent_id),
                 gateway_txn_id = COALESCE(?, gateway_txn_id),
                 gateway_response = ?,
                 updated_at = NOW()
             WHERE order_id = ?
             ORDER BY id DESC LIMIT 1",
            [
                $paypalOrderId !== '' ? $paypalOrderId : null,
                $captureId !== '' ? $captureId : null,
                json_encode($resource, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $orderId,
            ]
        );

        return _ecGatewayMarkOrderPaidFromWebhook('paypal', $orderId, $eventType, $captureId !== '' ? $captureId : $paypalOrderId);
    }

    return ['ok' => true, 'action' => 'ignored', 'event_type' => $eventType];
}

function _ecGatewayPaypalRefund(array $order, float $amount, string $currency, array $options = []): array
{
    $captureId = trim((string)($options['capture_id'] ?? ($order['payment']['gateway_txn_id'] ?? '')));
    if ($captureId === '') {
        return ['ok' => false, 'error' => 'No PayPal capture ID is stored for this order.'];
    }

    $result = ecPaypalRefundCapture($captureId, $amount, strtoupper($currency), [
        'reason' => (string)($options['reason'] ?? ''),
    ]);
    if (!$result['ok']) {
        return $result;
    }

    return [
        'ok' => true,
        'gateway' => 'paypal',
        'refund_id' => (string)($result['refund_id'] ?? ''),
        'status' => (string)($result['status'] ?? ''),
        'raw' => $result['raw'] ?? [],
    ];
}
