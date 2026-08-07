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
    $rawUrl = trim((string)($payload['featured_image_url'] ?? $payload['url'] ?? ''));
    $featuredPath = trim((string)($payload['featured_image'] ?? ''));
    $alt = trim((string)($payload['featured_image_alt'] ?? $payload['alt'] ?? ''));
    $url = $rawUrl;
    $resolvedFrom = 'fallback';

    // Delegate to the canonical CMS media authority (modules/cms): resolve a
    // stored media row to its public URL + alt, or resolve a featured_image path.
    if (function_exists('cmsResolveUploadUrl')) {
        try {
            if ($featuredPath !== '') {
                $url = cmsResolveUploadUrl($featuredPath);
                $resolvedFrom = 'cms';
            } elseif ($mediaId !== null && $mediaId > 0) {
                $row = null;
                if (function_exists('cmsDb')) {
                    try {
                        $stmt = cmsDb()->prepare('SELECT file_path, alt_text FROM cms_media WHERE id = ? LIMIT 1');
                        $stmt->execute([$mediaId]);
                        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                    } catch (Throwable $e) {
                    }
                }
                if (is_array($row)) {
                    $filePath = trim((string)($row['file_path'] ?? ''));
                    if ($filePath !== '') {
                        $url = cmsResolveUploadUrl($filePath);
                    }
                    if ($alt === '') {
                        $alt = trim((string)($row['alt_text'] ?? ''));
                    }
                    $resolvedFrom = 'cms';
                }
            }
        } catch (Throwable $e) {
            // fall through to fallback
        }
    }

    return [
        'ok' => true,
        'data' => [
            'media_id' => $mediaId,
            'url' => $url,
            'alt' => $alt,
            'provider' => 'cms-akira-media',
            'resolved_from' => $resolvedFrom,
        ],
    ];
}

// ── Event Listeners ──────────────────────────────────────────────
// Register inter-module event listeners here. Examples:
//
// app()->events()->listen('order.placed', function (array $payload, string $event) {
//     // React to events from other modules
// }, 10, 'cms-akira-media');
