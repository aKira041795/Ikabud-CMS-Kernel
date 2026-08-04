<?php
/**
 * Cms Akira Profile Minimal Module — Helpers
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

function capmCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('cms-akira-profile-minimal');
    if (!$ctx) {
        throw new \RuntimeException('Cms Akira Profile Minimal module context unavailable');
    }
    return $ctx;
}

function capmDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    return capmCtx()->db();
}

function capmInput(?string $key = null, mixed $default = null): mixed
{
    return capmCtx()->input($key, $default);
}

function capmRender(string $template, array $context = []): string
{
    $resolved = str_starts_with($template, 'modules/cms-akira-profile-minimal/')
        ? $template
        : 'modules/cms-akira-profile-minimal/' . ltrim($template, '/');

    return capmCtx()->render($resolved, kernelPrepareRenderContext($resolved, $context));
}

// ── Event Listeners ──────────────────────────────────────────────
// Register inter-module event listeners here. Examples:
//
// app()->events()->listen('order.placed', function (array $payload, string $event) {
//     // React to events from other modules
// }, 10, 'cms-akira-profile-minimal');
