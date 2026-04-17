<?php

declare(strict_types=1);

function cmsGenerateThumbnails(string $absolutePath, string $relativeDir, string $filenameBase, string $ext): array
{
    if (!extension_loaded('gd')) return [];

    $sizes = [
        'thumb'  => ['w' => 150, 'h' => 150, 'crop' => true],
        'medium' => ['w' => 300, 'h' => 300, 'crop' => false],
        'large'  => ['w' => 1024, 'h' => 1024, 'crop' => false],
    ];

    $info = @getimagesize($absolutePath);
    if (!$info) return [];

    $origW = (int)$info[0];
    $origH = (int)$info[1];
    $mime = $info['mime'] ?? '';
    if ($origW <= 0 || $origH <= 0) return [];

    $src = null;
    switch ($mime) {
        case 'image/jpeg': $src = @imagecreatefromjpeg($absolutePath); break;
        case 'image/png':  $src = @imagecreatefrompng($absolutePath); break;
        case 'image/gif':  $src = @imagecreatefromgif($absolutePath); break;
        case 'image/webp': $src = @imagecreatefromwebp($absolutePath); break;
    }
    if (!$src) return [];

    $uploadDir = dirname($absolutePath);
    $generated = [];

    foreach ($sizes as $name => $spec) {
        $maxW = $spec['w'];
        $maxH = $spec['h'];
        $crop = $spec['crop'];

        // Skip if image is already smaller than this size
        if ($origW <= $maxW && $origH <= $maxH) continue;

        if ($crop) {
            // Center crop to exact dimensions
            $ratio = max($maxW / $origW, $maxH / $origH);
            $srcW = (int)ceil($maxW / $ratio);
            $srcH = (int)ceil($maxH / $ratio);
            $srcX = (int)(($origW - $srcW) / 2);
            $srcY = (int)(($origH - $srcH) / 2);
            $dst = imagecreatetruecolor($maxW, $maxH);
        } else {
            // Fit within dimensions preserving aspect ratio
            $ratio = min($maxW / $origW, $maxH / $origH);
            $newW = (int)round($origW * $ratio);
            $newH = (int)round($origH * $ratio);
            $srcX = 0; $srcY = 0; $srcW = $origW; $srcH = $origH;
            $dst = imagecreatetruecolor($newW, $newH);
            $maxW = $newW; $maxH = $newH;
        }

        // Preserve transparency for PNG/GIF/WebP
        if (in_array($mime, ['image/png', 'image/gif', 'image/webp'], true)) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, $maxW - 1, $maxH - 1, $transparent);
        }

        imagecopyresampled($dst, $src, 0, 0, $srcX, $srcY, $maxW, $maxH, $srcW, $srcH);

        $thumbFilename = $filenameBase . '-' . $name . '.' . $ext;
        $thumbPath = $uploadDir . '/' . $thumbFilename;

        switch ($mime) {
            case 'image/jpeg': imagejpeg($dst, $thumbPath, 82); break;
            case 'image/png':  imagepng($dst, $thumbPath, 6); break;
            case 'image/gif':  imagegif($dst, $thumbPath); break;
            case 'image/webp': imagewebp($dst, $thumbPath, 82); break;
        }

        imagedestroy($dst);
        $generated[$name] = $relativeDir . '/' . $thumbFilename;
    }

    imagedestroy($src);
    return $generated;
}

function cmsResetSettingsCache(): void
{
    $tid = cmsRuntimeTenantId();
    $GLOBALS['cms_settings_cached_t' . $tid] = false;
    $GLOBALS['cms_settings_value_t' . $tid] = null;
    if (function_exists('cmsClearPersistentSettingsCache')) {
        cmsClearPersistentSettingsCache();
    }
    if (function_exists('cmsResetCacheRuntimeState')) {
        cmsResetCacheRuntimeState();
    }
}

/**
 * Validate and normalize a CMS settings payload before saving.
 * Returns the cleaned settings array.
 */

