<?php

declare(strict_types=1);

function cmsThemesPath(): string
{
    return rtrim((string)(defined('STORAGE_PATH') ? STORAGE_PATH : BASE_PATH . '/storage'), '/') . '/cms-themes';
}

if (!defined('CMS_THEME_SYMLINK')) {
    define('CMS_THEME_SYMLINK', rtrim((string)(defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2)), '/') . '/templates/_cms_active_theme');
}

/**
 * Default CMS settings — used when no settings have been saved yet.
 * Mirrors the gui-settings pattern: every key has an explicit default.
 */

function cmsSettingsDefaults(): array
{
    static $defaults = null;
    if ($defaults !== null) {
        return $defaults;
    }

    $defaults = [];
    $manifest = discoverModules()['cms'] ?? [];
    $fields = is_array($manifest['settings_fields'] ?? null) ? $manifest['settings_fields'] : [];

    foreach ($fields as $field) {
        if (!is_array($field)) {
            continue;
        }

        $key = trim((string)($field['key'] ?? ''));
        if ($key === '' || !array_key_exists('default', $field)) {
            continue;
        }

        $defaults[$key] = (string)$field['default'];
    }

    return $defaults;
}

/**
 * Read current CMS settings merged with defaults.
 * Ensures every known key is always present.
 * Result is cached in-process to avoid repeated file reads.
 */

function cmsSettingsPersistentCacheTtl(): int
{
    return max(0, (int)($_ENV['CMS_SETTINGS_CACHE_TTL'] ?? 30));
}

function cmsSettingsPersistentCacheInstance(): string
{
    $tid = cmsRuntimeTenantId();
    return 'cms_settings_t' . $tid;
}

function cmsSettingsPersistentCacheKey(): string
{
    return 'cms:settings:merged:v1';
}

function cmsClearPersistentSettingsCache(): void
{
    if (cmsSettingsPersistentCacheTtl() <= 0) {
        return;
    }

    $instance = cmsSettingsPersistentCacheInstance();
    $key = cmsSettingsPersistentCacheKey();
    app()->cache()->clearByTags($instance, ['cms:settings']);
    app()->cache()->clearByTags($instance, ['cms:settings:tenant']);
    // Best-effort direct key clear via pattern fallback is intentionally avoided;
    // tag invalidation handles all setting snapshots for the tenant instance.
}

function readCmsSettings(): array
{
    // In-process cache keyed by tenant so different tenants in the same
    // process don't share each other's CMS configuration.
    $tid = cmsRuntimeTenantId();
    $cacheKey = 'cms_settings_cached_t' . $tid;
    $valueKey = 'cms_settings_value_t' . $tid;
    if (!empty($GLOBALS[$cacheKey])) {
        return $GLOBALS[$valueKey];
    }

    $defaults = cmsSettingsDefaults();

    $persistentTtl = cmsSettingsPersistentCacheTtl();
    if ($persistentTtl > 0) {
        $cached = app()->cache()->get(cmsSettingsPersistentCacheInstance(), cmsSettingsPersistentCacheKey());
        if (is_array($cached)) {
            $result = array_merge($defaults, $cached);
            $GLOBALS[$cacheKey] = true;
            $GLOBALS[$valueKey] = $result;
            return $result;
        }
    }

    $saved = getModuleSettings('cms');
    if (!is_array($saved) || empty($saved)) {
        $result = $defaults;
    } else {
        $result = array_merge($defaults, $saved);
    }

    if ($persistentTtl > 0) {
        app()->cache()->setWithTags(
            cmsSettingsPersistentCacheInstance(),
            cmsSettingsPersistentCacheKey(),
            $result,
            ['cms:settings', 'cms:settings:tenant'],
            $persistentTtl
        );
    }

    $GLOBALS[$cacheKey] = true;
    $GLOBALS[$valueKey] = $result;
    return $result;
}

/**
 * Clear the in-process CMS settings cache.
 * Call after saving settings so subsequent reads pick up changes.
 */

