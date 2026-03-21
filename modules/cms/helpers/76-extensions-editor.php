<?php

declare(strict_types=1);

function cmsGetContentTemplates(string $contentType = ''): array
{
    $defaults = [
        ['slug' => 'default', 'label' => 'Default', 'types' => ['post', 'page'], 'path' => ''],
    ];

    // Discover templates from active theme
    $activeTheme = cmsActiveTheme();
    if ($activeTheme !== null) {
        $themePath = cmsThemesPath() . '/' . $activeTheme;
        if (is_dir($themePath)) {
            $themeJson = $themePath . '/theme.json';
            if (is_file($themeJson)) {
                $meta = kernelReadJsonFile($themeJson);
                $rawTemplates = [];

                // Canonical schema
                if (!empty($meta['templates']) && is_array($meta['templates'])) {
                    $rawTemplates = $meta['templates'];
                }

                // Backward-compatible schema used by some existing themes
                if (empty($rawTemplates) && !empty($meta['pageTemplates']) && is_array($meta['pageTemplates'])) {
                    foreach ($meta['pageTemplates'] as $tpl) {
                        if (!is_array($tpl) || empty($tpl['slug'])) continue;
                        $slug = (string)$tpl['slug'];
                        $rawTemplates[] = [
                            'slug' => $slug,
                            'label' => (string)($tpl['label'] ?? $tpl['name'] ?? ucfirst(str_replace(['-', '_'], ' ', $slug))),
                            'types' => ['page'],
                            'path' => (string)($tpl['path'] ?? ('public/' . $slug . '.disyl')),
                        ];
                    }
                }

                if (!empty($rawTemplates)) {
                    foreach ($rawTemplates as $tpl) {
                        if (!is_array($tpl) || empty($tpl['slug'])) continue;
                        $label = trim((string)($tpl['label'] ?? $tpl['name'] ?? ''));
                        if ($label === '') {
                            $label = ucfirst(str_replace(['-', '_'], ' ', (string)$tpl['slug']));
                        }
                        $defaults[] = [
                            'slug' => (string)$tpl['slug'],
                            'label' => $label,
                            'types' => is_array($tpl['types'] ?? null) ? $tpl['types'] : ['post', 'page'],
                            'path' => (string)($tpl['path'] ?? ''),
                        ];
                    }
                }
            }
        }
    }

    // De-duplicate by slug while preserving first occurrence
    $seen = [];
    $defaults = array_values(array_filter($defaults, function (array $tpl) use (&$seen): bool {
        $slug = (string)($tpl['slug'] ?? '');
        if ($slug === '' || isset($seen[$slug])) {
            return false;
        }
        $seen[$slug] = true;
        return true;
    }));

    $all = app()->hooks()->filter('cms.content.templates', $defaults, $contentType);
    if (!is_array($all)) {
        $all = $defaults;
    }

    // Filter by content type if specified
    if ($contentType !== '') {
        $all = array_values(array_filter($all, function ($t) use ($contentType) {
            $types = $t['types'] ?? [];
            return empty($types) || in_array($contentType, $types, true);
        }));
    }

    return $all;
}

/**
 * Resolve the public render template path for a content item.
 * Checks content meta _template, resolves to theme/module path.
 *
 * @param string $defaultSubPath e.g. 'public/single.disyl' or 'public/page.disyl'
 * @param array $meta Content meta array
 * @param string $contentType e.g. 'post' or 'page'
 * @return string Template path relative to templates/
 */

function cmsResolveContentTemplate(string $defaultSubPath, array $meta, string $contentType = ''): string
{
    $templateSlug = trim((string)($meta['_template'] ?? ''));
    if ($templateSlug === '' || $templateSlug === 'default') {
        return cmsResolveTemplate($defaultSubPath);
    }

    // Look up registered templates
    $templates = cmsGetContentTemplates($contentType);
    foreach ($templates as $tpl) {
        if (($tpl['slug'] ?? '') === $templateSlug && !empty($tpl['path'])) {
            // Check if the path exists (might be theme-relative or absolute)
            $path = (string)$tpl['path'];
            // If path starts with _cms_active_theme/ or modules/, use directly
            if (str_starts_with($path, '_cms_active_theme/') || str_starts_with($path, 'modules/')) {
                return $path;
            }
            // Otherwise treat as theme-relative sub-path
            $resolved = cmsResolveTemplate($path);
            return $resolved;
        }
    }

    // Fallback: check if theme has a file named after the slug
    $slugFile = pathinfo($defaultSubPath, PATHINFO_DIRNAME) . '/' . $templateSlug . '.disyl';
    $resolved = cmsResolveTemplate($slugFile);
    if ($resolved !== 'modules/cms/' . $slugFile) {
        return $resolved;
    }

    return cmsResolveTemplate($defaultSubPath);
}

/**
 * Get registered custom block types from all listeners.
 * Returns array of block type definitions.
 */

function cmsGetExtensionBlockTypes(): array
{
    $blocks = app()->hooks()->filter('cms.editor.block_types', []);
    return is_array($blocks) ? $blocks : [];
}

/**
 * Get extra sidebar fields from all listeners for a content type.
 * Returns array of field definitions.
 */

function cmsGetExtensionSidebarFields(string $contentType): array
{
    $fields = app()->hooks()->filter('cms.editor.sidebar_fields', [], $contentType);
    return is_array($fields) ? $fields : [];
}

/**
 * Get extra CMS admin nav items from all listeners.
 * Returns array of nav item definitions.
 */

