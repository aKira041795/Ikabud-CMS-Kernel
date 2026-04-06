<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers/openai.php';
require_once __DIR__ . '/helpers/groq.php';
require_once __DIR__ . '/helpers/ollama.php';
require_once __DIR__ . '/helpers/gemini.php';
require_once __DIR__ . '/helpers/cerebras.php';
require_once __DIR__ . '/helpers/openrouter.php';
require_once __DIR__ . '/helpers/mistral.php';

function aiRuntimeOverrides(?array $replace = null): array
{
    static $overrides = [];
    $tid = app()->tenant()->current();

    if ($replace !== null) {
        $overrides[$tid] = $replace;
    }

    return $overrides[$tid] ?? [];
}

function aiWithRuntimeOverrides(array $overrides, callable $callback): mixed
{
    $previous = aiRuntimeOverrides();
    aiRuntimeOverrides(array_merge($previous, $overrides));

    try {
        return $callback();
    } finally {
        aiRuntimeOverrides($previous);
    }
}

function aiGlobalSettings(): array
{
    try {
        if (function_exists('readModuleRegistry')) {
            $registry = readModuleRegistry();
            $settings = $registry['ai']['settings'] ?? [];
            return is_array($settings) ? $settings : [];
        }
    } catch (Throwable $e) {
    }

    return [];
}

function aiSettingsDefaults(): array
{
    static $defaults = null;
    if ($defaults !== null) {
        return $defaults;
    }

    $defaults = [];
    $manifest = discoverModules()['ai'] ?? [];
    $fields = is_array($manifest['settings_fields'] ?? null) ? $manifest['settings_fields'] : [];

    foreach ($fields as $field) {
        if (!is_array($field)) {
            continue;
        }

        $key = trim((string)($field['key'] ?? ''));
        if ($key === '' || !array_key_exists('default', $field)) {
            continue;
        }

        $defaults[$key] = $field['default'];
    }

    return $defaults;
}

function aiResolvedSettings(): array
{
    $resolved = aiSettingsDefaults();

    foreach (aiGlobalSettings() as $key => $value) {
        if (is_string($value) && trim($value) === '') {
            continue;
        }
        $resolved[$key] = $value;
    }

    try {
        if (function_exists('getModuleSettings')) {
            $tenant = getModuleSettings('ai');
            if (is_array($tenant)) {
                // Merge tenant into global, but skip empty-string tenant
                // values so they don't mask valid global defaults.
                foreach ($tenant as $key => $value) {
                    if (is_string($value) && trim($value) === '') {
                        continue;
                    }
                    $resolved[$key] = $value;
                }
            }
        }
    } catch (Throwable $e) {
    }

    foreach (aiRuntimeOverrides() as $key => $value) {
        if (is_string($value) && trim($value) === '') {
            continue;
        }
        $resolved[$key] = $value;
    }

    return $resolved;
}

function ai_capability_handlers(): array
{
    return [
        'ai.capability.suggest@1' => 'ai_cap_ai_capability_suggest_1',
        'ai.text.generate@1' => 'ai_cap_ai_text_generate_1',
        'ai.explain@1' => 'ai_cap_ai_explain_1',
    ];
}