function cmsValidateSettings(array $input): array
{
    $defaults = cmsSettingsDefaults();
    $allowed = array_keys($defaults);
    $clean = [];

    foreach ($allowed as $key) {
        if (!array_key_exists($key, $input)) {
            continue;
        }
        $clean[$key] = trim((string)$input[$key]);
    }

    // Numeric fields — clamp to sensible ranges
    if (isset($clean['posts_per_page'])) {
        $clean['posts_per_page'] = (string)max(1, min(100, (int)$clean['posts_per_page']));
    }
    if (isset($clean['excerpt_length'])) {
        $clean['excerpt_length'] = (string)max(20, min(500, (int)$clean['excerpt_length']));
    }
    if (isset($clean['max_upload_mb'])) {
        $clean['max_upload_mb'] = (string)max(1, min(64, (int)$clean['max_upload_mb']));
    }
    if (isset($clean['cache_ttl'])) {
        $clean['cache_ttl'] = (string)max(0, min(86400, (int)$clean['cache_ttl']));
    }

    // Boolean-ish fields
    foreach (['comments_enabled', 'cache_enabled', 'builder_enforce_lock', 'media_alt_required', 'reading_time_enabled', 'media_usage_tracking'] as $boolKey) {
        if (isset($clean[$boolKey])) {
            $clean[$boolKey] = in_array($clean[$boolKey], ['1', 'true', 'yes', 'on'], true) ? '1' : '0';
        }
    }

    // Numeric fields for new settings
    if (isset($clean['reading_time_wpm'])) {
        $clean['reading_time_wpm'] = (string)max(50, min(1000, (int)$clean['reading_time_wpm']));
    }

    // Enum fields
    if (isset($clean['default_post_status']) && !in_array($clean['default_post_status'], ['draft', 'published'], true)) {
        $clean['default_post_status'] = 'draft';
    }
    if (isset($clean['homepage_type']) && !in_array($clean['homepage_type'], ['posts', 'page'], true)) {
        $clean['homepage_type'] = 'posts';
    }
    if (isset($clean['default_comment_status']) && !in_array($clean['default_comment_status'], ['open', 'closed'], true)) {
        $clean['default_comment_status'] = 'open';
    }

    // builder_enabled_types: allow only known content type slugs (alphanumeric/dash/comma)
    if (isset($clean['builder_enabled_types'])) {
        $types = array_filter(array_map('trim', explode(',', $clean['builder_enabled_types'])));
        $types = array_filter($types, fn($t) => (bool)preg_match('/^[a-z0-9_\-]+$/i', $t));
        $clean['builder_enabled_types'] = implode(',', $types);
    }

    // enabled_post_formats: allow only known formats
    $knownFormats = ['standard', 'aside', 'gallery', 'link', 'image', 'quote', 'status', 'video', 'audio', 'chat'];
    if (isset($clean['enabled_post_formats'])) {
        $fmts = array_filter(array_map('trim', explode(',', $clean['enabled_post_formats'])));
        $fmts = array_filter($fmts, fn($f) => in_array($f, $knownFormats, true));
        $clean['enabled_post_formats'] = implode(',', $fmts);
    }

    // media_extra_allowed_exts: alphanumeric only
    if (isset($clean['media_extra_allowed_exts'])) {
        $exts = array_filter(array_map('trim', explode(',', $clean['media_extra_allowed_exts'])));
        $exts = array_filter($exts, fn($e) => (bool)preg_match('/^[a-z0-9]+$/', $e));
        $clean['media_extra_allowed_exts'] = implode(',', $exts);
    }
    if (isset($clean['seo_robots'])) {
        $validRobots = ['index, follow', 'noindex, follow', 'index, nofollow', 'noindex, nofollow'];
        if (!in_array($clean['seo_robots'], $validRobots, true)) {
            $clean['seo_robots'] = 'index, follow';
        }
    }

    // Sanitize URL-like fields
    foreach (['social_facebook', 'social_twitter', 'social_instagram', 'social_youtube', 'social_linkedin', 'seo_og_image'] as $urlKey) {
        if (isset($clean[$urlKey]) && $clean[$urlKey] !== '') {
            $clean[$urlKey] = filter_var($clean[$urlKey], FILTER_SANITIZE_URL) ?: '';
        }
    }

    // Title separator — single char or short string
    if (isset($clean['seo_title_separator'])) {
        $clean['seo_title_separator'] = mb_substr($clean['seo_title_separator'], 0, 3);
    }

    return $clean;
}

