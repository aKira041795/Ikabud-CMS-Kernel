<?php

declare(strict_types=1);

function cmsFooterSettingsDefaults(): array
{
    return [
        'columns'                 => 3,
        'widget_container_width'  => 'contained',
        'widget_inner_width_mode' => 'contained',
        'widget_inner_custom_width' => '960px',
        'inner_width'             => 'contained',
        'bg_color'                => '#1e293b',
        'text_color'              => '#cbd5e1',
        'link_color'              => '#94a3b8',
        'link_hover_color'        => '#ffffff',
        'title_color'             => '#f1f5f9',
        'bar_bg_color'            => '#0f172a',
        'bar_text_color'          => '#64748b',
        'bar_link_color'          => '#94a3b8',
        'bar_link_hover_color'    => '#ffffff',
        'copyright_text'          => '© {current_year} {site_title}. All rights reserved.',
        'show_footer_bar'         => 1,
        'show_admin_link'         => 1,
        'padding_top'             => '40',
        'padding_bottom'          => '40',
    ];
}

/**
 * Default header customizer settings.
 */
/**
 * Default settings for the Colors (general body/HTML) customizer section.
 * These act as the site-wide fallback, overridden by header/footer specific settings.
 */

function cmsColorsSettingsDefaults(): array
{
    return [
        // Brand palette
        'color_primary'       => '#3b82f6',
        'color_secondary'     => '#64748b',
        'color_accent'        => '#f59e0b',
        // Body
        'body_bg_color'       => '#ffffff',
        'body_text_color'     => '#1e293b',
        'body_text_light'     => '#64748b',
        'body_link_color'     => '#3b82f6',
        'body_link_hover'     => '#2563eb',
        // UI surfaces
        'border_color'        => '#e2e8f0',
        'light_bg_color'      => '#f8fafc',
        // Storefront entity surfaces
        'storefront_surface_bg'      => '#ffffff',
        'storefront_surface_border'  => '#e2e8f0',
        'storefront_price_color'     => '#0f172a',
        'storefront_badge_bg'        => '#fee2e2',
        'storefront_badge_text'      => '#b91c1c',
        'storefront_cta_bg'          => '#0284c7',
        'storefront_cta_text'        => '#ffffff',
        'storefront_secondary_bg'    => '#ffffff',
        'storefront_secondary_text'  => '#334155',
        'storefront_secondary_border'=> '#cbd5e1',
        'storefront_success_bg'      => '#ecfdf5',
        'storefront_success_text'    => '#047857',
        'storefront_warning_bg'      => '#fffbeb',
        'storefront_warning_text'    => '#b45309',
        'storefront_danger_bg'       => '#fef2f2',
        'storefront_danger_text'     => '#dc2626',
        // Typography
        'font_body'           => 'Inter',
        'font_heading'        => 'Inter',
        'font_size_base'      => '16',
        'line_height'         => '1.6',
        // Headings
        'heading_color'       => '',
        'h1_size'             => '2.5',
        'h2_size'             => '2',
        'h3_size'             => '1.5',
        'h4_size'             => '1.25',
        // Layout
        'container_width'     => '1200',
        'border_radius'       => '0.5',
    ];
}

function cmsCustomizerSanitizeFontFamily(string $value, string $fallback = ''): string
{
    $value = trim($value);
    $value = preg_replace('/[^a-zA-Z0-9\s\-_,\'\"\.]/', '', $value) ?? '';
    return $value !== '' ? $value : $fallback;
}

function cmsCustomizerFontIsSystem(string $font): bool
{
    return in_array(strtolower(trim($font)), ['system-ui', 'georgia', 'serif', 'sans-serif', 'monospace'], true);
}

function cmsCustomizerFontStylesheetHtml(array $faces): string
{
    $loadedFamilies = [];
    foreach ($faces as $face) {
        $normalized = cmsCustomizerSanitizeFontFamily((string)$face, '');
        if ($normalized === '' || cmsCustomizerFontIsSystem($normalized) || isset($loadedFamilies[$normalized])) {
            continue;
        }
        $loadedFamilies[$normalized] = str_replace('%20', '+', rawurlencode($normalized));
    }

    if ($loadedFamilies === []) {
        return '';
    }

    $fontParams = [];
    foreach ($loadedFamilies as $familyParam) {
        $fontParams[] = 'family=' . $familyParam . ':wght@400;500;600;700';
    }
    $fontHref = 'https://fonts.googleapis.com/css2?' . implode('&', $fontParams) . '&display=swap';
    $escapedFontHref = htmlspecialchars($fontHref, ENT_QUOTES, 'UTF-8');

    return '<link rel="stylesheet" href="' . $escapedFontHref . '" media="print" onload="this.media=\'all\'">'
        . '<noscript><link rel="stylesheet" href="' . $escapedFontHref . '"></noscript>';
}

function cmsCustomizerFontCssValue(string $font, string $fallbackCss): string
{
    $font = cmsCustomizerSanitizeFontFamily($font, '');
    $lower = strtolower($font);

    return match ($lower) {
        '' => $fallbackCss,
        'system-ui' => 'system-ui,-apple-system,BlinkMacSystemFont,sans-serif',
        'sans-serif' => 'sans-serif',
        'serif' => 'serif',
        'monospace' => 'monospace',
        'georgia' => '\'Georgia\',serif',
        default => '\'' . $font . '\',-apple-system,BlinkMacSystemFont,sans-serif',
    };
}

/**
 * Validate and sanitize Colors customizer settings.
 */

function cmsValidateColorsSettings(array $input): array
{
    $defaults = cmsColorsSettingsDefaults();
    $validated = [];

    // Color fields
    $colorKeys = [
        'color_primary', 'color_secondary', 'color_accent',
        'body_bg_color', 'body_text_color', 'body_text_light',
        'body_link_color', 'body_link_hover',
        'border_color', 'light_bg_color', 'heading_color',
        'storefront_surface_bg', 'storefront_surface_border',
        'storefront_price_color', 'storefront_badge_bg', 'storefront_badge_text',
        'storefront_cta_bg', 'storefront_cta_text',
        'storefront_secondary_bg', 'storefront_secondary_text', 'storefront_secondary_border',
        'storefront_success_bg', 'storefront_success_text',
        'storefront_warning_bg', 'storefront_warning_text',
        'storefront_danger_bg', 'storefront_danger_text',
    ];
    foreach ($colorKeys as $key) {
        $val = trim((string)($input[$key] ?? $defaults[$key]));
        if ($val === '' || preg_match('/^#[0-9a-fA-F]{3,8}$/', $val) || preg_match('/^rgba?\(/', $val)) {
            $validated[$key] = $val;
        } else {
            $validated[$key] = $defaults[$key];
        }
    }

    // Font families — allow safe font names
    foreach (['font_body', 'font_heading'] as $key) {
        $validated[$key] = cmsCustomizerSanitizeFontFamily((string)($input[$key] ?? $defaults[$key]), (string)$defaults[$key]);
    }

    // Font size base: 12-24px
    $fs = (int)($input['font_size_base'] ?? $defaults['font_size_base']);
    $validated['font_size_base'] = (string)max(12, min(24, $fs ?: 16));

    // Line height: 1.0-2.5
    $lh = (float)($input['line_height'] ?? $defaults['line_height']);
    $lh = max(1.0, min(2.5, $lh ?: 1.6));
    $validated['line_height'] = (string)round($lh, 2);

    // Heading sizes (rem): 1.0-5.0
    foreach (['h1_size', 'h2_size', 'h3_size', 'h4_size'] as $key) {
        $v = (float)($input[$key] ?? $defaults[$key]);
        $v = max(1.0, min(5.0, $v ?: (float)$defaults[$key]));
        $validated[$key] = (string)round($v, 2);
    }

    // Container width: 800-1600px
    $cw = (int)($input['container_width'] ?? $defaults['container_width']);
    $validated['container_width'] = (string)max(800, min(1600, $cw ?: 1200));

    // Border radius: 0-2rem
    $br = (float)($input['border_radius'] ?? $defaults['border_radius']);
    $br = max(0, min(2, $br));
    $validated['border_radius'] = (string)round($br, 2);

    return $validated;
}

function cmsNormalizeCustomizerScope(?string $scope, string $fallback = 'native'): string
{
    $scope = trim((string)$scope);
    return in_array($scope, ['native', 'ecommerce'], true) ? $scope : $fallback;
}

function cmsRequestedCustomizerScope(array $params = []): string
{
    $requested = trim((string)($params['scope'] ?? ''));
    if ($requested === '') {
        return cmsActiveCustomizerScope();
    }

    return cmsNormalizeCustomizerScope($requested, cmsActiveCustomizerScope());
}

function cmsEcommerceEntityViewRouteKinds(): array
{
    return ['shop_index', 'shop_category', 'product_detail'];
}

function cmsNormalizeEcommercePublicRouteKind(?string $routeKind = null): string
{
    $routeKind = trim((string)$routeKind);
    if ($routeKind === '') {
        return 'generic';
    }

    $allowed = [
        'generic',
        'shop_index',
        'shop_category',
        'product_detail',
        'cart',
        'checkout',
        'order_confirmation',
        'my_orders',
        'order_detail',
        'not_found',
    ];

    return in_array($routeKind, $allowed, true) ? $routeKind : 'generic';
}

function cmsEcommercePublicPresentationMode(array $context = []): string
{
    if (function_exists('cmsResolveEcommerceThemePolicy')) {
        $policy = cmsResolveEcommerceThemePolicy($context);
        $mode = trim((string)($policy['public_presentation_mode'] ?? 'traditional'));
        return in_array($mode, ['traditional', 'entity_view'], true) ? $mode : 'traditional';
    }

    $routeKind = cmsNormalizeEcommercePublicRouteKind(
        (string)($context['public_route_kind'] ?? $context['ecommerce_public_route'] ?? 'generic')
    );

    if (cmsActiveCustomizerScope() !== 'ecommerce') {
        return 'traditional';
    }

    return in_array($routeKind, cmsEcommerceEntityViewRouteKinds(), true) ? 'entity_view' : 'traditional';
}

function cmsKnownCustomizerSections(): array
{
    return ['footer', 'header', 'sidebar', 'colors', 'custom_code', 'entity_presentation', 'theme'];
}

function cmsCustomizerSectionDefaults(string $section): array
{
    return match ($section) {
        'footer' => cmsFooterSettingsDefaults(),
        'header' => cmsHeaderSettingsDefaults(),
        'sidebar' => cmsSidebarSettingsDefaults(),
        'colors' => cmsColorsSettingsDefaults(),
        'custom_code' => cmsCustomCodeSettingsDefaults(),
        'entity_presentation' => cmsEntityPresentationSectionDefaults(),
        'theme' => cmsThemeLayoutSettingsDefaults(),
        default => [],
    };
}

function cmsValidateCustomizerSectionSettings(string $section, array $settings): array
{
    return match ($section) {
        'footer' => cmsValidateFooterSettings($settings),
        'header' => cmsValidateHeaderSettings($settings),
        'sidebar' => cmsValidateSidebarSettings($settings),
        'colors' => cmsValidateColorsSettings($settings),
        'custom_code' => cmsValidateCustomCodeSettings($settings),
        'entity_presentation' => cmsValidateEntityPresentationSettings($settings, cmsEntityPresentationSectionDefaults()),
        'theme' => cmsValidateThemeLayoutSettings($settings),
        default => $settings,
    };
}

function cmsThemeCustomizerScopeFromManifest(array $manifest): string
{
    return cmsNormalizeCustomizerScope((string)($manifest['customizer_scope'] ?? 'native'));
}

function cmsThemeManifestEntityViewDefaults(?array $manifest = null): array
{
    $manifest = is_array($manifest) ? $manifest : cmsActiveThemeManifest();
    $defaults = $manifest['entity_view_defaults'] ?? [];
    return is_array($defaults) ? $defaults : [];
}

function cmsEntityPresentationSectionDefaults(?string $scope = null, ?array $manifest = null): array
{
    $manifest = is_array($manifest) ? $manifest : cmsActiveThemeManifest();
    $scope = cmsNormalizeCustomizerScope($scope, cmsThemeCustomizerScopeFromManifest($manifest));
    $defaults = cmsEntityPresentationSettingsDefaults();
    if ($scope === 'ecommerce') {
        $defaults['entity_layout_profile'] = 'commerce';
    }

    $entityDefaults = cmsThemeManifestEntityViewDefaults($manifest);
    $blockVariants = cmsThemeManifestBlockVariantDefaults($manifest);
    $settingMap = cmsThemeBlockVariantSettingMap();

    $overrides = [];
    if (isset($entityDefaults['layout_profile'])) {
        $overrides['entity_layout_profile'] = (string)$entityDefaults['layout_profile'];
    }
    foreach ($blockVariants as $blockId => $variant) {
        $settingKey = $settingMap[$blockId] ?? null;
        if ($settingKey !== null) {
            $overrides[$settingKey] = (string)$variant;
        }
    }
    foreach ([
        'summary_width' => 'entity_summary_width',
        'summary_sticky' => 'entity_summary_sticky',
        'media_ratio' => 'entity_media_ratio',
        'spacing_scale' => 'entity_spacing_scale',
        'action_size' => 'entity_action_size',
        'list_show_filter_summary' => 'entity_list_show_filter_summary',
        'list_category_navigation' => 'entity_list_category_navigation',
        'list_card_density' => 'entity_list_card_density',
        'list_show_excerpt' => 'entity_list_show_excerpt',
        'list_excerpt_length' => 'entity_list_excerpt_length',
        'list_title_font' => 'entity_list_title_font',
        'list_text_font' => 'entity_list_text_font',
        'list_title_size' => 'entity_list_title_size',
        'list_price_size' => 'entity_list_price_size',
        'list_card_min_width' => 'entity_list_card_min_width',
        'list_title_lines' => 'entity_list_title_lines',
    ] as $sourceKey => $settingKey) {
        if (array_key_exists($sourceKey, $entityDefaults)) {
            $overrides[$settingKey] = $entityDefaults[$sourceKey];
        }
    }

    return cmsValidateEntityPresentationSettings(array_merge($defaults, $overrides), $defaults);
}

function cmsThemeManifestBlockVariantDefaults(?array $manifest = null): array
{
    $manifest = is_array($manifest) ? $manifest : cmsActiveThemeManifest();
    $entityDefaults = cmsThemeManifestEntityViewDefaults($manifest);
    $variants = $entityDefaults['block_variants'] ?? ($manifest['block_variants'] ?? []);
    return is_array($variants) ? $variants : [];
}

function cmsThemeManifestTokenPxValue(array $tokens, string $key, int $fallback): int
{
    $raw = trim((string)($tokens[$key] ?? ''));
    if ($raw === '') {
        return $fallback;
    }

    if (preg_match('/^([0-9]+(?:\.[0-9]+)?)px$/i', $raw, $matches)) {
        return (int)round((float)$matches[1]);
    }
    if (preg_match('/^[0-9]+(?:\.[0-9]+)?$/', $raw)) {
        return (int)round((float)$raw);
    }

    return $fallback;
}

function cmsThemeManifestTokenRemValue(array $tokens, string $key, float $fallback): float
{
    $raw = trim((string)($tokens[$key] ?? ''));
    if ($raw === '') {
        return $fallback;
    }

    if (preg_match('/^([0-9]+(?:\.[0-9]+)?)rem$/i', $raw, $matches)) {
        return (float)$matches[1];
    }
    if (preg_match('/^([0-9]+(?:\.[0-9]+)?)px$/i', $raw, $matches)) {
        return ((float)$matches[1]) / 16;
    }
    if (preg_match('/^[0-9]+(?:\.[0-9]+)?$/', $raw)) {
        return (float)$raw;
    }

    return $fallback;
}

function cmsThemeManifestColorsDefaults(?array $manifest = null): array
{
    $manifest = is_array($manifest) ? $manifest : cmsActiveThemeManifest();
    $tokens = function_exists('cmsThemeManifestTokens') ? cmsThemeManifestTokens($manifest) : [];
    $defaults = cmsColorsSettingsDefaults();

    $overrides = [];
    $map = [
        'color-primary' => ['color_primary', 'body_link_color', 'storefront_cta_bg'],
        'color-accent' => ['color_accent'],
        'color-background' => ['body_bg_color'],
        'color-surface' => ['light_bg_color', 'storefront_surface_bg', 'storefront_secondary_bg'],
        'color-text' => ['body_text_color', 'storefront_price_color', 'storefront_secondary_text'],
        'color-muted' => ['color_secondary', 'body_text_light'],
        'color-border' => ['border_color', 'storefront_surface_border', 'storefront_secondary_border'],
    ];
    foreach ($map as $tokenKey => $settingKeys) {
        $value = trim((string)($tokens[$tokenKey] ?? ''));
        if ($value === '') {
            continue;
        }
        foreach ($settingKeys as $settingKey) {
            $overrides[$settingKey] = $value;
        }
    }

    if (!isset($overrides['storefront_cta_text']) && isset($overrides['body_bg_color'])) {
        $overrides['storefront_cta_text'] = $overrides['body_bg_color'];
    }

    $containerWidth = cmsThemeManifestTokenPxValue($tokens, 'container-max', (int)$defaults['container_width']);
    if ($containerWidth > 0) {
        $overrides['container_width'] = (string)$containerWidth;
    }

    $borderRadius = cmsThemeManifestTokenRemValue($tokens, 'radius-card', (float)$defaults['border_radius']);
    if ($borderRadius > 0) {
        $overrides['border_radius'] = (string)round($borderRadius, 2);
    }

    return cmsValidateColorsSettings(array_merge($defaults, $overrides));
}

function cmsThemeManifestThemeLayoutDefaults(?array $manifest = null, ?string $scope = null): array
{
    $manifest = is_array($manifest) ? $manifest : cmsActiveThemeManifest();
    $scope = cmsNormalizeCustomizerScope($scope, cmsThemeCustomizerScopeFromManifest($manifest));
    $tokens = function_exists('cmsThemeManifestTokens') ? cmsThemeManifestTokens($manifest) : [];
    $defaults = cmsThemeLayoutSettingsDefaults();

    $overrides = [];
    $containerMax = cmsThemeManifestTokenPxValue($tokens, 'container-max', (int)$defaults['site_max_width']);
    if ($containerMax > 0) {
        $overrides['site_max_width'] = (string)$containerMax;
    }

    return cmsValidateThemeLayoutSettings(array_merge($defaults, $overrides));
}

function cmsThemeManifestCustomizerDefaults(string $section, ?array $manifest = null, ?string $scope = null): array
{
    return match ($section) {
        'colors' => cmsThemeManifestColorsDefaults($manifest),
        'entity_presentation' => cmsEntityPresentationSectionDefaults($scope, $manifest),
        'theme' => cmsThemeManifestThemeLayoutDefaults($manifest, $scope),
        default => cmsCustomizerSectionDefaults($section),
    };
}

function cmsActiveCustomizerScope(): string
{
    if (function_exists('cmsResolveEcommerceThemePolicy')) {
        $policy = cmsResolveEcommerceThemePolicy(cmsCurrentPublicThemeContext());
        return cmsNormalizeCustomizerScope((string)($policy['active_theme_scope'] ?? 'native'));
    }

    return cmsThemeCustomizerScopeFromManifest(cmsActiveThemeManifest());
}

function cmsThemeShouldDeferCustomizerPresentation(?array $manifest = null): bool
{
    $manifest = is_array($manifest) ? $manifest : cmsActiveThemeManifest();
    if (($manifest['defer_customizer_presentation'] ?? null) !== null) {
        return !empty($manifest['defer_customizer_presentation']);
    }

    return cmsThemeCustomizerScopeFromManifest($manifest) === 'ecommerce';
}

function cmsCustomizerSettingsEqual(array $left, array $right): bool
{
    ksort($left);
    ksort($right);
    return $left === $right;
}

function cmsShouldRenderCustomizerPresentationCss(string $section, array $settings, ?array $manifest = null, ?string $scope = null): bool
{
    $manifest = is_array($manifest) ? $manifest : cmsActiveThemeManifest();
    $scope = $scope !== null ? trim($scope) : cmsActiveCustomizerScope();
    if (!cmsThemeShouldDeferCustomizerPresentation($manifest)) {
        return true;
    }
    if (!in_array($section, ['colors', 'entity_presentation', 'theme'], true)) {
        return true;
    }

    $current = cmsValidateCustomizerSectionSettings($section, $settings);
    $themeDefaults = cmsThemeManifestCustomizerDefaults($section, $manifest, $scope);
    return !cmsCustomizerSettingsEqual($current, $themeDefaults);
}

function cmsCustomizerScopeLabel(?string $scope = null): string
{
    $scope = $scope !== null ? trim($scope) : cmsActiveCustomizerScope();
    return $scope === 'ecommerce' ? 'E-commerce Theme Customizer' : 'Theme Customizer';
}

function cmsCustomizerScopeIntro(?string $scope = null): string
{
    $scope = $scope !== null ? trim($scope) : cmsActiveCustomizerScope();
    return $scope === 'ecommerce'
        ? 'Customize the ecommerce presentation layer without inheriting native theme sidebar and layout concerns.'
        : 'Customize your site\'s shared presentation layer for the native theme experience.';
}

function cmsCustomizerStorefrontScope(?string $scope = null): bool
{
    $scope = $scope !== null ? trim($scope) : cmsActiveCustomizerScope();
    return $scope === 'ecommerce';
}

function cmsCustomizerUsesStorefrontChrome(?string $scope = null, array $publicCtx = []): bool
{
    if (!cmsCustomizerStorefrontScope($scope)) {
        return false;
    }

    $origin = trim((string)($publicCtx['public_render_origin'] ?? ''));
    $routeKind = trim((string)($publicCtx['public_route_kind'] ?? $publicCtx['ecommerce_public_route'] ?? ''));
    $presentationMode = trim((string)($publicCtx['public_presentation_mode'] ?? ''));

    if ($origin === 'ecommerce') {
        return true;
    }

    if ($origin === 'cms' && ($presentationMode === 'canonical' || ($routeKind !== '' && $routeKind !== 'generic'))) {
        return false;
    }

    return true;
}

function cmsCustomizerHomeUrl(string $baseUrl, ?string $scope = null, array $publicCtx = []): string
{
    return $baseUrl . (cmsCustomizerUsesStorefrontChrome($scope, $publicCtx) ? '/ecommerce/shop' : '/cms');
}

function cmsCustomizerSearchConfig(?string $scope = null, array $publicCtx = []): array
{
    if (cmsCustomizerUsesStorefrontChrome($scope, $publicCtx)) {
        return [
            'action_path' => '/ecommerce/shop',
            'query_param' => 'search',
            'placeholder' => 'Search products...',
        ];
    }

    return [
        'action_path' => '/cms/search',
        'query_param' => 'q',
        'placeholder' => 'Search…',
    ];
}

function cmsCustomizerFallbackNavItems(string $baseUrl, ?string $scope = null, array $publicCtx = []): array
{
    if (cmsCustomizerUsesStorefrontChrome($scope, $publicCtx)) {
        return [
            ['label' => 'Shop', 'href' => $baseUrl . '/ecommerce/shop'],
            ['label' => 'My Orders', 'href' => $baseUrl . '/ecommerce/my-orders'],
        ];
    }

    return [
        ['label' => 'Home', 'href' => $baseUrl . '/cms'],
        ['label' => 'Blog', 'href' => $baseUrl . '/cms/blog'],
    ];
}

function cmsCustomizerStorageSection(string $section, ?string $scope = null): string
{
    $scope = $scope !== null ? trim($scope) : cmsActiveCustomizerScope();
    if ($scope === '' || $scope === 'native') {
        return $section;
    }

    return $scope . ':' . $section;
}

function cmsCustomizerScopeTag(?string $scope = null): string
{
    $scope = $scope !== null ? trim($scope) : cmsActiveCustomizerScope();
    return 'cms:customizer:scope:' . ($scope !== '' ? $scope : 'native');
}

function cmsCustomizerRequestCacheKey(string $suffix, ?string $scope = null): string
{
    $scope = $scope !== null ? trim($scope) : cmsActiveCustomizerScope();
    return 'cms_customizer_' . ($scope !== '' ? $scope : 'native') . '_' . $suffix . '_t' . cmsRuntimeTenantId();
}

