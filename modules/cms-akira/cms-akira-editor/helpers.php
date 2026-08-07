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

    // Canonical render: the CMS stores and renders editor content as HTML.
    // The rendered HTML is the content itself (no artificial wrapper).
    $content = (string)($payload['content'] ?? '');
    return [
        'ok' => true,
        'data' => [
            'html' => $content,
            'provider' => 'cms-akira-editor',
            'resolved_from' => 'cms',
        ],
    ];
}

function cae_cap_editor_normalize_1(mixed $payload, string $capabilityId = 'editor.normalize@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'payload must be an object'];
    }

    $content = (string)($payload['content'] ?? '');
    $context = (string)($payload['context'] ?? 'cms.content');

    // Delegate to the canonical CMS editor contract (modules/cms), which routes
    // through tinymce.html.normalize@1 with a safe fallback.
    if (function_exists('cmsEditorNormalizeHtml')) {
        try {
            $normalized = cmsEditorNormalizeHtml($content, $context);
            return [
                'ok' => true,
                'data' => [
                    'content' => $normalized,
                    'provider' => 'cms-akira-editor',
                    'resolved_from' => 'cms',
                ],
            ];
        } catch (Throwable $e) {
            // fall through to fallback
        }
    }

    $normalized = str_replace(["\r\n", "\r"], "\n", $content);
    $normalized = preg_replace("/\n{3,}/", "\n\n", $normalized) ?? $normalized;

    return [
        'ok' => true,
        'data' => [
            'content' => trim($normalized),
            'provider' => 'cms-akira-editor',
            'resolved_from' => 'fallback',
        ],
    ];
}

function cae_cap_editor_sanitize_1(mixed $payload, string $capabilityId = 'editor.sanitize@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'payload must be an object'];
    }

    $content = (string)($payload['content'] ?? '');
    $context = (string)($payload['context'] ?? 'cms.content');

    // Delegate to the canonical CMS editor contract (modules/cms), which routes
    // through tinymce.html.sanitize@1 with a safe tag-allowlist fallback.
    if (function_exists('cmsEditorSanitizeHtml')) {
        try {
            $sanitized = cmsEditorSanitizeHtml($content, $context);
            return [
                'ok' => true,
                'data' => [
                    'content' => $sanitized,
                    'provider' => 'cms-akira-editor',
                    'resolved_from' => 'cms',
                ],
            ];
        } catch (Throwable $e) {
            // fall through to fallback
        }
    }

    $sanitized = preg_replace('~<script\b[^>]*>.*?</script>~isu', '', $content) ?? $content;
    $sanitized = preg_replace('~<style\b[^>]*>.*?</style>~isu', '', $sanitized) ?? $sanitized;

    return [
        'ok' => true,
        'data' => [
            'content' => $sanitized,
            'provider' => 'cms-akira-editor',
            'resolved_from' => 'fallback',
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
            'resolved_from' => 'cms',
        ],
    ];
}

function cae_cap_editor_assets_1(mixed $payload, string $capabilityId = 'editor.assets@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'payload must be an object'];
    }

    $context = (string)($payload['context'] ?? 'cms.content');
    $profile = (string)($payload['profile'] ?? 'default');

    // Delegate to the canonical editor asset resolver (modules/cms), which
    // routes through tinymce.assets.get@1 — the real TinyMCE build.
    if (function_exists('cmsTinyMceAssets')) {
        try {
            $assets = cmsTinyMceAssets($context, $profile);
            if (is_array($assets)) {
                $assets['js'] = $assets['js_urls'] ?? [];
                $assets['css'] = $assets['css_urls'] ?? [];
                $assets['provider'] = 'cms-akira-editor';
                $assets['resolved_from'] = 'cms';
                return ['ok' => true, 'data' => $assets];
            }
        } catch (Throwable $e) {
            // fall through to fallback
        }
    }

    return [
        'ok' => true,
        'data' => [
            'version' => null,
            'js' => [],
            'css' => [],
            'js_urls' => [],
            'css_urls' => [],
            'provider' => 'cms-akira-editor',
            'resolved_from' => 'fallback',
        ],
    ];
}

// ── Event Listeners ──────────────────────────────────────────────
// Register inter-module event listeners here. Examples:
//
// app()->events()->listen('order.placed', function (array $payload, string $event) {
//     // React to events from other modules
// }, 10, 'cms-akira-editor');
