<?php
/**
 * Cms Akira Theme Module — Helpers
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

function catCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('cms-akira-theme');
    if (!$ctx) {
        throw new \RuntimeException('Cms Akira Theme module context unavailable');
    }
    return $ctx;
}

function catDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    return catCtx()->db();
}

function catInput(?string $key = null, mixed $default = null): mixed
{
    return catCtx()->input($key, $default);
}

function catRender(string $template, array $context = []): string
{
    $resolved = str_starts_with($template, 'modules/cms-akira-theme/')
        ? $template
        : 'modules/cms-akira-theme/' . ltrim($template, '/');

    return catCtx()->render($resolved, kernelPrepareRenderContext($resolved, $context));
}

function cms_akira_theme_capability_handlers(): array
{
    return [
        'akira.theme.resolve@1' => 'cat_cap_akira_theme_resolve_1',
    ];
}

function cat_cap_akira_theme_resolve_1(mixed $payload, string $capabilityId = 'akira.theme.resolve@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'payload must be an object'];
    }

    $themeKey = trim((string)($payload['theme'] ?? 'akira-default'));
    if ($themeKey === '') {
        $themeKey = 'akira-default';
    }

    return [
        'ok' => true,
        'data' => [
            'theme' => $themeKey,
            'layout' => 'content',
            'provider' => 'cms-akira-theme',
        ],
    ];
}

// ── Event Listeners ──────────────────────────────────────────────
// Register inter-module event listeners here. Examples:
//
// app()->events()->listen('order.placed', function (array $payload, string $event) {
//     // React to events from other modules
// }, 10, 'cms-akira-theme');
