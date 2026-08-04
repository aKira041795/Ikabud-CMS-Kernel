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

function cms_akira_seo_capability_handlers(): array
{
    return [
        'akira.seo.meta.build@1' => 'cas_cap_akira_seo_meta_build_1',
    ];
}

function cas_cap_akira_seo_meta_build_1(mixed $payload, string $capabilityId = 'akira.seo.meta.build@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'payload must be an object'];
    }

    $title = trim((string)($payload['title'] ?? ''));
    if ($title === '') {
        return ['ok' => false, 'error' => 'title is required'];
    }

    $excerpt = trim((string)($payload['excerpt'] ?? ''));
    $slug = trim((string)($payload['slug'] ?? ''));
    $canonical = trim((string)($payload['canonical_path'] ?? ''));

    $metaTitle = mb_substr($title, 0, 60);
    $sourceText = $excerpt !== '' ? $excerpt : trim(strip_tags((string)($payload['body'] ?? '')));
    $metaDescription = mb_substr($sourceText, 0, 160);

    if ($canonical === '' && $slug !== '') {
        $canonical = '/content/' . $slug;
    }

    return [
        'ok' => true,
        'data' => [
            'meta_title' => $metaTitle,
            'meta_description' => $metaDescription,
            'canonical_path' => $canonical !== '' ? $canonical : null,
            'provider' => 'cms-akira-seo',
        ],
    ];
}

// ── Event Listeners ──────────────────────────────────────────────
// Register inter-module event listeners here. Examples:
//
// app()->events()->listen('order.placed', function (array $payload, string $event) {
//     // React to events from other modules
// }, 10, 'cms-akira-seo');
