<?php
/**
 * Cms Akira Profile Standard Module — Helpers
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

function capsCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('cms-akira-profile-standard');
    if (!$ctx) {
        throw new \RuntimeException('Cms Akira Profile Standard module context unavailable');
    }
    return $ctx;
}

function capsDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    return capsCtx()->db();
}

function capsInput(?string $key = null, mixed $default = null): mixed
{
    return capsCtx()->input($key, $default);
}

function capsRender(string $template, array $context = []): string
{
    $resolved = str_starts_with($template, 'modules/cms-akira-profile-standard/')
        ? $template
        : 'modules/cms-akira-profile-standard/' . ltrim($template, '/');

    return capsCtx()->render($resolved, kernelPrepareRenderContext($resolved, $context));
}

// ── Event Listeners ──────────────────────────────────────────────
// Register inter-module event listeners here. Examples:
//
// app()->events()->listen('order.placed', function (array $payload, string $event) {
//     // React to events from other modules
// }, 10, 'cms-akira-profile-standard');
