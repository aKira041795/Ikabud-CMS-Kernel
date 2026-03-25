<?php

declare(strict_types=1);

function cmsNormalizePublishAt(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $value = str_replace('T', ' ', $value);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value) === 1) {
        $value .= ':00';
    }

    $ts = strtotime($value);
    if ($ts === false) {
        return null;
    }

    return date('Y-m-d H:i:s', $ts);
}

/**
 * Process post excerpts for public display.
 * - If no excerpt exists, falls back to body text (HTML stripped).
 * - Strips HTML tags and truncates to the configured excerpt_length setting.
 */
function cmsProcessPostExcerpts(array $posts, ?int $maxLen = null): array
{
    if ($maxLen === null) {
        $settings = readCmsSettings();
        $maxLen = max(20, (int)($settings['excerpt_length'] ?? 160));
    }

    foreach ($posts as &$post) {
        $text = '';
        if (!empty($post['excerpt'])) {
            $text = strip_tags((string)$post['excerpt']);
        } elseif (!empty($post['body'])) {
            $text = strip_tags((string)$post['body']);
        }

        // Normalise whitespace
        $text = trim(preg_replace('/\s+/', ' ', $text));

        if (mb_strlen($text) > $maxLen) {
            // Break on word boundary
            $text = mb_substr($text, 0, $maxLen);
            $lastSpace = mb_strrpos($text, ' ');
            if ($lastSpace !== false && $lastSpace > $maxLen * 0.6) {
                $text = mb_substr($text, 0, $lastSpace);
            }
            $text .= '…';
        }

        $post['excerpt'] = $text;
    }
    unset($post);

    return $posts;
}

/**
 * Build the SQL predicate for publicly visible content.
 */

function cmsPublicVisibilitySql(string $alias = 'c'): string
{
    $alias = trim($alias) !== '' ? trim($alias) : 'c';
    return "(({$alias}.status = 'published') OR ({$alias}.status = 'scheduled' AND {$alias}.published_at IS NOT NULL AND {$alias}.published_at <= NOW()))";
}

/**
 * Generate a URL-friendly slug from a string.
 */

function cmsSlugify(string $text): string
{
    $slug = strtolower(trim($text));
    $slug = preg_replace('/[^a-z0-9\s\-]/', '', $slug);
    $slug = preg_replace('/[\s\-]+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : ('item-' . substr(uniqid(), -6));
}

/**
 * Generate a UUID v4.
 */

function cmsUuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Build a tenant-aware absolute base URL for outbound links.
 * Prefers request host/scheme, then falls back to app.url.
 */
function cmsExternalBaseUrl(): string
{
    $appUrl = trim((string) config('app.url', ''));
    $fallback = rtrim($appUrl, '/');

    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return $fallback;
    }

    $forwardedProto = trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($forwardedProto !== '') {
        $parts = explode(',', $forwardedProto);
        $scheme = strtolower(trim((string) ($parts[0] ?? 'http')));
    } else {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        $scheme = ($https !== '' && $https !== 'off') ? 'https' : 'http';
    }

    $basePath = rtrim((string) parse_url($appUrl, PHP_URL_PATH), '/');
    return rtrim($scheme . '://' . $host . $basePath, '/');
}

/**
 * Get the CMS uploads directory path.
 */

function cmsUploadsPath(): string
{
    $basePath = BASE_PATH . '/modules/cms/assets/uploads';
    $tid = app()->tenant()->current();
    if ($tid !== null) {
        $basePath .= '/t' . $tid;
    }
    return $basePath;
}

/**
 * Get the public URL for a CMS upload.
 */

function cmsUploadsUrl(string $relativePath): string
{
    $prefix = '/assets/modules/cms/uploads';
    $tid = app()->tenant()->current();
    if ($tid !== null) {
        $prefix .= '/t' . $tid;
    }
    return $prefix . '/' . ltrim($relativePath, '/');
}

function cmsLegacyUploadsPath(): string
{
    return BASE_PATH . '/modules/cms/assets/uploads';
}

function cmsLegacyUploadsUrl(string $relativePath): string
{
    return '/assets/modules/cms/uploads/' . ltrim($relativePath, '/');
}

/**
 * Resolve the most accurate filesystem path for an uploaded file.
 *
 * Files may exist in either the current tenant-scoped storage or the legacy
 * shared uploads directory, depending on when they were created.
 */
function cmsResolveUploadAbsolutePath(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    if ($relativePath === '') {
        return cmsUploadsPath();
    }

    $tenantPath = cmsUploadsPath() . '/' . $relativePath;
    if (is_file($tenantPath)) {
        return $tenantPath;
    }

    $legacyPath = cmsLegacyUploadsPath() . '/' . $relativePath;
    if (is_file($legacyPath)) {
        return $legacyPath;
    }

    return $tenantPath;
}

/**
 * Resolve the most accurate public URL for an uploaded file.
 *
 * Prefer the tenant-scoped asset URL when the file exists there; otherwise
 * fall back to the legacy shared upload URL so older media remains reachable.
 */
