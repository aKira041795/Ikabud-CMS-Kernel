<?php
/**
 * Help Module — Helpers
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

function hCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('help');
    if (!$ctx) {
        throw new \RuntimeException('Help module context unavailable');
    }
    return $ctx;
}

function hDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    return hCtx()->db();
}

function hInput(?string $key = null, mixed $default = null): mixed
{
    return hCtx()->input($key, $default);
}

function hRender(string $template, array $context = []): string
{
    $resolved = str_starts_with($template, 'modules/help/')
        ? $template
        : 'modules/help/' . ltrim($template, '/');

    return hCtx()->render($resolved, kernelPrepareRenderContext($resolved, $context));
}

// ── Event Listeners ──────────────────────────────────────────────
// Register inter-module event listeners here. Examples:
//
// app()->events()->listen('order.placed', function (array $payload, string $event) {
//     // React to events from other modules
// }, 10, 'help');