function cmsCustomizerPersistentCacheTtl(): int
{
    return max(0, (int)($_ENV['CMS_CUSTOMIZER_CACHE_TTL'] ?? 300));
}

function cmsCustomizerPersistentCacheInstance(?string $scope = null): string
{
    $scope = $scope !== null ? trim($scope) : cmsActiveCustomizerScope();
    return 'cms_customizer_' . ($scope !== '' ? $scope : 'native') . '_t' . cmsRuntimeTenantId();
}

function cmsCustomizerPersistentCacheKey(string $section, ?string $scope = null): string
{
    $scope = $scope !== null ? trim($scope) : cmsActiveCustomizerScope();
    return 'customizer:' . ($scope !== '' ? $scope : 'native') . ':section:' . cmsCustomizerStorageSection($section, $scope) . ':v1';
}

function cmsCustomizerFragmentCacheKey(string $fragment, ?string $scope = null): string
{
    $scope = $scope !== null ? trim($scope) : cmsActiveCustomizerScope();
    return 'customizer:' . ($scope !== '' ? $scope : 'native') . ':fragment:' . $fragment . ':v4';
}

function cmsCustomizerFragmentCacheGet(string $fragment, ?string $scope = null): ?array
{
    return cmsCacheGet(cmsCustomizerFragmentCacheKey($fragment, $scope));
}

function cmsCustomizerFragmentCacheSet(string $fragment, array $data, array $tags = [], ?string $scope = null): void
{
    $scope = $scope !== null ? trim($scope) : cmsActiveCustomizerScope();
    $defaultTags = ['cms:customizer', cmsCustomizerScopeTag($scope), 'cms:customizer:fragment:' . $fragment, 'cms:customizer:fragment:' . ($scope !== '' ? $scope : 'native') . ':' . $fragment];
    cmsCacheSet(
        cmsCustomizerFragmentCacheKey($fragment, $scope),
        $data,
        array_values(array_unique(array_merge($defaultTags, $tags)))
    );
}

function cmsCustomizerCurrentPathCacheToken(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $path = rtrim((string)$path, '/');
    return $path !== '' ? $path : '/';
}