function cmsNormalizeBlocksJson(mixed $blocks): ?string
{
    if ($blocks === null || $blocks === '') {
        return null;
    }
    if (is_string($blocks)) {
        $decoded = json_decode($blocks, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }
        return json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    if (is_array($blocks)) {
        return json_encode($blocks, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    return null;
}

function cmsSendCacheHeaders(string $etag, string $lastModified): bool
{
    $etag = '"' . $etag . '"';
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', strtotime($lastModified)) . ' GMT');
    // Use no-cache so the browser always revalidates with the server via ETag/If-None-Match.
    // max-age=N suppresses requests entirely for N seconds, meaning browser-side cached HTML
    // persists even after a server-side cache flush (e.g. customizer save, settings change,
    // content publish). With no-cache the server returns 304 instantly when nothing changed
    // (no body retransmitted), so performance is equivalent while content is always fresh.
    header('Cache-Control: public, no-cache');

    // Check If-None-Match
    $clientEtag = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    if ($clientEtag === $etag) {
        http_response_code(304);
        return true;
    }

    // Check If-Modified-Since
    $clientMod = (string)($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '');
    if ($clientMod !== '' && strtotime($clientMod) >= strtotime($lastModified)) {
        http_response_code(304);
        return true;
    }

    return false;
}

// ── CMS Cache Invalidation via EventBus ─────────────────────────────
// Listen for content change events and flush related cache entries.

if (class_exists('Ikabud\\Kernel\\EventBus')) {
    $bus = \Ikabud\Kernel\EventBus::getInstance();

    // Content created
    $bus->listen('cms.content.created', function (array $payload) {
        $type = (string)($payload['type'] ?? 'post');
        $tags = ['cms:type:' . $type];
        if ($type === 'post') {
            $tags[] = 'cms:home';
            $tags[] = 'cms:api:posts';
        } elseif ($type === 'page') {
            $tags[] = 'cms:api:pages';
        }
        cmsCacheInvalidateByTags($tags);
        if (function_exists('pageCacheInvalidateModule')) {
            pageCacheInvalidateModule('cms');
        }
    }, 10, 'cms');

    // Content published
    $bus->listen('cms.content.published', function (array $payload) {
        $tags = ['cms:home', 'cms:api:posts'];
        $slug = (string)($payload['slug'] ?? '');
        if ($slug) $tags[] = 'cms:post:' . $slug;
        $id = (int)($payload['content_id'] ?? 0);
        if ($id > 0) $tags[] = 'cms:content:' . $id;
        cmsCacheInvalidateByTags($tags);
        if (function_exists('pageCacheInvalidateModule')) {
            pageCacheInvalidateModule('cms');
        }
    }, 10, 'cms');

    // Content updated
    $bus->listen('cms.content.updated', function (array $payload) {
        $id   = (int)($payload['content_id'] ?? 0);
        $slug = (string)($payload['slug'] ?? '');
        $type = (string)($payload['type'] ?? 'post');
        $tags = ['cms:type:' . $type];
        if ($id > 0)   $tags[] = 'cms:content:' . $id;
        if ($slug)     $tags[] = 'cms:' . $type . ':' . $slug;
        if ($type === 'post') {
            $tags[] = 'cms:home';
            $tags[] = 'cms:api:posts';
        } elseif ($type === 'page') {
            $tags[] = 'cms:api:pages';
        }
        cmsCacheInvalidateByTags($tags);
        if (function_exists('pageCacheInvalidateModule')) {
            pageCacheInvalidateModule('cms');
        }
    }, 10, 'cms');

    // Content deleted
    $bus->listen('cms.content.deleted', function (array $payload) {
        $id   = (int)($payload['content_id'] ?? 0);
        $slug = (string)($payload['slug'] ?? '');
        $type = (string)($payload['type'] ?? 'post');
        $tags = ['cms:type:' . $type, 'cms:home', 'cms:api:posts', 'cms:api:pages'];
        if ($id > 0) $tags[] = 'cms:content:' . $id;
        if ($slug)   $tags[] = 'cms:' . $type . ':' . $slug;
        cmsCacheInvalidateByTags($tags);
        if (function_exists('pageCacheInvalidateModule')) {
            pageCacheInvalidateModule('cms');
        }
    }, 10, 'cms');

    // Settings changed — flush everything
    $bus->listen('cms.settings.updated', function () {
        cmsCacheFlushAll();
        if (function_exists('pageCacheInvalidateModule')) {
            pageCacheInvalidateModule('cms');
        }
    }, 10, 'cms');

    $bus->listen('workflow.transitioned', function (array $payload) {
        $workflowKey = (string)($payload['workflow_key'] ?? '');
        $toState = (string)($payload['to_state'] ?? '');
        $entityType = (string)($payload['entity_type'] ?? '');
        $contentId = (int)($payload['entity_id'] ?? 0);

        if ($workflowKey !== 'cms.content' || $entityType !== 'cms_content' || $toState !== 'review' || $contentId <= 0) {
            return;
        }

        // AI automation sends its own email after the transition to avoid blocking the capability timeout
        $meta = $payload['meta'] ?? [];
        if (is_array($meta) && ($meta['source'] ?? '') === 'ai_automation') {
            return;
        }

        if (!function_exists('cmsAiAutomationSendApprovalNotification')) {
            return;
        }

        try {
            cmsAiAutomationSendApprovalNotification($contentId);
        } catch (\Throwable $e) {
            write_log('cms ai automation approval email failed: ' . $e->getMessage(), 'error', ['content_id' => $contentId]);
        }
    }, 20, 'cms');
}

// ── CMS Categories ─────────────────────────────────────────────────

/**
 * List all categories, optionally flat or as a tree.
 */
