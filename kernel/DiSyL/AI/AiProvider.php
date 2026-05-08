<?php

declare(strict_types=1);

namespace Ikabud\Kernel\DiSyL\AI;

/**
 * Provider interface for {ai_generate}/{ai_query}/{ai_complete}.
 *
 * 4.6.0 ships only an interface + a deterministic test/echo provider.
 * Real backends (OpenAI, Anthropic, local llama.cpp) plug in via
 * TemplateEngine::setAiProvider() and are validated by Policy::allowsModel().
 */
interface AiProvider
{
    /**
     * @param array{model:string, prompt:string, max_tokens:int, temperature?:float, schema?:?string} $req
     * @return array{text:string, input_tokens:int, output_tokens:int, model:string}
     */
    public function complete(array $req): array;
}
