<?php
/**
 * Cms Akira Ai Module — Helpers
 *
 * This file is auto-loaded when the module is enabled.
 * Scoped helper functions provide isolated access to module context,
 * database, input, and rendering. Register event listeners here too.
 *
 * @see docs/kernel/module-development-guide.md
 * @see docs/kernel/module-quickstart.md
 */

declare(strict_types=1);

// ── Scoped Context Helpers ───────────────────────────────────────

function caaCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('cms-akira-ai');
    if (!$ctx) {
        throw new \RuntimeException('Cms Akira Ai module context unavailable');
    }
    return $ctx;
}

function caaDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    return caaCtx()->db();
}

function caaInput(?string $key = null, mixed $default = null): mixed
{
    return caaCtx()->input($key, $default);
}

function caaRender(string $template, array $context = []): string
{
    $resolved = str_starts_with($template, 'modules/cms-akira-ai/')
        ? $template
        : 'modules/cms-akira-ai/' . ltrim($template, '/');

    return caaCtx()->render($resolved, kernelPrepareRenderContext($resolved, $context));
}

function cms_akira_ai_capability_handlers(): array
{
    return [
        'akira.ai.summary.suggest@1' => 'caa_cap_akira_ai_summary_suggest_1',
    ];
}

function caa_cap_akira_ai_summary_suggest_1(mixed $payload, string $capabilityId = 'akira.ai.summary.suggest@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'payload must be an object'];
    }

    $title = trim((string)($payload['title'] ?? ''));
    if ($title === '') {
        return ['ok' => false, 'error' => 'title is required'];
    }

    $text = trim((string)($payload['excerpt'] ?? ''));
    if ($text === '') {
        $text = trim(strip_tags((string)($payload['body'] ?? '')));
    }

    $summary = $text !== '' ? mb_substr($text, 0, 200) : mb_substr($title, 0, 200);

    $keywords = [];
    foreach (preg_split('/\s+/', mb_strtolower($title . ' ' . $text)) ?: [] as $word) {
        $word = preg_replace('/[^a-z0-9\-]/', '', $word ?? '') ?? '';
        if ($word === '' || mb_strlen($word) < 4) {
            continue;
        }
        $keywords[$word] = true;
        if (count($keywords) >= 6) {
            break;
        }
    }

    return [
        'ok' => true,
        'data' => [
            'summary' => $summary,
            'keywords' => array_keys($keywords),
            'provider' => 'cms-akira-ai',
        ],
    ];
}

// ── Event Listeners ──────────────────────────────────────────────
// Register inter-module event listeners here. Examples:
//
// app()->events()->listen('order.placed', function (array $payload, string $event) {
//     // React to events from other modules
// }, 10, 'cms-akira-ai');