// ── Theme CSS Structural Validator ──────────────────────────────────────────

/**
 * Validate a theme's CSS against structural property restrictions.
 *
 * Entity block CSS classes (.cms-entity-view, .cms-pricing-block, etc.) must
 * not override structural layout properties (display, flex-direction, grid,
 * order, position). Only color, typography, border-radius, and spacing tokens
 * are allowed.
 *
 * Returns an array of violation descriptions. Empty = valid.
 *
 * @param  string $css  Raw CSS content to validate
 * @return array<int,string>  List of violations
 */
function cmsValidateThemeCss(string $css): array
{
    $violations = [];

    // Protected block selectors
    $protectedSelectors = [
        '.cms-entity-view',
        '.cms-entity-hero',
        '.cms-entity-header',
        '.cms-entity-meta',
        '.cms-entity-body',
        '.cms-pricing-block',
        '.cms-inventory-block',
        '.cms-gallery-block',
        '.cms-lessons-block',
        '.cms-progress-block',
        '.cms-action-block',
        '.cms-btn-primary',
        '.cms-btn-secondary',
    ];

    // Structural properties that themes must not override on protected selectors
    $forbiddenProperties = [
        'display',
        'flex-direction',
        'flex-wrap',
        'grid-template-columns',
        'grid-template-rows',
        'grid-template-areas',
        'order',
        'position',
        'float',
        'clear',
        'overflow',
    ];

    // Strip CSS comments to avoid false positives
    $stripped = preg_replace('/\/\*.*?\*\//s', '', $css) ?? $css;

    // Extract rule blocks: selector { ... }
    if (!preg_match_all('/([^{}]+)\{([^{}]+)\}/s', $stripped, $matches, PREG_SET_ORDER)) {
        return [];
    }

    foreach ($matches as $match) {
        $selector = trim($match[1]);
        $body     = trim($match[2]);

        // Check if this rule targets any protected selector
        $targetedSelectors = [];
        foreach ($protectedSelectors as $protected) {
            if (stripos($selector, $protected) !== false) {
                $targetedSelectors[] = $protected;
            }
        }

        if (empty($targetedSelectors)) {
            continue;
        }

        // Parse properties from the rule body
        $declarations = array_filter(array_map('trim', explode(';', $body)));
        foreach ($declarations as $decl) {
            $parts = explode(':', $decl, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $prop = strtolower(trim($parts[0]));

            foreach ($forbiddenProperties as $forbidden) {
                if ($prop === $forbidden) {
                    $violations[] = sprintf(
                        'Structural override: "%s" sets "%s" on protected selector "%s"',
                        $selector,
                        $forbidden,
                        implode(', ', $targetedSelectors)
                    );
                }
            }
        }
    }

    return $violations;
}

/**
 * Validate the active theme's CSS files for structural violations.
 * Returns violations array (empty = clean).
 */
function cmsValidateActiveThemeCss(): array
{
    $theme = cmsActiveTheme();
    if ($theme === null) {
        return [];
    }

    $violations = [];

    // Check theme storage CSS files
    $themeDir = cmsThemesPath() . '/' . $theme;
    if (is_dir($themeDir)) {
        foreach (glob($themeDir . '/**/*.css') ?: [] as $cssFile) {
            $css = @file_get_contents($cssFile);
            if ($css !== false && $css !== '') {
                $fileViolations = cmsValidateThemeCss($css);
                foreach ($fileViolations as $v) {
                    $violations[] = basename($cssFile) . ': ' . $v;
                }
            }
        }
    }

    // Check public assets CSS
    $basePath  = rtrim((string)(defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2)), '/');
    $publicDir = $basePath . '/public/assets/cms/themes/' . $theme;
    if (is_dir($publicDir)) {
        foreach (glob($publicDir . '/*.css') ?: [] as $cssFile) {
            $css = @file_get_contents($cssFile);
            if ($css !== false && $css !== '') {
                $fileViolations = cmsValidateThemeCss($css);
                foreach ($fileViolations as $v) {
                    $violations[] = basename($cssFile) . ': ' . $v;
                }
            }
        }
    }

    return $violations;
}

/**
 * Reset in-process CMS theme runtime caches.
 */

