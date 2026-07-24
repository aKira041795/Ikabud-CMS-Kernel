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

/**
 * Sensitive key names that are stored encrypted at rest.
 * These keys are encrypted before persistence and decrypted on read.
 */
function aiSensitiveKeyNames(): array
{
    return [
        'openai_api_key',
        'groq_api_key',
        'gemini_api_key',
        'mistral_api_key',
        'cerebras_api_key',
        'openrouter_api_key',
        'search_grounding_api_key',
    ];
}

/**
 * Encrypt sensitive values in an AI settings array before persistence.
 * Non-sensitive keys pass through unchanged.
 */
function aiEncryptSensitiveSettings(array $settings): array
{
    $sensitiveKeys = aiSensitiveKeyNames();
    foreach ($sensitiveKeys as $key) {
        if (isset($settings[$key]) && is_string($settings[$key]) && $settings[$key] !== '') {
            $existingEnvelope = json_decode($settings[$key], true);
            if (is_array($existingEnvelope) && isset($existingEnvelope['ciphertext'], $existingEnvelope['iv'], $existingEnvelope['tag'])) {
                continue;
            }
            try {
                $enc = (new \Ikabud\Kernel\Crypto())->encryptString($settings[$key]);
                $settings[$key] = json_encode($enc, JSON_UNESCAPED_SLASHES);
            } catch (\Throwable $e) {
                // If encryption fails, store a placeholder that won't be usable.
                // The key must be re-entered by the admin.
                if (function_exists('write_log')) {
                    write_log('ai: failed to encrypt sensitive setting', 'error', [
                        'key' => $key,
                        'error' => $e->getMessage(),
                    ]);
                }
                $settings[$key] = '';
            }
        }
    }
    return $settings;
}

/**
 * Decrypt sensitive values in an AI settings array after reading from storage.
 * Non-sensitive keys and already-plaintext values pass through unchanged.
 */
