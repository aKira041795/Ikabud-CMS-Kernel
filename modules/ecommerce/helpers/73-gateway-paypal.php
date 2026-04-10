<?php

declare(strict_types=1);

function ecPaypalApiBase(): string
{
    return trim((string)ecSettings('payment_gateway_mode')) === 'live'
        ? 'https://api-m.paypal.com'
        : 'https://api-m.sandbox.paypal.com';
}

function ecPaypalAccessToken(bool $refresh = false): array
{
    static $cache = [];
    $mode = trim((string)ecSettings('payment_gateway_mode')) ?: 'sandbox';
    if (!$refresh && isset($cache[$mode]) && (int)($cache[$mode]['expires_at'] ?? 0) > (time() + 30)) {
        return ['ok' => true, 'access_token' => (string)$cache[$mode]['access_token']];
    }

    $response = _ecPaypalRequest('POST', '/v1/oauth2/token', ['grant_type' => 'client_credentials'], [], true);
    if (!$response['ok']) {
        return $response;
    }

    $data = is_array($response['data'] ?? null) ? $response['data'] : [];
    $accessToken = trim((string)($data['access_token'] ?? ''));
    if ($accessToken === '') {
        return ['ok' => false, 'error' => 'PayPal access token missing from response'];
    }

    $cache[$mode] = [
        'access_token' => $accessToken,
        'expires_at' => time() + max(60, (int)($data['expires_in'] ?? 300)),
    ];

    return ['ok' => true, 'access_token' => $accessToken];
}

function ecPaypalCreateOrder(float $amount, string $currency, array $options = []): array
{
    $body = [
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'reference_id' => (string)($options['order_number'] ?? $options['order_id'] ?? 'order'),
            'custom_id' => isset($options['order_id']) ? (string)$options['order_id'] : '',
            'description' => (string)($options['description'] ?? 'Order payment'),
            'amount' => [
                'currency_code' => strtoupper($currency),
                'value' => number_format($amount, 2, '.', ''),
            ],
        ]],
        'application_context' => [
            'user_action' => 'PAY_NOW',
            'return_url' => (string)($options['return_url'] ?? ''),
            'cancel_url' => (string)($options['cancel_url'] ?? $options['return_url'] ?? ''),
        ],
    ];

    $customerEmail = trim((string)($options['customer_email'] ?? ''));
    if ($customerEmail !== '') {
        $body['payer'] = ['email_address' => $customerEmail];
    }

    $response = _ecPaypalRequest('POST', '/v2/checkout/orders', $body);
    if (!$response['ok']) {
        return $response;
    }

    $data = is_array($response['data'] ?? null) ? $response['data'] : [];
    $approveUrl = '';
    foreach ((array)($data['links'] ?? []) as $link) {
        if (($link['rel'] ?? '') === 'approve') {
            $approveUrl = (string)($link['href'] ?? '');
            break;
        }
    }

    return [
        'ok' => true,
        'order_id' => (string)($data['id'] ?? ''),
        'approve_url' => $approveUrl,
        'status' => (string)($data['status'] ?? ''),
        'raw' => $data,
    ];
}

function ecPaypalRetrieveOrder(string $paypalOrderId): array
{
    return _ecPaypalRequest('GET', '/v2/checkout/orders/' . rawurlencode($paypalOrderId));
}

function ecPaypalCaptureOrder(string $paypalOrderId): array
{
    return _ecPaypalRequest('POST', '/v2/checkout/orders/' . rawurlencode($paypalOrderId) . '/capture', []);
}

function ecPaypalRefundCapture(string $captureId, float $amount, string $currency, array $options = []): array
{
    $body = [
        'amount' => [
            'value' => number_format($amount, 2, '.', ''),
            'currency_code' => strtoupper($currency),
        ],
    ];
    $reason = trim((string)($options['reason'] ?? ''));
    if ($reason !== '') {
        $body['note_to_payer'] = $reason;
    }

    $response = _ecPaypalRequest('POST', '/v2/payments/captures/' . rawurlencode($captureId) . '/refund', $body);
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

function ecPaypalVerifyWebhookSignature(array $headers, string $rawBody): array
{
    $webhookId = trim((string)ecSettings('paypal_webhook_id'));
    if ($webhookId === '') {
        return ['ok' => false, 'error' => 'PayPal webhook ID not configured'];
    }

    $event = json_decode($rawBody, true);
    if (!is_array($event)) {
        return ['ok' => false, 'error' => 'Invalid PayPal webhook payload'];
    }

    $verificationBody = [
        'auth_algo' => (string)($headers['paypal-auth-algo'] ?? ''),
        'cert_url' => (string)($headers['paypal-cert-url'] ?? ''),
        'transmission_id' => (string)($headers['paypal-transmission-id'] ?? ''),
        'transmission_sig' => (string)($headers['paypal-transmission-sig'] ?? ''),
        'transmission_time' => (string)($headers['paypal-transmission-time'] ?? ''),
        'webhook_id' => $webhookId,
        'webhook_event' => $event,
    ];

    $response = _ecPaypalRequest('POST', '/v1/notifications/verify-webhook-signature', $verificationBody);
    if (!$response['ok']) {
        return $response;
    }

    $data = is_array($response['data'] ?? null) ? $response['data'] : [];
    return [
        'ok' => true,
        'verified' => strtoupper((string)($data['verification_status'] ?? '')) === 'SUCCESS',
        'raw' => $data,
    ];
}

function _ecPaypalRequest(string $method, string $endpoint, ?array $body = null, array $headers = [], bool $skipAuth = false): array
{
    if (isset($GLOBALS['__ec_paypal_http_mock']) && is_callable($GLOBALS['__ec_paypal_http_mock'])) {
        return (array)call_user_func($GLOBALS['__ec_paypal_http_mock'], strtoupper($method), $endpoint, $body, $headers, $skipAuth);
    }

    $clientId = trim((string)ecSettings('paypal_client_id'));
    $secret = trim((string)ecSettings('paypal_secret'));
    if ($clientId === '' || $secret === '') {
        return ['ok' => false, 'error' => 'PayPal client credentials are not configured'];
    }

    $url = ecPaypalApiBase() . $endpoint;
    $ch = curl_init();
    $requestHeaders = ['Accept: application/json'];

    if ($skipAuth) {
        $requestHeaders[] = 'Authorization: Basic ' . base64_encode($clientId . ':' . $secret);
        $requestHeaders[] = 'Content-Type: application/x-www-form-urlencoded';
    } else {
        $token = ecPaypalAccessToken();
        if (!$token['ok']) {
            return $token;
        }
        $requestHeaders[] = 'Authorization: Bearer ' . $token['access_token'];
        $requestHeaders[] = 'Content-Type: application/json';
    }

    foreach ($headers as $header) {
        $requestHeaders[] = $header;
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
    ]);

    if ($body !== null) {
        if ($skipAuth) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body));
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
    }

    $responseBody = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($responseBody === false) {
        write_log('PayPal cURL error: ' . $curlError, 'error', ['endpoint' => $endpoint]);
        return ['ok' => false, 'error' => 'cURL error: ' . $curlError];
    }

    $decoded = json_decode((string)$responseBody, true);
    if ($httpCode < 200 || $httpCode >= 300) {
        $message = trim((string)($decoded['message'] ?? $decoded['details'][0]['issue'] ?? ''));
        write_log('PayPal API error', 'error', [
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