function cmsResetThemeRuntimeCache(): void
{
    $tid = cmsRuntimeTenantId();
    $GLOBALS['cms_active_theme_cached_t' . $tid] = false;
    $GLOBALS['cms_active_theme_value_t' . $tid] = null;
    $GLOBALS['cms_theme_symlink_checked_t' . $tid] = false;
}

/**
 * Get the active theme slug from CMS settings, or null if using default.
 */

function cmsActiveTheme(): ?string
{
    $tid = cmsRuntimeTenantId();
    $cachedKey = 'cms_active_theme_cached_t' . $tid;
    $valueKey = 'cms_active_theme_value_t' . $tid;
    $cached = (bool)($GLOBALS[$cachedKey] ?? false);
    $theme = $GLOBALS[$valueKey] ?? null;
    if ($cached) {
        return $theme;
    }
    $GLOBALS[$cachedKey] = true;
    $settings = getModuleSettings('cms');
    $slug = trim((string)($settings['active_theme'] ?? ''));
    if ($slug === '' || $slug === 'default') {
        $GLOBALS[$valueKey] = null;
        return null;
    }
    // Validate the theme directory exists
    $dir = cmsThemesPath() . '/' . $slug;
    if (!is_dir($dir)) {
        $GLOBALS[$valueKey] = null;
        return null;
    }
    $GLOBALS[$valueKey] = $slug;
    return $slug;
}

/**
 * Discover available themes from storage/cms-themes/.
 * Returns array of theme metadata from theme.json files.
 */

function cmsAvailableThemes(): array
{
    $dir = cmsThemesPath();
    if (!is_dir($dir)) {
        return [];
    }
    $themes = [];
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $themeDir = $dir . '/' . $entry;
        if (!is_dir($themeDir)) continue;
        $metaFile = $themeDir . '/theme.json';
        $meta = ['slug' => $entry, 'name' => ucfirst($entry), 'version' => '1.0', 'author' => '', 'description' => ''];
        if (is_file($metaFile)) {
            $decoded = kernelReadJsonFile($metaFile);
            if (!empty($decoded)) {
                $meta = array_merge($meta, $decoded);
            }
        }
        $meta['slug'] = $entry;
        // Count override files
        $overrides = 0;
        foreach (['layouts/public.disyl', 'public/home.disyl', 'public/single.disyl', 'public/page.disyl'] as $tpl) {
            if (is_file($themeDir . '/' . $tpl)) {
                $overrides++;
            }
        }
        $meta['override_count'] = $overrides;
        $themes[] = $meta;
    }
    return $themes;
}

/**
 * Path to the lock file guarding theme symlink mutations/renders.
 */
function cmsThemeSymlinkLockPath(): string
{
    $locksDir = rtrim((string)(defined('STORAGE_PATH') ? STORAGE_PATH : BASE_PATH . '/storage'), '/') . '/locks';
    if (!is_dir($locksDir)) {
        @mkdir($locksDir, 0775, true);
    }
    return $locksDir . '/cms-theme-symlink.lock';
}

/**
 * Execute callback while holding an exclusive lock for theme symlink operations.
 */
function cmsWithThemeSymlinkLock(callable $callback, int $lockMode = LOCK_EX): mixed
{
    $lockPath = cmsThemeSymlinkLockPath();
    $handle = @fopen($lockPath, 'c+');
    if (!is_resource($handle)) {
        return $callback();
    }

    $timingEnabled = timing_logs_enabled('CMS_THEME_TIMING_LOGS') || timing_logs_enabled('APP_TIMING_LOGS');
    $waitStart = $timingEnabled ? microtime(true) : 0.0;
    $callbackStart = 0.0;
    $lockAcquired = false;
    $lockWaitMs = null;

    try {
        if (@flock($handle, $lockMode)) {
            $lockAcquired = true;
            if ($timingEnabled) {
                $lockWaitMs = round((microtime(true) - $waitStart) * 1000, 2);
                $callbackStart = microtime(true);
            }

            $result = $callback();

            if ($timingEnabled && $callbackStart > 0.0) {
                $context = [
                    'lock_mode' => $lockMode === LOCK_SH ? 'shared' : 'exclusive',
                    'lock_wait_ms' => $lockWaitMs,
                ];
                log_timing('cms.theme_symlink_lock', $callbackStart, $context, 'CMS_THEME_TIMING_LOGS', 'CMS_THEME_TIMING_THRESHOLD_MS');
            }

            return $result;
        }
        return $callback();
    } finally {
        if ($timingEnabled && !$lockAcquired) {
            log_timing('cms.theme_symlink_lock_unavailable', $waitStart, [
                'lock_mode' => $lockMode === LOCK_SH ? 'shared' : 'exclusive',
            ], 'CMS_THEME_TIMING_LOGS', 'CMS_THEME_TIMING_THRESHOLD_MS');
        }
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }
}

