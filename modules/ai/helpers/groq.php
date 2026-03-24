<?php

declare(strict_types=1);

function aiGroqSettings(): array
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

function aiGroqApiKey(): string
{
    $s = aiGroqSettings();
    $key = trim((string)($s['groq_api_key'] ?? ''));
    if ($key !== '') {
        return $key;
    }
    return '';
}

function aiGroqModel(): string
{
    $s = aiGroqSettings();
    $tier = trim((string)($s['tier'] ?? ''));
    if ($tier === '') {
        $tier = 'free';
    }

    if ($tier === 'custom') {
        $custom = trim((string)($s['groq_model'] ?? ''));
        if ($custom !== '') {
            return $custom;
        }
    }

    if ($tier === 'paid') {
        $paid = trim((string)($s['groq_model_paid'] ?? ''));
        if ($paid !== '') {
            return $paid;
        }
    }

    $free = trim((string)($s['groq_model_free'] ?? ''));
    return $free !== '' ? $free : 'llama-3.1-8b-instant';
}

function aiGroqSuggestTriggers(array $context): array
{
    $apiKey = aiGroqApiKey();
    if ($apiKey === '') {
        return ['ok' => false, 'error' => 'GROQ_API_KEY is not configured'];
    }

    $timeoutSeconds = 25;

    $event = is_array($context['event'] ?? null) ? $context['event'] : [];
    $existing = is_array($context['existing_triggers'] ?? null) ? $context['existing_triggers'] : [];
    $availableCaps = is_array($context['available_capabilities'] ?? null) ? $context['available_capabilities'] : [];

    $module = (string)($event['module'] ?? '');
    $eventKey = (string)($event['event_key'] ?? '');
    $desc = (string)($event['description'] ?? '');
    $vars = $event['available_vars'] ?? [];
    if (!is_array($vars)) $vars = [];

    $system = "You are a careful backend assistant for a modular PHP app. Your job is to propose kernel_event_triggers suggestions. Output must be valid JSON only.";

    $user = [
        'task' => 'suggest_triggers',
        'event' => [
            'module' => $module,
            'event_key' => $eventKey,
            'description' => $desc,
            'available_vars' => array_values($vars),
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
            'properties' => [
                'suggestions' => [
                    'type' => 'array',
                ],
            ],
            'required' => ['suggestions'],
        ],
    ];

    // Groq is OpenAI-compatible. We avoid response_format here for maximum compatibility.
    $payload = [
        'model' => aiGroqModel(),
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => json_encode($user, JSON_UNESCAPED_SLASHES)],
        ],
        'temperature' => 0.2,
    ];

    $resp = aiGroqHttp('https://api.groq.com/openai/v1/chat/completions', $payload, $apiKey, $timeoutSeconds);
    if (empty($resp['ok'])) {
        return $resp;
    }

    $content = (string)($resp['content'] ?? '');
    $decoded = json_decode($content, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'Groq returned invalid JSON'];
    }

    return [
        'ok' => true,
        'suggestions' => is_array($decoded['suggestions'] ?? null) ? $decoded['suggestions'] : [],
        'raw' => $decoded,
    ];
}

function aiGroqTextGenerate(array $messages, float $temperature = 0.2, bool $json = false, int $timeoutSeconds = 5, ?int $maxTokens = null): array
{
    $apiKey = aiGroqApiKey();
    if ($apiKey === '') {
        return ['ok' => false, 'error' => 'GROQ_API_KEY is not configured'];
    }

    $timeoutSeconds = max(1, min(55, $timeoutSeconds));

    $payload = [
        'model' => aiGroqModel(),
        'messages' => $messages,
        'temperature' => $temperature,
    ];
    if ($json) {
        $payload['response_format'] = ['type' => 'json_object'];
    }
    if ($maxTokens !== null && $maxTokens > 0) {
        $payload['max_tokens'] = $maxTokens;
    }

    $resp = aiGroqHttp('https://api.groq.com/openai/v1/chat/completions', $payload, $apiKey, $timeoutSeconds);
    if (empty($resp['ok'])) {
        return $resp;
    }

    return ['ok' => true, 'content' => (string)($resp['content'] ?? '')];
}

function aiGroqHttp(string $url, array $payload, string $apiKey, int $timeoutSeconds = 25): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'curl_init failed'];
    }

    $timeoutSeconds = max(1, min(60, $timeoutSeconds));

    $json = json_encode($payload);
    if ($json === false) {
        return ['ok' => false, 'error' => 'Failed to encode request'];
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);

    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'error' => 'Groq request failed: ' . $err];
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'Groq returned non-JSON response', 'http_code' => $code];
    }

    if ($code < 200 || $code >= 300) {
        $msg = (string)($decoded['error']['message'] ?? ('HTTP ' . $code));
        return ['ok' => false, 'error' => $msg, 'http_code' => $code];
    }

    $content = (string)($decoded['choices'][0]['message']['content'] ?? '');
    if (trim($content) === '') {
        return ['ok' => false, 'error' => 'Groq returned empty content'];
    }

    return ['ok' => true, 'content' => $content, 'http_code' => $code];
}