function cmsCustomizerRenderContextCacheToken(array $publicCtx = []): string
{
    $normalize = static function ($value, string $fallback = ''): string {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_scalar($value)) {
            $normalized = trim((string)$value);
            return $normalized !== '' ? $normalized : $fallback;
        }
        return $fallback;
    };

    $payload = [
        'path' => cmsCustomizerCurrentPathCacheToken(),
        'scope' => $normalize($publicCtx['active_customizer_scope'] ?? cmsActiveCustomizerScope(), 'native'),
        'active_theme' => $normalize($publicCtx['active_theme'] ?? ''),
        'active_theme_source' => $normalize($publicCtx['active_theme_source'] ?? '', 'site'),
        'configured_site_theme' => $normalize($publicCtx['configured_site_theme'] ?? ''),
        'preferred_storefront_theme' => $normalize($publicCtx['preferred_storefront_theme'] ?? ''),
        'public_render_origin' => $normalize($publicCtx['public_render_origin'] ?? '', 'cms'),
        'public_route_kind' => $normalize($publicCtx['public_route_kind'] ?? '', 'generic'),
        'public_presentation_mode' => $normalize($publicCtx['public_presentation_mode'] ?? '', 'traditional'),
        'is_ecommerce_public' => !empty($publicCtx['is_ecommerce_public']) ? '1' : '0',
        'is_ecommerce_entity_route' => !empty($publicCtx['is_ecommerce_entity_route']) ? '1' : '0',
    ];

    return sha1((string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function cmsUpsertCustomizerSection(object $db, string $section, array $settings, array $widgets = [], ?int $userId = null, ?string $scope = null): void
{
    $scope = $scope !== null ? trim($scope) : cmsActiveCustomizerScope();
    $settings = cmsValidateCustomizerSectionSettings($section, $settings);
    $settingsJson = json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $widgetsJson = json_encode($widgets, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $stmt = $db->prepare(
        "INSERT INTO cms_theme_customizer (section, settings_json, widgets_json, updated_by)\n"
        . " VALUES (:section, :settings, :widgets, :uid)\n"
        . " ON DUPLICATE KEY UPDATE settings_json = VALUES(settings_json), widgets_json = VALUES(widgets_json), updated_by = VALUES(updated_by)"
    );
    $stmt->execute([
        ':section' => cmsCustomizerStorageSection($section, $scope),
        ':settings' => $settingsJson,
        ':widgets' => $widgetsJson,
        ':uid' => $userId,
    ]);

    $cacheKey = cmsCustomizerRequestCacheKey('section_row', $scope);
    $cache = $GLOBALS[$cacheKey] ?? [];
    $cache[$section] = [
        'settings_json' => $settingsJson,
        'widgets_json' => $widgetsJson,
    ];
    $GLOBALS[$cacheKey] = $cache;

    cmsCustomizerClearPersistentCache($section, $scope);
}

function cmsDeleteCustomizerSection(object $db, string $section, ?string $scope = null): void
{
    $scope = $scope !== null ? trim($scope) : cmsActiveCustomizerScope();

    try {
        $stmt = $db->prepare("DELETE FROM cms_theme_customizer WHERE section = :section");
        $stmt->execute([':section' => cmsCustomizerStorageSection($section, $scope)]);
    } catch (Throwable $e) {
    }

    $cacheKey = cmsCustomizerRequestCacheKey('section_row', $scope);
    $cache = $GLOBALS[$cacheKey] ?? [];
    $cache[$section] = null;
    $GLOBALS[$cacheKey] = $cache;

    cmsCustomizerClearPersistentCache($section, $scope);
}

function cmsEntityPresentationSectionData(object $db, ?string $scope = null): array
{
    $scope = $scope !== null ? trim($scope) : cmsActiveCustomizerScope();
    $defaults = cmsEntityPresentationSectionDefaults($scope);

    $canonical = cmsCustomizerSectionRecord($db, 'entity_presentation', $scope);
    if (is_array($canonical)) {
        $settings = json_decode((string)($canonical['settings_json'] ?? '{}'), true) ?: [];
        return [
            'settings' => cmsValidateEntityPresentationSettings(array_merge($defaults, $settings), $defaults),
            'widgets' => [],
            'source_section' => 'entity_presentation',
            'is_canonical' => true,
        ];
    }

    return [
        'settings' => $defaults,
        'widgets' => [],
        'source_section' => 'defaults',
        'is_canonical' => false,
    ];
}

function cmsNormalizeEntityPresentationStorage(object $db, ?int $userId = null, ?string $scope = null): void
{
    $scope = $scope !== null ? trim($scope) : cmsActiveCustomizerScope();
    $scopeTag = $scope !== '' ? $scope : 'native';
    $flag = 'cms_customizer_entity_presentation_normalized_' . $scopeTag . '_t' . cmsRuntimeTenantId();
    if (!empty($GLOBALS[$flag])) {
        return;
    }
    $GLOBALS[$flag] = true;

    $defaults = cmsEntityPresentationSectionDefaults($scope);
    $canonicalKeys = array_fill_keys(array_keys($defaults), true);
    $canonicalRecord = cmsCustomizerSectionRecord($db, 'entity_presentation', $scope);
    $canonicalStored = [];
    if (is_array($canonicalRecord)) {
        $canonicalStored = json_decode((string)($canonicalRecord['settings_json'] ?? '{}'), true) ?: [];
    }
    $canonicalChanged = false;

    $legacySections = $scope === 'ecommerce' ? ['storefront', 'theme'] : ['theme'];
    foreach ($legacySections as $legacySection) {
        $legacyRecord = cmsCustomizerSectionRecord($db, $legacySection, $scope);
        if (!is_array($legacyRecord)) {
            continue;
        }

        $legacySettings = json_decode((string)($legacyRecord['settings_json'] ?? '{}'), true) ?: [];
        $legacyPresentationSettings = array_intersect_key($legacySettings, $canonicalKeys);
        foreach ($legacyPresentationSettings as $key => $value) {
            if (!array_key_exists($key, $canonicalStored)) {
                $canonicalStored[$key] = $value;
                $canonicalChanged = true;
            }
        }

        if ($legacySection === 'theme') {
            $hasLegacyEntityKeys = false;
            foreach (array_keys($legacySettings) as $key) {
                if (strpos((string)$key, 'entity_') === 0 || in_array((string)$key, cmsEntityPresentationThemeCompatKeys(), true)) {
                    $hasLegacyEntityKeys = true;
                    break;
                }
            }

            if ($hasLegacyEntityKeys) {
                $themeWidgets = json_decode((string)($legacyRecord['widgets_json'] ?? '[]'), true) ?: [];
                $sanitizedTheme = cmsValidateThemeLayoutSettings($legacySettings);
                cmsUpsertCustomizerSection($db, 'theme', $sanitizedTheme, $themeWidgets, $userId, $scope);
            }

            continue;
        }

        cmsDeleteCustomizerSection($db, $legacySection, $scope);
    }

    if ($canonicalChanged || (!is_array($canonicalRecord) && $canonicalStored !== [])) {
        $canonicalSettings = cmsValidateEntityPresentationSettings(array_merge($defaults, $canonicalStored), $defaults);
        cmsUpsertCustomizerSection($db, 'entity_presentation', $canonicalSettings, [], $userId, $scope);
    }
}

function cmsSeedActiveThemeCustomizerDefaults(object $db, ?int $userId = null): void
{
    $manifest = cmsActiveThemeManifest();
    $scope = cmsThemeCustomizerScopeFromManifest($manifest);
    foreach (['colors', 'entity_presentation', 'theme'] as $section) {
        cmsUpsertCustomizerSection(
            $db,
            $section,
            cmsThemeManifestCustomizerDefaults($section, $manifest, $scope),
            [],
            $userId,
            $scope
        );
    }
}

function cmsCustomizerClearPersistentCache(?string $section = null, ?string $scope = null): void
{
    if (cmsCustomizerPersistentCacheTtl() <= 0) {
        return;
    }

    $scope = $scope !== null ? trim($scope) : cmsActiveCustomizerScope();
    $tags = ['cms:customizer', cmsCustomizerScopeTag($scope)];
    if ($section !== null && $section !== '') {
        $tags[] = 'cms:customizer:' . ($scope !== '' ? $scope : 'native') . ':' . $section;
    }

    app()->cache()->clearByTags(cmsCustomizerPersistentCacheInstance($scope), $tags);
}

function cmsShellEntityToken(string $value, string $fallback = 'generic'): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9\-]+/', '-', $value) ?? '';
    $value = trim($value, '-');
    return $value !== '' ? $value : $fallback;
}

function cmsRenderShellEntityView(string $region, string $html, array $options = []): string
{
    $region = cmsShellEntityToken($region, 'generic');
    $extraClasses = $options['classes'] ?? [];
    if (is_string($extraClasses) && $extraClasses !== '') {
        $extraClasses = [$extraClasses];
    }
    if (!is_array($extraClasses)) {
        $extraClasses = [];
    }

    $classes = array_values(array_filter(array_merge([
        'cms-shell-entity-view',
        'cms-shell-entity-view--' . $region,
    ], array_map(static fn ($class): string => trim((string)$class), $extraClasses))));

    $data = is_array($options['data'] ?? null) ? $options['data'] : [];
    $data['shell-entity-view'] = '1';
    $data['shell-entity-region'] = $region;

    $attrs = ' class="' . htmlspecialchars(implode(' ', $classes), ENT_QUOTES) . '"';
    foreach ($data as $key => $value) {
        $key = cmsShellEntityToken((string)$key, 'meta');
        $value = trim((string)$value);
        if ($value === '') {
            continue;
        }
        $attrs .= ' data-' . $key . '="' . htmlspecialchars($value, ENT_QUOTES) . '"';
    }

    return '<section' . $attrs . '><div class="cms-shell-entity-view__body">' . $html . '</div></section>';
}

function cmsRenderShellEntityWidget(string $region, array $widget, string $html): string
{
    $type = cmsShellEntityToken((string)($widget['type'] ?? ''), 'unknown');
    $title = trim((string)(($widget['props'] ?? [])['title'] ?? ''));

    return cmsRenderShellEntityView($region, $html, [
        'classes' => [
            'cms-shell-entity-view--widget',
            'cms-shell-entity-view--widget-' . $type,
        ],
        'data' => [
            'shell-entity-node' => 'widget',
            'shell-entity-widget-type' => $type,
            'shell-entity-widget-title' => $title,
        ],
    ]);
}

function cmsEnsureCustomizerScopeSeeded(object $db, ?string $scope = null): void
{
    $scope = $scope !== null ? trim($scope) : cmsActiveCustomizerScope();
    cmsNormalizeEntityPresentationStorage($db, null, $scope);

    if ($scope === '' || $scope === 'native') {
        return;
    }

    $requestCacheKey = cmsCustomizerRequestCacheKey('section_row', $scope);
    $requestCache = $GLOBALS[$requestCacheKey] ?? [];

    $flag = 'cms_customizer_seeded_' . $scope . '_t' . cmsRuntimeTenantId();
    if (!empty($GLOBALS[$flag])) {
        return;
    }
    $GLOBALS[$flag] = true;

    foreach (cmsKnownCustomizerSections() as $section) {
        if (cmsCustomizerSectionRecord($db, $section, $scope) !== null) {
            continue;
        }

        $settings = null;
        $widgets = [];
        if ($section === 'sidebar') {
            $settings = cmsSidebarSettingsDefaults();
            $settings['enabled'] = 0;
        } elseif (in_array($section, ['colors', 'entity_presentation', 'theme'], true)) {
            $settings = cmsThemeManifestCustomizerDefaults($section, cmsActiveThemeManifest(), $scope);
        } else {
            $source = cmsCustomizerSectionRecord($db, $section, 'native');
            if ($source !== null) {
                $settings = json_decode((string)($source['settings_json'] ?? '{}'), true) ?: [];
                $widgets = json_decode((string)($source['widgets_json'] ?? '[]'), true) ?: [];
            } else {
                $settings = cmsCustomizerSectionDefaults($section);
            }
        }

        if ($settings === null) {
            continue;
        }

        cmsUpsertCustomizerSection($db, $section, $settings, $widgets, null, $scope);
        $requestCache[$section] = $GLOBALS[$requestCacheKey][$section] ?? [
            'settings_json' => json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'widgets_json' => json_encode($widgets, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    }

    $GLOBALS[$requestCacheKey] = $requestCache;
    cmsCustomizerClearPersistentCache(null, $scope);
}

/**
 * Preload all customizer sections into request cache in a single DB query.
 * Call once at the start of public context building to avoid N separate
 * queries when each section is read individually.
 */
function cmsCustomizerPreloadAll(object $db, ?string $scope = null): void
{
    $scope = $scope !== null ? trim($scope) : cmsActiveCustomizerScope();
    cmsEnsureCustomizerScopeSeeded($db, $scope);

    $cacheKey = cmsCustomizerRequestCacheKey('section_row', $scope);
    if (!empty($GLOBALS[$cacheKey])) {
        return; // Already preloaded this request
    }

    $knownSections = cmsKnownCustomizerSections();
    $storageMap = [];
    $storageLookup = [];
    foreach ($knownSections as $knownSection) {
        $storageSection = cmsCustomizerStorageSection($knownSection, $scope);
        $storageMap[$knownSection] = $storageSection;
        $storageLookup[$storageSection] = $knownSection;
    }
    $cache = [];
    $ttl = cmsCustomizerPersistentCacheTtl();
    $instance = cmsCustomizerPersistentCacheInstance($scope);

    // Try persistent cache first
    if ($ttl > 0) {
        // Fast path: single bundle read instead of 6 individual reads
        $bundleCacheKey = 'customizer:' . ($scope !== '' ? $scope : 'native') . ':bundle:v1';
        $bundle = app()->cache()->get($instance, $bundleCacheKey);
        if (is_array($bundle)) {
            $complete = true;
            foreach ($knownSections as $s) {
                if (!array_key_exists($s, $bundle)) { $complete = false; break; }
            }
            if ($complete) {
                $GLOBALS[$cacheKey] = $bundle;
                return;
            }
        }
        // Fallback: read individual section keys (and promote to bundle on success)
        $allCached = true;
        foreach ($knownSections as $s) {
            $persistent = app()->cache()->get($instance, cmsCustomizerPersistentCacheKey($s, $scope));
            if (is_array($persistent)) {
                if (isset($persistent['_empty']) && $persistent['_empty'] === true) {
                    $cache[$s] = null;
                } else {
                    $cache[$s] = $persistent;
                }
            } else {
                $allCached = false;
                break;
            }
        }
        if ($allCached) {
            $GLOBALS[$cacheKey] = $cache;
            // Promote to bundle for future requests (1 read instead of 6)
            app()->cache()->setWithTags($instance, $bundleCacheKey, $cache, ['cms:customizer'], $ttl);
            return;
        }
    }

    // Persistent cache incomplete — batch-load from DB
    $cache = [];
    try {
        $placeholders = implode(',', array_fill(0, count($storageMap), '?'));
        $stmt = $db->prepare("SELECT section, settings_json, widgets_json FROM cms_theme_customizer WHERE section IN ({$placeholders})");
        $stmt->execute(array_values($storageMap));
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $storageSection = (string)($row['section'] ?? '');
            $baseSection = $storageLookup[$storageSection] ?? '';
            if ($baseSection === '') {
                continue;
            }
            $cache[$baseSection] = ['settings_json' => $row['settings_json'], 'widgets_json' => $row['widgets_json']];
        }
    } catch (\Throwable $e) {
        // Table may not exist yet; leave cache empty
    }

    // Fill in missing sections as null so individual lookups won't re-query
    foreach ($knownSections as $s) {
        if (!array_key_exists($s, $cache)) {
            $cache[$s] = null;
        }
    }

    $GLOBALS[$cacheKey] = $cache;

    // Populate persistent cache for each section
    if ($ttl > 0) {
        foreach ($knownSections as $s) {
            $cacheValue = $cache[$s] ?? ['_empty' => true];
            app()->cache()->setWithTags(
                $instance,
                cmsCustomizerPersistentCacheKey($s, $scope),
                $cacheValue,
                ['cms:customizer', cmsCustomizerScopeTag($scope), 'cms:customizer:' . ($scope !== '' ? $scope : 'native') . ':' . $s],
                $ttl
            );
        }
        // Write bundle so the next request pays 1 cache read instead of 6
        app()->cache()->setWithTags(
            $instance,
            $bundleCacheKey,
            $cache,
            ['cms:customizer', cmsCustomizerScopeTag($scope)],
            $ttl
        );
    }
}

function cmsCustomizerSectionRecord(object $db, string $section, ?string $scope = null): ?array
{
    $scope = $scope !== null ? trim($scope) : cmsActiveCustomizerScope();
    $cacheKey = cmsCustomizerRequestCacheKey('section_row', $scope);
    $cache = $GLOBALS[$cacheKey] ?? [];
    if (array_key_exists($section, $cache)) {
        return $cache[$section];
    }

    if (cmsCustomizerPersistentCacheTtl() > 0) {
        $persistent = app()->cache()->get(cmsCustomizerPersistentCacheInstance($scope), cmsCustomizerPersistentCacheKey($section, $scope));
        if (is_array($persistent)) {
            // Sentinel value means "section does not exist in DB"
            if (isset($persistent['_empty']) && $persistent['_empty'] === true) {
                $cache[$section] = null;
                $GLOBALS[$cacheKey] = $cache;
                return null;
            }
            $cache[$section] = $persistent;
            $GLOBALS[$cacheKey] = $cache;
            return $persistent;
        }
    }

    $row = null;
    try {
        $stmt = $db->prepare("SELECT settings_json, widgets_json FROM cms_theme_customizer WHERE section = :s LIMIT 1");
        $stmt->execute([':s' => cmsCustomizerStorageSection($section, $scope)]);
        $fetched = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($fetched)) {
            $row = $fetched;
        }
    } catch (Throwable $e) {
        $row = null;
    }

    $cache[$section] = $row;
    $GLOBALS[$cacheKey] = $cache;
    if (cmsCustomizerPersistentCacheTtl() > 0) {
        // Cache both hits and misses; use sentinel for missing sections
        $cacheValue = $row ?? ['_empty' => true];
        app()->cache()->setWithTags(
            cmsCustomizerPersistentCacheInstance($scope),
            cmsCustomizerPersistentCacheKey($section, $scope),
            $cacheValue,
            ['cms:customizer', cmsCustomizerScopeTag($scope), 'cms:customizer:' . ($scope !== '' ? $scope : 'native') . ':' . $section],
            cmsCustomizerPersistentCacheTtl()
        );
    }
    return $row;
}

function cmsCustomizerSectionExists(object $db, string $section, ?string $scope = null): bool
{
    return cmsCustomizerSectionRecord($db, $section, $scope) !== null;
}

function cmsEntityPresentationSettingsDefaults(): array
{
    return [
        'entity_layout_profile'   => 'default',
        'entity_pricing_variant'  => '',
        'entity_action_variant'   => '',
        'entity_list_pricing_variant' => '',
        'entity_list_inventory_variant' => '',
        'entity_list_progress_variant' => '',
        'entity_summary_width'    => '320',
        'entity_summary_sticky'   => '1',
        'entity_media_ratio'      => 'auto',
        'entity_spacing_scale'    => 'comfortable',
        'entity_action_size'      => 'md',
        'entity_list_show_filter_summary' => '1',
        'entity_list_category_navigation' => 'list',
        'entity_list_card_density' => 'comfortable',
        'entity_list_show_excerpt' => '1',
        'entity_list_excerpt_length' => '120',
        'entity_list_title_font' => '',
        'entity_list_text_font' => '',
        'entity_list_title_size' => '19',
        'entity_list_price_size' => '17',
        'entity_list_card_min_width' => '240',
        'entity_list_title_lines' => '2',
        'blog_layout' => 'list',
        'blog_columns' => '2',
        'blog_gap' => '24',
        'blog_card_border' => '1',
        'blog_card_shadow' => '1',
        'blog_card_radius' => '8',
        'blog_featured_image' => '1',
        'blog_image_height' => '208',
        'blog_image_ratio' => 'auto',
        'blog_show_author' => '1',
        'blog_show_date' => '1',
        'blog_show_excerpt' => '1',
        'blog_show_readmore' => '1',
        'blog_readmore_text' => 'Read more →',
        'single_max_width' => '768',
        'single_show_author' => '1',
        'single_show_date' => '1',
        'single_show_categories' => '1',
        'single_show_tags' => '1',
        'single_show_nav' => '1',
    ];
}

function cmsEntityPresentationThemeCompatKeys(): array
{
    return [
        'blog_layout',
        'blog_columns',
        'blog_gap',
        'blog_card_border',
        'blog_card_shadow',
        'blog_card_radius',
        'blog_featured_image',
        'blog_image_height',
        'blog_image_ratio',
        'blog_show_author',
        'blog_show_date',
        'blog_show_excerpt',
        'blog_show_readmore',
        'blog_readmore_text',
        'single_max_width',
        'single_show_author',
        'single_show_date',
        'single_show_categories',
        'single_show_tags',
        'single_show_nav',
    ];
}

function cmsValidateEntityPresentationSettings(array $input, ?array $defaults = null): array
{
    $defaults = is_array($defaults) ? $defaults : cmsEntityPresentationSettingsDefaults();
    $validated = [];

    $validated['entity_layout_profile'] = in_array(($input['entity_layout_profile'] ?? ''), cmsEntityLayoutProfiles(), true)
        ? (string)$input['entity_layout_profile']
        : $defaults['entity_layout_profile'];

    $validated['entity_pricing_variant'] = cmsNormalizeThemeBlockVariant(
        'pricing',
        trim((string)($input['entity_pricing_variant'] ?? $defaults['entity_pricing_variant']))
    );
    $validated['entity_action_variant'] = cmsNormalizeThemeBlockVariant(
        'action',
        trim((string)($input['entity_action_variant'] ?? $defaults['entity_action_variant']))
    );
    $validated['entity_list_pricing_variant'] = cmsNormalizeThemeBlockVariant(
        'list-card-pricing',
        trim((string)($input['entity_list_pricing_variant'] ?? $defaults['entity_list_pricing_variant']))
    );
    $validated['entity_list_inventory_variant'] = cmsNormalizeThemeBlockVariant(
        'list-card-inventory',
        trim((string)($input['entity_list_inventory_variant'] ?? $defaults['entity_list_inventory_variant']))
    );
    $validated['entity_list_progress_variant'] = cmsNormalizeThemeBlockVariant(
        'list-card-progress',
        trim((string)($input['entity_list_progress_variant'] ?? $defaults['entity_list_progress_variant']))
    );

    $summaryWidth = (int)($input['entity_summary_width'] ?? $defaults['entity_summary_width']);
    $validated['entity_summary_width'] = (string)max(260, min(420, $summaryWidth ?: 320));
    $validated['entity_summary_sticky'] = (int)(bool)($input['entity_summary_sticky'] ?? $defaults['entity_summary_sticky']);
    $validated['entity_media_ratio'] = in_array(($input['entity_media_ratio'] ?? ''), ['auto', '16:9', '4:3', '1:1'], true)
        ? (string)$input['entity_media_ratio']
        : $defaults['entity_media_ratio'];
    $validated['entity_spacing_scale'] = in_array(($input['entity_spacing_scale'] ?? ''), ['compact', 'comfortable', 'airy'], true)
        ? (string)$input['entity_spacing_scale']
        : $defaults['entity_spacing_scale'];
    $validated['entity_action_size'] = in_array(($input['entity_action_size'] ?? ''), ['sm', 'md', 'lg'], true)
        ? (string)$input['entity_action_size']
        : $defaults['entity_action_size'];
    $validated['entity_list_show_filter_summary'] = (int)(bool)($input['entity_list_show_filter_summary'] ?? $defaults['entity_list_show_filter_summary']);
    $validated['entity_list_category_navigation'] = in_array(($input['entity_list_category_navigation'] ?? ''), ['list', 'dropdown'], true)
        ? (string)$input['entity_list_category_navigation']
        : $defaults['entity_list_category_navigation'];
    $validated['entity_list_card_density'] = in_array(($input['entity_list_card_density'] ?? ''), ['compact', 'comfortable', 'airy'], true)
        ? (string)$input['entity_list_card_density']
        : $defaults['entity_list_card_density'];
    $validated['entity_list_show_excerpt'] = (int)(bool)($input['entity_list_show_excerpt'] ?? $defaults['entity_list_show_excerpt']);
    $excerptLength = (int)($input['entity_list_excerpt_length'] ?? $defaults['entity_list_excerpt_length']);
    $validated['entity_list_excerpt_length'] = (string)max(40, min(220, $excerptLength ?: 120));
    $validated['entity_list_title_font'] = cmsCustomizerSanitizeFontFamily((string)($input['entity_list_title_font'] ?? $defaults['entity_list_title_font']), (string)$defaults['entity_list_title_font']);
    $validated['entity_list_text_font'] = cmsCustomizerSanitizeFontFamily((string)($input['entity_list_text_font'] ?? $defaults['entity_list_text_font']), (string)$defaults['entity_list_text_font']);
    $titleSize = (int)($input['entity_list_title_size'] ?? $defaults['entity_list_title_size']);
    $validated['entity_list_title_size'] = (string)max(16, min(32, $titleSize));
    $priceSize = (int)($input['entity_list_price_size'] ?? $defaults['entity_list_price_size']);
    $validated['entity_list_price_size'] = (string)max(14, min(28, $priceSize));
    $cardMinWidth = (int)($input['entity_list_card_min_width'] ?? $defaults['entity_list_card_min_width']);
    $validated['entity_list_card_min_width'] = (string)max(200, min(340, $cardMinWidth));
    $titleLines = (int)($input['entity_list_title_lines'] ?? $defaults['entity_list_title_lines']);
    $validated['entity_list_title_lines'] = (string)max(1, min(4, $titleLines));

    $singleMaxWidth = (int)($input['single_max_width'] ?? $defaults['single_max_width']);
    $validated['single_max_width'] = (string)max(480, min(1200, $singleMaxWidth ?: 768));

    $validated['blog_layout'] = in_array(($input['blog_layout'] ?? ''), ['list', 'grid', 'cards'], true)
        ? (string)$input['blog_layout']
        : $defaults['blog_layout'];

    $blogColumns = (int)($input['blog_columns'] ?? $defaults['blog_columns']);
    $validated['blog_columns'] = (string)max(2, min(4, $blogColumns ?: 2));

    $blogGap = (int)($input['blog_gap'] ?? $defaults['blog_gap']);
    $validated['blog_gap'] = (string)max(0, min(64, $blogGap));

    foreach ([
        'blog_card_border',
        'blog_card_shadow',
        'blog_featured_image',
        'blog_show_author',
        'blog_show_date',
        'blog_show_excerpt',
        'blog_show_readmore',
        'single_show_author',
        'single_show_date',
        'single_show_categories',
        'single_show_tags',
        'single_show_nav',
    ] as $key) {
        $validated[$key] = (int)(bool)($input[$key] ?? $defaults[$key]);
    }

    $blogCardRadius = (int)($input['blog_card_radius'] ?? $defaults['blog_card_radius']);
    $validated['blog_card_radius'] = (string)max(0, min(24, $blogCardRadius));

    $blogImageHeight = (int)($input['blog_image_height'] ?? $defaults['blog_image_height']);
    $validated['blog_image_height'] = (string)max(100, min(500, $blogImageHeight));

    $validated['blog_image_ratio'] = in_array(($input['blog_image_ratio'] ?? ''), ['auto', '16:9', '4:3', '1:1'], true)
        ? (string)$input['blog_image_ratio']
        : $defaults['blog_image_ratio'];

    $validated['blog_readmore_text'] = htmlspecialchars(trim((string)($input['blog_readmore_text'] ?? $defaults['blog_readmore_text'])), ENT_QUOTES);
    if ($validated['blog_readmore_text'] === '') {
        $validated['blog_readmore_text'] = $defaults['blog_readmore_text'];
    }

    return $validated;
}

/**
 * Render <style> tag with general/body CSS custom properties from Colors settings.
 * Injected into <head> or start of <body> in the public template.
 */

function cmsRenderColorsStyle(object $db): string
{
    $cached = cmsCustomizerFragmentCacheGet('colors_style');
    if (is_array($cached) && array_key_exists('html', $cached)) {
        return (string)$cached['html'];
    }

    if (!cmsCustomizerSectionExists($db, 'colors')) {
        cmsCustomizerFragmentCacheSet('colors_style', ['html' => ''], ['cms:customizer:colors']);
        return '';
    }

    $data = cmsCustomizerGet($db, 'colors');
    $s = $data['settings'];
    $storefrontVarsCss  = ':root{';
    $storefrontVarsCss .= '--storefront-surface-bg:' . ($s['storefront_surface_bg'] ?? '#ffffff') . ';';
    $storefrontVarsCss .= '--storefront-surface-border:' . ($s['storefront_surface_border'] ?? '#e2e8f0') . ';';
    $storefrontVarsCss .= '--storefront-price-color:' . ($s['storefront_price_color'] ?? '#0f172a') . ';';
    $storefrontVarsCss .= '--storefront-badge-bg:' . ($s['storefront_badge_bg'] ?? '#fee2e2') . ';';
    $storefrontVarsCss .= '--storefront-badge-text:' . ($s['storefront_badge_text'] ?? '#b91c1c') . ';';
    $storefrontVarsCss .= '--storefront-cta-bg:' . ($s['storefront_cta_bg'] ?? '#0284c7') . ';';
    $storefrontVarsCss .= '--storefront-cta-text:' . ($s['storefront_cta_text'] ?? '#ffffff') . ';';
    $storefrontVarsCss .= '--storefront-secondary-bg:' . ($s['storefront_secondary_bg'] ?? '#ffffff') . ';';
    $storefrontVarsCss .= '--storefront-secondary-text:' . ($s['storefront_secondary_text'] ?? '#334155') . ';';
    $storefrontVarsCss .= '--storefront-secondary-border:' . ($s['storefront_secondary_border'] ?? '#cbd5e1') . ';';
    $storefrontVarsCss .= '--storefront-success-bg:' . ($s['storefront_success_bg'] ?? '#ecfdf5') . ';';
    $storefrontVarsCss .= '--storefront-success-text:' . ($s['storefront_success_text'] ?? '#047857') . ';';
    $storefrontVarsCss .= '--storefront-warning-bg:' . ($s['storefront_warning_bg'] ?? '#fffbeb') . ';';
    $storefrontVarsCss .= '--storefront-warning-text:' . ($s['storefront_warning_text'] ?? '#b45309') . ';';
    $storefrontVarsCss .= '--storefront-danger-bg:' . ($s['storefront_danger_bg'] ?? '#fef2f2') . ';';
    $storefrontVarsCss .= '--storefront-danger-text:' . ($s['storefront_danger_text'] ?? '#dc2626') . ';';
    $storefrontVarsCss .= '--container-width:' . ($s['container_width'] ?? '1200') . 'px;';
    $storefrontVarsCss .= '--container-max:' . ($s['container_width'] ?? '1200') . 'px;';
    $storefrontVarsCss .= '}';
    if (!cmsShouldRenderCustomizerPresentationCss('colors', $data['settings'])) {
        if (cmsActiveCustomizerScope() !== 'ecommerce') {
            cmsCustomizerFragmentCacheSet('colors_style', ['html' => ''], ['cms:customizer:colors']);
            return '';
        }
        $html = '<style id="cz-colors-override">' . $storefrontVarsCss . '.entity-commerce-poc{--container-max:var(--container-width);}</style>';
        cmsCustomizerFragmentCacheSet('colors_style', ['html' => $html], ['cms:customizer:colors']);
        return $html;
    }

    $fontBody = cmsCustomizerSanitizeFontFamily((string)($s['font_body'] ?? 'Inter'), 'Inter');
    $fontHeading = cmsCustomizerSanitizeFontFamily((string)($s['font_heading'] ?? 'Inter'), 'Inter');
    $googleFontsHtml = cmsCustomizerFontStylesheetHtml([$fontBody, $fontHeading]);

    $css  = ':root{';
    $css .= '--color-primary:' . ($s['color_primary'] ?? '#3b82f6') . ';';
    $css .= '--color-secondary:' . ($s['color_secondary'] ?? '#64748b') . ';';
    $css .= '--color-accent:' . ($s['color_accent'] ?? '#f59e0b') . ';';
    $css .= '--color-background:' . ($s['body_bg_color'] ?? '#ffffff') . ';';
    $css .= '--color-text:' . ($s['body_text_color'] ?? '#1e293b') . ';';
    $css .= '--color-text-light:' . ($s['body_text_light'] ?? '#64748b') . ';';
    $css .= '--color-link:' . ($s['body_link_color'] ?? '#3b82f6') . ';';
    $css .= '--color-link-hover:' . ($s['body_link_hover'] ?? '#2563eb') . ';';
    $css .= '--color-border:' . ($s['border_color'] ?? '#e2e8f0') . ';';
    $css .= '--color-light-bg:' . ($s['light_bg_color'] ?? '#f8fafc') . ';';
    $css .= '--storefront-surface-bg:' . ($s['storefront_surface_bg'] ?? '#ffffff') . ';';
    $css .= '--storefront-surface-border:' . ($s['storefront_surface_border'] ?? '#e2e8f0') . ';';
    $css .= '--storefront-price-color:' . ($s['storefront_price_color'] ?? '#0f172a') . ';';
    $css .= '--storefront-badge-bg:' . ($s['storefront_badge_bg'] ?? '#fee2e2') . ';';
    $css .= '--storefront-badge-text:' . ($s['storefront_badge_text'] ?? '#b91c1c') . ';';
    $css .= '--storefront-cta-bg:' . ($s['storefront_cta_bg'] ?? '#0284c7') . ';';
    $css .= '--storefront-cta-text:' . ($s['storefront_cta_text'] ?? '#ffffff') . ';';
    $css .= '--storefront-secondary-bg:' . ($s['storefront_secondary_bg'] ?? '#ffffff') . ';';
    $css .= '--storefront-secondary-text:' . ($s['storefront_secondary_text'] ?? '#334155') . ';';
    $css .= '--storefront-secondary-border:' . ($s['storefront_secondary_border'] ?? '#cbd5e1') . ';';
    $css .= '--storefront-success-bg:' . ($s['storefront_success_bg'] ?? '#ecfdf5') . ';';
    $css .= '--storefront-success-text:' . ($s['storefront_success_text'] ?? '#047857') . ';';
    $css .= '--storefront-warning-bg:' . ($s['storefront_warning_bg'] ?? '#fffbeb') . ';';
    $css .= '--storefront-warning-text:' . ($s['storefront_warning_text'] ?? '#b45309') . ';';
    $css .= '--storefront-danger-bg:' . ($s['storefront_danger_bg'] ?? '#fef2f2') . ';';
    $css .= '--storefront-danger-text:' . ($s['storefront_danger_text'] ?? '#dc2626') . ';';
    $css .= '--font-body:' . cmsCustomizerFontCssValue($fontBody, 'system-ui,-apple-system,BlinkMacSystemFont,sans-serif') . ';';
    $css .= '--font-heading:' . cmsCustomizerFontCssValue($fontHeading, 'system-ui,-apple-system,BlinkMacSystemFont,sans-serif') . ';';
    $css .= '--container-width:' . ($s['container_width'] ?? '1200') . 'px;';
    $css .= '--container-max:' . ($s['container_width'] ?? '1200') . 'px;';
    $css .= '--radius-sm:' . round(((float)($s['border_radius'] ?? 0.5)) * 0.5, 2) . 'rem;';
    $css .= '--radius-md:' . ($s['border_radius'] ?? '0.5') . 'rem;';
    $css .= '--radius-lg:' . round(((float)($s['border_radius'] ?? 0.5)) * 2, 2) . 'rem;';
    $css .= '}';

    // Body base
    $css .= 'html{font-size:' . ($s['font_size_base'] ?? '16') . 'px;}';
    $css .= 'body{font-family:var(--font-body);line-height:' . ($s['line_height'] ?? '1.6') . ';';
    $css .= 'color:var(--color-text);background-color:var(--color-background);}';
    $css .= 'button,input,select,textarea{font-family:inherit;font-size:inherit;line-height:inherit;color:inherit;}';

    // Links
    $css .= 'a{color:var(--color-link);}';
    $css .= 'a:hover{color:var(--color-link-hover);}';

    // Headings
    $headingColor = trim((string)($s['heading_color'] ?? ''));
    $css .= 'h1,h2,h3,h4,h5,h6{font-family:var(--font-heading);';
    if ($headingColor !== '') {
        $css .= 'color:' . $headingColor . ';';
    }
    $css .= '}';
    $css .= '.site-logo{font-family:var(--font-heading);}';
    $css .= '.site-tagline,.header-topbar,.site-header,.footer-widgets,.footer-bottom,.cms-sidebar-wrap,.cms-entity-view,.cms-entity-list{font-family:var(--font-body);}';
    $css .= 'h1{font-size:' . ($s['h1_size'] ?? '2.5') . 'rem;}';
    $css .= 'h2{font-size:' . ($s['h2_size'] ?? '2') . 'rem;}';
    $css .= 'h3{font-size:' . ($s['h3_size'] ?? '1.5') . 'rem;}';
    $css .= 'h4{font-size:' . ($s['h4_size'] ?? '1.25') . 'rem;}';
    $css .= 'body.entity-commerce-poc{font-family:var(--font-body);}';
    $css .= '.entity-commerce-poc{--container-max:var(--container-width);}';
    $css .= '.entity-commerce-poc .poc-branding__tag,.entity-commerce-poc .poc-nav a,.entity-commerce-poc .poc-footer__menu a,.entity-commerce-poc .nav-menu a,.entity-commerce-poc .nav-menu-sub a,.entity-commerce-poc .header-cta,.entity-commerce-poc .poc-kicker,.entity-commerce-poc .poc-summary,.entity-commerce-poc .poc-entity-view__header-note,.entity-commerce-poc .poc-product-card__excerpt,.entity-commerce-poc .poc-footer__copy,.entity-commerce-poc .poc-footer__meta,.entity-commerce-poc .poc-progress-label,.entity-commerce-poc .poc-price-pill,.entity-commerce-poc .poc-stock-tag,.entity-commerce-poc .poc-pagination__link,.entity-commerce-poc .poc-empty-state,.entity-commerce-poc .poc-pricing-block__label,.entity-commerce-poc .poc-action-strip__label,.entity-commerce-poc .poc-pricing-block__previous,.entity-commerce-poc .poc-pricing-block__badge,.entity-commerce-poc .poc-action-strip__button,.entity-commerce-poc .poc-action-strip__status,.entity-commerce-poc .cms-entity-meta,.entity-commerce-poc .cms-progress-block,.entity-commerce-poc .cms-pricing-block,.entity-commerce-poc .cms-inventory-block,.entity-commerce-poc .cms-action-block,.entity-commerce-poc .cms-gallery-block,.entity-commerce-poc .cms-lessons-block{font-family:var(--font-body);}';
    $css .= '.entity-commerce-poc .site-tagline,.entity-commerce-poc .footer-widget-col .widget-content,.entity-commerce-poc .footer-widget-col .widget-content a,.entity-commerce-poc .footer-widget-col .footer-menu li a,.entity-commerce-poc .footer-widget-col .contact-item,.entity-commerce-poc .footer-widget-col .contact-item a,.entity-commerce-poc .footer-bottom,.entity-commerce-poc .footer-bottom a{font-family:var(--font-body);}';
    $css .= '.entity-commerce-poc .site-logo,.entity-commerce-poc .poc-branding__mark,.entity-commerce-poc .poc-title,.entity-commerce-poc .poc-product-card__title,.entity-commerce-poc .poc-pricing-block__value,.entity-commerce-poc .cms-price-current{font-family:var(--font-heading);}';
    $css .= '.cms-entity-summary-stack-rail > *{background:var(--storefront-surface-bg);border:1px solid var(--storefront-surface-border);border-radius:var(--radius-lg);box-shadow:0 8px 24px rgba(15,23,42,0.05);}';
    $css .= '.cms-entity-summary-stack-inline > *{border-radius:var(--radius-lg);}';
    $css .= '.cms-price-current{color:var(--storefront-price-color);}';
    $css .= '.cms-price-badge{background:var(--storefront-badge-bg);color:var(--storefront-badge-text);border-color:var(--storefront-badge-bg);}';
    $css .= '.cms-action-block .cms-btn-primary{background:var(--storefront-cta-bg);color:var(--storefront-cta-text);border:1px solid var(--storefront-cta-bg);}';
    $css .= '.cms-action-block .cms-btn-primary:hover{filter:brightness(0.96);}';
    $css .= '.cms-action-block .cms-btn-secondary{background:var(--storefront-secondary-bg);color:var(--storefront-secondary-text);border:1px solid var(--storefront-secondary-border);}';
    $css .= '.cms-action-block .cms-btn-secondary:hover{background:var(--color-light-bg);}';
    $css .= '.cms-action-block .cms-btn-disabled{background:var(--color-light-bg);color:var(--color-text-light);border:1px solid var(--color-border);}';
    $css .= '.cms-inventory-pill{border-width:1px;border-style:solid;border-radius:999px;}';
    $css .= '.cms-inventory-pill--ok{background:var(--storefront-success-bg);color:var(--storefront-success-text);border-color:var(--storefront-success-bg);}';
    $css .= '.cms-inventory-pill--low{background:var(--storefront-warning-bg);color:var(--storefront-warning-text);border-color:var(--storefront-warning-bg);}';
    $css .= '.cms-inventory-pill--out{background:var(--storefront-danger-bg);color:var(--storefront-danger-text);border-color:var(--storefront-danger-bg);}';

    $html = $googleFontsHtml . '<style id="cz-colors-override">' . $css . '</style>';
    cmsCustomizerFragmentCacheSet('colors_style', ['html' => $html], ['cms:customizer:colors']);
    return $html;
}

// ── Custom Code / Advanced ──────────────────────────────────────────────

/**
 * Default settings for the Custom Code customizer section.
 */

function cmsCustomCodeSettingsDefaults(): array
{
    return [
        // Custom CSS
        'custom_css'              => '',
        // Code injection
        'head_code'               => '',
        'body_end_code'           => '',
        // Scroll to Top button
        'scroll_to_top'           => 0,
        'scroll_to_top_bg'        => '#3b82f6',
        'scroll_to_top_color'     => '#ffffff',
        'scroll_to_top_size'      => '44',
        'scroll_to_top_radius'    => '50',
        'scroll_to_top_position'  => 'right',
        'scroll_to_top_offset'    => '24',
        // Smooth scroll
        'smooth_scroll'           => 0,
        // Page transition
        'page_transition'         => 0,
        'page_transition_style'   => 'fade',
    ];
}

/**
 * Validate and sanitize Custom Code customizer settings.
 */

function cmsValidateCustomCodeSettings(array $input): array
{
    $defaults = cmsCustomCodeSettingsDefaults();
    $validated = [];

    // Custom CSS — strip </style> injection
    $css = (string)($input['custom_css'] ?? '');
    $css = str_ireplace('</style>', '', $css);
    $validated['custom_css'] = $css;

    // Head code — allow any HTML (admin-only, trusted)
    $validated['head_code'] = (string)($input['head_code'] ?? '');

    // Body end code — allow any HTML
    $validated['body_end_code'] = (string)($input['body_end_code'] ?? '');

    // Scroll to top: boolean toggle
    $validated['scroll_to_top'] = !empty($input['scroll_to_top']) ? 1 : 0;

    // Colors
    foreach (['scroll_to_top_bg', 'scroll_to_top_color'] as $key) {
        $val = trim((string)($input[$key] ?? $defaults[$key]));
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $val) || preg_match('/^rgba?\(/', $val)) {
            $validated[$key] = $val;
        } else {
            $validated[$key] = $defaults[$key];
        }
    }

    // Numeric: size, radius, offset
    $size = (int)($input['scroll_to_top_size'] ?? 44);
    $validated['scroll_to_top_size'] = (string)max(28, min(72, $size));

    $radius = (int)($input['scroll_to_top_radius'] ?? 50);
    $validated['scroll_to_top_radius'] = (string)max(0, min(50, $radius));

    $offset = (int)($input['scroll_to_top_offset'] ?? 24);
    $validated['scroll_to_top_offset'] = (string)max(8, min(80, $offset));

    // Position: left or right
    $pos = (string)($input['scroll_to_top_position'] ?? 'right');
    $validated['scroll_to_top_position'] = in_array($pos, ['left', 'right'], true) ? $pos : 'right';

    // Smooth scroll
    $validated['smooth_scroll'] = !empty($input['smooth_scroll']) ? 1 : 0;

    // Page transition
    $validated['page_transition'] = !empty($input['page_transition']) ? 1 : 0;
    $style = (string)($input['page_transition_style'] ?? 'fade');
    $validated['page_transition_style'] = in_array($style, ['fade', 'slide-up', 'zoom'], true) ? $style : 'fade';

    return $validated;
}

/**
 * Render custom code output for the public template.
 * Returns an associative array with keys: custom_css, head_code, body_end_code
 */