function cmsResolveUploadUrl(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    if ($relativePath === '') {
        return cmsUploadsUrl('');
    }

    $tenantPath = cmsUploadsPath() . '/' . $relativePath;
    if (is_file($tenantPath)) {
        return cmsUploadsUrl($relativePath);
    }

    $legacyPath = cmsLegacyUploadsPath() . '/' . $relativePath;
    if (is_file($legacyPath)) {
        return cmsLegacyUploadsUrl($relativePath);
    }

    return cmsUploadsUrl($relativePath);
}

/**
 * Generate thumbnail(s) for an uploaded image using GD.
 * Returns array of generated thumbnail relative paths, keyed by size name.
 */

// ── Content stats helpers ────────────────────────────────────────────────

/**
 * Count words in mixed content (HTML body + optional blocks JSON).
 */
function cmsCalculateWordCount(?string $html, ?string $blocksJson): int
{
    $text = '';
    if ($html !== null && $html !== '') {
        $text .= ' ' . strip_tags($html);
    }
    if ($blocksJson !== null && $blocksJson !== '') {
        // Extract plain text values from the blocks JSON tree
        $decoded = json_decode($blocksJson, true);
        if (is_array($decoded)) {
            array_walk_recursive($decoded, function ($val) use (&$text) {
                if (is_string($val) && strlen($val) < 10000) {
                    $text .= ' ' . strip_tags($val);
                }
            });
        }
    }
    $text = trim($text);
    if ($text === '') {
        return 0;
    }
    return str_word_count($text);
}

/**
 * Calculate estimated reading time in minutes.
 * Falls back to CMS setting `reading_time_wpm` (default 200).
 */
function cmsCalculateReadingTime(int $wordCount): int
{
    $wpm = (int)(readCmsSettings()['reading_time_wpm'] ?? 200);
    $wpm = max(50, $wpm);
    return max(1, (int)ceil($wordCount / $wpm));
}

// ── Builder-type helpers ───────────────────────────────────────────────

/**
 * Return true if the given content type is builder-enabled according to settings.
 */
function cmsBuilderSupportedForType(string $type): bool
{
    $setting = trim((string)(readCmsSettings()['builder_enabled_types'] ?? 'page'));
    $types   = array_filter(array_map('trim', explode(',', $setting)));
    return in_array($type, $types, true);
}

/**
 * Return true if a builder-built content item is locked for classic editing.
 * Combines the `page_builder_enabled` meta flag with the global `builder_enforce_lock` setting.
 */
function cmsBuilderIsLocked(array $meta): bool
{
    if (!cmsPageBuilderEnabled($meta)) {
        return false;
    }
    $enforce = (string)(readCmsSettings()['builder_enforce_lock'] ?? '1');
    return ($enforce === '1');
}

/**
 * Return the public permalink for a content record.
 */
function cmsContentPermalink(array $content): string
{
    $base = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
    $slug = (string)($content['slug'] ?? '');
    $type = (string)($content['type'] ?? 'post');
    if ($type === 'page') {
        return $base . '/cms/page/' . $slug;
    }
    if ($type === 'post') {
        return $base . '/cms/blog/' . $slug;
    }
    return $base . '/cms/' . $type . '/' . $slug;
}

// ── Media-usage helpers (requires DB) ────────────────────────────────────

/**
 * Sync media-usage records for a content item after save.
 * Scans `featured_image_id` and `body` + `blocks_json` for embedded media references.
 */
function cmsSyncMediaUsage(int $contentId, array $contentRow, ?string $blocksJson): void
{
    $settings = readCmsSettings();
    if (($settings['media_usage_tracking'] ?? '1') !== '1') {
        return;
    }

    $db = cmsDb();

    // Delete old usage records for this content
    try {
        $db->prepare("DELETE FROM cms_media_usage WHERE content_id = :cid")->execute([':cid' => $contentId]);
    } catch (\Throwable) {
        return;
    }

    $usages = []; // [media_id => usage_type]

    // Featured image
    $fid = (int)($contentRow['featured_image_id'] ?? 0);
    if ($fid > 0) {
        $usages[$fid] = 'featured_image';
    }

    // Blocks JSON — look for media_id, image_id, or src referencing uploads
    if ($blocksJson !== null && $blocksJson !== '') {
        $decoded = json_decode($blocksJson, true);
        if (is_array($decoded)) {
            array_walk_recursive($decoded, function ($val, $key) use (&$usages) {
                if (in_array($key, ['media_id', 'image_id'], true) && is_int($val) && $val > 0) {
                    $usages[$val] = $usages[$val] ?? 'embedded';
                }
            });
        }
    }

    if (empty($usages)) {
        return;
    }

    $insertStmt = $db->prepare(
        "INSERT IGNORE INTO cms_media_usage (media_id, content_id, usage_type) VALUES (:mid, :cid, :utype)"
    );
    foreach ($usages as $mediaId => $usageType) {
        try {
            $insertStmt->execute([':mid' => $mediaId, ':cid' => $contentId, ':utype' => $usageType]);
        } catch (\Throwable) {}
    }
}
