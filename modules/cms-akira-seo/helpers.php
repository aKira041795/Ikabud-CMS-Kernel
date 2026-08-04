<?php
/**
 * Cms Akira Seo Module — Helpers
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

function casCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('cms-akira-seo');
    if (!$ctx) {
        throw new \RuntimeException('Cms Akira Seo module context unavailable');
    }
    return $ctx;
}

function casDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    return casCtx()->db();
}

function casInput(?string $key = null, mixed $default = null): mixed
{
    return casCtx()->input($key, $default);
}

function casRender(string $template, array $context = []): string
{
    $resolved = str_starts_with($template, 'modules/cms-akira-seo/')
        ? $template
        : 'modules/cms-akira-seo/' . ltrim($template, '/');

    return casCtx()->render($resolved, kernelPrepareRenderContext($resolved, $context));
}

// ── Event Listeners ──────────────────────────────────────────────
// Register inter-module event listeners here. Examples:
//
// app()->events()->listen('order.placed', function (array $payload, string $event) {
//     // React to events from other modules
// }, 10, 'cms-akira-seo');