/**
 * Activate a CMS theme by creating a symlink in the templates directory.
 * Pass null or 'default' to deactivate (remove symlink).
 */

function cmsActivateThemeSymlink(?string $slug): void
{
    cmsWithThemeSymlinkLock(function () use ($slug): void {
        cmsResetThemeRuntimeCache();
        $link = (string)CMS_THEME_SYMLINK;
        // Remove existing symlink/file
        if (is_link($link)) {
            kernelDeletePath($link);
        } elseif (is_dir($link)) {
            kernelDeletePath($link);
        }
        if ($slug === null || $slug === '' || $slug === 'default') {
            return;
        }
        $target = cmsThemesPath() . '/' . $slug;
        if (is_dir($target)) {
            @symlink($target, $link);
        }
        kernelFlushCodeCaches();
    }, LOCK_EX);
}

/**
 * Ensure the theme symlink is current on each request (lightweight check).
 */

function cmsEnsureThemeSymlink(): void
{
    $tid = cmsRuntimeTenantId();
    $checkedKey = 'cms_theme_symlink_checked_t' . $tid;
    $done = (bool)($GLOBALS[$checkedKey] ?? false);
    if ($done) return;
    $GLOBALS[$checkedKey] = true;
    $active = cmsActiveTheme();
    $link = (string)CMS_THEME_SYMLINK;
    if ($active === null) {
        // No active theme — remove symlink if it exists
        if (is_link($link)) {
            kernelDeletePath($link);
        }
        return;
    }
    $target = cmsThemesPath() . '/' . $active;
    // Check if symlink already points to the right place
    if (is_link($link) && readlink($link) === $target) {
        return;
    }
    // Recreate
    cmsActivateThemeSymlink($active);
}

/**
 * Resolve a CMS public template path, checking theme override first.
 *
 * Usage: cmsResolveTemplate('public/single.disyl')
 * This checks:
 *   1. _cms_active_theme/{subPath} (symlink to active theme)
 *   2. modules/cms/{subPath} (default)
 *
 * Returns a path relative to templates/ that the kernel TemplateEngine can resolve.
 * Only public templates are themeable — admin templates always use defaults.
 */

function cmsResolveTemplate(string $subPath): string
{
    $default = 'modules/cms/' . $subPath;
    $activeTheme = cmsActiveTheme();
    if ($activeTheme === null) {
        return $default;
    }
    cmsEnsureThemeSymlink();
    // Check if theme override exists via the symlink
    $overridePath = (string)CMS_THEME_SYMLINK . '/' . $subPath;
    if (is_file($overridePath)) {
        return '_cms_active_theme/' . $subPath;
    }
    return $default;
    // Respect restrict_to_tokens: only allow overrides listed in overridable_blocks
    $manifest = cmsActiveThemeManifest();
    if (!empty($manifest['restrict_to_tokens'])) {
        $allowed = array_map('trim', (array)($manifest['overridable_blocks'] ?? []));
        // Normalise subPath to a block-relative name for comparison
        $baseName = basename($subPath);
        if (!in_array($baseName, $allowed, true) && !in_array($subPath, $allowed, true)) {
            return $default;
        }
    }

    return '_cms_active_theme/' . $subPath;
}

/**
 * Read the active theme's theme.json manifest.
 * Returns an empty array when no theme is active or the file is missing.
 * Relevant keys:
 *   restrict_to_tokens   (bool) — when true, theme may NOT override full templates
 *   overridable_blocks   (string[]) — allowlist of block filenames the theme may override
 *   tokens               (array<string,string>) — CSS custom property token overrides
 */