function cmsRenderCustomCodeOutput(object $db): array
{
    $cached = cmsCustomizerFragmentCacheGet('custom_code_output');
    if (is_array($cached) && array_key_exists('custom_css', $cached)) {
        return $cached;
    }

    $output = ['custom_css' => '', 'head_code' => '', 'body_end_code' => ''];

    if (!cmsCustomizerSectionExists($db, 'custom_code')) {
        cmsCustomizerFragmentCacheSet('custom_code_output', $output, ['cms:customizer:custom_code']);
        return $output;
    }

    $data = cmsCustomizerGet($db, 'custom_code');
    $s = $data['settings'];

    // Custom CSS
    $customCss = trim((string)($s['custom_css'] ?? ''));
    if ($customCss !== '') {
        $output['custom_css'] = '<style id="cz-custom-css">' . $customCss . '</style>';
    }

    // Head code (raw HTML)
    $headCode = trim((string)($s['head_code'] ?? ''));
    if ($headCode !== '') {
        $output['head_code'] = '<!-- Custom Head Code -->' . "\n" . $headCode;
    }

    // Body end code + scroll-to-top + smooth scroll + page transition
    $bodyEnd = '';

    // Raw body-end code
    $bodyEndCode = trim((string)($s['body_end_code'] ?? ''));
    if ($bodyEndCode !== '') {
        $bodyEnd .= '<!-- Custom Body End Code -->' . "\n" . $bodyEndCode . "\n";
    }

    // Smooth scroll CSS
    if (!empty($s['smooth_scroll'])) {
        $bodyEnd .= '<style id="cz-smooth-scroll">html{scroll-behavior:smooth;}</style>' . "\n";
    }

    // Page transition CSS + JS
    if (!empty($s['page_transition'])) {
        $style = htmlspecialchars($s['page_transition_style'] ?? 'fade');
        $transCSS  = '<style id="cz-page-transition">';
        if ($style === 'fade') {
            $transCSS .= 'body:not(.cz-loaded){opacity:0;}body.cz-loaded{animation:czFadeIn 0.5s cubic-bezier(0.4,0,0.2,1) both;}';
            $transCSS .= '@keyframes czFadeIn{from{opacity:0}to{opacity:1}}';
        } elseif ($style === 'slide-up') {
            $transCSS .= 'body:not(.cz-loaded){opacity:0;}body.cz-loaded{animation:czSlideUp 0.55s cubic-bezier(0.4,0,0.2,1) both;}';
            $transCSS .= '@keyframes czSlideUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}';
        } elseif ($style === 'zoom') {
            $transCSS .= 'body:not(.cz-loaded){opacity:0;}body.cz-loaded{animation:czZoomIn 0.55s cubic-bezier(0.4,0,0.2,1) both;}';
            $transCSS .= '@keyframes czZoomIn{from{opacity:0;transform:scale(0.99)}to{opacity:1;transform:none}}';
        }
        $transCSS .= '</style>';
        $bodyEnd .= $transCSS . "\n";
        // Use rAF to add class at earliest paint frame — avoids flash/jump
        $bodyEnd .= '<script>(function(){function r(){document.body.classList.add("cz-loaded")}if(document.readyState!=="loading"){requestAnimationFrame(r)}else{document.addEventListener("DOMContentLoaded",function(){requestAnimationFrame(r)})}})();</script>' . "\n";
    }

    // Scroll to top button
    if (!empty($s['scroll_to_top'])) {
        $bg     = htmlspecialchars($s['scroll_to_top_bg'] ?? '#3b82f6');
        $color  = htmlspecialchars($s['scroll_to_top_color'] ?? '#ffffff');
        $size   = (int)($s['scroll_to_top_size'] ?? 44);
        $radius = (int)($s['scroll_to_top_radius'] ?? 50);
        $pos    = ($s['scroll_to_top_position'] ?? 'right') === 'left' ? 'left' : 'right';
        $offset = (int)($s['scroll_to_top_offset'] ?? 24);

        $btnStyle  = "position:fixed;bottom:{$offset}px;{$pos}:{$offset}px;z-index:9999;";
        $btnStyle .= "width:{$size}px;height:{$size}px;border-radius:{$radius}%;";
        $btnStyle .= "background:{$bg};color:{$color};border:none;cursor:pointer;";
        $btnStyle .= "display:flex;align-items:center;justify-content:center;";
        $btnStyle .= "opacity:0;pointer-events:none;transition:opacity 0.3s ease,transform 0.3s ease;";
        $btnStyle .= "transform:translateY(12px);box-shadow:0 2px 8px rgba(0,0,0,0.15);";

        $iconSize = round($size * 0.4);
        $btnHtml  = '<button id="cz-scroll-top" aria-label="Scroll to top" style="' . $btnStyle . '">';
        $btnHtml .= '<svg width="' . $iconSize . '" height="' . $iconSize . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 15 12 9 6 15"/></svg>';
        $btnHtml .= '</button>';

        $btnJs  = '<script>';
        $btnJs .= '(function(){var b=document.getElementById("cz-scroll-top");if(!b)return;';
        $btnJs .= 'window.addEventListener("scroll",function(){if(window.scrollY>300){b.style.opacity="1";b.style.pointerEvents="auto";b.style.transform="translateY(0)";}else{b.style.opacity="0";b.style.pointerEvents="none";b.style.transform="translateY(12px)";}});';
        $btnJs .= 'b.addEventListener("click",function(){window.scrollTo({top:0,behavior:"smooth"});});';
        $btnJs .= '})();';
        $btnJs .= '</script>';

        $bodyEnd .= $btnHtml . "\n" . $btnJs . "\n";
    }

    $output['body_end_code'] = $bodyEnd;

    cmsCustomizerFragmentCacheSet('custom_code_output', $output, ['cms:customizer:custom_code']);
    return $output;
}

function cmsSidebarSettingsDefaults(): array
{
    $targets = cmsSidebarTemplateTargets();
    $defaultScope = !empty($targets) ? (string)($targets[0]['key'] ?? 'home') : 'home';
    return [
        'enabled'                 => 0,
        'scope_mode'              => 'general', // general | exclude_templates | template
        'template_scope'          => $defaultScope,
        'template_rules'          => [],
        'placement'               => 'right',   // left | right
        'width'                   => '300',
        'gap'                     => '32',
        'sticky'                  => 0,
        'widget_bg_color'         => '#ffffff',
        'widget_text_color'       => '#334155',
        'widget_title_color'      => '#0f172a',
        'widget_border_color'     => '#e2e8f0',
        'widget_link_color'       => '#2563eb',
        'widget_link_hover_color' => '#1d4ed8',
        'widget_padding'          => '16',
        'widget_radius'           => '10',
    ];
}

function cmsSidebarNormalizeTemplateRules(mixed $input, array $allowedTargets): array
{
    $rules = [];

    if (is_array($input)) {
        $rules = $input;
    } elseif (is_string($input)) {
        $trimmed = trim($input);
        if ($trimmed !== '') {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                $rules = $decoded;
            } else {
                $rules = preg_split('/\s*,\s*/', $trimmed) ?: [];
            }
        }
    }

    $normalized = [];
    foreach ($rules as $rule) {
        $key = trim((string)$rule);
        if ($key === '' || !in_array($key, $allowedTargets, true) || in_array($key, $normalized, true)) {
            continue;
        }
        $normalized[] = $key;
    }

    return $normalized;
}

function cmsSidebarResolvedTemplateRules(array $settings): array
{
    $allowedTargets = array_map(static fn($t) => (string)($t['key'] ?? ''), cmsSidebarTemplateTargets());
    $fallbackTarget = !empty($allowedTargets[0]) ? $allowedTargets[0] : (string)(cmsSidebarSettingsDefaults()['template_scope'] ?? 'home');
    $rules = cmsSidebarNormalizeTemplateRules($settings['template_rules'] ?? [], $allowedTargets);

    $legacyTemplateScope = trim((string)($settings['template_scope'] ?? ''));
    if ($rules === [] && $legacyTemplateScope !== '' && in_array($legacyTemplateScope, $allowedTargets, true)) {
        $rules[] = $legacyTemplateScope;
    }

    if ($rules === [] && $fallbackTarget !== '') {
        $scopeMode = (string)($settings['scope_mode'] ?? 'general');
        if ($scopeMode === 'template') {
            $rules[] = $fallbackTarget;
        }
    }

    return $rules;
}

function cmsSidebarTemplateMatchesScope(array $settings, string $templateKey): bool
{
    $scopeMode = (string)($settings['scope_mode'] ?? 'general');
    if (!in_array($scopeMode, ['general', 'exclude_templates', 'template'], true)) {
        $scopeMode = 'general';
    }

    $rules = cmsSidebarResolvedTemplateRules($settings);

    return match ($scopeMode) {
        'exclude_templates' => !in_array($templateKey, $rules, true),
        'template' => in_array($templateKey, $rules, true),
        default => true,
    };
}

function cmsHeaderSettingsDefaults(): array
{
    return [
        'layout'                 => 'default',
        'sticky'                 => 1,
        'show_tagline'           => 0,
        'show_search'            => 0,
        'show_cta_button'        => 0,
        'cta_text'               => 'Get Started',
        'cta_url'                => '',
        'cta_style'              => 'primary',
        'inner_width'            => 'contained',
        'logo_image_url'         => '',
        'logo_max_height'        => '40',
        'favicon_url'            => '',
        'padding_top'            => '12',
        'padding_bottom'         => '12',
        'bg_color'               => '#ffffff',
        'text_color'             => '#1f2937',
        'link_color'             => '#1f2937',
        'link_hover_color'       => '#2563eb',
        'dropdown_bg_color'      => '#ffffff',
        'dropdown_text_color'    => '#1f2937',
        'dropdown_hover_bg_color'=> '#f8fafc',
        'dropdown_hover_text_color' => '#2563eb',
        'dropdown_border_color'  => '#e5e7eb',
        'dropdown_min_width'     => '220',
        'dropdown_radius'        => '8',
        'dropdown_item_padding_y'=> '10',
        'logo_color'             => '#1f2937',
        'border_color'           => '#e5e7eb',
        'mobile_bg_color'        => '#ffffff',
        'mobile_text_color'      => '#1f2937',
        'height'                 => 'auto',
        'menu_location'          => 'primary',
        'transparent_home'       => 0,
        'header_bg_opacity'      => 100,
        'transparent_text_color' => '#ffffff',
        'transparent_logo_color' => '#ffffff',
        // Top bar (widget strip above the header)
        'show_topbar'            => 1,
        'topbar_bg_color'        => '#1e293b',
        'topbar_text_color'      => '#e2e8f0',
        'topbar_link_color'      => '#93c5fd',
        'topbar_link_hover_color'=> '#ffffff',
        'topbar_font_size'       => '13',
        'topbar_font_weight'     => 'normal',
        'topbar_align'           => 'center',
        'topbar_padding_y'       => '6',
        'topbar_border_bottom'   => 1,
        // Mobile settings
        'mobile_menu_style'      => 'dropdown',     // dropdown | canvas
        'mobile_canvas_direction'=> 'left',          // left | right
        'mobile_canvas_width'    => '300',
        'mobile_menu_location'   => '',           // separate menu for canvas (blank = use desktop menu_location)
        'mobile_menu_align'      => 'left',
        'mobile_hover_bg_color'  => '#f1f5f9',
        'mobile_active_bg_color' => '#e2e8f0',
        'mobile_logo_url'        => '',
        'mobile_logo_max_height' => '36',
        'mobile_breakpoint'      => '768',
        'mobile_close_on_link'   => 1,
        'mobile_overlay'         => 1,
        'mobile_overlay_color'   => 'rgba(0,0,0,0.5)',
    ];
}

// ── Theme Layout Settings ─────────────────────────────────────────────

function cmsEntityLayoutProfiles(): array
{
    return ['default', 'commerce', 'content'];
}

function cmsThemeBlockVariantOptions(): array
{
    return [
        'pricing' => ['', 'compact', 'featured', 'minimal'],
        'action'  => ['', 'inline', 'sticky-footer'],
        'inventory' => ['', 'compact'],
        'list-card-pricing' => ['', 'featured', 'minimal'],
        'list-card-inventory' => ['', 'compact'],
        'list-card-progress' => ['', 'inline'],
    ];
}

function cmsThemeBlockVariantSettingMap(): array
{
    return [
        'pricing' => 'entity_pricing_variant',
        'action' => 'entity_action_variant',
        'list-card-pricing' => 'entity_list_pricing_variant',
        'list-card-inventory' => 'entity_list_inventory_variant',
        'list-card-progress' => 'entity_list_progress_variant',
    ];
}

function cmsThemeManifestBlockVariants(?array $manifest = null): array
{
    $manifest = is_array($manifest) ? $manifest : cmsActiveThemeManifest();
    $defaults = $manifest['entity_view_defaults']['block_variants'] ?? $manifest['block_variants'] ?? [];
    return is_array($defaults) ? $defaults : [];
}

function cmsNormalizeThemeBlockVariant(string $block, string $variant): string
{
    $options = cmsThemeBlockVariantOptions();
    $allowed = $options[$block] ?? [''];
    return in_array($variant, $allowed, true) ? $variant : '';
}

function cmsEntityPresentationConfig(array $themeSettings): array
{
    $presentationSettings = cmsValidateEntityPresentationSettings($themeSettings);
    $profile = (string)$presentationSettings['entity_layout_profile'];
    $pricingVariant = cmsNormalizeThemeBlockVariant('pricing', trim((string)$presentationSettings['entity_pricing_variant']));
    $actionVariant = cmsNormalizeThemeBlockVariant('action', trim((string)$presentationSettings['entity_action_variant']));
    $listPricingVariant = cmsNormalizeThemeBlockVariant('list-card-pricing', trim((string)$presentationSettings['entity_list_pricing_variant']));
    $listInventoryVariant = cmsNormalizeThemeBlockVariant('list-card-inventory', trim((string)$presentationSettings['entity_list_inventory_variant']));
    $listProgressVariant = cmsNormalizeThemeBlockVariant('list-card-progress', trim((string)$presentationSettings['entity_list_progress_variant']));
    $summaryWidth = (int)$presentationSettings['entity_summary_width'];
    $mediaRatio = (string)$presentationSettings['entity_media_ratio'];
    $spacingScale = (string)$presentationSettings['entity_spacing_scale'];
    $actionSize = (string)$presentationSettings['entity_action_size'];
    $summarySticky = !empty($presentationSettings['entity_summary_sticky']) ? 1 : 0;
    $listShowFilterSummary = !empty($presentationSettings['entity_list_show_filter_summary']) ? 1 : 0;
    $listCategoryNavigation = (string)($presentationSettings['entity_list_category_navigation'] ?? 'list');
    $listCardDensity = (string)($presentationSettings['entity_list_card_density'] ?? 'comfortable');
    $listShowExcerpt = !empty($presentationSettings['entity_list_show_excerpt']) ? 1 : 0;
    $listExcerptLength = (int)($presentationSettings['entity_list_excerpt_length'] ?? 120);
    $listTitleFont = (string)($presentationSettings['entity_list_title_font'] ?? '');
    $listTextFont = (string)($presentationSettings['entity_list_text_font'] ?? '');
    $listTitleSize = (int)($presentationSettings['entity_list_title_size'] ?? 19);
    $listPriceSize = (int)($presentationSettings['entity_list_price_size'] ?? 17);
    $listCardMinWidth = (int)($presentationSettings['entity_list_card_min_width'] ?? 240);
    $listTitleLines = (int)($presentationSettings['entity_list_title_lines'] ?? 2);

    return [
        'layout_profile'      => $profile,
        'pricing_variant'     => $pricingVariant !== '' ? $pricingVariant : 'default',
        'action_variant'      => $actionVariant !== '' ? $actionVariant : 'default',
        'list_pricing_variant' => $listPricingVariant !== '' ? $listPricingVariant : 'default',
        'list_inventory_variant' => $listInventoryVariant !== '' ? $listInventoryVariant : 'default',
        'list_progress_variant' => $listProgressVariant !== '' ? $listProgressVariant : 'default',
        'header_before_media' => $profile === 'content',
        'summary_mode'        => $profile === 'commerce' ? 'rail' : 'flow',
        'summary_after_body'  => $profile === 'content',
        'root_class'          => 'cms-entity-profile-' . $profile,
        'summary_width'       => $summaryWidth,
        'summary_sticky'      => $summarySticky,
        'media_ratio'         => $mediaRatio,
        'spacing_scale'       => $spacingScale,
        'action_size'         => $actionSize,
        'list_show_filter_summary' => $listShowFilterSummary,
        'list_category_navigation' => $listCategoryNavigation,
        'list_card_density'   => $listCardDensity,
        'list_show_excerpt'   => $listShowExcerpt,
        'list_excerpt_length' => $listExcerptLength,
        'list_title_font'     => $listTitleFont,
        'list_text_font'      => $listTextFont,
        'list_title_size'     => $listTitleSize,
        'list_price_size'     => $listPriceSize,
        'list_card_min_width' => $listCardMinWidth,
        'list_title_lines'    => $listTitleLines,
    ];
}

function cmsCanonicalEntityPresentationConfig(array $themeSettings, array $context = []): array
{
    $presentationSettings = cmsValidateEntityPresentationSettings($themeSettings);
    if (function_exists('cmsCanonicalEntityRenderFamily') && cmsCanonicalEntityRenderFamily($context) === 'content') {
        $presentationSettings['entity_layout_profile'] = 'content';
    }

    return cmsEntityPresentationConfig($presentationSettings);
}

/**
 * Default settings for the Theme Layout customizer section.
 * Controls global layout structure, container sizing, and blog listing style.
 */
function cmsThemeLayoutSettingsDefaults(): array
{
    return [
        'layout_mode' => 'contained',
        'site_max_width' => '1280',
        'content_max_width' => '768',
        'content_padding_x' => '16',
        'content_padding_top' => '32',
        'content_padding_bottom' => '32',
    ];
}

/**
 * Validate and sanitize Theme Layout customizer settings.
 */
function cmsValidateThemeLayoutSettings(array $input): array
{
    $defaults = cmsThemeLayoutSettingsDefaults();
    $validated = [];

    // Layout mode
    $validated['layout_mode'] = in_array(($input['layout_mode'] ?? ''), ['contained', 'boxed', 'full-width'], true)
        ? (string)$input['layout_mode'] : $defaults['layout_mode'];

    // Width fields (px): clamp to reasonable ranges
    $widthFields = [
        'site_max_width'    => [960, 1920, 1280],
        'content_max_width' => [480, 1200, 768],
    ];
    foreach ($widthFields as $key => [$min, $max, $default]) {
        $v = (int)($input[$key] ?? $defaults[$key]);
        $validated[$key] = (string)max($min, min($max, $v ?: $default));
    }

    // Padding fields (px): 0-100
    foreach (['content_padding_x', 'content_padding_top', 'content_padding_bottom'] as $key) {
        $v = (int)($input[$key] ?? $defaults[$key]);
        $validated[$key] = (string)max(0, min(100, $v));
    }

    return $validated;
}

function cmsRenderThemeLayoutCss(array $settings, ?string $scope = null): string
{
    $s = cmsValidateThemeLayoutSettings($settings);
    $scope = cmsNormalizeCustomizerScope($scope, cmsActiveCustomizerScope());

    $css = ':root{';
    $css .= '--theme-site-max-width:' . ($s['site_max_width'] ?? '1280') . 'px;';
    $css .= '--theme-content-max-width:' . ($s['content_max_width'] ?? '768') . 'px;';
    $css .= '--theme-content-px:' . ($s['content_padding_x'] ?? '16') . 'px;';
    $css .= '--theme-content-pt:' . ($s['content_padding_top'] ?? '32') . 'px;';
    $css .= '--theme-content-pb:' . ($s['content_padding_bottom'] ?? '32') . 'px;';
    $css .= '}';

    $mode = $s['layout_mode'] ?? 'contained';
    if ($mode === 'boxed') {
        $css .= 'body{max-width:var(--theme-site-max-width);margin-left:auto;margin-right:auto;box-shadow:0 0 40px rgba(0,0,0,0.08);}';
    }

    $css .= '.cms-public-shell{width:100%;max-width:var(--theme-site-max-width);margin-left:auto;margin-right:auto;}';
    $css .= '.cms-public-main{max-width:var(--theme-site-max-width);margin-left:auto;margin-right:auto;';
    $css .= 'padding:var(--theme-content-pt) var(--theme-content-px) var(--theme-content-pb);}';
    $css .= '.cms-shell-entity-view{display:block;width:100%;}';
    $css .= '.cms-shell-entity-view__body{width:100%;}';
    $css .= '.cms-shell-entity-view--sidebar{height:100%;}';
    $css .= '.cms-shell-entity-view--widget .widget,.cms-shell-entity-view--widget .sidebar-widget,.cms-shell-entity-view--widget .header-widget{width:100%;}';

    if ($scope === 'ecommerce') {
        $css .= '.header-topbar .cms-public-shell,.site-header .cms-public-shell,.footer-widgets .cms-public-shell,.footer-bottom .cms-public-shell{width:100%;max-width:var(--theme-site-max-width);margin-left:auto;margin-right:auto;}';
        $css .= '.entity-commerce-poc{--container-width:var(--theme-site-max-width);--container-max:var(--theme-site-max-width);}';
        $css .= '.entity-commerce-poc .poc-main__inner{width:min(var(--theme-site-max-width),calc(100vw - (var(--theme-content-px) * 2)));margin-left:auto;margin-right:auto;}';
    }
    $css .= '.poc-header--customized .poc-header__slot--customized{width:min(var(--theme-site-max-width),calc(100vw - (var(--theme-content-px) * 2)));margin-left:auto;margin-right:auto;}';
    $css .= '.poc-header--customized .poc-header__inner--customized{padding-left:0;padding-right:0;}';
    $css .= '.poc-header--customized .header-topbar .container.cms-public-shell,.poc-header--customized .site-header .container.cms-public-shell,.poc-footer--customized .footer-widgets .container.cms-public-shell,.poc-footer--customized .footer-bottom .container.cms-public-shell,.poc-header--customized .header-topbar>.cms-public-shell--full,.poc-header--customized .site-header>.cms-public-shell--full,.poc-footer--customized .footer-widgets>.cms-public-shell--full,.poc-footer--customized .footer-bottom>.cms-public-shell--full{width:100%;max-width:none;margin:0;box-sizing:border-box;padding-left:var(--theme-content-px);padding-right:var(--theme-content-px);}';
    $css .= '.poc-header--customized .header-topbar-inner,.poc-header--customized .header-inner{padding-left:0;padding-right:0;}';
    $css .= '.footer-bottom>.container.cms-public-shell,.footer-bottom>.cms-public-shell--full{border-top:1px solid color-mix(in srgb, var(--footer-link, var(--footer-text, var(--color-border))) 22%, transparent);}';
    $css .= '.footer-bottom__inner{display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:0.5rem;padding:18px 0;}';
    $css .= '.footer-bottom__separator{opacity:0.5;}';
    $css .= '.footer-bottom__admin-link{color:var(--footer-link, var(--footer-text, var(--color-text-light)));font-size:0.8rem;text-decoration:none;}';
    $css .= '.footer-bottom__admin-link:hover{color:var(--footer-link-hover, var(--color-link-hover));}';
    $css .= '.cms-content-prose{max-width:var(--theme-content-max-width);margin-left:auto;margin-right:auto;}';

    return $css;
}

