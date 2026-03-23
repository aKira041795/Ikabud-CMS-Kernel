<?php

declare(strict_types=1);

/**
 * Mistral AI provider.
 *
 * Free tier: mistral-small-latest — generous free allowance on La Plateforme.
 * Paid tier: mistral-large-latest — higher quality reasoning.
 *
 * API: https://api.mistral.ai/v1/chat/completions (OpenAI-compatible)
 */

function aiMistralSettings(): array
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

function aiMistralApiKey(): string
{
    $s = aiMistralSettings();
    $key = trim((string)($s['mistral_api_key'] ?? ''));
    if ($key !== '') {
        return $key;
    }
    return trim((string)app()->config('ai.mistral_api_key', ''));
}

function aiMistralModel(): string
{
    $s = aiMistralSettings();
    $tier = trim((string)($s['tier'] ?? 'free'));

    if ($tier === 'custom') {
        $custom = trim((string)($s['mistral_model'] ?? ''));
        if ($custom !== '') {
            return $custom;
        }
    }
    if ($tier === 'paid') {
        $paid = trim((string)($s['mistral_model_paid'] ?? ''));
        return $paid !== '' ? $paid : 'mistral-large-latest';
    }

    $free = trim((string)($s['mistral_model_free'] ?? ''));
    return $free !== '' ? $free : 'mistral-small-latest';
}

function aiMistralSuggestTriggers(array $context): array
{
    $apiKey = aiMistralApiKey();
    if ($apiKey === '') {
        return ['ok' => false, 'error' => 'MISTRAL_API_KEY is not configured'];
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
        'model' => aiMistralModel(),
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ],
        'temperature' => 0.2,
        'response_format' => ['type' => 'json_object'],
    ];

    $resp = aiMistralHttp('https://api.mistral.ai/v1/chat/completions', $payload, $apiKey, 25);
    if (empty($resp['ok'])) {
        return $resp;
    }

    $decoded = json_decode((string)($resp['content'] ?? ''), true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'Mistral returned invalid JSON'];
    }

    return [
        'ok' => true,
        'suggestions' => is_array($decoded['suggestions'] ?? null) ? $decoded['suggestions'] : [],
        'raw' => $decoded,
    ];
}

function aiMistralTextGenerate(array $messages, float $temperature = 0.2, bool $json = false, int $timeoutSeconds = 5, ?int $maxTokens = null): array
{
    $apiKey = aiMistralApiKey();
    if ($apiKey === '') {
        return ['ok' => false, 'error' => 'MISTRAL_API_KEY is not configured'];
    }

    $timeoutSeconds = max(1, min(55, $timeoutSeconds));

    $payload = [
        'model' => aiMistralModel(),
        'messages' => $messages,
        'temperature' => $temperature,
    ];
    if ($json) {
        $payload['response_format'] = ['type' => 'json_object'];
    }
    if ($maxTokens !== null && $maxTokens > 0) {
        $payload['max_tokens'] = $maxTokens;
    }

    $resp = aiMistralHttp('https://api.mistral.ai/v1/chat/completions', $payload, $apiKey, $timeoutSeconds);
    if (empty($resp['ok'])) {
        return $resp;
    }

    return ['ok' => true, 'content' => (string)($resp['content'] ?? '')];
}

function aiMistralHttp(string $url, array $payload, string $apiKey, int $timeoutSeconds = 25): array
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
        return ['ok' => false, 'error' => 'Mistral request failed: ' . $err];
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'Mistral returned non-JSON response', 'http_code' => $code];
    }

    if ($code < 200 || $code >= 300) {
        $msg = (string)($decoded['error']['message'] ?? $decoded['message'] ?? ('HTTP ' . $code));
        return ['ok' => false, 'error' => $msg, 'http_code' => $code];
    }

    // OpenAI-compatible response format
    $content = (string)($decoded['choices'][0]['message']['content'] ?? '');
    if (trim($content) === '') {
        return ['ok' => false, 'error' => 'Mistral returned empty content'];
    }

    return ['ok' => true, 'content' => $content, 'http_code' => $code];
}