function cmsActiveThemeManifest(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $active = cmsActiveTheme();
    if ($active === null) {
        $cache = [];
        return $cache;
    }

    $manifestFile = cmsThemesPath() . '/' . $active . '/theme.json';
    if (!is_file($manifestFile)) {
        $cache = ['slug' => $active];
        return $cache;
    }

    $decoded = kernelReadJsonFile($manifestFile);
    $cache   = is_array($decoded) ? $decoded : [];
    $cache['slug'] = $active;
    return $cache;
}

/**
 * Generate a <style> block with CSS custom properties from a flat token map.
 * Keys become --{key}, values are CSS values.
 * Escapes values to prevent CSS injection.
 *
 * Usage: echo cmsThemeTokensCss($manifest['tokens'] ?? []);
 */
function cmsThemeTokensCss(array $tokens): string
{
    if (empty($tokens)) {
        return '';
    }
    $props = '';
    foreach ($tokens as $key => $value) {
        // Sanitise: allow only safe CSS value characters
        $safeKey   = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$key);
        $safeValue = str_replace(['<', '>', '"', "'", ';', '{', '}', '\\'], '', (string)$value);
        if ($safeKey === '' || $safeValue === '') {
            continue;
        }
        $props .= "    --{$safeKey}: {$safeValue};\n";
    }
    if ($props === '') {
        return '';
    }
    return "<style>\n:root {\n{$props}}\n</style>\n";
}

/**
 * Resolve a block template path, gating theme overrides by the overridable_blocks
 * allowlist. Falls back to the default CMS block template unconditionally when
 * the theme's restrict_to_tokens flag is set and the block is not in the allowlist.
 *
 * @param  string $block  Relative path under templates/, e.g. "modules/cms/public/blocks/pricing.block.disyl"
 * @return string         Path relative to templates/ for TemplateEngine::render()
 */
function cmsResolveBlockTemplate(string $block): string
{
    $manifest = cmsActiveThemeManifest();

    if (empty($manifest['slug'])) {
        return $block;
    }

    if (!empty($manifest['restrict_to_tokens'])) {
        $allowed  = array_map('trim', (array)($manifest['overridable_blocks'] ?? []));
        $baseName = basename($block);
        if (!in_array($baseName, $allowed, true) && !in_array($block, $allowed, true)) {
            // Theme is token-only; block override not permitted
            return $block;
        }
    }

    cmsEnsureThemeSymlink();
    // Strip a leading "modules/cms/" prefix to find the theme-relative path
    $themeRelative = preg_replace('#^modules/cms/#', '', $block);
    $overridePath  = (string)CMS_THEME_SYMLINK . '/' . $themeRelative;
    if (is_file($overridePath)) {
        return '_cms_active_theme/' . $themeRelative;
    }

    return $block;
}
/**
 * Render a resolved template path that may depend on the active theme symlink.
 */
function cmsRenderThemeAwareTemplate(string $template, array $context = []): string
{
    if (!str_starts_with($template, '_cms_active_theme/')) {
        return cmsRender($template, $context);
    }

    return cmsWithThemeSymlinkLock(function () use ($template, $context): string {
        return cmsRender($template, $context);
    }, LOCK_SH);
}

/**
 * Render a CMS public template with theme override support.
 * Wraps app()->render() with theme-aware template resolution.
 */

function cmsPublicRender(string $subPath, array $context = []): string
{
    $template = cmsResolveTemplate($subPath);
    if (!str_starts_with($template, '_cms_active_theme/')) {
        return cmsRender($template, $context);
    }

    return cmsWithThemeSymlinkLock(function () use ($template, $context): string {
        return cmsRender($template, $context);
    }, LOCK_SH);
}

/**
 * Resolve a public URL for an active theme asset with safe fallback.
 * Falls back to native-default when the active theme asset is missing.
 */
