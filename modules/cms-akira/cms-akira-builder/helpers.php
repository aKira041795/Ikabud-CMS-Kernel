<?php
/**
 * Cms Akira Builder Module — Helpers
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

function cabCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('cms-akira-builder');
    if (!$ctx) {
        throw new \RuntimeException('Cms Akira Builder module context unavailable');
    }
    return $ctx;
}

function cabDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    return cabCtx()->db();
}

function cabInput(?string $key = null, mixed $default = null): mixed
{
    return cabCtx()->input($key, $default);
}

function cabRender(string $template, array $context = []): string
{
    $resolved = str_starts_with($template, 'modules/cms-akira-builder/')
        ? $template
        : 'modules/cms-akira-builder/' . ltrim($template, '/');

    return cabCtx()->render($resolved, kernelPrepareRenderContext($resolved, $context));
}

// ── Event Listeners ──────────────────────────────────────────────
// Register inter-module event listeners here. Examples:
//
// app()->events()->listen('order.placed', function (array $payload, string $event) {
//     // React to events from other modules
// }, 10, 'cms-akira-builder');
