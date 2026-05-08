<?php

declare(strict_types=1);

namespace Ikabud\Kernel\DiSyL\AI;

/**
 * Deterministic echo-style provider used by tests and by templates when
 * no real AI backend has been wired.
 *
 * Output is `[ai:MODEL] PROMPT` truncated to roughly max_tokens*4 chars
 * (token ≈ 4 chars heuristic). Token counts derived from prompt length.
 */
final class EchoAiProvider implements AiProvider
{
    public function complete(array $req): array
    {
        $model = $req['model'] ?? 'echo';
        $prompt = $req['prompt'] ?? '';
        $maxTokens = (int) ($req['max_tokens'] ?? 200);
        $maxChars = $maxTokens * 4;
        $text = "[ai:$model] " . $prompt;
        if (strlen($text) > $maxChars) {
            $text = substr($text, 0, $maxChars);
        }
        return [
            'text'           => $text,
            'input_tokens'   => (int) ceil(strlen($prompt) / 4),
            'output_tokens'  => (int) ceil(strlen($text) / 4),
            'model'          => $model,
        ];
    }
}
