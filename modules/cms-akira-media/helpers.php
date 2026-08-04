<?php
/**
 * Cms Akira Media Module — Helpers
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

function camCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('cms-akira-media');
    if (!$ctx) {
        throw new \RuntimeException('Cms Akira Media module context unavailable');
    }
    return $ctx;
}

function camDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    return camCtx()->db();
}

function camInput(?string $key = null, mixed $default = null): mixed
{
    return camCtx()->input($key, $default);
}

function camRender(string $template, array $context = []): string
{
    $resolved = str_starts_with($template, 'modules/cms-akira-media/')
        ? $template
        : 'modules/cms-akira-media/' . ltrim($template, '/');

    return camCtx()->render($resolved, kernelPrepareRenderContext($resolved, $context));
}

function cms_akira_media_capability_handlers(): array
{
    return [
        'akira.media.resolve@1' => 'cam_cap_akira_media_resolve_1',
    ];
}

function cam_cap_akira_media_resolve_1(mixed $payload, string $capabilityId = 'akira.media.resolve@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'payload must be an object'];
    }

    $mediaId = isset($payload['media_id']) ? (int)$payload['media_id'] : null;
    $url = trim((string)($payload['url'] ?? $payload['featured_image_url'] ?? ''));
    $alt = trim((string)($payload['alt'] ?? $payload['featured_image_alt'] ?? ''));

    return [
        'ok' => true,
        'data' => [
            'media_id' => $mediaId,
            'url' => $url,
            'alt' => $alt,
            'provider' => 'cms-akira-media',
        ],
    ];
}

// ── Event Listeners ──────────────────────────────────────────────
// Register inter-module event listeners here. Examples:
//
// app()->events()->listen('order.placed', function (array $payload, string $event) {
//     // React to events from other modules
// }, 10, 'cms-akira-media');
