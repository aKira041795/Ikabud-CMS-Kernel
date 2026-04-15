<?php

declare(strict_types=1);

function ecOutboundWebhookStorageAvailable(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    try {
        ecDb()->query('SELECT 1 FROM ec_outbound_webhooks LIMIT 1');
        ecDb()->query('SELECT 1 FROM ec_webhook_deliveries LIMIT 1');
        $ready = true;
    } catch (\Throwable $e) {
        $ready = false;
    }

    return $ready;
}

function ecOutboundWebhookGenerateSecret(): string
{
    try {
        return bin2hex(random_bytes(24));
    } catch (\Throwable $e) {
        return sha1(uniqid('ec_webhook_', true));
    }
}

function ecOutboundWebhookNormalizeEventPatterns(mixed $patterns): array
{
    if (is_string($patterns)) {
        $patterns = preg_split('/[\r\n,]+/', $patterns) ?: [];
    }
    if (!is_array($patterns)) {
        return [];
    }

    $normalized = [];
    foreach ($patterns as $pattern) {
        $value = trim((string)$pattern);
        if ($value === '' || in_array($value, $normalized, true)) {
            continue;
        }
        $normalized[] = $value;
    }

    return $normalized;
}

function ecOutboundWebhookPatternRegex(string $pattern): string
{
    return '/^' . str_replace(['\\*', '\\?'], ['[^.]+', '.'], preg_quote($pattern, '/')) . '$/';
}

function ecOutboundWebhookMatchesEvent(string $eventName, array $patterns): bool
{
    if ($patterns === []) {
        return false;
    }

    foreach ($patterns as $pattern) {
        $pattern = trim((string)$pattern);
        if ($pattern === '') {
            continue;
        }
        if ($pattern === $eventName) {
            return true;
        }
        if (strpbrk($pattern, '*?') !== false && preg_match(ecOutboundWebhookPatternRegex($pattern), $eventName) === 1) {
            return true;
        }
    }

    return false;
}

function ecOutboundWebhookRow(array $row): array
{
    $eventPatterns = [];
    $rawPatterns = $row['event_patterns'] ?? '[]';
    if (is_string($rawPatterns) && $rawPatterns !== '') {
        $decoded = json_decode($rawPatterns, true);
        if (is_array($decoded)) {
            $eventPatterns = ecOutboundWebhookNormalizeEventPatterns($decoded);
        }
    } elseif (is_array($rawPatterns)) {
        $eventPatterns = ecOutboundWebhookNormalizeEventPatterns($rawPatterns);
    }

    return [
        'id' => (int)($row['id'] ?? 0),
        'name' => trim((string)($row['name'] ?? 'Webhook')),
        'target_url' => trim((string)($row['target_url'] ?? '')),
        'signing_secret' => trim((string)($row['signing_secret'] ?? '')),
        'event_patterns' => $eventPatterns,
        'event_patterns_text' => implode("\n", $eventPatterns),
        'is_active' => !empty($row['is_active']),
        'last_delivery_status' => trim((string)($row['last_delivery_status'] ?? '')),
        'last_delivery_at' => trim((string)($row['last_delivery_at'] ?? '')),
        'created_at' => trim((string)($row['created_at'] ?? '')),
        'updated_at' => trim((string)($row['updated_at'] ?? '')),
    ];
}

function ecOutboundWebhookList(bool $activeOnly = false): array
{
    if (!ecOutboundWebhookStorageAvailable()) {
        return [];
    }

    $sql = 'SELECT * FROM ec_outbound_webhooks';
    $params = [];
    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY is_active DESC, id ASC';

    try {
        $rows = ecDb()->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        write_log('ecOutboundWebhookList error: ' . $e->getMessage(), 'warning', ['module' => 'ecommerce']);
        return [];
    }

    return array_map('ecOutboundWebhookRow', $rows);
}