function aiDecryptSensitiveSettings(array $settings): array
{
    $sensitiveKeys = aiSensitiveKeyNames();
    foreach ($sensitiveKeys as $key) {
        if (!isset($settings[$key]) || !is_string($settings[$key]) || $settings[$key] === '') {
            continue;
        }
        // Detect if value is a JSON-encrypted envelope (starts with '{')
        $val = $settings[$key];
        if ($val[0] === '{') {
            $envelope = json_decode($val, true);
            if (is_array($envelope) && isset($envelope['ciphertext'], $envelope['iv'], $envelope['tag'])) {
                try {
                    $settings[$key] = (new \Ikabud\Kernel\Crypto())->decryptString(
                        $envelope['ciphertext'],
                        $envelope['iv'],
                        $envelope['tag'],
                        $envelope['key_id'] ?? null
                    );
                } catch (\Throwable $e) {
                    // Decryption failed — likely legacy plaintext or key rotation gap.
                    // Leave the value as-is (may be legacy plaintext).
                    if (function_exists('write_log')) {
                        write_log('ai: failed to decrypt sensitive setting', 'warning', [
                            'key' => $key,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }
        // If value doesn't look like an envelope, it's legacy plaintext — leave as-is.
    }
    return $settings;
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

    // Decrypt any sensitive keys that were stored encrypted at rest.
    return aiDecryptSensitiveSettings($resolved);
}

function ai_capability_handlers(): array
{
    return [
        'ai.capability.suggest@1' => 'ai_cap_ai_capability_suggest_1',
        'ai.text.generate@1' => 'ai_cap_ai_text_generate_1',
        'ai.explain@1' => 'ai_cap_ai_explain_1',
        'ai.search.discover@1' => 'ai_cap_ai_search_discover_1',
    ];
}

/** Register AI providers for trusted headless/CLI consumers such as Workbench. */
function aiRegisterHeadlessCapabilities(): void
{
    foreach (ai_capability_handlers() as $capabilityId => $handler) {
        if (!is_callable($handler) || app()->capabilities()->has($capabilityId)) {
            continue;
        }
        app()->capabilities()->register(
            $capabilityId,
            'ai',
            $handler,
            50,
            ['first'],
            ['origin' => ['type' => 'headless_module_activation', 'module' => 'ai']]
        );
    }
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

    return [
        'ok' => true,
        'content' => (string)($out['content'] ?? ''),
        'provider' => (string)($out['provider'] ?? ($provider !== '' ? $provider : 'openai')),
        'model' => (string)($out['model'] ?? ''),
    ];
}

function ai_cap_ai_search_discover_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'candidates' => [], 'error' => 'Invalid payload'];
    }

    $queries = (array)($payload['queries'] ?? []);
    $maxSources = max(1, min(25, (int)($payload['max_sources'] ?? 5)));
    $tenantId = (string)($payload['tenant_id'] ?? '');

    if ($queries === []) {
        return ['ok' => true, 'candidates' => [], 'disclosure' => 'No search queries provided.'];
    }

    // ── Provider resolution ──
    // Check AISS settings first, then env vars, for API keys.
    $settings = [];
    try {
        if ($tenantId !== '' && function_exists('academic_similarity_get_settings')) {
            $settings = academic_similarity_get_settings($tenantId);
        }
    } catch (\Throwable $e) {
        // Settings unavailable — fall through to env vars
    }

    $searchProvider = (string)($settings['internet_search_backend'] ?? $_ENV['AISS_SEARCH_BACKEND'] ?? 'serpapi');
    $apiKey = '';

    if ($searchProvider === 'serpapi') {
        $apiKey = trim((string)($settings['internet_check_api_key'] ?? $_ENV['SERPAPI_KEY'] ?? ''));
    } elseif ($searchProvider === 'google_cse') {
        $apiKey = trim((string)($settings['internet_check_api_key'] ?? $_ENV['GOOGLE_CSE_KEY'] ?? ''));
        // CSE also needs a CX (engine ID)
    } elseif ($searchProvider === 'bing') {
        $apiKey = trim((string)($settings['internet_check_api_key'] ?? $_ENV['BING_API_KEY'] ?? ''));
    }

    // If an explicit key env var is set in settings, use it
    $keyEnvVar = trim((string)($settings['internet_check_api_key_env'] ?? ''));
    if ($keyEnvVar !== '') {
        $envValue = $_ENV[$keyEnvVar] ?? getenv($keyEnvVar);
        if (is_string($envValue) && trim($envValue) !== '') {
            $apiKey = trim($envValue);
        }
    }

    if ($apiKey === '') {
        return [
            'ok' => true,
            'candidates' => [],
            'disclosure' => 'No search API key configured. Set SERPAPI_KEY (or equivalent) in .env, or configure internet_check_api_key in AISS settings. For zero-config testing, use provider=seed_urls.',
        ];
    }

    // ── Execute search ──
    $candidates = [];
    $searched = 0;

    foreach ($queries as $query) {
        if ($searched >= $maxSources * 2) break; // Don't exceed 2x max in searches

        $results = [];
        try {
            if ($searchProvider === 'serpapi') {
                $results = ai_search_serpapi($query, $apiKey, min(10, $maxSources));
            } elseif ($searchProvider === 'google_cse') {
                $cx = trim((string)($settings['internet_check_api_key_env'] ?? $_ENV['GOOGLE_CSE_CX'] ?? ''));
                $results = ai_search_google_cse($query, $apiKey, $cx, min(10, $maxSources));
            } elseif ($searchProvider === 'bing') {
                $results = ai_search_bing($query, $apiKey, min(10, $maxSources));
            }
        } catch (\Throwable $e) {
            if (function_exists('write_log')) {
                write_log("AISS search provider {$searchProvider} failed: " . $e->getMessage(), 'warning');
            }
            continue;
        }

        foreach ($results as $result) {
            $url = trim((string)($result['url'] ?? ''));
            if ($url === '') continue;
            // Skip duplicates
            foreach ($candidates as $existing) {
                if (($existing['url'] ?? '') === $url) continue 2;
            }
            $candidates[] = [
                'provider' => $searchProvider,
                'query' => $query,
                'rank' => count($candidates) + 1,
                'url' => $url,
                'title' => trim((string)($result['title'] ?? $url)),
                'snippet' => trim((string)($result['snippet'] ?? '')),
                'publisher' => trim((string)($result['publisher'] ?? '')),
            ];
            if (count($candidates) >= $maxSources) break 2;
        }
        $searched++;
    }

    $disclosure = $candidates === []
        ? "Searched {$searched} queries via {$searchProvider} but found no matching sources."
        : "Searched {$searched} queries via {$searchProvider} and discovered " . count($candidates) . " candidate source(s). This is not a comprehensive internet search.";

    return [
        'ok' => true,
        'candidates' => $candidates,
        'disclosure' => $disclosure,
    ];
}

/**
 * Search via SerpAPI (serpapi.com).
 * Returns up to $max results as [['url', 'title', 'snippet'], ...].
 */
function ai_search_serpapi(string $query, string $apiKey, int $max = 10): array
{
    $url = 'https://serpapi.com/search?q=' . rawurlencode($query)
         . '&api_key=' . rawurlencode($apiKey)
         . '&num=' . $max
         . '&engine=google';

    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 15,
            'header' => "User-Agent: AISS/1.0\r\nAccept: application/json\r\n",
        ],
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    if (!is_string($raw)) {
        return [];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return [];
    }

    $results = [];
    $organic = $data['organic_results'] ?? [];
    foreach ($organic as $item) {
        if (!is_array($item)) continue;
        $results[] = [
            'url' => (string)($item['link'] ?? ''),
            'title' => (string)($item['title'] ?? ''),
            'snippet' => (string)($item['snippet'] ?? ''),
        ];
        if (count($results) >= $max) break;
    }

    return $results;
}

/**
 * Search via Google Custom Search Engine.
 */
function ai_search_google_cse(string $query, string $apiKey, string $cx, int $max = 10): array
{
    if ($cx === '') return [];
    $url = 'https://www.googleapis.com/customsearch/v1?q=' . rawurlencode($query)
         . '&key=' . rawurlencode($apiKey)
         . '&cx=' . rawurlencode($cx)
         . '&num=' . min(10, $max);

    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 15,
            'header' => "User-Agent: AISS/1.0\r\nAccept: application/json\r\n",
        ],
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    if (!is_string($raw)) return [];

    $data = json_decode($raw, true);
    if (!is_array($data)) return [];

    $results = [];
    foreach (($data['items'] ?? []) as $item) {
        if (!is_array($item)) continue;
        $results[] = [
            'url' => (string)($item['link'] ?? ''),
            'title' => (string)($item['title'] ?? ''),
            'snippet' => (string)($item['snippet'] ?? ''),
        ];
        if (count($results) >= $max) break;
    }

    return $results;
}

/**
 * Search via Bing Web Search API.
 */
function ai_search_bing(string $query, string $apiKey, int $max = 10): array
{
    $url = 'https://api.bing.microsoft.com/v7.0/search?q=' . rawurlencode($query)
         . '&count=' . min(50, $max);

    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 15,
            'header' => "User-Agent: AISS/1.0\r\n"
                       . "Ocp-Apim-Subscription-Key: {$apiKey}\r\n"
                       . "Accept: application/json\r\n",
        ],
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    if (!is_string($raw)) return [];

    $data = json_decode($raw, true);
    if (!is_array($data)) return [];

    $results = [];
    foreach (($data['webPages']['value'] ?? []) as $item) {
        if (!is_array($item)) continue;
        $results[] = [
            'url' => (string)($item['url'] ?? ''),
            'title' => (string)($item['name'] ?? ''),
            'snippet' => (string)($item['snippet'] ?? ''),
        ];
        if (count($results) >= $max) break;
    }

    return $results;
}