function cmsRenderEntityPresentationCss(array $settings): string
{
    $presentation = cmsValidateEntityPresentationSettings($settings);
    $css = ':root{';
    $css .= '--theme-entity-summary-width:' . ($presentation['entity_summary_width'] ?? '320') . 'px;';
    $css .= '--theme-single-max-width:' . ($presentation['single_max_width'] ?? '768') . 'px;';
    $css .= '--theme-blog-gap:' . ($presentation['blog_gap'] ?? '24') . 'px;';
    $css .= '--theme-blog-cols:' . ($presentation['blog_columns'] ?? '2') . ';';
    $css .= '--theme-card-radius:' . ($presentation['blog_card_radius'] ?? '8') . 'px;';
    $css .= '--theme-image-height:' . ($presentation['blog_image_height'] ?? '208') . 'px;';

    $entitySpacingScale = (string)($presentation['entity_spacing_scale'] ?? 'comfortable');
    $entityGap = '2rem';
    $entityPanelPadding = '1rem';
    if ($entitySpacingScale === 'compact') {
        $entityGap = '1rem';
        $entityPanelPadding = '0.875rem';
    } elseif ($entitySpacingScale === 'airy') {
        $entityGap = '2.75rem';
        $entityPanelPadding = '1.25rem';
    }

    $entityActionSize = (string)($presentation['entity_action_size'] ?? 'md');
    $actionPadY = '0.75rem';
    $actionPadX = '1.5rem';
    $actionFontSize = '0.875rem';
    $actionMinHeight = '2.75rem';
    if ($entityActionSize === 'sm') {
        $actionPadY = '0.625rem';
        $actionPadX = '1rem';
        $actionFontSize = '0.8125rem';
        $actionMinHeight = '2.5rem';
    } elseif ($entityActionSize === 'lg') {
        $actionPadY = '0.875rem';
        $actionPadX = '1.75rem';
        $actionFontSize = '0.9375rem';
        $actionMinHeight = '3rem';
    }

    $entityMediaRatio = (string)($presentation['entity_media_ratio'] ?? 'auto');
    $ratioMap = ['16:9' => '16 / 9', '4:3' => '4 / 3', '1:1' => '1 / 1'];
    $entityMediaRatioValue = $ratioMap[$entityMediaRatio] ?? '4 / 3';

    $listDensity = (string)($presentation['entity_list_card_density'] ?? 'comfortable');
    $listGap = '1.5rem';
    $listCardPadding = '1rem';
    $listCardMinWidth = (string)((int)($presentation['entity_list_card_min_width'] ?? 240)) . 'px';
    $listTitleSize = (string)((int)($presentation['entity_list_title_size'] ?? 19)) . 'px';
    $listPriceSize = (string)((int)($presentation['entity_list_price_size'] ?? 17)) . 'px';
    $listTitleLines = (string)((int)($presentation['entity_list_title_lines'] ?? 2));
    $listTitleFontCss = cmsCustomizerFontCssValue((string)($presentation['entity_list_title_font'] ?? ''), 'var(--font-heading)');
    $listTextFontCss = cmsCustomizerFontCssValue((string)($presentation['entity_list_text_font'] ?? ''), 'var(--font-body)');
    $listExcerptSize = '0.9rem';
    $listPillFontSize = '0.82rem';
    $listPillPadY = '0.42rem';
    $listPillPadX = '0.75rem';
    if ($listDensity === 'compact') {
        $listGap = '1rem';
        $listCardPadding = '0.875rem';
        $listExcerptSize = '0.84rem';
        $listPillFontSize = '0.76rem';
        $listPillPadY = '0.34rem';
        $listPillPadX = '0.625rem';
    } elseif ($listDensity === 'airy') {
        $listGap = '2rem';
        $listCardPadding = '1.25rem';
        $listExcerptSize = '0.96rem';
        $listPillFontSize = '0.88rem';
        $listPillPadY = '0.48rem';
        $listPillPadX = '0.85rem';
    }

    $css .= '--theme-entity-gap:' . $entityGap . ';';
    $css .= '--theme-entity-panel-padding:' . $entityPanelPadding . ';';
    $css .= '--theme-entity-action-pad-y:' . $actionPadY . ';';
    $css .= '--theme-entity-action-pad-x:' . $actionPadX . ';';
    $css .= '--theme-entity-action-font-size:' . $actionFontSize . ';';
    $css .= '--theme-entity-action-min-height:' . $actionMinHeight . ';';
    $css .= '--theme-entity-list-gap:' . $listGap . ';';
    $css .= '--theme-entity-list-card-padding:' . $listCardPadding . ';';
    $css .= '--theme-entity-list-card-min-width:' . $listCardMinWidth . ';';
    $css .= '--theme-entity-list-title-size:' . $listTitleSize . ';';
    $css .= '--theme-entity-list-price-size:' . $listPriceSize . ';';
    $css .= '--theme-entity-list-title-lines:' . $listTitleLines . ';';
    $css .= '--theme-entity-list-title-font:' . $listTitleFontCss . ';';
    $css .= '--theme-entity-list-text-font:' . $listTextFontCss . ';';
    $css .= '--theme-entity-list-excerpt-size:' . $listExcerptSize . ';';
    $css .= '--theme-entity-list-pill-font-size:' . $listPillFontSize . ';';
    $css .= '--theme-entity-list-pill-pad-y:' . $listPillPadY . ';';
    $css .= '--theme-entity-list-pill-pad-x:' . $listPillPadX . ';';
    $css .= '--theme-entity-media-ratio:' . ($entityMediaRatio === 'auto' ? 'auto' : $entityMediaRatioValue) . ';';
    $css .= '--theme-entity-list-media-ratio:' . $entityMediaRatioValue . ';';
    $css .= '}';

    $css .= '.cms-entity-summary-stack{display:flex;flex-direction:column;gap:' . $entityGap . ';}';
    $css .= '.cms-entity-summary-stack-rail > *{padding:' . $entityPanelPadding . ';}';
    $css .= '.cms-entity-profile-commerce .cms-entity-layout{display:grid;grid-template-columns:minmax(0,1fr) minmax(260px,var(--theme-entity-summary-width));gap:' . $entityGap . ';align-items:start;}';
    if (!empty($presentation['entity_summary_sticky'])) {
        $css .= '.cms-entity-profile-commerce .cms-entity-summary{position:sticky;top:1.5rem;}';
    } else {
        $css .= '.cms-entity-profile-commerce .cms-entity-summary{position:static;}';
    }
    $css .= '.cms-single-prose{max-width:var(--theme-single-max-width);margin-left:auto;margin-right:auto;}';
    $css .= '.cms-entity-profile-content .cms-entity-header{max-width:var(--theme-single-max-width);margin-left:auto;margin-right:auto;}';
    $css .= '.cms-entity-profile-content .cms-entity-body,.cms-entity-profile-content .cms-entity-summary-stack{max-width:var(--theme-single-max-width);margin-left:auto;margin-right:auto;}';
    $blogLayout = (string)($presentation['blog_layout'] ?? 'list');
    if ($blogLayout === 'grid' || $blogLayout === 'cards') {
        $css .= '.cms-blog-listing{display:grid;grid-template-columns:repeat(var(--theme-blog-cols),1fr);gap:var(--theme-blog-gap);}';
        $css .= '@media(max-width:768px){.cms-blog-listing{grid-template-columns:1fr;}}';
    } else {
        $css .= '.cms-blog-listing{display:flex;flex-direction:column;gap:var(--theme-blog-gap);}';
    }

    $blogRatio = (string)($presentation['blog_image_ratio'] ?? 'auto');
    if ($blogRatio !== 'auto') {
        $blogRatioMap = ['16:9' => '56.25%', '4:3' => '75%', '1:1' => '100%'];
        $blogPct = $blogRatioMap[$blogRatio] ?? '56.25%';
        $css .= '.cms-blog-listing .cms-post-image{position:relative;width:100%;padding-bottom:' . $blogPct . ';overflow:hidden;}';
        $css .= '.cms-blog-listing .cms-post-image img{position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover;}';
    }

    $css .= '.cms-blog-card{border-radius:var(--theme-card-radius);}';
    if (empty($presentation['blog_card_border'])) {
        $css .= '.cms-blog-listing article{border:none;}';
    }
    if (!empty($presentation['blog_card_shadow'])) {
        $css .= '.cms-blog-listing article:hover{box-shadow:0 4px 12px rgba(0,0,0,0.1);}';
    }
    if ($entityMediaRatio !== 'auto') {
        $ratioValue = $ratioMap[$entityMediaRatio] ?? '16 / 9';
        $css .= '.cms-entity-hero{aspect-ratio:' . $ratioValue . ';overflow:hidden;border-radius:1rem;}';
        $css .= '.cms-entity-hero img{width:100%;height:100%;object-fit:cover;max-height:none;border-radius:0;}';
    }
    $css .= '.cms-action-block .cms-btn-primary,.cms-action-block .cms-btn-secondary,.cms-action-block .cms-btn-disabled{padding:var(--theme-entity-action-pad-y) var(--theme-entity-action-pad-x);font-size:var(--theme-entity-action-font-size);min-height:var(--theme-entity-action-min-height);border-radius:var(--radius-md);}';
    $css .= '.cms-entity-list__grid{gap:var(--theme-entity-list-gap);grid-template-columns:repeat(auto-fit,minmax(min(100%, var(--theme-entity-list-card-min-width)),1fr));}';
    $css .= '.cms-entity-card__body{display:flex;flex-direction:column;gap:calc(var(--theme-entity-list-gap) * 0.5);padding:var(--theme-entity-list-card-padding);font-family:var(--theme-entity-list-text-font);}';
    $css .= '.cms-entity-card__title{margin:0;font-family:var(--theme-entity-list-title-font);font-size:var(--theme-entity-list-title-size);line-height:1.12;letter-spacing:-0.02em;overflow-wrap:anywhere;word-break:break-word;display:-webkit-box;-webkit-line-clamp:var(--theme-entity-list-title-lines);-webkit-box-orient:vertical;overflow:hidden;min-height:calc(1.12em * var(--theme-entity-list-title-lines));}';
    $css .= '.cms-entity-card__excerpt{margin:0;font-size:var(--theme-entity-list-excerpt-size);font-family:var(--theme-entity-list-text-font);}';
    $css .= '.cms-entity-card .cms-price-current{font-size:var(--theme-entity-list-price-size);}';
    $css .= '@media(max-width:1024px){.cms-entity-profile-commerce .cms-entity-layout{grid-template-columns:1fr;}.cms-entity-profile-commerce .cms-entity-summary{position:static;}}';

    return $css;
}

function cmsRenderPublicThemeStyle(
    array $themeSettings,
    ?string $scope = null,
    bool $renderShell = true,
    bool $renderEntity = true,
    string $fragment = 'public_theme_style',
    string $styleId = 'cz-public-theme-override'
): string
{
    $scope = cmsNormalizeCustomizerScope($scope, cmsActiveCustomizerScope());
    $cached = cmsCustomizerFragmentCacheGet($fragment, $scope);
    if (is_array($cached) && array_key_exists('html', $cached)) {
        return (string)$cached['html'];
    }

    $themeCss = '';
    if ($renderShell && cmsShouldRenderCustomizerPresentationCss('theme', $themeSettings, null, $scope)) {
        $themeCss = cmsRenderThemeLayoutCss($themeSettings, $scope);
    }

    $entityCss = '';
    if ($renderEntity && cmsShouldRenderCustomizerPresentationCss('entity_presentation', $themeSettings, null, $scope)) {
        $entityCss = cmsRenderEntityPresentationCss($themeSettings);
    }

    $fontHtml = '';
    if ($entityCss !== '') {
        $fontHtml = cmsCustomizerFontStylesheetHtml([
            (string)($themeSettings['entity_list_title_font'] ?? ''),
            (string)($themeSettings['entity_list_text_font'] ?? ''),
        ]);
    }

    if ($themeCss === '' && $entityCss === '' && $fontHtml === '') {
        cmsCustomizerFragmentCacheSet($fragment, ['html' => ''], ['cms:customizer:theme', 'cms:customizer:entity_presentation'], $scope);
        return '';
    }

    $html = $fontHtml . '<style id="' . htmlspecialchars($styleId, ENT_QUOTES) . '">' . $themeCss . $entityCss . '</style>';
    cmsCustomizerFragmentCacheSet($fragment, ['html' => $html], ['cms:customizer:theme', 'cms:customizer:entity_presentation'], $scope);
    return $html;
}

/**
 * Render <style> block with CSS custom properties from Theme Layout settings.
 * Injected into public layout <head>.
 */
function cmsRenderThemeLayoutStyle(object $db, ?string $scope = null, ?array $settings = null, string $fragment = 'theme_layout_style', string $styleId = 'cz-theme-layout-override'): string
{
    $scope = cmsNormalizeCustomizerScope($scope, cmsActiveCustomizerScope());
    $useCache = $settings === null;

    if ($useCache) {
        $cached = cmsCustomizerFragmentCacheGet($fragment, $scope);
        if (is_array($cached) && array_key_exists('html', $cached)) {
            return (string)$cached['html'];
        }
    }

    if ($settings === null) {
        if (!cmsCustomizerSectionExists($db, 'theme', $scope)) {
            if ($useCache) {
                cmsCustomizerFragmentCacheSet($fragment, ['html' => ''], ['cms:customizer:theme'], $scope);
            }
            return '';
        }

        $data = cmsCustomizerGet($db, 'theme', $scope);
        $settings = is_array($data['settings'] ?? null) ? $data['settings'] : cmsThemeLayoutSettingsDefaults();
    }

    if (!cmsShouldRenderCustomizerPresentationCss('theme', $settings, null, $scope)) {
        if ($useCache) {
            cmsCustomizerFragmentCacheSet($fragment, ['html' => ''], ['cms:customizer:theme'], $scope);
        }
        return '';
    }

    $css = cmsRenderThemeLayoutCss($settings, $scope);
    $html = $css !== '' ? '<style id="' . htmlspecialchars($styleId, ENT_QUOTES) . '">' . $css . '</style>' : '';
    if ($useCache) {
        cmsCustomizerFragmentCacheSet($fragment, ['html' => $html], ['cms:customizer:theme'], $scope);
    }
    return $html;
}

function cmsRenderEntityPresentationStyle(object $db, string $fragment = 'entity_presentation_style', string $styleId = 'cz-entity-presentation-override'): string
{
    $cached = cmsCustomizerFragmentCacheGet($fragment);
    if (is_array($cached) && array_key_exists('html', $cached)) {
        return (string)$cached['html'];
    }

    if (!cmsCustomizerSectionExists($db, 'entity_presentation')) {
        cmsCustomizerFragmentCacheSet($fragment, ['html' => ''], ['cms:customizer:entity_presentation']);
        return '';
    }

    $data = cmsCustomizerGet($db, 'entity_presentation');
    $settings = cmsValidateEntityPresentationSettings(
        is_array($data['settings'] ?? null) ? $data['settings'] : [],
        cmsEntityPresentationSectionDefaults()
    );
    if (!cmsShouldRenderCustomizerPresentationCss('entity_presentation', $settings)) {
        cmsCustomizerFragmentCacheSet($fragment, ['html' => ''], ['cms:customizer:entity_presentation']);
        return '';
    }

    $fontHtml = cmsCustomizerFontStylesheetHtml([
        (string)($settings['entity_list_title_font'] ?? ''),
        (string)($settings['entity_list_text_font'] ?? ''),
    ]);
    $html = $fontHtml . '<style id="' . htmlspecialchars($styleId, ENT_QUOTES) . '">' . cmsRenderEntityPresentationCss($settings) . '</style>';
    cmsCustomizerFragmentCacheSet($fragment, ['html' => $html], ['cms:customizer:entity_presentation']);
    return $html;
}

/**
 * Read a customizer section from the database.
 * Returns ['settings' => [...], 'widgets' => [...]]
 */

function cmsCustomizerGet(object $db, string $section, ?string $scope = null): array
{
    if ($section === 'entity_presentation') {
        return cmsEntityPresentationSectionData($db, $scope);
    }

    $scope = $scope !== null ? trim($scope) : cmsActiveCustomizerScope();
    $defaults = cmsCustomizerSectionDefaults($section);

    try {
        $row = cmsCustomizerSectionRecord($db, $section, $scope);
        if (is_array($row)) {
            $settings = json_decode($row['settings_json'] ?? '{}', true) ?: [];
            $widgets  = json_decode($row['widgets_json'] ?? '[]', true) ?: [];
            return [
                'settings' => array_merge($defaults, $settings),
                'widgets'  => $widgets,
            ];
        }
    } catch (Throwable $e) {
        write_log('Theme customizer read error: ' . $e->getMessage(), 'error');
    }

    return ['settings' => $defaults, 'widgets' => []];
}

/**
 * Validate and sanitize footer customizer settings.
 */

function cmsValidateFooterSettings(array $input): array
{
    $defaults = cmsFooterSettingsDefaults();
    $validated = [];

    // Integer settings
    $validated['columns'] = max(0, min(5, (int)($input['columns'] ?? $defaults['columns'])));
    $validated['show_footer_bar'] = (int)(bool)($input['show_footer_bar'] ?? $defaults['show_footer_bar']);
    $validated['show_admin_link'] = (int)(bool)($input['show_admin_link'] ?? $defaults['show_admin_link']);

    // String settings
    foreach (['inner_width', 'copyright_text', 'padding_top', 'padding_bottom'] as $key) {
        $validated[$key] = trim((string)($input[$key] ?? $defaults[$key]));
    }

    $legacyShellMode = cmsCustomizerShellWidthMode([
        'inner_width' => $validated['inner_width'],
    ]);
    $validated['widget_container_width'] = trim((string)($input['widget_container_width'] ?? ($legacyShellMode === 'full' ? 'full' : $defaults['widget_container_width'])));

    $widgetInnerModeInput = trim((string)($input['widget_inner_width_mode'] ?? ''));
    if ($widgetInnerModeInput === '' && array_key_exists('widget_inner_width', $input)) {
        $widgetInnerModeInput = trim((string)$input['widget_inner_width']);
    }
    if ($widgetInnerModeInput === '') {
        $widgetInnerModeInput = $legacyShellMode === 'full' ? 'full' : $defaults['widget_inner_width_mode'];
    }
    $validated['widget_inner_width_mode'] = $widgetInnerModeInput;
    $validated['widget_inner_custom_width'] = cmsCustomizerCssLengthValue(
        (string)($input['widget_inner_custom_width'] ?? $defaults['widget_inner_custom_width']),
        (string)$defaults['widget_inner_custom_width']
    );

    // Color settings — basic hex validation
    $colorKeys = ['bg_color', 'text_color', 'link_color', 'link_hover_color',
                  'title_color', 'bar_bg_color', 'bar_text_color', 'bar_link_color', 'bar_link_hover_color'];
    foreach ($colorKeys as $key) {
        $val = trim((string)($input[$key] ?? $defaults[$key]));
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $val)) {
            $validated[$key] = $val;
        } else {
            $validated[$key] = $defaults[$key];
        }
    }

    // Constrain inner_width
    if (!in_array($validated['inner_width'], ['contained', 'full-width'], true)) {
        $validated['inner_width'] = 'contained';
    }

    if (!in_array($validated['widget_container_width'], ['contained', 'full'], true)) {
        $validated['widget_container_width'] = $legacyShellMode === 'full' ? 'full' : $defaults['widget_container_width'];
    }

    if (!in_array($validated['widget_inner_width_mode'], ['boxed', 'contained', 'full', 'custom'], true)) {
        $validated['widget_inner_width_mode'] = $legacyShellMode === 'full' ? 'full' : $defaults['widget_inner_width_mode'];
    }

    return $validated;
}

function cmsCustomizerCssLengthValue(string $raw, string $fallback): string
{
    $value = trim($raw);
    if ($value === '') {
        return $fallback;
    }

    if (preg_match('/^[0-9]+(?:\.[0-9]+)?$/', $value)) {
        $value .= 'px';
    }

    if (!preg_match('/^[0-9]+(?:\.[0-9]+)?(?:px|rem|em|vw|vh|%)$/i', $value)) {
        return $fallback;
    }

    return strtolower($value);
}

function cmsCustomizerShellWidthMode(array $settings, string $settingKey = 'inner_width'): string
{
    $raw = trim((string)($settings[$settingKey] ?? ''));
    if ($raw === '' && $settingKey !== 'inner_width') {
        $raw = trim((string)($settings['inner_width'] ?? ''));
    }

    return in_array($raw, ['full', 'full-width'], true) ? 'full' : 'contained';
}

function cmsCustomizerShellWidthClasses(array $settings, string $settingKey = 'inner_width'): string
{
    return cmsCustomizerShellWidthMode($settings, $settingKey) === 'full'
        ? 'cms-public-shell cms-public-shell--full'
        : 'container cms-public-shell cms-public-shell--contained';
}

function cmsCustomizerShellOuterWidthStyle(array $settings, string $settingKey = 'inner_width'): string
{
    if (cmsCustomizerShellWidthMode($settings, $settingKey) === 'full') {
        return 'width:100%;max-width:none;margin:0;';
    }

    return 'width:min(var(--container-max, var(--theme-site-max-width, 1180px)), calc(100vw - (var(--theme-content-px, 20px) * 2)));margin-left:auto;margin-right:auto;';
}

function cmsCustomizerFooterWidgetContainerStyle(array $settings): string
{
    if (cmsCustomizerShellWidthMode($settings, 'widget_container_width') === 'full') {
        return 'width:100%;max-width:none;margin:0;box-sizing:border-box;padding-left:var(--theme-content-px, 20px);padding-right:var(--theme-content-px, 20px);';
    }

    return cmsCustomizerShellOuterWidthStyle($settings, 'widget_container_width');
}

function cmsCustomizerFooterWidgetHolderMode(array $settings): string
{
    $mode = trim((string)($settings['widget_inner_width_mode'] ?? ''));
    if ($mode === '') {
        return cmsCustomizerShellWidthMode($settings, 'widget_container_width') === 'full' ? 'full' : 'contained';
    }

    return in_array($mode, ['boxed', 'contained', 'full', 'custom'], true) ? $mode : 'contained';
}

function cmsCustomizerFooterWidgetHolderClasses(array $settings): string
{
    return match (cmsCustomizerFooterWidgetHolderMode($settings)) {
        'boxed' => 'cms-public-shell cms-public-shell--boxed',
        'custom' => 'cms-public-shell cms-public-shell--custom',
        'full' => 'cms-public-shell cms-public-shell--full',
        default => 'container cms-public-shell cms-public-shell--contained',
    };
}

function cmsCustomizerFooterWidgetHolderStyle(array $settings): string
{
    return match (cmsCustomizerFooterWidgetHolderMode($settings)) {
        'boxed' => 'width:100%;max-width:var(--theme-content-max-width, 768px);margin-left:auto;margin-right:auto;',
        'custom' => 'width:100%;max-width:' . cmsCustomizerCssLengthValue((string)($settings['widget_inner_custom_width'] ?? '960px'), '960px') . ';margin-left:auto;margin-right:auto;',
        'full' => 'width:100%;max-width:none;margin:0;',
        default => 'width:100%;max-width:var(--theme-site-max-width, 1180px);margin-left:auto;margin-right:auto;',
    };
}

function cmsValidateSidebarSettings(array $input): array
{
    $defaults = cmsSidebarSettingsDefaults();
    $validated = [];
    $allowedTargets = array_map(static fn($t) => (string)($t['key'] ?? ''), cmsSidebarTemplateTargets());
    $fallbackTarget = !empty($allowedTargets[0]) ? $allowedTargets[0] : (string)$defaults['template_scope'];

    $validated['enabled'] = (int)(bool)($input['enabled'] ?? $defaults['enabled']);
    $validated['scope_mode'] = in_array(($input['scope_mode'] ?? ''), ['general', 'exclude_templates', 'template'], true)
        ? (string)$input['scope_mode'] : $defaults['scope_mode'];
    $legacyTemplateScope = in_array((string)($input['template_scope'] ?? ''), $allowedTargets, true)
        ? (string)$input['template_scope'] : '';
    $validated['template_rules'] = cmsSidebarNormalizeTemplateRules($input['template_rules'] ?? [], $allowedTargets);
    if ($validated['template_rules'] === [] && $legacyTemplateScope !== '') {
        $validated['template_rules'] = [$legacyTemplateScope];
    }
    if ($validated['template_rules'] === [] && $validated['scope_mode'] === 'template') {
        $validated['template_rules'] = [$fallbackTarget];
    }
    $validated['template_scope'] = $validated['template_rules'][0] ?? ($legacyTemplateScope !== '' ? $legacyTemplateScope : $fallbackTarget);
    $validated['placement'] = in_array(($input['placement'] ?? ''), ['left', 'right'], true)
        ? (string)$input['placement'] : $defaults['placement'];
    $validated['sticky'] = (int)(bool)($input['sticky'] ?? $defaults['sticky']);

    $validated['width'] = (string)max(220, min(420, (int)($input['width'] ?? $defaults['width'])));
    $validated['gap'] = (string)max(16, min(64, (int)($input['gap'] ?? $defaults['gap'])));
    $validated['widget_padding'] = (string)max(8, min(36, (int)($input['widget_padding'] ?? $defaults['widget_padding'])));
    $validated['widget_radius'] = (string)max(0, min(24, (int)($input['widget_radius'] ?? $defaults['widget_radius'])));

    $colorKeys = [
        'widget_bg_color', 'widget_text_color', 'widget_title_color',
        'widget_border_color', 'widget_link_color', 'widget_link_hover_color',
    ];
    foreach ($colorKeys as $key) {
        $val = trim((string)($input[$key] ?? $defaults[$key]));
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $val) || preg_match('/^rgba?\(/', $val)) {
            $validated[$key] = $val;
        } else {
            $validated[$key] = $defaults[$key];
        }
    }

    return $validated;
}

/**
 * Render footer widgets as HTML for the public theme.
 * Called from the public layout template / cmsPublicContext.
 *
 * Widget types supported:
 *   text        - Title + rich text content
 *   custom_html - Title + raw HTML
 *   nav_menu    - Title + rendered navigation menu by menu ID
 *   recent_posts - Title + N most recent published posts
 *   social_links - Title + social media icon links from CMS settings
 *   contact_info - Title + address/phone/email fields
 */

