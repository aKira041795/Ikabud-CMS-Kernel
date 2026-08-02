<?php
/**
 * Example Notes Module — Helpers
 *
 * Scoped helper functions are the idiomatic way to access module
 * context, database, input, and rendering. They isolate the module
 * from global state and make the code self-documenting.
 *
 * Naming convention: {2-3 letter prefix}{CapitalizedName}()
 *   example-notes → en → enCtx(), enDb(), enInput(), enRender()
 *
 * Register inter-module event listeners at the bottom of this file.
 * This file is auto-loaded by the kernel when the module is enabled.
 *
 * @see docs/module-development-guide.md — Helpers section
 * @see docs/module-quickstart.md — Step 2
 */

declare(strict_types=1);

// ── Scoped Context Helpers ───────────────────────────────────────

/**
 * Returns the scoped ModuleContext for example-notes.
 * Use this to access auth, settings, and rendering APIs.
 */
function enCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('example-notes');
    if (!$ctx) {
        throw new \RuntimeException('Example Notes module context unavailable');
    }
    return $ctx;
}

/**
 * Returns the scoped ModuleDB for example-notes.
 * All database access must go through this — never app()->db() directly.
 */
function enDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    return enCtx()->db();
}

/**
 * Returns the decoded request body (JSON or form post).
 * Pass a key to get a specific field; omit for the full array.
 */
function enInput(?string $key = null, mixed $default = null): mixed
{
    return enCtx()->input($key, $default);
}

/**
 * Renders a DiSyL template from this module's template directory.
 * Short paths are auto-prefixed: 'pages/list.disyl' resolves to
 * 'modules/example-notes/pages/list.disyl'.
 */
function enRender(string $template, array $context = []): string
{
    $resolved = str_starts_with($template, 'modules/example-notes/')
        ? $template
        : 'modules/example-notes/' . ltrim($template, '/');

    return enCtx()->render($resolved, kernelPrepareRenderContext($resolved, $context));
}

function example_notes_capability_handlers(): array
{
    return ['example_notes.ping@1' => 'example_notes_cap_ping_1'];
}

function example_notes_cap_ping_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    return ['ok' => true, 'module' => 'example-notes', 'echo' => is_array($payload) ? $payload : []];
}

// ── Event Listeners ──────────────────────────────────────────────
// Example: react to an event fired by another module.
//
// app()->events()->listen('some-module.thing.happened', function (array $payload, string $event) {
//     // Do something in response
// }, 10, 'example-notes');