function ecOutboundWebhookGet(int $id): ?array
{
    if ($id <= 0 || !ecOutboundWebhookStorageAvailable()) {
        return null;
    }

    try {
        $row = ecDb()->query('SELECT * FROM ec_outbound_webhooks WHERE id = ? LIMIT 1', [$id])->fetch(\PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) {
        return null;
    }

    return is_array($row) ? ecOutboundWebhookRow($row) : null;
}

function ecOutboundWebhookSave(array $input, ?int $id = null): int
{
    if (!ecOutboundWebhookStorageAvailable()) {
        throw new \RuntimeException('Outbound webhook storage is unavailable.');
    }

    $name = trim((string)($input['name'] ?? ''));
    $targetUrl = trim((string)($input['target_url'] ?? ''));
    $eventPatterns = ecOutboundWebhookNormalizeEventPatterns($input['event_patterns'] ?? $input['event_patterns_text'] ?? []);
    $isActive = !empty($input['is_active']);
    $existing = $id !== null ? ecOutboundWebhookGet($id) : null;
    $signingSecret = trim((string)($input['signing_secret'] ?? ''));
    if ($signingSecret === '' && is_array($existing)) {
        $signingSecret = (string)($existing['signing_secret'] ?? '');
    }
    if ($signingSecret === '') {
        $signingSecret = ecOutboundWebhookGenerateSecret();
    }

    if ($name === '') {
        throw new \InvalidArgumentException('Webhook name is required.');
    }
    if ($targetUrl === '' || !filter_var($targetUrl, FILTER_VALIDATE_URL)) {
        throw new \InvalidArgumentException('A valid target URL is required.');
    }
    if ($eventPatterns === []) {
        throw new \InvalidArgumentException('At least one event pattern is required.');
    }

    $db = ecDb();
    if ($id !== null && $id > 0) {
        $db->execute(
            'UPDATE ec_outbound_webhooks SET name = ?, target_url = ?, signing_secret = ?, event_patterns = ?, is_active = ?, updated_at = NOW() WHERE id = ? LIMIT 1',
            [$name, $targetUrl, $signingSecret, json_encode($eventPatterns, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $isActive ? 1 : 0, $id]
        );
        return $id;
    }

    $db->execute(
        'INSERT INTO ec_outbound_webhooks (name, target_url, signing_secret, event_patterns, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
        [$name, $targetUrl, $signingSecret, json_encode($eventPatterns, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $isActive ? 1 : 0]
    );

    return (int)$db->lastInsertId();
}

function ecOutboundWebhookDelete(int $id): bool
{
    if ($id <= 0 || !ecOutboundWebhookStorageAvailable()) {
        return false;
    }

    ecDb()->execute('DELETE FROM ec_outbound_webhooks WHERE id = ? LIMIT 1', [$id]);
    return true;
}

function ecOutboundWebhookRecentDeliveries(int $limit = 25, ?int $webhookId = null): array
{
    if (!ecOutboundWebhookStorageAvailable()) {
        return [];
    }

    $limit = max(1, min(100, $limit));
    $sql = 'SELECT d.*, w.name AS webhook_name FROM ec_webhook_deliveries d LEFT JOIN ec_outbound_webhooks w ON w.id = d.webhook_id';
    $params = [];
    if ($webhookId !== null && $webhookId > 0) {
        $sql .= ' WHERE d.webhook_id = ?';
        $params[] = $webhookId;
    }
    $sql .= ' ORDER BY d.id DESC LIMIT ' . $limit;

    try {
        $rows = ecDb()->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }

    $deliveries = [];
    foreach ($rows as $row) {
        $deliveries[] = [
            'id' => (int)($row['id'] ?? 0),
            'webhook_id' => isset($row['webhook_id']) ? (int)$row['webhook_id'] : null,
            'webhook_name' => trim((string)($row['webhook_name'] ?? 'Deleted webhook')),
            'event_name' => trim((string)($row['event_name'] ?? '')),
            'delivery_id' => trim((string)($row['delivery_id'] ?? '')),
            'status' => trim((string)($row['status'] ?? '')),
            'http_status' => (int)($row['http_status'] ?? 0),
            'response_body' => trim((string)($row['response_body'] ?? '')),
            'signature' => trim((string)($row['signature'] ?? '')),
            'created_at' => trim((string)($row['created_at'] ?? '')),
            'delivered_at' => trim((string)($row['delivered_at'] ?? '')),
        ];
    }

    return $deliveries;
}

function ecOutboundWebhookPayload(string $eventName, array $payload): string
{
    return json_encode([
        'event' => $eventName,
        'payload' => $payload,
        'occurred_at' => gmdate('c'),
        'tenant_id' => (int)(app()->tenant()->current() ?? 0),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function ecOutboundWebhookSignature(string $body, string $secret): string
{
    return 'sha256=' . hash_hmac('sha256', $body, $secret);
}

function ecOutboundWebhookHttpRequest(string $method, string $url, string $body, array $headers): array
{
    if (isset($GLOBALS['__ec_outbound_webhook_http_mock']) && is_callable($GLOBALS['__ec_outbound_webhook_http_mock'])) {
        return (array)call_user_func($GLOBALS['__ec_outbound_webhook_http_mock'], $method, $url, $body, $headers);
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_POSTFIELDS => $body,
    ]);

    $responseBody = curl_exec($ch);
    $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($responseBody === false) {
        return ['ok' => false, 'http_status' => 0, 'response_body' => $curlError !== '' ? $curlError : 'cURL error'];
    }

    return ['ok' => $httpStatus >= 200 && $httpStatus < 300, 'http_status' => $httpStatus, 'response_body' => (string)$responseBody];
}

function ecOutboundWebhookDeliver(array $webhook, string $eventName, array $payload): array
{
    $body = ecOutboundWebhookPayload($eventName, $payload);
    $deliveryId = function_exists('request_id') ? (string)request_id() . ':' . uniqid('wh_', false) : uniqid('wh_', true);
    $signature = ecOutboundWebhookSignature($body, (string)($webhook['signing_secret'] ?? ''));
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-Ecommerce-Event: ' . $eventName,
        'X-Ecommerce-Delivery-Id: ' . $deliveryId,
        'X-Ecommerce-Signature: ' . $signature,
    ];

    $response = ecOutboundWebhookHttpRequest('POST', (string)$webhook['target_url'], $body, $headers);
    $status = !empty($response['ok']) ? 'delivered' : 'failed';
    $responseBody = substr((string)($response['response_body'] ?? ''), 0, 4000);

    if (ecOutboundWebhookStorageAvailable()) {
        ecDb()->execute(
            'INSERT INTO ec_webhook_deliveries (webhook_id, event_name, delivery_id, request_body, signature, response_body, http_status, status, created_at, delivered_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)',
            [
                (int)($webhook['id'] ?? 0),
                $eventName,
                $deliveryId,
                $body,
                $signature,
                $responseBody,
                (int)($response['http_status'] ?? 0),
                $status,
                !empty($response['ok']) ? date('Y-m-d H:i:s') : null,
            ]
        );
        ecDb()->execute(
            'UPDATE ec_outbound_webhooks SET last_delivery_status = ?, last_delivery_at = NOW(), updated_at = NOW() WHERE id = ? LIMIT 1',
            [$status, (int)($webhook['id'] ?? 0)]
        );
    }

    if (empty($response['ok'])) {
        write_log('Outbound webhook delivery failed', 'warning', [
            'module' => 'ecommerce',
            'webhook_id' => (int)($webhook['id'] ?? 0),
            'event_name' => $eventName,
            'http_status' => (int)($response['http_status'] ?? 0),
        ]);
    }

    return [
        'ok' => !empty($response['ok']),
        'status' => $status,
        'http_status' => (int)($response['http_status'] ?? 0),
        'delivery_id' => $deliveryId,
        'signature' => $signature,
        'response_body' => $responseBody,
    ];
}

function ecOutboundWebhooksDispatchEvent(string $eventName, array $payload): array
{
    $results = [];
    foreach (ecOutboundWebhookList(true) as $webhook) {
        if (!ecOutboundWebhookMatchesEvent($eventName, (array)($webhook['event_patterns'] ?? []))) {
            continue;
        }

        // Dispatch via job queue for async delivery when available
        if (function_exists('kernelDispatchJob')) {
            $jobId = kernelDispatchJob('ecommerce:ecOutboundWebhookDeliverJob', [
                'webhook_id' => (int)$webhook['id'],
                'event_name' => $eventName,
                'payload' => $payload,
            ], 'webhooks', 0, 3);

            $results[] = ['ok' => $jobId > 0, 'status' => $jobId > 0 ? 'queued' : 'queue_failed', 'job_id' => $jobId];
            continue;
        }

        // Fallback: synchronous delivery if job queue is unavailable
        $results[] = ecOutboundWebhookDeliver($webhook, $eventName, $payload);
    }

    return $results;
}

/**
 * Job handler for async webhook delivery.
 * Called by the queue worker with payload from kernelDispatchJob.
 */
function ecOutboundWebhookDeliverJob(array $jobPayload): void
{
    $webhookId = (int)($jobPayload['webhook_id'] ?? 0);
    $eventName = trim((string)($jobPayload['event_name'] ?? ''));
    $payload = (array)($jobPayload['payload'] ?? []);

    if ($webhookId <= 0 || $eventName === '') {
        throw new \RuntimeException('Invalid webhook delivery job payload.');
    }

    $webhook = ecOutboundWebhookGet($webhookId);
    if (!is_array($webhook)) {
        // Webhook was deleted between dispatch and delivery — skip silently
        return;
    }

    if (empty($webhook['is_active'])) {
        return;
    }

    $result = ecOutboundWebhookDeliver($webhook, $eventName, $payload);
    if (empty($result['ok'])) {
        throw new \RuntimeException('Webhook delivery failed: HTTP ' . ($result['http_status'] ?? 0));
    }
}

function ecOutboundWebhookSendTest(int $webhookId): array
{
    $webhook = ecOutboundWebhookGet($webhookId);
    if (!is_array($webhook)) {
        return ['ok' => false, 'error' => 'Webhook not found.'];
    }

    $payload = [
        'message' => 'Outbound webhook test delivery',
        'sent_by' => 'ecommerce_admin',
        'webhook_id' => $webhookId,
    ];

    return ecOutboundWebhookDeliver($webhook, 'ecommerce.webhook.test', $payload);
}

foreach (['ecommerce.order.*', 'ecommerce.product.*'] as $eventPattern) {
    app()->events()->listen($eventPattern, function (array $payload, string $eventName): void {
        ecOutboundWebhooksDispatchEvent($eventName, $payload);
    }, 95, 'ecommerce');
}