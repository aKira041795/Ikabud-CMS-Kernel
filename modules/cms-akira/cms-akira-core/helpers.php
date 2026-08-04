<?php
/**
 * Cms Akira Core Module — Helpers
 *
 * This file is auto-loaded when the module is enabled.
 * Scoped helper functions provide isolated access to module context,
 * database, input, and rendering. Register event listeners here too.
 *
 * @see docs/kernel/module-development-guide.md
 * @see docs/kernel/module-quickstart.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers/providers.php';
require_once __DIR__ . '/helpers/capabilities.php';

// ── Scoped Context Helpers ───────────────────────────────────────

function cacCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('cms-akira-core');
    if (!$ctx) {
        throw new \RuntimeException('Cms Akira Core module context unavailable');
    }
    return $ctx;
}

function cacDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    return cacCtx()->db();
}

function cacInput(?string $key = null, mixed $default = null): mixed
{
    return cacCtx()->input($key, $default);
}

function cacRender(string $template, array $context = []): string
{
    $resolved = str_starts_with($template, 'modules/cms-akira-core/')
        ? $template
        : 'modules/cms-akira-core/' . ltrim($template, '/');

    return cacCtx()->render($resolved, kernelPrepareRenderContext($resolved, $context));
}

// ── Event Listeners ──────────────────────────────────────────────
// Register inter-module event listeners here. Examples:
//
// app()->events()->listen('order.placed', function (array $payload, string $event) {
//     // React to events from other modules
// }, 10, 'cms-akira-core');
