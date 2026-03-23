<?php

declare(strict_types=1);

/**
 * Cerebras AI provider (OpenAI-compatible API).
 *
 * Free tier: llama-4-scout-17b-16e-instruct — 30 RPM, 1M tokens/min.
 * Paid tier: llama3.1-70b — higher limits.
 * Known for extremely fast inference speed.
 *
 * API: https://api.cerebras.ai/v1/chat/completions
 */

function aiCerebrasSettings(): array
{
    try {
        if (function_exists('aiResolvedSettings')) {
            $s = aiResolvedSettings();
            return is_array($s) ? $s : [];
        }
    } catch (Throwable $e) {
    }
    return [];
}

function aiCerebrasApiKey(): string
{
    $s = aiCerebrasSettings();
    $key = trim((string)($s['cerebras_api_key'] ?? ''));
    if ($key !== '') {
        return $key;
    }
    return trim((string)app()->config('ai.cerebras_api_key', ''));
}

function aiCerebrasModel(): string
{
    $s = aiCerebrasSettings();
    $tier = trim((string)($s['tier'] ?? 'free'));

    if ($tier === 'custom') {
        $custom = trim((string)($s['cerebras_model'] ?? ''));
        if ($custom !== '') {
            return $custom;
        }
    }
    if ($tier === 'paid') {
        $paid = trim((string)($s['cerebras_model_paid'] ?? ''));
        return $paid !== '' ? $paid : 'llama3.1-70b';
    }

    $free = trim((string)($s['cerebras_model_free'] ?? ''));
    return $free !== '' ? $free : 'llama-4-scout-17b-16e-instruct';
}

function aiCerebrasSuggestTriggers(array $context): array
{
    $apiKey = aiCerebrasApiKey();
    if ($apiKey === '') {
        return ['ok' => false, 'error' => 'CEREBRAS_API_KEY is not configured'];
    }

    $event = is_array($context['event'] ?? null) ? $context['event'] : [];
    $existing = is_array($context['existing_triggers'] ?? null) ? $context['existing_triggers'] : [];
    $availableCaps = is_array($context['available_capabilities'] ?? null) ? $context['available_capabilities'] : [];

    $system = "You are a careful backend assistant for a modular PHP app. Your job is to propose kernel_event_triggers suggestions. Output must be valid JSON only.";

    $user = json_encode([
        'task' => 'suggest_triggers',
        'event' => [
            'module' => (string)($event['module'] ?? ''),
            'event_key' => (string)($event['event_key'] ?? ''),
            'description' => (string)($event['description'] ?? ''),
            'available_vars' => array_values(is_array($event['available_vars'] ?? null) ? $event['available_vars'] : []),
        ],
        'existing_triggers' => $existing,
        'available_capabilities' => $availableCaps,
        'constraints' => [
            'output_json_only' => true,
            'suggestions_max' => 5,
            'template_style' => 'Use {var} placeholders only from available_vars. Keep SMS templates <= 160 chars when possible.',
            'must_include_reason' => true,
        ],
        'output_schema' => [
            'type' => 'object',
            'properties' => ['suggestions' => ['type' => 'array']],
            'required' => ['suggestions'],
        ],
    ], JSON_UNESCAPED_SLASHES);

    $payload = [
        'model' => aiCerebrasModel(),
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ],
        'temperature' => 0.2,
    ];

    $resp = aiCerebrasHttp('https://api.cerebras.ai/v1/chat/completions', $payload, $apiKey, 25);
    if (empty($resp['ok'])) {
        return $resp;
    }

    $decoded = json_decode((string)($resp['content'] ?? ''), true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'Cerebras returned invalid JSON'];
    }

    return [
        'ok' => true,
        'suggestions' => is_array($decoded['suggestions'] ?? null) ? $decoded['suggestions'] : [],
        'raw' => $decoded,
    ];
}

function aiCerebrasTextGenerate(array $messages, float $temperature = 0.2, bool $json = false, int $timeoutSeconds = 5, ?int $maxTokens = null): array
{
    $apiKey = aiCerebrasApiKey();
    if ($apiKey === '') {
        return ['ok' => false, 'error' => 'CEREBRAS_API_KEY is not configured'];
    }

    $timeoutSeconds = max(1, min(55, $timeoutSeconds));

    $payload = [
        'model' => aiCerebrasModel(),
        'messages' => $messages,
        'temperature' => $temperature,
    ];
    if ($json) {
        $payload['response_format'] = ['type' => 'json_object'];
    }
    if ($maxTokens !== null && $maxTokens > 0) {
        $payload['max_tokens'] = $maxTokens;
    }

    $resp = aiCerebrasHttp('https://api.cerebras.ai/v1/chat/completions', $payload, $apiKey, $timeoutSeconds);
    if (empty($resp['ok'])) {
        return $resp;
    }

    return ['ok' => true, 'content' => (string)($resp['content'] ?? '')];
}

function aiCerebrasHttp(string $url, array $payload, string $apiKey, int $timeoutSeconds = 25): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'curl_init failed'];
    }

    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        return ['ok' => false, 'error' => 'Failed to encode request'];
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, max(1, min(60, $timeoutSeconds)));

    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'error' => 'Cerebras request failed: ' . $err];
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'Cerebras returned non-JSON response', 'http_code' => $code];
    }

    if ($code < 200 || $code >= 300) {
        $msg = (string)($decoded['error']['message'] ?? ('HTTP ' . $code));
        return ['ok' => false, 'error' => $msg, 'http_code' => $code];
    }

    // OpenAI-compatible response format
    $content = (string)($decoded['choices'][0]['message']['content'] ?? '');
    if (trim($content) === '') {
        return ['ok' => false, 'error' => 'Cerebras returned empty content'];
    }

    return ['ok' => true, 'content' => $content, 'http_code' => $code];
}