function cmsThemeAssetUrl(string $assetPath, string $baseUrl = ''): string
{
    $assetPath = ltrim(trim($assetPath), '/');
    if ($assetPath === '') {
        return '';
    }

    $basePath = rtrim((string)(defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2)), '/');
    $active = cmsActiveTheme() ?? 'native-default';

    $activePublicFile = $basePath . '/public/assets/cms/themes/' . $active . '/' . $assetPath;
    if (is_file($activePublicFile)) {
        return rtrim($baseUrl, '/') . '/assets/cms/themes/' . rawurlencode($active) . '/' . str_replace('%2F', '/', rawurlencode($assetPath));
    }

    $fallback = 'native-default';
    $fallbackPublicFile = $basePath . '/public/assets/cms/themes/' . $fallback . '/' . $assetPath;
    if (is_file($fallbackPublicFile)) {
        return rtrim($baseUrl, '/') . '/assets/cms/themes/' . rawurlencode($fallback) . '/' . str_replace('%2F', '/', rawurlencode($assetPath));
    }

    return '';
}

function cmsSidebarTemplateKeyFromPath(string $templatePath, string $fallback = 'home'): string
{
    $base = strtolower((string)pathinfo($templatePath, PATHINFO_FILENAME));
    $base = preg_replace('/[^a-z0-9_\-]/', '', $base ?? '') ?: '';
    if ($base === '') {
        $fallback = preg_replace('/[^a-z0-9_\-]/', '', strtolower($fallback)) ?: 'home';
        return $fallback;
    }
    return $base;
}

function cmsSidebarTemplateTargets(): array
{
    $targets = [];

    $activeTheme = cmsActiveTheme();
    if ($activeTheme !== null) {
        $dir = cmsThemesPath() . '/' . $activeTheme . '/public';
    } else {
        $native = cmsThemesPath() . '/native-default/public';
        $dir = is_dir($native) ? $native : ((defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2)) . '/templates/modules/cms/public');
    }

    if (is_dir($dir)) {
        foreach (glob($dir . '/*.disyl') ?: [] as $file) {
            $key = cmsSidebarTemplateKeyFromPath((string)$file, 'home');
            if ($key === 'sidebar') continue;
            $targets[$key] = [
                'key' => $key,
                'label' => ucwords(str_replace(['-', '_'], ' ', $key)) . ' Template',
            ];
        }
    }

    if (empty($targets)) {
        $targets = [
            'home' => ['key' => 'home', 'label' => 'Home Template'],
            'archive' => ['key' => 'archive', 'label' => 'Archive Template'],
            'single' => ['key' => 'single', 'label' => 'Single Template'],
            'page' => ['key' => 'page', 'label' => 'Page Template'],
            'search' => ['key' => 'search', 'label' => 'Search Template'],
        ];
    }

    $preferred = ['home', 'archive', 'single', 'page', 'search'];
    $ordered = [];
    foreach ($preferred as $key) {
        if (isset($targets[$key])) $ordered[] = $targets[$key];
    }
    foreach ($targets as $key => $target) {
        if (!in_array($key, $preferred, true)) $ordered[] = $target;
    }

    return $ordered;
}

function cmsSidebarThemeTemplateExists(): bool
{
    $resolved = cmsResolveTemplate('public/sidebar.disyl');
    $basePath = (string)(defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2));
    $absolute = rtrim($basePath, '/') . '/templates/' . ltrim($resolved, '/');
    return is_file($absolute);
}

/**
 * Build common render context for CMS admin templates.
 * Provides cms_user_display, cms_user_role, current_page for the admin layout.
 */

function cmsAdminContext(array $user, string $currentPage = '', array $breadcrumbs = []): array
{
    $source = (string)($user['source'] ?? '');
    $role   = (string)($user['role'] ?? '');
    $name   = (string)($user['full_name'] ?? $user['username'] ?? $user['name'] ?? 'User');

    if ($source === 'kernel' && $role === 'admin') {
        $displayRole = 'Kernel Admin';
    } else {
        $displayRole = ucfirst($role);
    }

    $baseUrl = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
    $defaultCrumbs = [['label' => 'Dashboard', 'url' => $baseUrl . '/cms/admin']];

    return [
        'cms_user_display' => $name,
        'cms_user_role'    => $displayRole,
        'current_page'     => $currentPage,
        'ext_nav_items'    => cmsGetExtensionNavItems(),
        'breadcrumbs'      => !empty($breadcrumbs) ? array_merge($defaultCrumbs, $breadcrumbs) : $defaultCrumbs,
    ];
}
