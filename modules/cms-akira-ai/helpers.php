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

// ── Event Listeners ──────────────────────────────────────────────
// Register inter-module event listeners here. Examples:
//
// app()->events()->listen('order.placed', function (array $payload, string $event) {
//     // React to events from other modules
// }, 10, 'cms-akira-ai');