function cmsRenderFooterWidgets(object $db): string
{
    $data = cmsCustomizerGet($db, 'footer');
    $settings = $data['settings'];
    $widgets  = $data['widgets'];
    $columns  = (int)($settings['columns'] ?? 3);

    if ($columns < 1 || empty($widgets)) {
        return '';
    }

    $cmsSettings = readCmsSettings();
    $baseUrl = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');

    // Group widgets by area (area = 1-based column index)
    $areas = [];
    for ($i = 1; $i <= $columns; $i++) {
        $areas[$i] = [];
    }
    foreach ($widgets as $widget) {
        $area = (int)($widget['area'] ?? 1);
        if ($area < 1 || $area > $columns) continue;
        $areas[$area][] = $widget;
    }

    // Build CSS custom properties from settings
    $bgColor        = htmlspecialchars($settings['bg_color'] ?? '#1e293b');
    $textColor      = htmlspecialchars($settings['text_color'] ?? '#cbd5e1');
    $linkColor      = htmlspecialchars($settings['link_color'] ?? '#94a3b8');
    $linkHoverColor = htmlspecialchars($settings['link_hover_color'] ?? '#ffffff');
    $titleColor     = htmlspecialchars($settings['title_color'] ?? '#f1f5f9');
    $paddingTop     = (int)($settings['padding_top'] ?? 40);
    $paddingBottom  = (int)($settings['padding_bottom'] ?? 40);
    $widgetContainerMode = cmsCustomizerShellWidthMode($settings, 'widget_container_width');
    $outerWidthStyle = cmsCustomizerFooterWidgetContainerStyle($settings);
    $innerWidthClass = cmsCustomizerFooterWidgetHolderClasses($settings);
    $innerWidthStyle = cmsCustomizerFooterWidgetHolderStyle($settings);

    $html = '<div class="footer-widgets cms-shell-width-' . $widgetContainerMode . '" style="'
        . '--footer-bg:' . $bgColor . ';'
        . '--footer-text:' . $textColor . ';'
        . '--footer-link:' . $linkColor . ';'
        . '--footer-link-hover:' . $linkHoverColor . ';'
        . '--footer-title-color:' . $titleColor . ';'
        . 'background:' . $bgColor . ';'
        . 'color:' . $textColor . ';'
        . 'padding:' . $paddingTop . 'px 0 ' . $paddingBottom . 'px;'
        . $outerWidthStyle . '">';

    $html .= '<div class="' . $innerWidthClass . '" style="' . $innerWidthStyle . '">';
    $html .= '<div class="footer-widgets-grid" data-columns="' . $columns . '">';

    for ($col = 1; $col <= $columns; $col++) {
        $html .= '<div class="footer-widget-col">';
        foreach ($areas[$col] as $widget) {
            $html .= cmsRenderSingleFooterWidget($widget, $db, $cmsSettings, $baseUrl);
        }
        $html .= '</div>';
    }

    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>';

    return $html;
}

/**
 * Render a single footer widget instance.
 */

function cmsRenderSingleFooterWidget(array $widget, object $db, array $cmsSettings, string $baseUrl): string
{
    $type  = trim((string)($widget['type'] ?? ''));
    $props = (array)($widget['props'] ?? []);
    $title = trim((string)($props['title'] ?? ''));

    $html = '<div class="widget widget-' . htmlspecialchars($type) . '">';
    if ($title !== '') {
        $html .= '<h4 class="widget-title" style="color:var(--footer-title-color, var(--footer-text));">'
                . htmlspecialchars($title) . '</h4>';
    }
    $html .= '<div class="widget-content">';

    switch ($type) {
        case 'text':
            $content = (string)($props['content'] ?? '');
            $html .= '<div class="widget-text">' . $content . '</div>';
            break;

        case 'custom_html':
            $content = (string)($props['content'] ?? '');
            $html .= $content;
            break;

        case 'nav_menu':
            $menuId = (int)($props['menu_id'] ?? 0);
            if ($menuId > 0) {
                try {
                    $html .= cmsRenderMenuById($db, $menuId, 'footer-menu');
                } catch (Throwable $e) {
                    $html .= '<p style="opacity:0.5;">Menu not found</p>';
                }
            }
            break;

        case 'recent_posts':
            $count = max(1, min(10, (int)($props['count'] ?? 5)));
            try {
                $stmt = $db->prepare(
                    "SELECT title, slug, created_at FROM cms_content
                     WHERE type = 'post' AND status = 'published' AND deleted_at IS NULL
                     ORDER BY created_at DESC LIMIT :n"
                );
                $stmt->bindValue(':n', $count, PDO::PARAM_INT);
                $stmt->execute();
                $posts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                if (!empty($posts)) {
                    $html .= '<ul class="footer-menu">';
                    foreach ($posts as $p) {
                        $href = htmlspecialchars($baseUrl . '/cms/blog/' . $p['slug']);
                        $html .= '<li><a href="' . $href . '">' . htmlspecialchars($p['title']) . '</a></li>';
                    }
                    $html .= '</ul>';
                } else {
                    $html .= '<p style="opacity:0.5;">No posts yet</p>';
                }
            } catch (Throwable $e) {
                $html .= '<p style="opacity:0.5;">Unable to load posts</p>';
            }
            break;

        case 'social_links':
            $links = cmsPublicSocialLinks($cmsSettings);
            if (!empty($links)) {
                $html .= '<div class="social-links"><ul class="social-menu">';
                foreach ($links as $sl) {
                    $icon = cmsGetSocialIcon($sl['name']);
                    $html .= '<a href="' . htmlspecialchars($sl['url']) . '" target="_blank" rel="noopener noreferrer" '
                           . 'title="' . htmlspecialchars($sl['label']) . '">' . $icon . '</a>';
                }
                $html .= '</ul></div>';
            }
            break;

        case 'contact_info':
            $address = trim((string)($props['address'] ?? ''));
            $phone   = trim((string)($props['phone'] ?? ''));
            $email   = trim((string)($props['email'] ?? ''));
            $html .= '<div class="contact-info">';
            if ($address !== '') {
                $html .= '<div class="contact-item"><span class="icon">📍</span><span>' . htmlspecialchars($address) . '</span></div>';
            }
            if ($phone !== '') {
                $html .= '<div class="contact-item"><span class="icon">📞</span><a href="tel:' . htmlspecialchars($phone) . '">' . htmlspecialchars($phone) . '</a></div>';
            }
            if ($email !== '') {
                $html .= '<div class="contact-item"><span class="icon">✉️</span><a href="mailto:' . htmlspecialchars($email) . '">' . htmlspecialchars($email) . '</a></div>';
            }
            $html .= '</div>';
            break;

        default:
            $html .= '<p style="opacity:0.5;">Unknown widget type</p>';
            break;
    }

    $html .= '</div></div>';
    return cmsRenderShellEntityWidget('footer', $widget, $html);
}

/**
 * Render a menu by its database ID (not location).
 * Uses cmsResolveMenuItemUrl() to properly resolve link_type/link_ref URLs.
 */

function cmsGetSocialIcon(string $name): string
{
    $icons = [
        'facebook'  => '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>',
        'twitter'   => '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>',
        'instagram' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>',
        'youtube'   => '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M23.498 6.186a3.016 3.016 0 00-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 00.502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 002.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 002.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>',
        'linkedin'  => '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>',
    ];
    return $icons[$name] ?? '<span style="font-size:1.2em;">🔗</span>';
}

/**
 * Render the full footer from theme customizer.
 * Replaces the static footer template with dynamic customizer-driven content.
 */

function cmsRenderCustomizedFooter(object $db, array $publicCtx = []): string
{
    $fragmentKey = 'footer_html:' . cmsCustomizerRenderContextCacheToken($publicCtx);
    $cached = cmsCustomizerFragmentCacheGet($fragmentKey);
    if (is_array($cached) && array_key_exists('html', $cached)) {
        return (string)$cached['html'];
    }

    $data = cmsCustomizerGet($db, 'footer');
    $settings = $data['settings'];
    $widgets  = $data['widgets'];

    $cmsSettings = readCmsSettings();
    $baseUrl = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');

    $html = '';

    // Widget area
    $columns = (int)($settings['columns'] ?? 3);
    if ($columns > 0 && !empty($widgets)) {
        $html .= cmsRenderFooterWidgets($db);
    }

    // Footer bar
    if ((int)($settings['show_footer_bar'] ?? 1)) {
        $shellWidthMode = cmsCustomizerShellWidthMode($settings);
        $outerWidthStyle = cmsCustomizerShellOuterWidthStyle($settings);
        $innerWidthClass = cmsCustomizerShellWidthClasses($settings);
        $barBg    = htmlspecialchars($settings['bar_bg_color'] ?? '#0f172a');
        $barText  = htmlspecialchars($settings['bar_text_color'] ?? '#64748b');
        $barLink  = htmlspecialchars($settings['bar_link_color'] ?? '#94a3b8');
        $barLinkH = htmlspecialchars($settings['bar_link_hover_color'] ?? '#ffffff');

        $copyright = (string)($settings['copyright_text'] ?? '');
        $copyright = str_replace('{current_year}', date('Y'), $copyright);
        $copyright = str_replace('{site_title}', htmlspecialchars($cmsSettings['site_title'] ?? ''), $copyright);

        $html .= '<div class="footer-bottom cms-shell-width-' . $shellWidthMode . '" style="background:' . $barBg . ';color:' . $barText . ';'
        . '--footer-link:' . $barLink . ';--footer-link-hover:' . $barLinkH . ';'
        . $outerWidthStyle . '">';
        $html .= '<div class="' . $innerWidthClass . '">';
        $html .= '<div class="footer-bottom__inner">';
        $html .= '<span class="footer-bottom__copy">' . $copyright . '</span>';

        if ((int)($settings['show_admin_link'] ?? 1)) {
            $html .= '<span class="footer-bottom__separator">·</span>';
            $html .= '<a class="footer-bottom__admin-link" href="' . $baseUrl . '/cms/admin">Admin</a>';
        }
        $html .= '</div></div></div>';
    }

    $themeWrapped = cmsRenderActiveThemeCustomizerPartial('footer', [
        'footer_html' => $html,
        'footer_settings' => $settings,
        'footer_widgets' => $widgets,
        'cms_settings' => $cmsSettings,
    ]);
    if ($themeWrapped !== '') {
        $html = $themeWrapped;
    }

    $html = cmsRenderShellEntityView('footer', $html, [
        'data' => [
            'shell-entity-node' => 'region',
            'shell-entity-kind' => 'footer',
            'public-render-origin' => (string)($publicCtx['public_render_origin'] ?? 'cms'),
            'public-route-kind' => (string)($publicCtx['public_route_kind'] ?? 'generic'),
            'public-presentation-mode' => (string)($publicCtx['public_presentation_mode'] ?? 'traditional'),
        ],
    ]);

    cmsCustomizerFragmentCacheSet($fragmentKey, ['html' => $html], ['cms:customizer:footer', 'cms:settings', 'cms:menus']);
    return $html;
}

function cmsRenderCustomizedSidebar(object $db, array $publicCtx = []): array
{
    $data = cmsCustomizerGet($db, 'sidebar');
    $settings = $data['settings'] ?? cmsSidebarSettingsDefaults();
    $widgets = is_array($data['widgets'] ?? null) ? $data['widgets'] : [];

    $enabled = (int)($settings['enabled'] ?? 0) === 1;
    if (!$enabled) {
        return ['enabled' => false, 'position' => ($settings['placement'] ?? 'right'), 'width' => ($settings['width'] ?? '300'), 'html' => ''];
    }

    $defaultTarget = cmsSidebarSettingsDefaults()['template_scope'] ?? 'home';
    $templateKey = (string)$defaultTarget;
    if (isset($publicCtx['sidebar_template']) && is_string($publicCtx['sidebar_template']) && $publicCtx['sidebar_template'] !== '') {
        $templateKey = $publicCtx['sidebar_template'];
    }

    $scopeMode = (string)($settings['scope_mode'] ?? 'general');
    $templateRules = cmsSidebarResolvedTemplateRules($settings);
    $showForThisTemplate = cmsSidebarTemplateMatchesScope($settings, $templateKey);
    if (!$showForThisTemplate) {
        return ['enabled' => false, 'position' => ($settings['placement'] ?? 'right'), 'width' => ($settings['width'] ?? '300'), 'html' => ''];
    }

    $cacheFragment = 'sidebar_html:' . sha1($templateKey . '|' . $scopeMode . '|' . implode(',', $templateRules) . '|' . cmsCustomizerRenderContextCacheToken($publicCtx));
    $cached = cmsCustomizerFragmentCacheGet($cacheFragment);
    if (is_array($cached)
        && isset($cached['enabled'], $cached['position'], $cached['width'])
        && array_key_exists('html', $cached)) {
        return [
            'enabled' => (bool)$cached['enabled'],
            'position' => (string)$cached['position'],
            'width' => (string)$cached['width'],
            'html' => (string)$cached['html'],
        ];
    }

    $cmsSettings = readCmsSettings();
    $baseUrl = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');

    $widgetBg = htmlspecialchars((string)($settings['widget_bg_color'] ?? '#ffffff'));
    $widgetText = htmlspecialchars((string)($settings['widget_text_color'] ?? '#334155'));
    $widgetTitle = htmlspecialchars((string)($settings['widget_title_color'] ?? '#0f172a'));
    $widgetBorder = htmlspecialchars((string)($settings['widget_border_color'] ?? '#e2e8f0'));
    $widgetLink = htmlspecialchars((string)($settings['widget_link_color'] ?? '#2563eb'));
    $widgetLinkHover = htmlspecialchars((string)($settings['widget_link_hover_color'] ?? '#1d4ed8'));
    $widgetPadding = max(8, min(36, (int)($settings['widget_padding'] ?? 16)));
    $widgetRadius = max(0, min(24, (int)($settings['widget_radius'] ?? 10)));
    $sticky = (int)($settings['sticky'] ?? 0) === 1;
    $gap = max(16, min(64, (int)($settings['gap'] ?? 32)));
    $width = max(220, min(420, (int)($settings['width'] ?? 300)));

    $styleHtml = '<style id="cz-sidebar-style">';
    $styleHtml .= '.cms-sidebar-wrap{display:flex;flex-direction:column;gap:' . $gap . 'px;}';
    $styleHtml .= '.cms-sidebar-wrap .sidebar-widget{background:' . $widgetBg . ';color:' . $widgetText . ';border:1px solid ' . $widgetBorder . ';border-radius:' . $widgetRadius . 'px;padding:' . $widgetPadding . 'px;}';
    $styleHtml .= '.cms-sidebar-wrap .sidebar-widget-title{margin:0 0 0.625rem 0;font-size:1rem;font-weight:700;color:' . $widgetTitle . ';}';
    $styleHtml .= '.cms-sidebar-wrap a{color:' . $widgetLink . ';text-decoration:none;}';
    $styleHtml .= '.cms-sidebar-wrap a:hover{color:' . $widgetLinkHover . ';text-decoration:underline;}';
    $styleHtml .= '.cms-sidebar-wrap .sidebar-menu{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:0.5rem;}';
    $styleHtml .= '.cms-sidebar-wrap .contact-item{display:flex;gap:0.5rem;align-items:flex-start;margin-bottom:0.5rem;}';
    $styleHtml .= '.cms-sidebar-wrap .sidebar-search-form{display:flex;gap:0.5rem;}';
    $styleHtml .= '.cms-sidebar-wrap .sidebar-search-input{flex:1;min-width:0;padding:0.5rem 0.625rem;border:1px solid ' . $widgetBorder . ';border-radius:0.5rem;background:#fff;color:' . $widgetText . ';}';
    $styleHtml .= '.cms-sidebar-wrap .sidebar-search-btn,.cms-sidebar-wrap .sidebar-cta-btn{display:inline-flex;align-items:center;justify-content:center;padding:0.5rem 0.75rem;border-radius:0.5rem;background:' . $widgetLink . ';color:#fff;text-decoration:none;border:1px solid ' . $widgetLink . ';font-size:0.875rem;line-height:1.2;}';
    $styleHtml .= '.cms-sidebar-wrap .sidebar-search-btn:hover,.cms-sidebar-wrap .sidebar-cta-btn:hover{background:' . $widgetLinkHover . ';border-color:' . $widgetLinkHover . ';color:#fff;text-decoration:none;}';
    $styleHtml .= '.cms-sidebar-wrap .sidebar-tag-cloud{display:flex;flex-wrap:wrap;gap:0.5rem;}';
    $styleHtml .= '.cms-sidebar-wrap .sidebar-tag{display:inline-flex;align-items:center;padding:0.25rem 0.625rem;border:1px solid ' . $widgetBorder . ';border-radius:999px;font-size:0.75rem;line-height:1.2;color:' . $widgetText . ';background:' . $widgetBg . ';}';
    $styleHtml .= '.cms-sidebar-wrap .sidebar-tag:hover{color:' . $widgetLinkHover . ';border-color:' . $widgetLinkHover . ';text-decoration:none;}';
    if ($sticky) {
        $styleHtml .= '@media(min-width:1024px){.cms-sidebar-wrap{position:sticky;top:1.5rem;}}';
    }
    $styleHtml .= '</style>';

    $widgetsHtml = '<aside class="cms-sidebar-wrap" style="--sidebar-width:' . $width . 'px;">';
    foreach ($widgets as $widget) {
        $widgetsHtml .= cmsRenderSingleSidebarWidget((array)$widget, $db, $cmsSettings, $baseUrl, $publicCtx);
    }
    $widgetsHtml .= '</aside>';

    $bodyHtml = $widgetsHtml;
    if (cmsSidebarThemeTemplateExists()) {
        try {
            $custom = cmsPublicRender('public/sidebar.disyl', [
                'sidebar_widgets_html' => $widgetsHtml,
                'sidebar_widgets' => $widgets,
                'sidebar_settings' => $settings,
                'sidebar_template_key' => $templateKey,
                'sidebar_scope_mode' => $scopeMode,
                'sidebar_template_rules' => $templateRules,
                'sidebar_position' => in_array(($settings['placement'] ?? ''), ['left', 'right'], true) ? $settings['placement'] : 'right',
                'sidebar_width' => (string)$width,
                'cms_settings' => $cmsSettings,
            ]);
            if (trim((string)$custom) !== '') {
                $bodyHtml = (string)$custom;
            }
        } catch (Throwable $e) {
            $bodyHtml = $widgetsHtml;
        }
    }

    $wrappedBodyHtml = cmsRenderShellEntityView('sidebar', $bodyHtml, [
        'data' => [
            'shell-entity-node' => 'region',
            'shell-entity-kind' => 'sidebar',
            'public-render-origin' => (string)($publicCtx['public_render_origin'] ?? 'cms'),
            'public-route-kind' => (string)($publicCtx['public_route_kind'] ?? 'generic'),
            'public-presentation-mode' => (string)($publicCtx['public_presentation_mode'] ?? 'traditional'),
        ],
    ]);

    $html = $styleHtml . $wrappedBodyHtml;

    $result = [
        'enabled' => true,
        'position' => in_array(($settings['placement'] ?? ''), ['left', 'right'], true) ? $settings['placement'] : 'right',
        'width' => (string)$width,
        'html' => $html,
    ];

    cmsCustomizerFragmentCacheSet($cacheFragment, $result, ['cms:customizer:sidebar', 'cms:settings', 'cms:menus']);

    return $result;
}

function cmsRenderSingleSidebarWidget(array $widget, object $db, array $cmsSettings, string $baseUrl, array $publicCtx = []): string
{
    $type  = trim((string)($widget['type'] ?? ''));
    $props = (array)($widget['props'] ?? []);
    $title = trim((string)($props['title'] ?? ''));

    $html = '<div class="sidebar-widget sidebar-widget-' . htmlspecialchars($type) . '">';
    if ($title !== '') {
        $html .= '<h4 class="sidebar-widget-title">' . htmlspecialchars($title) . '</h4>';
    }

    switch ($type) {
        case 'text':
            $content = (string)($props['content'] ?? '');
            $html .= '<div class="sidebar-widget-text">' . $content . '</div>';
            break;
        case 'custom_html':
            $content = (string)($props['content'] ?? '');
            $html .= $content;
            break;
        case 'nav_menu':
            $menuId = (int)($props['menu_id'] ?? 0);
            if ($menuId > 0) {
                $html .= cmsRenderMenuById($db, $menuId, 'sidebar-menu', cmsActiveCustomizerScope());
            }
            break;
        case 'recent_posts':
            $count = max(1, min(10, (int)($props['count'] ?? 5)));
            try {
                $stmt = $db->prepare(
                    "SELECT title, slug FROM cms_content
                     WHERE type = 'post' AND status = 'published' AND deleted_at IS NULL
                     ORDER BY created_at DESC LIMIT :n"
                );
                $stmt->bindValue(':n', $count, PDO::PARAM_INT);
                $stmt->execute();
                $posts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                if (!empty($posts)) {
                    $html .= '<ul class="sidebar-menu">';
                    foreach ($posts as $p) {
                        $href = htmlspecialchars($baseUrl . '/cms/blog/' . $p['slug']);
                        $html .= '<li><a href="' . $href . '">' . htmlspecialchars($p['title']) . '</a></li>';
                    }
                    $html .= '</ul>';
                }
            } catch (Throwable $e) {}
            break;
        case 'search_box':
            $placeholder = trim((string)($props['placeholder'] ?? 'Search...'));
            if ($placeholder === '') {
                $placeholder = 'Search...';
            }
            $buttonLabel = trim((string)($props['button_label'] ?? 'Search'));
            if ($buttonLabel === '') {
                $buttonLabel = 'Search';
            }
            $searchConfig = cmsCustomizerSearchConfig(null, $publicCtx);
            $html .= '<form class="sidebar-search-form" action="' . htmlspecialchars($baseUrl . $searchConfig['action_path']) . '" method="get">';
            $html .= '<input class="sidebar-search-input" type="search" name="' . htmlspecialchars($searchConfig['query_param']) . '" placeholder="' . htmlspecialchars($placeholder) . '">';
            $html .= '<button class="sidebar-search-btn" type="submit">' . htmlspecialchars($buttonLabel) . '</button>';
            $html .= '</form>';
            break;
        case 'categories':
            $count = max(1, min(30, (int)($props['count'] ?? 8)));
            $showCount = (int)($props['show_count'] ?? 1) === 1;
            try {
                $stmt = $db->prepare(
                    "SELECT c.name, c.slug, COUNT(p.id) AS post_count
                     FROM cms_categories c
                     LEFT JOIN cms_content_categories cc ON cc.category_id = c.id
                     LEFT JOIN cms_content p ON p.id = cc.content_id
                       AND p.type = 'post' AND p.status = 'published' AND p.deleted_at IS NULL
                     GROUP BY c.id, c.name, c.slug
                     ORDER BY c.name ASC
                     LIMIT :n"
                );
                $stmt->bindValue(':n', $count, PDO::PARAM_INT);
                $stmt->execute();
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                if (!empty($rows)) {
                    $html .= '<ul class="sidebar-menu">';
                    foreach ($rows as $row) {
                        $label = htmlspecialchars((string)($row['name'] ?? ''));
                        $href = htmlspecialchars($baseUrl . '/cms/blog?category=' . urlencode((string)($row['slug'] ?? '')));
                        $html .= '<li><a href="' . $href . '">' . $label;
                        if ($showCount) {
                            $html .= ' <span style="opacity:.65;">(' . (int)($row['post_count'] ?? 0) . ')</span>';
                        }
                        $html .= '</a></li>';
                    }
                    $html .= '</ul>';
                }
            } catch (Throwable $e) {}
            break;
        case 'tag_cloud':
            $count = max(1, min(60, (int)($props['count'] ?? 20)));
            try {
                $stmt = $db->prepare(
                    "SELECT t.name, t.slug, COUNT(p.id) AS post_count
                     FROM cms_tags t
                     LEFT JOIN cms_content_tags ct ON ct.tag_id = t.id
                     LEFT JOIN cms_content p ON p.id = ct.content_id
                       AND p.type = 'post' AND p.status = 'published' AND p.deleted_at IS NULL
                     GROUP BY t.id, t.name, t.slug
                     ORDER BY post_count DESC, t.name ASC
                     LIMIT :n"
                );
                $stmt->bindValue(':n', $count, PDO::PARAM_INT);
                $stmt->execute();
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                if (!empty($rows)) {
                    $html .= '<div class="sidebar-tag-cloud">';
                    foreach ($rows as $row) {
                        $href = htmlspecialchars($baseUrl . '/cms/blog?tag=' . urlencode((string)($row['slug'] ?? '')));
                        $html .= '<a class="sidebar-tag" href="' . $href . '">' . htmlspecialchars((string)($row['name'] ?? '')) . '</a>';
                    }
                    $html .= '</div>';
                }
            } catch (Throwable $e) {}
            break;
        case 'archives':
            $count = max(1, min(36, (int)($props['count'] ?? 12)));
            $showCount = (int)($props['show_count'] ?? 1) === 1;
            try {
                $stmt = $db->prepare(
                    "SELECT DATE_FORMAT(c.published_at, '%Y-%m') AS ym,
                            DATE_FORMAT(c.published_at, '%M %Y') AS label,
                            COUNT(*) AS post_count
                     FROM cms_content c
                     WHERE c.type = 'post' AND c.deleted_at IS NULL AND " . cmsPublicVisibilitySql('c') . "
                     GROUP BY DATE_FORMAT(c.published_at, '%Y-%m'), DATE_FORMAT(c.published_at, '%M %Y')
                     ORDER BY ym DESC
                     LIMIT :n"
                );
                $stmt->bindValue(':n', $count, PDO::PARAM_INT);
                $stmt->execute();
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                if (!empty($rows)) {
                    $html .= '<ul class="sidebar-menu">';
                    foreach ($rows as $row) {
                        $ym = (string)($row['ym'] ?? '');
                        if ($ym === '') {
                            continue;
                        }
                        $label = htmlspecialchars((string)($row['label'] ?? $ym));
                        $href = htmlspecialchars($baseUrl . '/cms/blog?archive=' . urlencode($ym));
                        $html .= '<li><a href="' . $href . '">' . $label;
                        if ($showCount) {
                            $html .= ' <span style="opacity:.65;">(' . (int)($row['post_count'] ?? 0) . ')</span>';
                        }
                        $html .= '</a></li>';
                    }
                    $html .= '</ul>';
                }
            } catch (Throwable $e) {}
            break;
        case 'cta_button':
            $text = trim((string)($props['text'] ?? 'Learn More'));
            $url = trim((string)($props['url'] ?? '#'));
            if ($text === '') {
                $text = 'Learn More';
            }
            if ($url === '') {
                $url = '#';
            }
            $target = (int)($props['new_tab'] ?? 0) === 1 ? ' target="_blank" rel="noopener noreferrer"' : '';
            $html .= '<a class="sidebar-cta-btn" href="' . htmlspecialchars($url) . '"' . $target . '>' . htmlspecialchars($text) . '</a>';
            break;
        case 'social_links':
            $links = cmsPublicSocialLinks($cmsSettings);
            if (!empty($links)) {
                $html .= '<ul class="sidebar-menu">';
                foreach ($links as $sl) {
                    $html .= '<li><a href="' . htmlspecialchars($sl['url']) . '" target="_blank" rel="noopener noreferrer">' . htmlspecialchars($sl['label']) . '</a></li>';
                }
                $html .= '</ul>';
            }
            break;
        case 'contact_info':
            $address = trim((string)($props['address'] ?? ''));
            $phone   = trim((string)($props['phone'] ?? ''));
            $email   = trim((string)($props['email'] ?? ''));
            if ($address !== '') {
                $html .= '<div class="contact-item"><span>📍</span><span>' . htmlspecialchars($address) . '</span></div>';
            }
            if ($phone !== '') {
                $html .= '<div class="contact-item"><span>📞</span><a href="tel:' . htmlspecialchars($phone) . '">' . htmlspecialchars($phone) . '</a></div>';
            }
            if ($email !== '') {
                $html .= '<div class="contact-item"><span>✉️</span><a href="mailto:' . htmlspecialchars($email) . '">' . htmlspecialchars($email) . '</a></div>';
            }
            break;
        default:
            break;
    }

    $html .= '</div>';
    return cmsRenderShellEntityWidget('sidebar', $widget, $html);
}

