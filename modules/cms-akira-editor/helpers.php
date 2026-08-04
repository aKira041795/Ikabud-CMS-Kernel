<?php
/**
 * Cms Akira Editor Module — Helpers
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

function caeCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('cms-akira-editor');
    if (!$ctx) {
        throw new \RuntimeException('Cms Akira Editor module context unavailable');
    }
    return $ctx;
}

function caeDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    return caeCtx()->db();
}

function caeInput(?string $key = null, mixed $default = null): mixed
{
    return caeCtx()->input($key, $default);
}

function caeRender(string $template, array $context = []): string
{
    $resolved = str_starts_with($template, 'modules/cms-akira-editor/')
        ? $template
        : 'modules/cms-akira-editor/' . ltrim($template, '/');

    return caeCtx()->render($resolved, kernelPrepareRenderContext($resolved, $context));
}

// ── Event Listeners ──────────────────────────────────────────────
// Register inter-module event listeners here. Examples:
//
// app()->events()->listen('order.placed', function (array $payload, string $event) {
//     // React to events from other modules
// }, 10, 'cms-akira-editor');
