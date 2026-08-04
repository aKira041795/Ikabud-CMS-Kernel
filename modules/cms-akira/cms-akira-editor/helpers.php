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

function cms_akira_editor_capability_handlers(): array
{
    return [
        'editor.render@1' => 'cae_cap_editor_render_1',
        'editor.normalize@1' => 'cae_cap_editor_normalize_1',
        'editor.sanitize@1' => 'cae_cap_editor_sanitize_1',
        'editor.validate@1' => 'cae_cap_editor_validate_1',
        'editor.assets@1' => 'cae_cap_editor_assets_1',
    ];
}

function cae_cap_editor_render_1(mixed $payload, string $capabilityId = 'editor.render@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'payload must be an object'];
    }

    $content = (string)($payload['content'] ?? '');
    return [
        'ok' => true,
        'data' => [
            'html' => '<div class="akira-editor-content">' . $content . '</div>',
            'provider' => 'cms-akira-editor',
        ],
    ];
}

function cae_cap_editor_normalize_1(mixed $payload, string $capabilityId = 'editor.normalize@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'payload must be an object'];
    }

    $content = (string)($payload['content'] ?? '');
    $normalized = str_replace(["\r\n", "\r"], "\n", $content);
    $normalized = preg_replace("/\n{3,}/", "\n\n", $normalized) ?? $normalized;

    return [
        'ok' => true,
        'data' => [
            'content' => trim($normalized),
            'provider' => 'cms-akira-editor',
        ],
    ];
}

function cae_cap_editor_sanitize_1(mixed $payload, string $capabilityId = 'editor.sanitize@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'payload must be an object'];
    }

    $content = (string)($payload['content'] ?? '');
    $sanitized = preg_replace('~<script\b[^>]*>.*?</script>~isu', '', $content) ?? $content;
    $sanitized = preg_replace('~<style\b[^>]*>.*?</style>~isu', '', $sanitized) ?? $sanitized;

    return [
        'ok' => true,
        'data' => [
            'content' => $sanitized,
            'provider' => 'cms-akira-editor',
        ],
    ];
}

function cae_cap_editor_validate_1(mixed $payload, string $capabilityId = 'editor.validate@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'payload must be an object'];
    }

    $content = (string)($payload['content'] ?? '');
    if (trim($content) === '') {
        return ['ok' => false, 'error' => 'content is required'];
    }

    if (mb_strlen($content) > 50000) {
        return ['ok' => false, 'error' => 'content exceeds max length'];
    }

    return [
        'ok' => true,
        'data' => [
            'valid' => true,
            'provider' => 'cms-akira-editor',
        ],
    ];
}

function cae_cap_editor_assets_1(mixed $payload, string $capabilityId = 'editor.assets@1', string $caller = 'unknown'): array
{
    return [
        'ok' => true,
        'data' => [
            'js' => [],
            'css' => [],
            'provider' => 'cms-akira-editor',
        ],
    ];
}

// ── Event Listeners ──────────────────────────────────────────────
// Register inter-module event listeners here. Examples:
//
// app()->events()->listen('order.placed', function (array $payload, string $event) {
//     // React to events from other modules
// }, 10, 'cms-akira-editor');
