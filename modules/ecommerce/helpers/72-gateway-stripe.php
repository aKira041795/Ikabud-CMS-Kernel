<?php

declare(strict_types=1);

define('EC_STRIPE_API_BASE', 'https://api.stripe.com/v1');

function ecStripeCreateCheckoutSession(int $amountMinor, string $currency, array $options = []): array
{
    $description = trim((string)($options['description'] ?? 'Order payment')) ?: 'Order payment';
    $successUrl = trim((string)($options['success_url'] ?? ''));
    $cancelUrl = trim((string)($options['cancel_url'] ?? $successUrl));
    $paymentMethods = array_values(array_filter(array_map(
        'trim',
        (array)($options['payment_method_types'] ?? ['card'])
    )));
    if ($paymentMethods === []) {
        $paymentMethods = ['card'];
    }

    $body = [
        'mode' => 'payment',
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
        'payment_method_types' => $paymentMethods,
        'line_items' => [[
            'quantity' => 1,
            'price_data' => [
                'currency' => strtolower($currency),
                'unit_amount' => $amountMinor,
                'product_data' => [
                    'name' => $description,
                ],
            ],
        ]],
        'client_reference_id' => isset($options['order_id']) ? (string)$options['order_id'] : '',
        'metadata' => array_filter([
            'order_id' => isset($options['order_id']) ? (string)$options['order_id'] : '',
            'order_number' => (string)($options['order_number'] ?? ''),
        ], static fn(string $value): bool => $value !== ''),
    ];

    $customerEmail = trim((string)($options['customer_email'] ?? ''));
    if ($customerEmail !== '') {
        $body['customer_email'] = $customerEmail;
    }

    $response = _ecStripeRequest('POST', '/checkout/sessions', $body);
    if (!$response['ok']) {
        return $response;
    }

    $data = is_array($response['data'] ?? null) ? $response['data'] : [];

    return [
        'ok' => true,
        'session_id' => (string)($data['id'] ?? ''),
        'checkout_url' => (string)($data['url'] ?? ''),
        'payment_intent_id' => (string)($data['payment_intent'] ?? ''),
        'status' => (string)($data['status'] ?? ''),
        'payment_status' => (string)($data['payment_status'] ?? ''),
        'raw' => $data,
    ];
}

function ecStripeRetrieveCheckoutSession(string $sessionId): array
{
    return _ecStripeRequest('GET', '/checkout/sessions/' . rawurlencode($sessionId));
}

function ecStripeRetrievePaymentIntent(string $intentId): array
{
    return _ecStripeRequest('GET', '/payment_intents/' . rawurlencode($intentId));
}

function ecStripeCreateRefund(string $paymentIntentId, ?int $amountMinor = null, array $options = []): array
{
    $body = [
        'payment_intent' => $paymentIntentId,
    ];
    if ($amountMinor !== null && $amountMinor > 0) {
        $body['amount'] = $amountMinor;
    }

    $reason = trim((string)($options['reason'] ?? ''));
    if ($reason !== '') {
        $body['reason'] = 'requested_by_customer';
        $body['metadata'] = ['reason_detail' => $reason];
    }

    $response = _ecStripeRequest('POST', '/refunds', $body);
    if (!$response['ok']) {
        return $response;
    }

    $data = is_array($response['data'] ?? null) ? $response['data'] : [];
    return [
        'ok' => true,
        'refund_id' => (string)($data['id'] ?? ''),
        'status' => (string)($data['status'] ?? ''),
        'raw' => $data,
    ];
}

function ecStripeVerifyWebhook(string $rawBody, string $sigHeader, string $webhookSecret, int $toleranceSeconds = 300): bool
{
    if ($rawBody === '' || $sigHeader === '' || $webhookSecret === '') {
        return false;
    }

    $parts = [];
    foreach (explode(',', $sigHeader) as $segment) {
        $kv = explode('=', trim($segment), 2);
        if (count($kv) === 2) {
            $parts[$kv[0]][] = $kv[1];
        }
    }

    $timestamp = (int)($parts['t'][0] ?? 0);
    $signatures = (array)($parts['v1'] ?? []);
    if ($timestamp <= 0 || $signatures === []) {
        return false;
    }

    if ($toleranceSeconds > 0 && abs(time() - $timestamp) > $toleranceSeconds) {
        return false;
    }

    $signedPayload = $timestamp . '.' . $rawBody;
    $expected = hash_hmac('sha256', $signedPayload, $webhookSecret);
    foreach ($signatures as $signature) {
        if (hash_equals($expected, $signature)) {
            return true;
        }
    }

    return false;
}

function _ecStripeRequest(string $method, string $endpoint, ?array $body = null): array
{
    if (isset($GLOBALS['__ec_stripe_http_mock']) && is_callable($GLOBALS['__ec_stripe_http_mock'])) {
        return (array)call_user_func($GLOBALS['__ec_stripe_http_mock'], strtoupper($method), $endpoint, $body);
    }

    $secretKey = trim((string)ecSettings('stripe_secret_key'));
    if ($secretKey === '') {
        return ['ok' => false, 'error' => 'Stripe secret key not configured'];
    }

    $url = EC_STRIPE_API_BASE . $endpoint;
    $ch = curl_init();
    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . $secretKey,
    ];

    $payload = null;
    if ($body !== null) {
        $payload = http_build_query($body);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
    ]);

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }

    $responseBody = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($responseBody === false) {
        write_log('Stripe cURL error: ' . $curlError, 'error', ['endpoint' => $endpoint]);
        return ['ok' => false, 'error' => 'cURL error: ' . $curlError];
    }

    $decoded = json_decode((string)$responseBody, true);
    if ($httpCode < 200 || $httpCode >= 300) {
        $message = trim((string)($decoded['error']['message'] ?? ''));
        write_log('Stripe API error', 'error', [
            'endpoint' => $endpoint,
            'http_code' => $httpCode,
            'error' => $message,
        ]);
        return ['ok' => false, 'error' => $message !== '' ? $message : ('HTTP ' . $httpCode), 'http_code' => $httpCode];
    }

    return [
        'ok' => true,
        'data' => is_array($decoded) ? $decoded : [],
        'http_code' => $httpCode,
    ];
}