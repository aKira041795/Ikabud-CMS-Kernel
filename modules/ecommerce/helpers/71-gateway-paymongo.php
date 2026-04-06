<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — PayMongo Gateway Client (helpers/71-gateway-paymongo.php)
//
// Pure cURL-based HTTP client for the PayMongo REST API.
// Docs: https://developers.paymongo.com/reference
// ─────────────────────────────────────────────────────────────────────────

define('EC_PAYMONGO_API_BASE', 'https://api.paymongo.com/v1');

/**
 * Create a Payment Intent on PayMongo.
 *
 * @param int    $amountCentavos  Amount in smallest currency unit (centavos).
 * @param string $currency        ISO currency code (e.g. PHP).
 * @param array  $methods         Allowed payment methods: card, gcash, maya, grab_pay, dob.
 * @param string $description     Description shown on payment page.
 * @return array {ok: bool, intent_id?: string, client_key?: string, checkout_url?: string, error?: string}
 */
function ecPaymongoCreateIntent(int $amountCentavos, string $currency, array $methods, string $description): array
{
    $body = [
        'data' => [
            'attributes' => [
                'amount'                 => $amountCentavos,
                'currency'               => $currency,
                'payment_method_allowed'  => $methods,
                'description'            => $description,
                'capture_type'           => 'automatic',
            ],
        ],
    ];

    $response = _ecPaymongoRequest('POST', '/payment_intents', $body);

    if (!$response['ok']) {
        return $response;
    }

    $data = $response['data'] ?? [];
    $attrs = $data['attributes'] ?? [];

    return [
        'ok'           => true,
        'intent_id'    => (string)($data['id'] ?? ''),
        'client_key'   => (string)($attrs['client_key'] ?? ''),
        'checkout_url' => (string)($attrs['next_action']['redirect']['url'] ?? ''),
        'status'       => (string)($attrs['status'] ?? ''),
    ];
}

/**
 * Attach a Payment Method to an existing Payment Intent.
 *
 * @param string $intentId   The Payment Intent ID (pi_xxx).
 * @param string $methodId   The Payment Method ID (pm_xxx).
 * @param string $returnUrl  URL to redirect the customer back to after auth.
 * @return array {ok: bool, status?: string, checkout_url?: string, error?: string}
 */
function ecPaymongoAttachMethod(string $intentId, string $methodId, string $returnUrl): array
{
    $body = [
        'data' => [
            'attributes' => [
                'payment_method' => $methodId,
                'return_url'     => $returnUrl,
            ],
        ],
    ];

    $response = _ecPaymongoRequest('POST', '/payment_intents/' . urlencode($intentId) . '/attach', $body);

    if (!$response['ok']) {
        return $response;
    }

    $data = $response['data'] ?? [];
    $attrs = $data['attributes'] ?? [];

    $checkoutUrl = '';
    if (isset($attrs['next_action']['redirect']['url'])) {
        $checkoutUrl = (string)$attrs['next_action']['redirect']['url'];
    }

    return [
        'ok'           => true,
        'status'       => (string)($attrs['status'] ?? ''),
        'checkout_url' => $checkoutUrl,
    ];
}

/**
 * Retrieve a Payment Intent's current status.
 *
 * @param string $intentId  The Payment Intent ID (pi_xxx).
 * @return array {ok: bool, data?: array, error?: string}
 */
function ecPaymongoRetrieveIntent(string $intentId): array
{
    return _ecPaymongoRequest('GET', '/payment_intents/' . urlencode($intentId));
}

/**
 * Verify a PayMongo webhook signature (HMAC-SHA256).
 *
 * PayMongo sends the header as: t=<timestamp>,te=<test_sig>,li=<live_sig>
 *
 * @param string $rawBody       Raw request body.
 * @param string $sigHeader     The Paymongo-Signature header value.
 * @param string $webhookSecret The webhook signing secret.
 * @return bool
 */
function ecPaymongoVerifyWebhook(string $rawBody, string $sigHeader, string $webhookSecret): bool
{
    // Parse signature header: t=xxx,te=xxx,li=xxx
    $parts = [];
    foreach (explode(',', $sigHeader) as $segment) {
        $kv = explode('=', $segment, 2);
        if (count($kv) === 2) {
            $parts[trim($kv[0])] = trim($kv[1]);
        }
    }

    $timestamp = $parts['t'] ?? '';
    if ($timestamp === '') {
        return false;
    }

    // Determine which signature to check based on key prefix
    $mode = trim((string)ecSettings('payment_gateway_mode'));
    $expectedSig = ($mode === 'live') ? ($parts['li'] ?? '') : ($parts['te'] ?? '');
    if ($expectedSig === '') {
        return false;
    }

    // Construct signed payload and compute HMAC
    $signedPayload = $timestamp . '.' . $rawBody;
    $computed = hash_hmac('sha256', $signedPayload, $webhookSecret);

    return hash_equals($computed, $expectedSig);
}

// ── Internal HTTP client ────────────────────────────────────────────

/**
 * Make an authenticated HTTP request to the PayMongo API.
 *
 * @param string     $method   HTTP method: GET, POST.
 * @param string     $endpoint API path (e.g. /payment_intents).
 * @param array|null $body     JSON body (for POST).
 * @return array {ok: bool, data?: array, error?: string, http_code?: int}
 */
function _ecPaymongoRequest(string $method, string $endpoint, ?array $body = null): array
{
    $secretKey = trim((string)ecSettings('paymongo_secret_key'));
    if ($secretKey === '') {
        return ['ok' => false, 'error' => 'PayMongo secret key not configured'];
    }

    $url = EC_PAYMONGO_API_BASE . $endpoint;

    $ch = curl_init();

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Basic ' . base64_encode($secretKey . ':'),
    ];

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
    }

    $responseBody = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($responseBody === false) {
        write_log('PayMongo cURL error: ' . $curlError, 'error', ['endpoint' => $endpoint]);
        return ['ok' => false, 'error' => 'cURL error: ' . $curlError];
    }

    $decoded = json_decode((string)$responseBody, true);

    if ($httpCode < 200 || $httpCode >= 300) {
        $apiError = '';
        if (isset($decoded['errors'][0]['detail'])) {
            $apiError = (string)$decoded['errors'][0]['detail'];
        } elseif (isset($decoded['errors'][0]['code'])) {
            $apiError = (string)$decoded['errors'][0]['code'];
        }

        write_log('PayMongo API error', 'error', [
            'endpoint'  => $endpoint,
            'http_code' => $httpCode,
            'error'     => $apiError,
        ]);

        return ['ok' => false, 'error' => $apiError ?: ('HTTP ' . $httpCode), 'http_code' => $httpCode];
    }

    return [
        'ok'        => true,
        'data'      => $decoded['data'] ?? $decoded,
        'http_code' => $httpCode,
    ];
}
