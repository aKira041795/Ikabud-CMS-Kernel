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

    $content = [
        'title' => $title,
        'slug' => (string)($payload['slug'] ?? ''),
        'excerpt' => (string)($payload['excerpt'] ?? ''),
        'body' => (string)($payload['body'] ?? ''),
        'type' => (string)($payload['type'] ?? 'post'),
        'meta' => is_array($payload['meta'] ?? null) ? $payload['meta'] : [],
        'featured_image' => (string)($payload['featured_image'] ?? ''),
    ];

    // Delegate to the canonical CMS SEO authority (modules/cms) via the
    // cms.seo.resolve@1 capability contract. Capability delegation is the ONLY
    // cross-module path — no named foreign-helper calls (cmsResolveSeoTitle,
    // cmsDefaultSeoHeadHtml, cmsStructuredDataJsonLd, readCmsSettings, cmsSeoStrip).
    try {
        $cmsResult = app()->cap()->call('cms.seo.resolve@1', ['content' => $content]);
        if (is_array($cmsResult) && ($cmsResult['ok'] ?? false) === true && is_array($cmsResult['data'] ?? null)) {
            $resolved = $cmsResult['data'];
            $seoTitle = (string)($resolved['title'] ?? '');
            $headHtml = (string)($resolved['head_html'] ?? '');
            $structured = (string)($resolved['json_ld'] ?? '');

            $metaDesc = trim((string)($content['meta']['seo_description'] ?? ''));
            if ($metaDesc === '') {
                $metaDesc = trim((string)$content['excerpt']);
            }
            if ($metaDesc === '') {
                $metaDesc = trim(strip_tags($content['body']));
            }
            $metaDesc = mb_substr($metaDesc, 0, 160);

            $canonical = trim((string)($payload['canonical_path'] ?? ''));
            $slug = trim((string)$content['slug']);
            if ($canonical === '' && $slug !== '') {
                if ($content['type'] === 'post') {
                    $canonical = '/cms/blog/' . $slug;
                } elseif ($content['type'] === 'page') {
                    $canonical = '/cms/page/' . $slug;
                } elseif ($content['type'] === 'product') {
                    $canonical = '/ecommerce/shop/' . $slug;
                } else {
                    $canonical = '/content/' . $slug;
                }
            }

            return [
                'ok' => true,
                'data' => [
                    'meta_title' => $seoTitle,
                    'meta_description' => $metaDesc,
                    'canonical_path' => $canonical !== '' ? $canonical : null,
                    'head_html' => $headHtml,
                    'structured_data' => $structured,
                    'provider' => 'cms-akira-seo',
                    'resolved_from' => 'cms',
                ],
            ];
        }
    } catch (Throwable $e) {
        // fall through to fallback
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
            'resolved_from' => 'fallback',
        ],
    ];
}

// ── Event Listeners ──────────────────────────────────────────────
// Register inter-module event listeners here. Examples:
//
// app()->events()->listen('order.placed', function (array $payload, string $event) {
//     // React to events from other modules
// }, 10, 'cms-akira-seo');