function cmsGetExtensionNavItems(): array
{
    $items = app()->hooks()->filter('cms.admin.nav_items', []);
    return is_array($items) ? $items : [];
}

function cmsGetPublicHeadHtml(array $content = []): string
{
    $baseline = cmsDefaultSeoHeadHtml($content);
    $html = app()->hooks()->filter('cms.public.head', $baseline, $content);
    return is_string($html) ? $html : '';
}

/**
 * Filter rendered content HTML through all listeners.
 */

function cmsFilterRenderedContent(string $html, array $content = []): string
{
    $result = app()->hooks()->filter('cms.public.render_content', $html, $content);
    return is_string($result) ? $result : $html;
}

/**
 * Filter public content query arguments through all listeners.
 */

function cmsFilterQueryArgs(array $args, string $contentType): array
{
    $result = app()->hooks()->filter('cms.content.query_args', $args, $contentType);
    return is_array($result) ? $result : $args;
}

/**
 * Resolve TinyMCE assets for a given context and profile.
 * Returns an array with version, js_urls, and css_urls.
 */

function cmsTinyMceAssets(string $context = 'cms.content', string $profile = 'default'): array
{
    try {
        $result = app()->cap()->call('tinymce.assets.get@1', [
            'context' => $context,
            'profile' => $profile,
        ], ['mode' => 'first', 'caller_module' => 'cms', 'caller_method' => __METHOD__]);
        if (is_array($result) && !empty($result['ok']) && is_array($result['data'] ?? null)) {
            return $result['data'];
        }
    } catch (Throwable $e) {
    }

    return [
        'version' => null,
        'js_urls' => [],
        'css_urls' => [],
    ];
}

/**
 * Resolve TinyMCE config for a given context, profile, and readonly state.
 * Returns an array with config options.
 */

function cmsTinyMceConfig(string $context = 'cms.content', string $profile = 'default', bool $readonly = false): array
{
    try {
        $result = app()->cap()->call('tinymce.config.get@1', [
            'context' => $context,
            'profile' => $profile,
            'readonly' => $readonly,
        ], ['mode' => 'first', 'caller_module' => 'cms']);
        if (is_array($result) && !empty($result['ok']) && is_array($result['data'] ?? null)) {
            return $result['data'];
        }
    } catch (Throwable $e) {
    }

    return [
        'selector' => '[data-tinymce-editor]',
        'menubar' => true,
        'branding' => false,
        'height' => 520,
        'plugins' => [],
        'toolbar' => '',
        'readonly' => $readonly,
    ];
}

/**
 * Get the field keys declared as 'richtext' for a given content type.
 * Checks both cms_field_definitions and extension sidebar fields.
 */

function cmsGetRichTextFieldKeys(string $contentType): array
{
    $keys = [];

    // From field definitions
    try {
        $stmt = cmsDb()->prepare(
            "SELECT f.field_key FROM cms_field_definitions f
             INNER JOIN cms_content_types t ON t.id = f.content_type_id
             WHERE t.slug = :slug AND t.is_active = 1 AND f.field_type = 'richtext'"
        );
        $stmt->execute([':slug' => $contentType]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $k) {
            $keys[] = (string)$k;
        }
    } catch (Throwable $e) {}

    // From extension sidebar fields
    $extFields = cmsGetExtensionSidebarFields($contentType);
    foreach ($extFields as $ef) {
        if (($ef['type'] ?? '') === 'richtext' && !empty($ef['key'])) {
            $keys[] = (string)$ef['key'];
        }
    }

    return array_values(array_unique($keys));
}

/**
 * Normalize and sanitize richtext meta values through TinyMCE contracts.
 * Mutates the $meta array in place for keys identified as richtext fields.
 */

function cmsSanitizeRichTextMeta(array &$meta, string $contentType): void
{
    $richTextKeys = cmsGetRichTextFieldKeys($contentType);
    if (empty($richTextKeys)) {
        return;
    }

    foreach ($richTextKeys as $key) {
        if (!array_key_exists($key, $meta)) {
            continue;
        }
        $val = (string)$meta[$key];
        if ($val === '') {
            continue;
        }
        $val = cmsEditorNormalizeHtml($val, 'cms.meta');
        $val = cmsEditorSanitizeHtml($val, 'cms.meta');
        $meta[$key] = $val;
    }
}

/**
 * Normalize HTML through the TinyMCE capability contract with safe fallback.
 */

function cmsEditorNormalizeHtml(string $html, string $context = 'cms.content'): string
{
    try {
        $result = app()->cap()->call('tinymce.html.normalize@1', [
            'html' => $html,
            'context' => $context,
        ], ['mode' => 'first', 'caller_module' => 'cms']);
        if (is_array($result) && !empty($result['ok'])) {
            return (string)($result['html'] ?? $html);
        }
    } catch (Throwable $e) {
    }

    return trim($html);
}

/**
 * Sanitize HTML through the TinyMCE capability contract with safe fallback.
 */

function cmsEditorSanitizeHtml(string $html, string $context = 'cms.content'): string
{
    try {
        $result = app()->cap()->call('tinymce.html.sanitize@1', [
            'html' => $html,
            'context' => $context,
        ], ['mode' => 'first', 'caller_module' => 'cms']);
        if (is_array($result) && !empty($result['ok'])) {
            return (string)($result['html'] ?? $html);
        }
    } catch (Throwable $e) {
    }

    return trim($html);
}

// ── Structured Data (JSON-LD) — adopted from ikabud-kernel SEOService ──

/**
 * Generate JSON-LD structured data for a CMS content item.
 * Outputs Article for posts, WebPage for pages.
 */
