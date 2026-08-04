<?php
/**
 * Cms Akira Profile Headless Module — Helpers
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

function caphCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('cms-akira-profile-headless');
    if (!$ctx) {
        throw new \RuntimeException('Cms Akira Profile Headless module context unavailable');
    }
    return $ctx;
}

function caphDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    return caphCtx()->db();
}

function caphInput(?string $key = null, mixed $default = null): mixed
{
    return caphCtx()->input($key, $default);
}

function caphRender(string $template, array $context = []): string
{
    $resolved = str_starts_with($template, 'modules/cms-akira-profile-headless/')
        ? $template
        : 'modules/cms-akira-profile-headless/' . ltrim($template, '/');

    return caphCtx()->render($resolved, kernelPrepareRenderContext($resolved, $context));
}

// ── Event Listeners ──────────────────────────────────────────────
// Register inter-module event listeners here. Examples:
//
// app()->events()->listen('order.placed', function (array $payload, string $event) {
//     // React to events from other modules
// }, 10, 'cms-akira-profile-headless');