/**
 * Validate and sanitize header customizer settings.
 */

function cmsValidateHeaderSettings(array $input): array
{
    $defaults = cmsHeaderSettingsDefaults();
    $validated = [];

    // Layout
    $validated['layout'] = in_array($input['layout'] ?? '', ['default', 'centered', 'logo-left-menu-right'], true)
        ? $input['layout'] : $defaults['layout'];

    // Boolean toggles
    foreach (['sticky', 'show_tagline', 'show_search', 'show_cta_button', 'transparent_home', 'show_topbar', 'topbar_border_bottom'] as $key) {
        $validated[$key] = (int)(bool)($input[$key] ?? $defaults[$key]);
    }

    // String settings
    foreach (['cta_text', 'cta_url', 'menu_location', 'height', 'logo_image_url', 'favicon_url'] as $key) {
        $validated[$key] = trim((string)($input[$key] ?? $defaults[$key]));
    }

    // Logo max height
    $lmh = (int)($input['logo_max_height'] ?? $defaults['logo_max_height']);
    $validated['logo_max_height'] = (string)max(16, min(120, $lmh ?: 40));

    // CTA style
    $validated['cta_style'] = in_array($input['cta_style'] ?? '', ['primary', 'secondary', 'outline'], true)
        ? $input['cta_style'] : $defaults['cta_style'];

    // Inner width
    $validated['inner_width'] = in_array($input['inner_width'] ?? '', ['contained', 'full-width'], true)
        ? $input['inner_width'] : $defaults['inner_width'];

    // Height — 'auto' or pixel value 40-120
    $hVal = trim((string)($validated['height'] ?? 'auto'));
    if ($hVal !== 'auto') {
        $hInt = (int)$hVal;
        $validated['height'] = $hInt >= 40 && $hInt <= 120 ? (string)$hInt : 'auto';
    } else {
        $validated['height'] = 'auto';
    }

    // Padding
    $validated['padding_top'] = (string)max(0, min(80, (int)($input['padding_top'] ?? $defaults['padding_top'])));
    $validated['padding_bottom'] = (string)max(0, min(80, (int)($input['padding_bottom'] ?? $defaults['padding_bottom'])));

    // Top bar font size: 10-18
    $tbFs = (int)($input['topbar_font_size'] ?? $defaults['topbar_font_size']);
    $validated['topbar_font_size'] = (string)max(10, min(18, $tbFs ?: 13));

    // Top bar font weight
    $validated['topbar_font_weight'] = in_array($input['topbar_font_weight'] ?? '', ['normal', '500', '600', 'bold'], true)
        ? $input['topbar_font_weight'] : $defaults['topbar_font_weight'];

    // Top bar alignment
    $validated['topbar_align'] = in_array($input['topbar_align'] ?? '', ['left', 'center', 'right', 'space-between'], true)
        ? $input['topbar_align'] : $defaults['topbar_align'];

    // Top bar vertical padding: 0-24
    $validated['topbar_padding_y'] = (string)max(0, min(24, (int)($input['topbar_padding_y'] ?? $defaults['topbar_padding_y'])));

    // Header background opacity: 0-100
    $validated['header_bg_opacity'] = (string)max(0, min(100, (int)($input['header_bg_opacity'] ?? $defaults['header_bg_opacity'])));

    // Dropdown sizing
    $validated['dropdown_min_width'] = (string)max(160, min(420, (int)($input['dropdown_min_width'] ?? $defaults['dropdown_min_width'])));
    $validated['dropdown_radius'] = (string)max(0, min(20, (int)($input['dropdown_radius'] ?? $defaults['dropdown_radius'])));
    $validated['dropdown_item_padding_y'] = (string)max(6, min(20, (int)($input['dropdown_item_padding_y'] ?? $defaults['dropdown_item_padding_y'])));

    // Color settings
    $colorKeys = ['bg_color', 'text_color', 'link_color', 'link_hover_color',
                  'dropdown_bg_color', 'dropdown_text_color', 'dropdown_hover_bg_color', 'dropdown_hover_text_color', 'dropdown_border_color',
                  'logo_color', 'border_color', 'mobile_bg_color', 'mobile_text_color',
                  'transparent_text_color', 'transparent_logo_color',
                  'topbar_bg_color', 'topbar_text_color', 'topbar_link_color', 'topbar_link_hover_color',
                  'mobile_overlay_color', 'mobile_hover_bg_color', 'mobile_active_bg_color'];
    foreach ($colorKeys as $key) {
        $val = trim((string)($input[$key] ?? $defaults[$key]));
        // Accept hex colors and rgba()
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $val) || preg_match('/^rgba?\(/', $val)) {
            $validated[$key] = $val;
        } else {
            $validated[$key] = $defaults[$key];
        }
    }

    // Mobile menu settings
    $validated['mobile_menu_style'] = in_array($input['mobile_menu_style'] ?? '', ['dropdown', 'canvas'], true)
        ? $input['mobile_menu_style'] : $defaults['mobile_menu_style'];
    $validated['mobile_canvas_direction'] = in_array($input['mobile_canvas_direction'] ?? '', ['left', 'right'], true)
        ? $input['mobile_canvas_direction'] : $defaults['mobile_canvas_direction'];
    $validated['mobile_menu_align'] = in_array($input['mobile_menu_align'] ?? '', ['left', 'center', 'right'], true)
        ? $input['mobile_menu_align'] : $defaults['mobile_menu_align'];
    $mcw = (int)($input['mobile_canvas_width'] ?? $defaults['mobile_canvas_width']);
    $validated['mobile_canvas_width'] = (string)max(220, min(420, $mcw ?: 300));
    $mobileMenuLocationRaw = trim((string)($input['mobile_menu_location'] ?? $defaults['mobile_menu_location']));
    // Backward-compat: if older UI saved numeric menu ID, map it to a location slug.
    if ($mobileMenuLocationRaw !== '' && ctype_digit($mobileMenuLocationRaw)) {
        $menuId = (int)$mobileMenuLocationRaw;
        $mappedSlug = '';
        try {
            $stmt = cmsDb()->prepare('SELECT location FROM cms_menus WHERE id = ? LIMIT 1');
            $stmt->execute([$menuId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $mappedSlug = trim((string)($row['location'] ?? ''));
            if ($mappedSlug === '') {
                $stmt2 = cmsDb()->prepare('SELECT slug FROM cms_menu_locations WHERE menu_id = ? LIMIT 1');
                $stmt2->execute([$menuId]);
                $row2 = $stmt2->fetch(PDO::FETCH_ASSOC);
                $mappedSlug = trim((string)($row2['slug'] ?? ''));
            }
        } catch (Throwable $e) {
            $mappedSlug = '';
        }
        $mobileMenuLocationRaw = $mappedSlug;
    }
    // Validate against registered location slugs.
    $allowedMenuLocations = array_map(
        static fn(array $loc): string => (string)($loc['slug'] ?? ''),
        cmsGetMenuLocations()
    );
    $validated['mobile_menu_location'] = in_array($mobileMenuLocationRaw, $allowedMenuLocations, true)
        ? $mobileMenuLocationRaw
        : '';
    $validated['mobile_logo_url'] = trim((string)($input['mobile_logo_url'] ?? ''));
    $mlh = (int)($input['mobile_logo_max_height'] ?? $defaults['mobile_logo_max_height']);
    $validated['mobile_logo_max_height'] = (string)max(16, min(80, $mlh ?: 36));
    $mbp = (int)($input['mobile_breakpoint'] ?? $defaults['mobile_breakpoint']);
    $validated['mobile_breakpoint'] = in_array($mbp, [640, 768, 1024], true) ? (string)$mbp : $defaults['mobile_breakpoint'];
    foreach (['mobile_close_on_link', 'mobile_overlay'] as $key) {
        $validated[$key] = (int)(bool)($input[$key] ?? $defaults[$key]);
    }

    return $validated;
}

/**
 * Render the customized header HTML for public pages.
 *
 * Returns full <header> markup styled with customizer settings,
 * or empty string if no customizer data exists.
 */

function cmsRenderCustomizedHeader(object $db, array $publicCtx = []): string
{
    $fragmentKey = 'header_html:' . cmsCustomizerRenderContextCacheToken($publicCtx);
    $cached = cmsCustomizerFragmentCacheGet($fragmentKey);
    if (is_array($cached) && array_key_exists('html', $cached)) {
        return (string)$cached['html'];
    }

    if (!cmsCustomizerSectionExists($db, 'header')) {
        cmsCustomizerFragmentCacheSet($fragmentKey, ['html' => ''], ['cms:customizer:header', 'cms:settings', 'cms:menus']);
        return '';
    }

    $data = cmsCustomizerGet($db, 'header');
    $settings = $data['settings'];
    $widgets  = $data['widgets'] ?? [];

    $cmsSettings = readCmsSettings();
    $baseUrl = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
    $scope = trim((string)($publicCtx['active_customizer_scope'] ?? cmsActiveCustomizerScope()));
    if ($scope === '') {
        $scope = cmsActiveCustomizerScope();
    }
    $homeUrl = cmsCustomizerHomeUrl($baseUrl, $scope, $publicCtx);
    $searchConfig = cmsCustomizerSearchConfig($scope, $publicCtx);
    $searchAction = $baseUrl . $searchConfig['action_path'];

    $siteTitle   = htmlspecialchars($cmsSettings['site_title'] ?? 'Site');
    $siteTagline = htmlspecialchars($cmsSettings['site_tagline'] ?? '');

    $bgColor       = htmlspecialchars($settings['bg_color'] ?? '#ffffff');
    $textColor     = htmlspecialchars($settings['text_color'] ?? '#1f2937');
    $linkColor     = htmlspecialchars($settings['link_color'] ?? '#1f2937');
    $linkHover     = htmlspecialchars($settings['link_hover_color'] ?? '#2563eb');
    $dropdownBg    = htmlspecialchars($settings['dropdown_bg_color'] ?? '#ffffff');
    $dropdownText  = htmlspecialchars($settings['dropdown_text_color'] ?? '#1f2937');
    $dropdownHoverBg = htmlspecialchars($settings['dropdown_hover_bg_color'] ?? '#f8fafc');
    $dropdownHoverText = htmlspecialchars($settings['dropdown_hover_text_color'] ?? '#2563eb');
    $dropdownBorder = htmlspecialchars($settings['dropdown_border_color'] ?? '#e5e7eb');
    $dropdownMinWidth = max(160, min(420, (int)($settings['dropdown_min_width'] ?? 220)));
    $dropdownRadius = max(0, min(20, (int)($settings['dropdown_radius'] ?? 8)));
    $dropdownItemPaddingY = max(6, min(20, (int)($settings['dropdown_item_padding_y'] ?? 10)));
    $logoColor     = htmlspecialchars($settings['logo_color'] ?? '#1f2937');
    $borderColor   = htmlspecialchars($settings['border_color'] ?? '#e5e7eb');
    $mobileBg      = htmlspecialchars($settings['mobile_bg_color'] ?? '#ffffff');
    $mobileText    = htmlspecialchars($settings['mobile_text_color'] ?? '#1f2937');
    $isSticky      = (int)($settings['sticky'] ?? 1);
    $shellWidthMode = cmsCustomizerShellWidthMode($settings);
    $innerWidth    = cmsCustomizerShellWidthClasses($settings);
    $layout        = $settings['layout'] ?? 'default';
    $logoUrl       = trim((string)($settings['logo_image_url'] ?? ''));
    $logoMaxH      = (int)($settings['logo_max_height'] ?? 40);
    $faviconUrl    = trim((string)($settings['favicon_url'] ?? ''));

    // Height: "auto" for dynamic or pixel value
    $heightVal     = trim((string)($settings['height'] ?? 'auto'));
    $heightStyle   = ($heightVal === 'auto') ? 'min-height:3.5rem;' : 'min-height:' . (int)$heightVal . 'px;';
    $heightCssVar  = ($heightVal === 'auto') ? 'auto' : (int)$heightVal . 'px';
    $paddingTop    = (int)($settings['padding_top'] ?? 12);
    $paddingBottom = (int)($settings['padding_bottom'] ?? 12);

    $stickyClass = $isSticky ? ' site-header--sticky' : '';
    $layoutClass = ' site-header--' . htmlspecialchars($layout);

    // Build CSS custom properties
    $cssVars = '--header-bg:' . $bgColor . ';'
        . '--header-text:' . $textColor . ';'
        . '--header-link:' . $linkColor . ';'
        . '--header-link-hover:' . $linkHover . ';'
        . '--nav-dropdown-bg:' . $dropdownBg . ';'
        . '--nav-dropdown-link-color:' . $dropdownText . ';'
        . '--nav-dropdown-link-hover-bg:' . $dropdownHoverBg . ';'
        . '--nav-dropdown-link-hover:' . $dropdownHoverText . ';'
        . '--nav-dropdown-border:' . $dropdownBorder . ';'
        . '--nav-dropdown-radius:' . $dropdownRadius . 'px;'
        . '--nav-dropdown-item-padding:' . $dropdownItemPaddingY . 'px 1rem;'
        . '--header-logo-color:' . $logoColor . ';'
        . '--header-border:' . $borderColor . ';'
        . '--header-mobile-bg:' . $mobileBg . ';'
        . '--header-mobile-text:' . $mobileText . ';'
        . '--header-height:' . $heightCssVar . ';';

    // Transparent header: opacity-based background transparency (all pages)
    $transparentJs = '';
    $isTransparentEnabled = (int)($settings['transparent_home'] ?? 0);
    $transText = '';
    $transLogo = '';
    $headerBgOpacity = 100;
    $bgR = 255; $bgG = 255; $bgB = 255;
    if ($isTransparentEnabled) {
        $transText = htmlspecialchars($settings['transparent_text_color'] ?? '#ffffff');
        $transLogo = htmlspecialchars($settings['transparent_logo_color'] ?? '#ffffff');
        $headerBgOpacity = max(0, min(100, (int)($settings['header_bg_opacity'] ?? 100)));
        // Parse bg_color to RGB components for rgba() construction
        $bgColorRaw = $settings['bg_color'] ?? '#ffffff';
        if (preg_match('/^#([0-9a-fA-F]{3,8})$/', $bgColorRaw, $m)) {
            $hex = $m[1];
            if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
            $hex = substr($hex, 0, 6);
            $bgR = hexdec(substr($hex, 0, 2));
            $bgG = hexdec(substr($hex, 2, 2));
            $bgB = hexdec(substr($hex, 4, 2));
        } elseif (preg_match('/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/', $bgColorRaw, $m)) {
            $bgR = (int)$m[1]; $bgG = (int)$m[2]; $bgB = (int)$m[3];
        }
    }

    // Favicon link tag injected before header (picked up by <head> won't work here,
    // but we can inject it as a JS one-liner)
    $faviconSnippet = '';
    if ($faviconUrl !== '') {
        $faviconSafe = htmlspecialchars($faviconUrl, ENT_QUOTES);
        $faviconSnippet = '<script>document.head.querySelector("link[rel=icon]")?.remove();'
            . 'var _fi=document.createElement("link");_fi.rel="icon";_fi.href="' . $faviconSafe . '";'
            . 'document.head.appendChild(_fi);</script>';
    }

    $html = $faviconSnippet;

    // ── Wrapper for topbar + header (sticky lives here, not on <header>) ──
    $wrapperClass = 'header-wrapper cms-shell-width-' . $shellWidthMode;
    if ($isSticky) {
        $wrapperClass .= ' header-wrapper--sticky';
    }
    $html .= '<div class="' . $wrapperClass . '">'; 

    // ── Top Bar (widget strip above the main header) ──────────────────
    $showTopbar = (int)($settings['show_topbar'] ?? 1);
    $hasTopbar = ($showTopbar && !empty($widgets));
    if ($hasTopbar) {
        $tbBg       = htmlspecialchars($settings['topbar_bg_color'] ?? '#1e293b');
        $tbText     = htmlspecialchars($settings['topbar_text_color'] ?? '#e2e8f0');
        $tbLink     = htmlspecialchars($settings['topbar_link_color'] ?? '#93c5fd');
        $tbLinkH    = htmlspecialchars($settings['topbar_link_hover_color'] ?? '#ffffff');
        $tbFs       = max(10, min(18, (int)($settings['topbar_font_size'] ?? 13)));
        $tbFw       = in_array($settings['topbar_font_weight'] ?? 'normal', ['normal','500','600','bold'], true)
                    ? $settings['topbar_font_weight'] : 'normal';
        $tbAlign    = in_array($settings['topbar_align'] ?? 'center', ['left','center','right','space-between'], true)
                    ? $settings['topbar_align'] : 'center';
        $tbPadY     = max(0, min(24, (int)($settings['topbar_padding_y'] ?? 6)));
        $tbBorder   = (int)($settings['topbar_border_bottom'] ?? 1);

        $tbJustify  = $tbAlign === 'space-between' ? 'space-between' : $tbAlign;
        if ($tbJustify === 'left') $tbJustify = 'flex-start';
        if ($tbJustify === 'right') $tbJustify = 'flex-end';

        $tbCssVars  = '--topbar-bg:' . $tbBg . ';'
            . '--topbar-text:' . $tbText . ';'
            . '--topbar-link:' . $tbLink . ';'
            . '--topbar-link-hover:' . $tbLinkH . ';'
            . '--topbar-font-size:' . $tbFs . 'px;'
            . '--topbar-font-weight:' . $tbFw . ';';
        $tbStyle = $tbCssVars
            . 'background:var(--topbar-bg);color:var(--topbar-text);'
            . 'font-size:var(--topbar-font-size);font-weight:var(--topbar-font-weight);'
            . 'padding:' . $tbPadY . 'px 0;'
            . ($tbBorder ? 'border-bottom:1px solid rgba(255,255,255,0.1);' : '');

        $html .= '<div class="header-topbar" style="' . $tbStyle . '">';
        $html .= '<div class="' . $innerWidth . '">';
        $html .= '<div class="header-topbar-inner" style="justify-content:' . $tbJustify . ';">';
        foreach ($widgets as $widget) {
            $html .= cmsRenderSingleHeaderWidget($widget, $db, $cmsSettings, $baseUrl);
        }
        $html .= '</div></div></div>';
    }

    // ── Main Header ──────────────────────────────────────────────────
    $html .= '<header class="site-header' . $stickyClass . $layoutClass . '" style="' . $cssVars . '">';
    $html .= '<div class="' . $innerWidth . '">';
    $html .= '<div class="header-inner" style="' . $heightStyle . 'padding:' . $paddingTop . 'px 0 ' . $paddingBottom . 'px;">';

    // Branding
    $html .= '<div class="site-branding">';
    if ($logoUrl !== '') {
        $html .= '<a href="' . $homeUrl . '" class="site-logo site-logo--image">'
            . '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES) . '" alt="' . $siteTitle . '" '
            . 'style="max-height:' . $logoMaxH . 'px;width:auto;" class="header-logo-img">'
            . '</a>';
    } else {
        $html .= '<a href="' . $homeUrl . '" class="site-logo" style="color:var(--header-logo-color);">' . $siteTitle . '</a>';
    }
    if ((int)($settings['show_tagline'] ?? 0) && $siteTagline !== '') {
        $html .= '<p class="site-tagline">' . $siteTagline . '</p>';
    }
    $html .= '</div>';

    // Mobile toggle
    $mobileStyle = $settings['mobile_menu_style'] ?? 'dropdown';
    $mobileCloseOnLink = (int)($settings['mobile_close_on_link'] ?? 1);
    $html .= '<button class="mobile-menu-toggle" data-close-on-link="' . $mobileCloseOnLink . '" aria-label="Toggle menu"><span></span><span></span><span></span></button>';

    // Navigation — use nav-menu class so theme CSS applies colors correctly
    $menuLocation = $settings['menu_location'] ?? 'primary';
    $menuHtml = '';
    try {
        $menuHtml = cmsRenderMenu($menuLocation, [
            'css_class'    => 'main-navigation',
            'menu_class'   => 'nav-menu',
            'submenu_class' => 'nav-menu-sub',
            'scope' => $scope,
        ]);
    } catch (Throwable $e) {}

    if ($menuHtml !== '') {
        $html .= $menuHtml;
    } else {
        $html .= '<nav class="main-navigation">';
        $html .= '<ul class="nav-menu">';
        foreach (cmsCustomizerFallbackNavItems($baseUrl, $scope, $publicCtx) as $item) {
            $html .= '<li><a href="' . htmlspecialchars($item['href'], ENT_QUOTES) . '">' . htmlspecialchars($item['label'], ENT_QUOTES) . '</a></li>';
        }
        $html .= '</ul>';
        $html .= '</nav>';
    }

    // CTA button
    if ((int)($settings['show_cta_button'] ?? 0)) {
        $ctaText  = htmlspecialchars($settings['cta_text'] ?? 'Get Started');
        $ctaUrl   = htmlspecialchars($settings['cta_url'] ?? '#');
        $ctaStyle = $settings['cta_style'] ?? 'primary';
        $ctaClass = 'header-cta header-cta--' . htmlspecialchars($ctaStyle);
        $html .= '<a href="' . $ctaUrl . '" class="' . $ctaClass . '">' . $ctaText . '</a>';
    }

    // Search toggle + overlay
    if ((int)($settings['show_search'] ?? 0)) {
        $html .= '<button class="header-search-toggle" aria-label="Open search" onclick="document.getElementById(\'header-search-overlay\').classList.add(\'active\')">'
            . '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>'
            . '</button>';
    }

    $html .= '</div></div></header>';
    $html .= '</div>'; // close .header-wrapper before canvas/overlay layers

    // ── Canvas Navigation (separate panel from desktop nav) ──
    if ($mobileStyle === 'canvas') {
        $mobileLogoUrlCanvas = trim((string)($settings['mobile_logo_url'] ?? ''));
        $mobileLogoMaxHCanvas = max(16, min(80, (int)($settings['mobile_logo_max_height'] ?? 36)));
        $canvasHeaderHtml = '<div class="mobile-canvas-header">';
        if ($mobileLogoUrlCanvas !== '') {
            $canvasHeaderHtml .= '<img src="' . htmlspecialchars($mobileLogoUrlCanvas, ENT_QUOTES) . '" alt="' . $siteTitle . '" style="max-height:' . $mobileLogoMaxHCanvas . 'px;width:auto;">';
        } else {
            $canvasHeaderHtml .= '<span style="font-weight:700;font-size:1.125rem;color:var(--header-mobile-text,var(--color-text));">' . $siteTitle . '</span>';
        }
        $canvasHeaderHtml .= '<button class="mobile-canvas-close" aria-label="Close menu"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg></button>';
        $canvasHeaderHtml .= '</div>';

        $canvasMenuLoc = trim((string)($settings['mobile_menu_location'] ?? ''));
        // Backward-compat: legacy value might be a numeric menu ID.
        if ($canvasMenuLoc !== '' && ctype_digit($canvasMenuLoc)) {
            $menuId = (int)$canvasMenuLoc;
            $mappedSlug = '';
            try {
                $stmt = cmsDb()->prepare('SELECT location FROM cms_menus WHERE id = ? LIMIT 1');
                $stmt->execute([$menuId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $mappedSlug = trim((string)($row['location'] ?? ''));
                if ($mappedSlug === '') {
                    $stmt2 = cmsDb()->prepare('SELECT slug FROM cms_menu_locations WHERE menu_id = ? LIMIT 1');
                    $stmt2->execute([$menuId]);
                    $row2 = $stmt2->fetch(PDO::FETCH_ASSOC);
                    $mappedSlug = trim((string)($row2['slug'] ?? ''));
                }
            } catch (Throwable $e) {
                $mappedSlug = '';
            }
            $canvasMenuLoc = $mappedSlug;
        }
        if ($canvasMenuLoc === '') {
            $canvasMenuLoc = $menuLocation;
        }
        $canvasVarStyle = '--header-mobile-bg:' . $mobileBg . ';'
            . '--header-mobile-text:' . $mobileText . ';'
            . '--header-border:' . $borderColor . ';'
            . '--header-link:' . $linkColor . ';';
        $canvasNavHtml = '';
        try {
            $canvasNavHtml = cmsRenderMenu($canvasMenuLoc, [
                'css_class'    => 'canvas-navigation mobile-canvas-target',
                'menu_class'   => 'nav-menu',
                'submenu_class' => 'nav-menu-sub',
                'scope' => $scope,
            ]);
        } catch (Throwable $e) {}
        if ($canvasNavHtml !== '') {
            $canvasNavHtml = preg_replace('/^<nav([^>]*)>/', '<nav$1 style="' . $canvasVarStyle . '">' . $canvasHeaderHtml, $canvasNavHtml, 1) ?: $canvasNavHtml;
            $html .= $canvasNavHtml;
        } else {
            $html .= '<nav class="canvas-navigation mobile-canvas-target" style="' . $canvasVarStyle . '"><ul class="nav-menu">';
            $html .= $canvasHeaderHtml;
            foreach (cmsCustomizerFallbackNavItems($baseUrl, $scope, $publicCtx) as $item) {
                $html .= '<li><a href="' . htmlspecialchars($item['href'], ENT_QUOTES) . '">' . htmlspecialchars($item['label'], ENT_QUOTES) . '</a></li>';
            }
            $html .= '</ul></nav>';
        }
    }

    // Canvas mode uses panel background color only (no fullscreen overlay)

    // ── Mobile-specific CSS injection ──
    $breakpoint = (int)($settings['mobile_breakpoint'] ?? 768);
    $canvasWidth = (int)($settings['mobile_canvas_width'] ?? 300);
    $canvasDir = ($settings['mobile_canvas_direction'] ?? 'left') === 'right' ? 'right' : 'left';
    $canvasAlign = in_array(($settings['mobile_menu_align'] ?? 'left'), ['left','center','right'], true)
        ? $settings['mobile_menu_align'] : 'left';
    $canvasAlignItems = $canvasAlign === 'center' ? 'center' : ($canvasAlign === 'right' ? 'flex-end' : 'flex-start');
    $canvasTextAlign = $canvasAlign === 'center' ? 'center' : ($canvasAlign === 'right' ? 'right' : 'left');
    $canvasHoverBg = htmlspecialchars($settings['mobile_hover_bg_color'] ?? '#f1f5f9');
    $canvasActiveBg = htmlspecialchars($settings['mobile_active_bg_color'] ?? '#e2e8f0');
    $mobileLogoUrl = trim((string)($settings['mobile_logo_url'] ?? ''));
    $mobileLogoMaxH = max(16, min(80, (int)($settings['mobile_logo_max_height'] ?? 36)));

    $mobileCSS = '<style id="cz-mobile-header">';

    // Override the default 768px breakpoint with configured value
    if ($breakpoint !== 768) {
        // Hide the old 768px rule effects by showing them at the configured breakpoint instead
        $mobileCSS .= '@media(min-width:769px) and (max-width:' . $breakpoint . 'px){';
        $mobileCSS .= '.mobile-menu-toggle{display:flex!important;}';
        $mobileCSS .= '.main-navigation{' . ($mobileStyle === 'canvas' ? 'display:block;' : 'position:absolute;top:100%;left:0;right:0;display:none;') . '}';
        $mobileCSS .= '.main-navigation .nav-menu{flex-direction:column;gap:0;padding:1rem;}';
        $mobileCSS .= '.main-navigation .nav-menu a{padding:0.75rem 0;border-bottom:1px solid var(--header-border,var(--color-border));color:var(--header-mobile-text,var(--header-link,var(--color-text)));}';
        $mobileCSS .= '}';
    }

    // Canvas mode styles
    if ($mobileStyle === 'canvas') {
        $translateHide = $canvasDir === 'left' ? 'translateX(-100%)' : 'translateX(100%)';
        $mobileCSS .= '@media(max-width:' . $breakpoint . 'px){';
        // Runtime-selected canvas target (canvas-navigation preferred; main-navigation fallback)
        $mobileCSS .= '.mobile-canvas-target{position:fixed;top:0;' . $canvasDir . ':0;bottom:0;width:' . $canvasWidth . 'px;max-width:85vw;';
        $mobileCSS .= 'background:var(--header-mobile-bg,var(--header-bg,#fff));color:var(--header-mobile-text,var(--color-text));';
        $mobileCSS .= 'transform:' . $translateHide . ';transition:transform 0.3s cubic-bezier(0.4,0,0.2,1);';
        $mobileCSS .= 'z-index:2147483001;overflow-y:auto;display:block!important;box-shadow:none;padding:0;}';
        $mobileCSS .= '.mobile-canvas-target.active{transform:translateX(0);box-shadow:0 0 20px rgba(0,0,0,0.15);}';
        $mobileCSS .= '.mobile-canvas-target .nav-menu{flex-direction:column;align-items:' . $canvasAlignItems . ';gap:0;padding:1.25rem;}';
        $mobileCSS .= '.mobile-canvas-target .nav-menu li{width:100%;}';
        $mobileCSS .= '.mobile-canvas-target .nav-menu a{padding:0.875rem 0;border-bottom:1px solid var(--header-border,var(--color-border,#e5e7eb));';
        $mobileCSS .= 'color:var(--header-mobile-text,var(--header-link,var(--color-text)));font-size:1rem;display:block;width:100%;text-align:' . $canvasTextAlign . ';padding-left:0.75rem;padding-right:0.75rem;border-radius:0.5rem;}';
        $mobileCSS .= '.mobile-canvas-target .nav-menu a:hover{background:' . $canvasHoverBg . ';}';
        $mobileCSS .= '.mobile-canvas-target .nav-menu li.current-menu-item>a{background:' . $canvasActiveBg . ';}';
        $mobileCSS .= '.mobile-canvas-target .nav-menu-sub{position:static;top:auto;left:auto;min-width:0;width:100%;opacity:1;visibility:visible;transform:none;';
        $mobileCSS .= 'display:block;margin:0;padding:0 0 0.5rem 0.75rem;border:none;border-radius:0;box-shadow:none;background:transparent;}';
        $mobileCSS .= '.mobile-canvas-target .nav-menu-sub a{font-size:0.95rem;padding-top:0.625rem;padding-bottom:0.625rem;}';
        // Canvas header area (logo + close button)
        $mobileCSS .= '.mobile-canvas-header{display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;border-bottom:1px solid var(--header-border,var(--color-border,#e5e7eb));}';
        $mobileCSS .= '.mobile-canvas-close{background:none;border:none;cursor:pointer;color:var(--header-mobile-text,var(--color-text));padding:0.5rem;}';
        $mobileCSS .= '}';
        // At desktop, hide canvas-specific nav and elements
        $mobileCSS .= '@media(min-width:' . ($breakpoint + 1) . 'px){';
        $mobileCSS .= '.canvas-navigation{display:none!important;}';
        $mobileCSS .= '.mobile-canvas-target{position:static!important;transform:none!important;box-shadow:none!important;z-index:auto!important;max-width:none!important;width:auto!important;}';
        $mobileCSS .= '.mobile-canvas-header{display:none!important;}';
        $mobileCSS .= '}';
    }

    $mobileCSS .= '@media(max-width:' . $breakpoint . 'px){';
    $mobileCSS .= '.main-navigation .nav-menu-sub{position:static;top:auto;left:auto;min-width:0;width:100%;opacity:1;visibility:visible;transform:none;';
    $mobileCSS .= 'display:block;margin:0;padding:0 0 0.5rem 0.75rem;border:none;border-radius:0;box-shadow:none;background:transparent;}';
    $mobileCSS .= '.main-navigation .nav-menu-sub a{white-space:normal;}';
    $mobileCSS .= '}';

    // Mobile logo swap
    if ($mobileLogoUrl !== '') {
        $mobileCSS .= '@media(max-width:' . $breakpoint . 'px){';
        $mobileCSS .= '.header-logo-img{content:url(' . htmlspecialchars($mobileLogoUrl, ENT_QUOTES) . ');max-height:' . $mobileLogoMaxH . 'px!important;}';
        $mobileCSS .= '}';
    }

    $mobileCSS .= '</style>';
    $html .= $mobileCSS;

    // Desktop submenu styling from customizer settings
    $dropdownCSS  = '<style id="cz-header-dropdown">';
    $dropdownCSS .= '.site-header .nav-menu-sub{background:var(--nav-dropdown-bg,#fff);border:1px solid var(--nav-dropdown-border,#e5e7eb);border-radius:var(--nav-dropdown-radius,8px);min-width:' . $dropdownMinWidth . 'px;padding:0.375rem 0;box-shadow:0 12px 28px rgba(15,23,42,.12);}';
    $dropdownCSS .= '.site-header .nav-menu-sub li{list-style:none;margin:0;padding:0;}';
    $dropdownCSS .= '.site-header .nav-menu-sub a{display:flex;align-items:center;box-sizing:border-box;width:100%;line-height:1.2;color:var(--nav-dropdown-link-color,var(--header-link,var(--color-text)));padding:var(--nav-dropdown-item-padding,10px 1rem);}';
    $dropdownCSS .= '.site-header .nav-menu-sub li:hover>a,.site-header .nav-menu-sub a:hover,.site-header .nav-menu-sub li.current-menu-item>a{background:var(--nav-dropdown-link-hover-bg,#f8fafc);color:var(--nav-dropdown-link-hover,var(--header-link-hover,var(--color-primary)));}';
    $dropdownCSS .= '.site-header .nav-menu-sub .nav-menu-sub{top:0;left:100%;margin-left:2px;}';
    $dropdownCSS .= '</style>';
    $html .= $dropdownCSS;

    // Canvas behavior is handled centrally in theme JS (`script.js`) to avoid competing listeners.

    // Search overlay (outside wrapper for z-index stacking)
    if ((int)($settings['show_search'] ?? 0)) {
        $html .= '<div id="header-search-overlay" class="header-search-overlay">'
            . '<div class="header-search-overlay-inner">'
            . '<form action="' . $searchAction . '" method="GET" class="header-search-form">'
            . '<input type="text" name="' . htmlspecialchars($searchConfig['query_param'], ENT_QUOTES) . '" class="header-search-input" placeholder="' . htmlspecialchars($searchConfig['placeholder'], ENT_QUOTES) . '" autocomplete="off" autofocus>'
            . '<button type="submit" class="header-search-submit" aria-label="Search">'
            . '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>'
            . '</button>'
            . '</form>'
            . '<button class="header-search-close" aria-label="Close search" onclick="document.getElementById(\'header-search-overlay\').classList.remove(\'active\')">'
            . '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>'
            . '</button>'
            . '</div></div>';
    }

    // Scroll-transparency JS — on scroll past threshold, header bg becomes
    // semi-transparent (transparent_bg_color). At top of page, normal bg shows.
    if ($isTransparentEnabled) {
        $opVal = $headerBgOpacity / 100;
        $transparentJs  = '<script>(function(){';
        $transparentJs .= 'var w=document.querySelector(".header-wrapper");';
        $transparentJs .= 'if(!w)return;';
        $transparentJs .= 'var h=w.querySelector(".site-header");';
        $transparentJs .= 'if(!h)return;';
        // RGB components of bg_color + configured opacity
        $transparentJs .= 'var r=' . $bgR . ',g=' . $bgG . ',b=' . $bgB . ',op=' . $opVal . ';';
        $transparentJs .= 'var tx="' . $transText . '",lg="' . $transLogo . '";';
        // Save original (normal) header colors
        $transparentJs .= 'var oBg=h.style.getPropertyValue("--header-bg"),oTx=h.style.getPropertyValue("--header-text"),';
        $transparentJs .= 'oLk=h.style.getPropertyValue("--header-link"),oLh=h.style.getPropertyValue("--header-link-hover"),';
        $transparentJs .= 'oLg=h.style.getPropertyValue("--header-logo-color"),oBd=h.style.getPropertyValue("--header-border");';
        $transparentJs .= 'var active=false;';
        // Apply transparent state (at top of page — header overlays content)
        $transparentJs .= 'function apply(){';
        $transparentJs .= 'if(active)return;active=true;';
        $transparentJs .= 'h.style.setProperty("--header-bg","rgba("+r+","+g+","+b+","+op+")");';
        $transparentJs .= 'h.style.setProperty("--header-text",tx);h.style.setProperty("--header-link",tx);';
        $transparentJs .= 'h.style.setProperty("--header-link-hover","#ffffff");';
        $transparentJs .= 'h.style.setProperty("--header-logo-color",lg);h.style.setProperty("--header-border","transparent");';
        $transparentJs .= '}';
        // Revert to normal text colors but keep opacity on background (for sticky scroll)
        $transparentJs .= 'function revert(){';
        $transparentJs .= 'if(!active)return;active=false;';
        $transparentJs .= 'h.style.setProperty("--header-bg","rgba("+r+","+g+","+b+","+op+")");';
        $transparentJs .= 'h.style.setProperty("--header-text",oTx);';
        $transparentJs .= 'h.style.setProperty("--header-link",oLk);h.style.setProperty("--header-link-hover",oLh);';
        $transparentJs .= 'h.style.setProperty("--header-logo-color",oLg);h.style.setProperty("--header-border",oBd);';
        $transparentJs .= '}';
        // Start transparent; on scroll down revert to solid, scroll back up re-apply transparent
        $transparentJs .= 'apply();';
        $transparentJs .= 'window.addEventListener("scroll",function(){';
        $transparentJs .= 'if(window.scrollY>80){revert();}else{apply();}';
        $transparentJs .= '},{passive:true});';
        $transparentJs .= '})();</script>';
        $html .= $transparentJs;
    }

    $themeWrapped = cmsRenderActiveThemeCustomizerPartial('header', [
        'header_html' => $html,
        'header_settings' => $settings,
        'header_widgets' => $widgets,
        'cms_settings' => $cmsSettings,
    ]);
    if ($themeWrapped !== '') {
        $html = $themeWrapped;
    }

    $html = cmsRenderShellEntityView('header', $html, [
        'data' => [
            'shell-entity-node' => 'region',
            'shell-entity-kind' => 'header',
            'public-render-origin' => (string)($publicCtx['public_render_origin'] ?? 'cms'),
            'public-route-kind' => (string)($publicCtx['public_route_kind'] ?? 'generic'),
            'public-presentation-mode' => (string)($publicCtx['public_presentation_mode'] ?? 'traditional'),
        ],
    ]);

    cmsCustomizerFragmentCacheSet($fragmentKey, ['html' => $html], ['cms:customizer:header', 'cms:settings', 'cms:menus']);
    return $html;
}

function cmsRenderActiveThemeCustomizerPartial(string $partialName, array $context = []): string
{
    $partialName = trim($partialName);
    if ($partialName === '') {
        return '';
    }

    try {
        cmsEnsureThemeSymlink();
    } catch (Throwable $e) {
        return '';
    }

    $relativePath = 'public/' . $partialName . '.disyl';
    $fullPath = CMS_THEME_SYMLINK . '/' . $relativePath;
    if (!is_file($fullPath)) {
        return '';
    }

    try {
        return cmsWithThemeSymlinkLock(static function () use ($relativePath, $context): string {
            return cmsRender('_cms_active_theme/' . $relativePath, $context);
        }, LOCK_SH);
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Render a single header widget element.
 */

function cmsRenderSingleHeaderWidget(array $widget, object $db, array $cmsSettings, string $baseUrl): string
{
    $type  = trim((string)($widget['type'] ?? ''));
    $props = (array)($widget['props'] ?? []);
    $title = trim((string)($props['title'] ?? ''));

    $html = '<div class="header-widget header-widget-' . htmlspecialchars($type) . '">';

    switch ($type) {
        case 'text':
            $content    = trim((string)($props['content'] ?? ''));
            $fontSize   = max(10, min(24, (int)($props['font_size'] ?? 13)));
            $rawWeight  = trim((string)($props['font_weight'] ?? 'normal'));
            $fontWeight = in_array($rawWeight, ['normal','500','600','bold'], true) ? $rawWeight : 'normal';
            if ($content !== '') {
                $style = 'font-size:' . $fontSize . 'px;font-weight:' . $fontWeight;
                $html .= '<span class="header-widget-text" style="' . $style . '">' . htmlspecialchars($content) . '</span>';
            }
            break;

        case 'custom_html':
            $content = trim((string)($props['content'] ?? ''));
            $html .= $content; // raw HTML
            break;

        case 'social_links':
            $socialLinks = cmsPublicSocialLinks($cmsSettings);
            if (!empty($socialLinks)) {
                $html .= '<div class="header-social-links">';
                foreach ($socialLinks as $sl) {
                    $html .= '<a href="' . htmlspecialchars($sl['url'], ENT_QUOTES) . '" target="_blank" rel="noopener" '
                        . 'aria-label="' . htmlspecialchars($sl['platform'], ENT_QUOTES) . '" class="header-social-link">'
                        . cmsGetSocialIcon($sl['platform'])
                        . '</a>';
                }
                $html .= '</div>';
            }
            break;

        case 'contact_info':
            $phone   = trim((string)($props['phone'] ?? ''));
            $email   = trim((string)($props['email'] ?? ''));
            $address = trim((string)($props['address'] ?? ''));
            if ($address !== '') {
                $html .= '<span class="header-widget-contact header-widget-address">'
                    . '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>'
                    . ' ' . htmlspecialchars($address, ENT_QUOTES)
                    . '</span>';
            }
            if ($phone !== '') {
                $html .= '<a href="tel:' . htmlspecialchars($phone, ENT_QUOTES) . '" class="header-widget-contact">'
                    . '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6A19.79 19.79 0 012.12 4.18 2 2 0 014.11 2h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>'
                    . ' ' . htmlspecialchars($phone, ENT_QUOTES)
                    . '</a>';
            }
            if ($email !== '') {
                $html .= '<a href="mailto:' . htmlspecialchars($email, ENT_QUOTES) . '" class="header-widget-contact">'
                    . '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>'
                    . ' ' . htmlspecialchars($email, ENT_QUOTES)
                    . '</a>';
            }
            break;

        case 'nav_menu':
            $menuId = (int)($props['menu_id'] ?? 0);
            if ($menuId > 0) {
                try {
                    $stmt = $db->prepare("SELECT location FROM cms_menus WHERE id = :id LIMIT 1");
                    $stmt->execute([':id' => $menuId]);
                    $menuRow = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($menuRow) {
                        $html .= cmsRenderMenu($menuRow['location'], [
                            'css_class'     => 'header-widget-nav',
                            'menu_class'    => 'header-widget-menu',
                            'submenu_class' => 'header-widget-submenu',
                            'depth'         => 1,
                            'scope'         => cmsActiveCustomizerScope(),
                        ]);
                    }
                } catch (Throwable $e) {}
            }
            break;

        case 'button':
            $btnText  = htmlspecialchars(trim((string)($props['text'] ?? 'Click')));
            $btnUrl   = htmlspecialchars(trim((string)($props['url'] ?? '#')));
            $btnStyle = in_array($props['style'] ?? 'primary', ['primary','secondary','outline','link'], true)
                      ? $props['style'] : 'primary';
            $newTab   = (int)($props['new_tab'] ?? 0) ? ' target="_blank" rel="noopener"' : '';
            $btnClass = $btnStyle === 'link' ? 'header-widget-link' : 'header-widget-btn header-widget-btn--' . $btnStyle;
            $html .= '<a href="' . $btnUrl . '" class="' . $btnClass . '"' . $newTab . '>' . $btnText . '</a>';
            break;

        case 'opening_hours':
            $ohText = htmlspecialchars(trim((string)($props['text'] ?? '')));
            $showIcon = (int)($props['icon'] ?? 1);
            if ($ohText !== '') {
                $html .= '<span class="header-widget-hours">';
                if ($showIcon) {
                    $html .= '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> ';
                }
                $html .= $ohText . '</span>';
            }
            break;

        default:
            break;
    }

    $html .= '</div>';
    return cmsRenderShellEntityWidget('header', $widget, $html);
}

// ── Hook Registrations ──────────────────────────────────────────────

// Register CMS home URL for CMS roles
app()->hooks()->on('kernel.home_url', function (?string $url, string $role) {
    // CMS roles get redirected to CMS admin dashboard
    if (isset(CMS_ROLES[$role])) {
        return '/cms/admin';
    }
    return $url;
}, 50);

// Register CMS auth cookie so kernel recognizes it
app()->hooks()->on('kernel.auth_cookie_names', function (array $cookies) {
    $cookies[] = 'cms_token';
    return $cookies;
}, 10);

// Expose CMS settings via kernel hook so other modules can read them
app()->hooks()->on('cms.settings', function (array $defaults): array {
    return array_merge($defaults, readCmsSettings());
}, 10);