function ai_cap_ai_capability_suggest_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'Invalid payload'];
    }

    $event = $payload['event'] ?? null;
    if (!is_array($event)) {
        return ['ok' => false, 'error' => 'event is required'];
    }

    $existing = $payload['existing_triggers'] ?? [];
    if (!is_array($existing)) {
        $existing = [];
    }

    $availableCaps = $payload['available_capabilities'] ?? [];
    if (!is_array($availableCaps)) {
        $availableCaps = [];
    }

    $ctx = [
        'event' => $event,
        'existing_triggers' => $existing,
        'available_capabilities' => $availableCaps,
        'mode' => (string)($payload['mode'] ?? 'triggers'),
    ];

    $provider = '';
    try {
        if (function_exists('aiResolvedSettings')) {
            $s = aiResolvedSettings();
            if (is_array($s)) {
                $provider = trim((string)($s['provider'] ?? ''));
            }
        }
    } catch (Throwable $e) {
    }

    if ($provider === 'groq') {
        $out = aiGroqSuggestTriggers($ctx);
    } elseif ($provider === 'ollama') {
        $out = aiOllamaSuggestTriggers($ctx);
    } elseif ($provider === 'gemini') {
        $out = aiGeminiSuggestTriggers($ctx);
    } elseif ($provider === 'cerebras') {
        $out = aiCerebrasSuggestTriggers($ctx);
    } elseif ($provider === 'openrouter') {
        $out = aiOpenRouterSuggestTriggers($ctx);
    } elseif ($provider === 'mistral') {
        $out = aiMistralSuggestTriggers($ctx);
    } else {
        $out = aiOpenAiSuggestTriggers($ctx);
    }

    if (empty($out['ok'])) {
        return $out;
    }

    $suggestions = $out['suggestions'] ?? null;
    if (!is_array($suggestions)) {
        $suggestions = [];
    }

    // Normalize expected structure
    $normalized = [];
    foreach ($suggestions as $s) {
        if (!is_array($s)) continue;
        $normalized[] = [
            'module' => (string)($s['module'] ?? ($event['module'] ?? '')),
            'event_key' => (string)($s['event_key'] ?? ($event['event_key'] ?? '')),
            'capability_id' => (string)($s['capability_id'] ?? ''),
            'provider' => isset($s['provider']) ? (string)$s['provider'] : null,
            'is_enabled' => array_key_exists('is_enabled', $s) ? (bool)$s['is_enabled'] : true,
            'priority' => array_key_exists('priority', $s) ? (int)$s['priority'] : 100,
            'template' => array_key_exists('template', $s) ? (string)$s['template'] : null,
            'max_per_minute' => array_key_exists('max_per_minute', $s) ? ($s['max_per_minute'] === null ? null : (int)$s['max_per_minute']) : null,
            'retry_count' => array_key_exists('retry_count', $s) ? (int)$s['retry_count'] : 0,
            'timeout_ms' => array_key_exists('timeout_ms', $s) ? (int)$s['timeout_ms'] : 5000,
            'meta' => array_key_exists('meta', $s) && is_array($s['meta']) ? $s['meta'] : null,
            'reason' => array_key_exists('reason', $s) ? (string)$s['reason'] : '',
        ];
    }

    return [
        'ok' => true,
        'suggestions' => $normalized,
        'provider' => $provider !== '' ? $provider : 'openai',
    ];
}

function ai_cap_ai_explain_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'Invalid payload'];
    }

    $concept = trim((string)($payload['concept'] ?? ''));
    if ($concept === '') {
        return ['ok' => false, 'error' => 'concept is required'];
    }

    // Get glossary entry if available
    $glossary = [];
    try {
        $glossary = app()->glossary();
    } catch (Throwable $e) {
    }
    $glossEntry = $glossary[$concept] ?? null;

    // Try AI explanation
    $systemPrompt = 'You are a helpful assistant that explains technical platform concepts in simple, non-technical language. '
        . 'The platform is called Ikabud — a modular application platform. '
        . 'Keep explanations under 3 sentences. Use everyday analogies when helpful. '
        . 'Do not use jargon unless defining it.';

    $context = '';
    if (is_array($glossEntry)) {
        $context = 'Technical ID: ' . $concept . "\n";
        $context .= 'Label: ' . ($glossEntry['label'] ?? '') . "\n";
        $context .= 'Brief description: ' . ($glossEntry['description'] ?? '') . "\n";
        $context .= 'Category: ' . ($glossEntry['category'] ?? '') . "\n";
    }

    $userPrompt = 'Explain this platform concept in plain English for a non-technical business user:\n\n' . $context
        . ($context === '' ? $concept : '') . "\n\n"
        . 'Give a short, clear explanation.';

    try {
        $result = app()->cap()->call('ai.text.generate@1', [
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'temperature' => 0.3,
            'json' => false,
            'timeout_ms' => 5000,
        ], ['caller_module' => 'ai']);

        if (!empty($result['ok']) && isset($result['content']) && trim((string)$result['content']) !== '') {
            return [
                'ok' => true,
                'concept' => $concept,
                'explanation' => trim((string)$result['content']),
                'glossary' => $glossEntry,
                'source' => 'ai',
            ];
        }
    } catch (Throwable $e) {
        // Fall back to glossary
    }

    // Fallback: return glossary description if AI failed
    if (is_array($glossEntry) && !empty($glossEntry['description'])) {
        return [
            'ok' => true,
            'concept' => $concept,
            'explanation' => (string)$glossEntry['description'],
            'glossary' => $glossEntry,
            'source' => 'glossary',
        ];
    }

    return ['ok' => false, 'error' => 'Could not explain concept: ' . $concept];
}

