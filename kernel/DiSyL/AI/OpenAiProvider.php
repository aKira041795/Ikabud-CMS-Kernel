<?php

declare(strict_types=1);

namespace Ikabud\Kernel\DiSyL\AI;

/**
 * OpenAI provider — governed AI backend for ikb_ai_summary / ikb_ai_assist.
 *
 * Reads OPENAI_API_KEY and OPENAI_MODEL from environment.
 * When no API key is set, falls back to EchoAiProvider behavior.
 * All calls are governed by Policy (kill switch, model allowlist, cost ceiling).
 *
 * Usage:
 *   $engine->setAiProvider(new OpenAiProvider());
 *
 * Environment:
 *   OPENAI_API_KEY=sk-...       (required for real calls)
 *   OPENAI_MODEL=gpt-4o-mini    (default: gpt-4o-mini)
 *   OPENAI_BASE_URL=...         (optional: custom endpoint / proxy)
 */
final class OpenAiProvider implements AiProvider
{
    private string $apiKey;
    private string $model;
    private string $baseUrl;

    public function __construct(
        ?string $apiKey = null,
        ?string $model = null,
        ?string $baseUrl = null
    ) {
        $this->apiKey = $apiKey
            ?? (string)(getenv('OPENAI_API_KEY') ?: '');
        $this->model = $model
            ?? (string)(getenv('OPENAI_MODEL') ?: 'gpt-4o-mini');
        $this->baseUrl = $baseUrl
            ?? (string)(getenv('OPENAI_BASE_URL') ?: 'https://api.openai.com/v1');
    }

    public function complete(array $req): array
    {
        $model = (string)($req['model'] ?? $this->model);
        $prompt = (string)($req['prompt'] ?? '');
        $maxTokens = (int)($req['max_tokens'] ?? 256);

        // No API key configured — fall back to deterministic placeholder
        if ($this->apiKey === '') {
            return $this->echoFallback($model, $prompt, $maxTokens);
        }

        try {
            $response = $this->callOpenAi($model, $prompt, $maxTokens);
            return $response;
        } catch (\Throwable $e) {
            if (\function_exists('write_log')) {
                \write_log('OpenAiProvider: API call failed', 'warning', [
                    'model' => $model,
                    'error' => $e->getMessage(),
                ]);
            }
            // Fall back on error
            return $this->echoFallback($model, $prompt, $maxTokens);
        }
    }

    /**
     * @return array{text: string, input_tokens: int, output_tokens: int, model: string}
     */
    private function callOpenAi(string $model, string $prompt, int $maxTokens): array
    {
        $url = rtrim($this->baseUrl, '/') . '/chat/completions';

        $body = json_encode([
            'model' => $model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'max_tokens' => $maxTokens,
            'temperature' => 0.7,
        ], JSON_UNESCAPED_SLASHES);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $error !== '') {
            throw new \RuntimeException('OpenAI request failed: ' . ($error ?: 'unknown error'));
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('OpenAI returned invalid JSON');
        }

        if ($httpCode >= 400 || isset($data['error'])) {
            $errMsg = $data['error']['message'] ?? ('HTTP ' . $httpCode);
            throw new \RuntimeException('OpenAI error: ' . $errMsg);
        }

        $text = $data['choices'][0]['message']['content'] ?? '';
        $usage = $data['usage'] ?? [];

        return [
            'text' => $text,
            'input_tokens' => (int)($usage['prompt_tokens'] ?? 0),
            'output_tokens' => (int)($usage['completion_tokens'] ?? 0),
            'model' => $data['model'] ?? $model,
        ];
    }

    /**
     * Fallback when no API key is configured — matches EchoAiProvider behavior.
     */
    private function echoFallback(string $model, string $prompt, int $maxTokens): array
    {
        $maxChars = $maxTokens * 4;
        $text = '[ai:' . $model . '] ' . $prompt;
        if (\strlen($text) > $maxChars) {
            $text = \substr($text, 0, $maxChars);
        }
        return [
            'text' => $text,
            'input_tokens' => (int)\ceil(\strlen($prompt) / 4),
            'output_tokens' => (int)\ceil(\strlen($text) / 4),
            'model' => $model,
        ];
    }
}