function ai_cap_ai_text_generate_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'Invalid payload'];
    }

    $messages = $payload['messages'] ?? null;
    if (!is_array($messages) || empty($messages)) {
        return ['ok' => false, 'error' => 'messages is required'];
    }

    $temperature = array_key_exists('temperature', $payload) ? (float)$payload['temperature'] : 0.2;
    $json = array_key_exists('json', $payload) ? (bool)$payload['json'] : false;
    $timeoutMs = array_key_exists('timeout_ms', $payload) ? (int)$payload['timeout_ms'] : 5000;
    $timeoutSeconds = max(1, (int)ceil($timeoutMs / 1000));
    $maxTokens = isset($payload['max_tokens']) && (int)$payload['max_tokens'] > 0 ? (int)$payload['max_tokens'] : null;
    $preferredTier = trim((string)($payload['preferred_tier'] ?? ''));
    if (!in_array($preferredTier, ['free', 'paid', 'custom'], true)) {
        $preferredTier = '';
    }

    $provider = '';
    try {
        if (function_exists('aiResolvedSettings')) {
            $s = aiResolvedSettings();
            if (is_array($s)) {
                $provider = trim((string)($s['provider'] ?? ''));
            }
        }
    } catch (Throwable $e) {
    }

    $callProvider = static function () use ($provider, $messages, $temperature, $json, $timeoutSeconds, $maxTokens): array {
        if ($provider === 'groq') {
            return aiGroqTextGenerate($messages, $temperature, $json, $timeoutSeconds, $maxTokens);
        }
        if ($provider === 'ollama') {
            return aiOllamaTextGenerate($messages, $temperature, $json, $timeoutSeconds, $maxTokens);
        }
        if ($provider === 'gemini') {
            return aiGeminiTextGenerate($messages, $temperature, $json, $timeoutSeconds, $maxTokens);
        }
        if ($provider === 'cerebras') {
            return aiCerebrasTextGenerate($messages, $temperature, $json, $timeoutSeconds, $maxTokens);
        }
        if ($provider === 'openrouter') {
            return aiOpenRouterTextGenerate($messages, $temperature, $json, $timeoutSeconds, $maxTokens);
        }
        if ($provider === 'mistral') {
            return aiMistralTextGenerate($messages, $temperature, $json, $timeoutSeconds, $maxTokens);
        }

        return aiOpenAiTextGenerate($messages, $temperature, $json, $timeoutSeconds, $maxTokens);
    };

    $out = $preferredTier !== ''
        ? aiWithRuntimeOverrides(['tier' => $preferredTier], $callProvider)
        : $callProvider();

    if (empty($out['ok'])) {
        return $out;
    }

    return ['ok' => true, 'content' => (string)($out['content'] ?? '')];
}
