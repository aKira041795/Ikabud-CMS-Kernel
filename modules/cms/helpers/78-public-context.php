<?php

declare(strict_types=1);

function cmsCheckDangerousFileSignature(string $filePath): ?string
{
    if (!is_file($filePath)) return null;

    $handle = @fopen($filePath, 'rb');
    if (!$handle) return null;

    $header = fread($handle, 256);
    fclose($handle);

    if ($header === false || $header === '') return null;

    $signatures = [
        '<?php'    => 'PHP code',
        '<?='      => 'PHP short tag',
        '#!/'      => 'Shell script',
        '<script'  => 'JavaScript in HTML',
    ];

    foreach ($signatures as $sig => $label) {
        if (stripos($header, $sig) !== false) {
            return 'File contains dangerous content (' . $label . ')';
        }
    }

    // Check for Windows/Linux executables at byte 0
    if (strlen($header) >= 2) {
        if (substr($header, 0, 2) === 'MZ') {
            return 'File appears to be a Windows executable';
        }
        if (substr($header, 0, 4) === "\x7fELF") {
            return 'File appears to be a Linux executable';
        }
    }

    return null;
}

function cmsPublicContextTimingEnabled(): bool
{
    return timing_logs_enabled('CMS_PUBLIC_CONTEXT_TIMING') || timing_logs_enabled('APP_TIMING_LOGS');
}

function cmsPublicContextTimingThresholdMs(): int
{
    $threshold = timing_logs_threshold_ms('CMS_PUBLIC_CONTEXT_TIMING_THRESHOLD_MS', -1);
    if ($threshold >= 0) {
        return $threshold;
    }

    return timing_logs_threshold_ms('APP_TIMING_THRESHOLD_MS', 0);
}

function cmsPublicContextLogStage(string $stage, float $startTime, array $context = []): ?float
{
    return log_timing(
        'cms.public_context.' . $stage,
        $startTime,
        $context,
        'CMS_PUBLIC_CONTEXT_TIMING',
        'CMS_PUBLIC_CONTEXT_TIMING_THRESHOLD_MS'
    );
}

function cmsPublicContextDetailedTimingEnabled(): bool
{
    $value = $_ENV['CMS_PUBLIC_CONTEXT_TIMING_VERBOSE'] ?? null;
    if ($value === null || $value === '') {
        return false;
    }

    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

function cmsPublicContextCrossRequestCacheTtl(): int
{
    $ttl = (int)($_ENV['CMS_PUBLIC_CONTEXT_CACHE_TTL'] ?? 120);
    if ($ttl < 60) {
        $ttl = 60;
    }
    if ($ttl > 180) {
        $ttl = 180;
    }

    return $ttl;
}

function cmsPublicContextCacheHost(): string
{
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    return strtolower($host);
}

function cmsPublicContextNormalizeForCache($value)
{
    if (!is_array($value)) {
        return $value;
    }

    $normalized = [];
    foreach ($value as $key => $item) {
        $normalized[$key] = cmsPublicContextNormalizeForCache($item);
    }

    if (array_keys($normalized) !== range(0, count($normalized) - 1)) {
        ksort($normalized);
    }

    return $normalized;
}

function cmsPublicContextDerivedCacheKey(array $factors, array $extra): string
{
    $payload = [
        'tenant_id' => cmsRuntimeTenantId(),
        'host' => cmsPublicContextCacheHost(),
        'factors' => cmsPublicContextNormalizeForCache($factors),
        'extra' => cmsPublicContextNormalizeForCache($extra),
    ];

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') {
        $json = serialize($payload);
    }

    return 'cms:public_context:derived:v1:' . hash('sha256', $json);
}

function cmsPublicContextSectionAvailability(): array
{
    $cache = $GLOBALS[cmsCustomizerRequestCacheKey('section_row')] ?? null;
    if (!is_array($cache)) {
        return [];
    }

    $availability = [];
    foreach ($cache as $section => $row) {
        if (!is_string($section) || $section === '') {
            continue;
        }
        $availability[$section] = $row !== null;
    }

    return $availability;
}

function cmsPublicContextHasSection(array $availability, string $section): bool
{
    if (!array_key_exists($section, $availability)) {
        return true;
    }

    return $availability[$section] === true;
}

// ── Public Context Enrichment ────────────────────────────────────────

/**
 * Build common render context for public CMS templates.
 * Includes menus, social links, current year, site settings.
 */

function cmsPublicContext(array $extra = []): array
{
    // Per-request cache: avoid rebuilding menus, customizer, and theme
    // context when multiple handlers call cmsPublicContext() in the same request.
    static $cached = null;
    static $cachedExtra = null;
    if ($cached !== null && $cachedExtra === $extra) {
        return $cached;
    }

    $timingEnabled = cmsPublicContextTimingEnabled();
    $detailedTimingEnabled = $timingEnabled && cmsPublicContextDetailedTimingEnabled();
    $totalStart = $timingEnabled ? microtime(true) : 0.0;
    $stageStart = $timingEnabled ? microtime(true) : 0.0;
    $settings = readCmsSettings();
    $db = cmsDb();
    $baseUrl = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
    $requestType = !empty($extra['entity']['id']) ? 'entity' : (!empty($extra['content']['id']) ? 'content' : 'generic');
    $builderEnabled = !empty($extra['builder_enabled']);
    $builderSidebarRequested = $builderEnabled
        && isset($extra['sidebar_template'])
        && is_string($extra['sidebar_template'])
        && trim($extra['sidebar_template']) !== '';
    $publicRenderOrigin = trim((string)($extra['public_render_origin'] ?? 'cms'));
    $requestedRouteKind = trim((string)($extra['public_route_kind'] ?? $extra['ecommerce_public_route'] ?? 'generic'));
    $publicRouteKind = $publicRenderOrigin === 'ecommerce' && function_exists('cmsNormalizeEcommercePublicRouteKind')
        ? cmsNormalizeEcommercePublicRouteKind($requestedRouteKind)
        : ($requestedRouteKind !== '' ? $requestedRouteKind : 'generic');
    $requestedPresentationMode = trim((string)($extra['public_presentation_mode'] ?? ''));
    $themePolicy = function_exists('cmsResolveEcommerceThemePolicy')
        ? cmsResolveEcommerceThemePolicy(array_merge($extra, [
            'public_render_origin' => $publicRenderOrigin,
            'public_route_kind' => $publicRouteKind,
        ]))
        : [];
    $activeThemeSlug = trim((string)($themePolicy['active_theme'] ?? (cmsActiveTheme() ?? 'native-default')));
    if ($activeThemeSlug === '') {
        $activeThemeSlug = 'native-default';
    }
    $publicPresentationMode = $publicRenderOrigin !== 'ecommerce' && $requestedPresentationMode !== ''
        ? $requestedPresentationMode
        : trim((string)($themePolicy['public_presentation_mode'] ?? (
            function_exists('cmsEcommercePublicPresentationMode')
                ? cmsEcommercePublicPresentationMode(array_merge($extra, ['public_route_kind' => $publicRouteKind]))
                : trim((string)($extra['public_presentation_mode'] ?? 'traditional'))
        )));
    if ($publicPresentationMode === '') {
        $publicPresentationMode = 'traditional';
    }
    $activeThemeSource = trim((string)($themePolicy['active_theme_source'] ?? 'site'));
    $activeCustomizerScope = trim((string)($themePolicy['active_theme_scope'] ?? (function_exists('cmsActiveCustomizerScope') ? cmsActiveCustomizerScope() : 'native')));
    if ($detailedTimingEnabled) {
        cmsPublicContextLogStage('init', $stageStart, ['theme' => $activeThemeSlug, 'request_type' => $requestType]);
    }

    $derivedCacheTtl = cmsCacheEnabled() ? cmsPublicContextCrossRequestCacheTtl() : 0;
    $derivedCacheKey = cmsPublicContextDerivedCacheKey([
        'theme' => $activeThemeSlug,
        'theme_source' => $activeThemeSource,
        'customizer_scope' => $activeCustomizerScope,
        'public_render_origin' => $publicRenderOrigin,
        'public_route_kind' => $publicRouteKind,
        'public_presentation_mode' => $publicPresentationMode,
        'request_type' => $requestType,
        'builder_enabled' => $builderEnabled,
        'builder_sidebar_requested' => $builderSidebarRequested,
    ], $extra);

    $derivedCtx = null;
    if ($derivedCacheTtl > 0) {
        try {
            $cachedPayload = app()->cache()->get(cmsCacheInstance(), $derivedCacheKey);
            if (is_array($cachedPayload) && isset($cachedPayload['ctx']) && is_array($cachedPayload['ctx'])) {
                $derivedCtx = $cachedPayload['ctx'];
            }
        } catch (Throwable $e) {
            $derivedCtx = null;
        }
    }

    if ($derivedCtx !== null) {
        $ctx = $derivedCtx;
    } else {

    // Preload all customizer sections in one DB query instead of 6 separate ones
    $stageStart = $timingEnabled ? microtime(true) : 0.0;
    try {
        cmsEnsureCustomizerScopeSeeded($db);
        cmsCustomizerPreloadAll($db);
    } catch (Throwable $e) {
        // Non-fatal — individual lookups will fall back to per-section queries
    }
    $sectionAvailability = cmsPublicContextSectionAvailability();
    if ($detailedTimingEnabled) {
        cmsPublicContextLogStage('customizer_preload', $stageStart, ['theme' => $activeThemeSlug, 'request_type' => $requestType]);
    }

    // Build primary menu
    $primaryMenu = '';
    $stageStart = $timingEnabled ? microtime(true) : 0.0;
    try {
        $primaryMenu = cmsRenderMenu('primary');
    } catch (Throwable $e) {
        // Menu may not exist yet
    }
    if ($detailedTimingEnabled) {
        cmsPublicContextLogStage('primary_menu', $stageStart, ['theme' => $activeThemeSlug, 'request_type' => $requestType]);
    }

    // Build footer menu
    $footerMenu = '';
    $stageStart = $timingEnabled ? microtime(true) : 0.0;
    try {
        $footerMenu = cmsRenderMenu('footer');
    } catch (Throwable $e) {}
    if ($detailedTimingEnabled) {
        cmsPublicContextLogStage('footer_menu', $stageStart, ['theme' => $activeThemeSlug, 'request_type' => $requestType]);
    }

    // Social links from settings
    $socialLinks = cmsPublicSocialLinks($settings);

    $headerSection = ['settings' => function_exists('cmsHeaderSettingsDefaults') ? cmsHeaderSettingsDefaults() : []];
    $footerSection = ['settings' => function_exists('cmsFooterSettingsDefaults') ? cmsFooterSettingsDefaults() : []];
    try {
        $headerSection = function_exists('cmsCustomizerGet')
            ? cmsCustomizerGet($db, 'header', $activeCustomizerScope)
            : $headerSection;
    } catch (Throwable $e) {
        $headerSection = ['settings' => function_exists('cmsHeaderSettingsDefaults') ? cmsHeaderSettingsDefaults() : []];
    }
    try {
        $footerSection = function_exists('cmsCustomizerGet')
            ? cmsCustomizerGet($db, 'footer', $activeCustomizerScope)
            : $footerSection;
    } catch (Throwable $e) {
        $footerSection = ['settings' => function_exists('cmsFooterSettingsDefaults') ? cmsFooterSettingsDefaults() : []];
    }

    $headerSettings = function_exists('cmsValidateHeaderSettings')
        ? cmsValidateHeaderSettings(is_array($headerSection['settings'] ?? null) ? $headerSection['settings'] : [])
        : [];
    $footerSettings = function_exists('cmsValidateFooterSettings')
        ? cmsValidateFooterSettings(is_array($footerSection['settings'] ?? null) ? $footerSection['settings'] : [])
        : [];
    $sidebarSection = ['settings' => function_exists('cmsSidebarSettingsDefaults') ? cmsSidebarSettingsDefaults($activeCustomizerScope) : []];
    try {
        $sidebarSection = function_exists('cmsCustomizerGet')
            ? cmsCustomizerGet($db, 'sidebar', $activeCustomizerScope)
            : $sidebarSection;
    } catch (Throwable $e) {
        $sidebarSection = ['settings' => function_exists('cmsSidebarSettingsDefaults') ? cmsSidebarSettingsDefaults($activeCustomizerScope) : []];
    }
    $sidebarSettings = is_array($sidebarSection['settings'] ?? null) ? $sidebarSection['settings'] : [];

    $ctx = [
        'site_title'    => (string)($settings['site_title'] ?? ''),
        'site_tagline'  => (string)($settings['site_tagline'] ?? ''),
        'current_year'  => date('Y'),
        'active_theme_slug' => $activeThemeSlug,
        'theme_style_url' => cmsThemeAssetUrl('style.css', $baseUrl),
        'theme_script_url' => cmsThemeAssetUrl('script.js', $baseUrl),
        'cms_public_css_url' => rtrim($baseUrl, '/') . '/assets/cms/cms-public.css',
        'primary_menu'  => $primaryMenu,
        'footer_menu'   => $footerMenu,
        'social_links'  => $socialLinks,
        'cms_settings'  => $settings,
        'active_theme' => $activeThemeSlug,
        'active_theme_source' => $activeThemeSource,
        'active_customizer_scope' => $activeCustomizerScope,
        'configured_site_theme' => (string)($themePolicy['configured_site_theme'] ?? ''),
        'preferred_storefront_theme' => (string)($themePolicy['preferred_storefront_theme'] ?? ''),
        'public_render_origin' => $publicRenderOrigin,
        'public_route_kind' => $publicRouteKind !== '' ? $publicRouteKind : 'generic',
        'public_presentation_mode' => $publicPresentationMode !== '' ? $publicPresentationMode : 'traditional',
        'is_ecommerce_public' => $publicRenderOrigin === 'ecommerce',
        'is_ecommerce_entity_route' => !empty($themePolicy['is_ecommerce_entity_route']),
        'header_layout' => (string)($headerSettings['layout'] ?? 'default'),
        'header_sticky' => !empty($headerSettings['sticky']),
        'header_show_topbar' => !empty($headerSettings['show_topbar']),
        'header_show_tagline' => !empty($headerSettings['show_tagline']),
        'header_show_search' => !empty($headerSettings['show_search']),
        'header_show_cta_button' => !empty($headerSettings['show_cta_button']),
        'header_cta_text' => (string)($headerSettings['cta_text'] ?? 'Get Started'),
        'header_cta_url' => (string)($headerSettings['cta_url'] ?? ''),
        'header_logo_image_url' => (string)($headerSettings['logo_image_url'] ?? ''),
        'header_logo_max_height' => (string)($headerSettings['logo_max_height'] ?? '40'),
        'header_mobile_menu_style' => (string)($headerSettings['mobile_menu_style'] ?? 'dropdown'),
        'header_mobile_breakpoint' => (string)($headerSettings['mobile_breakpoint'] ?? '768'),
        'footer_columns' => (int)($footerSettings['columns'] ?? 3),
        'footer_admin_link' => !empty($footerSettings['show_admin_link']),
        'footer_copyright_text' => trim((string)($footerSettings['copyright_text'] ?? '')),
    ];

    $renderCtx = array_merge($ctx, $extra);

    // Render canonical footer region
    $stageStart = $timingEnabled ? microtime(true) : 0.0;
    try {
        if (cmsPublicContextHasSection($sectionAvailability, 'footer')) {
            $footerResult = cmsDispatchThemeCustomizer('footer', $db, $renderCtx);
            $footerHtml = is_string($footerResult) ? $footerResult : '';
            $ctx['footer_region'] = [
                'present' => ($footerHtml !== ''),
                'html' => $footerHtml,
                'source' => $footerHtml !== '' ? 'theme_region' : 'none',
            ];
        } else {
            $ctx['footer_region'] = ['present' => false, 'html' => '', 'source' => 'disabled'];
        }
    } catch (Throwable $e) {
        $ctx['footer_region'] = ['present' => false, 'html' => '', 'source' => 'error'];
    }
    // @deprecated 6.1 — legacy alias for footer_region.html/present.
    // Use {ikb_region name="footer"} in DiSyL templates instead.
    $ctx['customized_footer'] = (string)($ctx['footer_region']['html'] ?? '');
    $ctx['has_customized_footer'] = !empty($ctx['footer_region']['present']);
    if ($detailedTimingEnabled) {
        cmsPublicContextLogStage('customized_footer', $stageStart, ['theme' => $activeThemeSlug, 'request_type' => $requestType]);
    }

    // Render canonical header region
    $stageStart = $timingEnabled ? microtime(true) : 0.0;
    try {
        if (cmsPublicContextHasSection($sectionAvailability, 'header')) {
            $headerResult = cmsDispatchThemeCustomizer('header', $db, array_merge($renderCtx, $ctx));
            $headerHtml = is_string($headerResult) ? $headerResult : '';
            $ctx['header_region'] = [
                'present' => ($headerHtml !== ''),
                'html' => $headerHtml,
                'source' => $headerHtml !== '' ? 'theme_region' : 'none',
            ];
        } else {
            $ctx['header_region'] = ['present' => false, 'html' => '', 'source' => 'disabled'];
        }
    } catch (Throwable $e) {
        $ctx['header_region'] = ['present' => false, 'html' => '', 'source' => 'error'];
    }
    // @deprecated 6.1 — legacy alias for header_region.html/present.
    // Use {ikb_region name="header"} in DiSyL templates instead.
    $ctx['customized_header'] = (string)($ctx['header_region']['html'] ?? '');
    $ctx['has_customized_header'] = !empty($ctx['header_region']['present']);
    if ($detailedTimingEnabled) {
        cmsPublicContextLogStage('customized_header', $stageStart, ['theme' => $activeThemeSlug, 'request_type' => $requestType]);
    }

    // Render canonical sidebar region
    $stageStart = $timingEnabled ? microtime(true) : 0.0;
    try {
        $sidebarCtx = array_merge($renderCtx, $ctx);
        $forceHideSidebar = !empty($sidebarCtx['force_hide_customized_sidebar']);
        $forceShowSidebar = !empty($sidebarCtx['force_customized_sidebar']) || !empty($sidebarCtx['cms_global_sidebar_force']);
        $sidebarPosition = (string)($sidebarSettings['placement'] ?? 'right');
        $sidebarWidth = (string)($sidebarSettings['width'] ?? '300');
        $sidebarEnabled = !$forceHideSidebar && (((int)($sidebarSettings['enabled'] ?? 0) === 1) || $forceShowSidebar);

        $sidebarResult = cmsDispatchThemeCustomizer('sidebar', $db, $sidebarCtx);
        $sidebarHtml = is_array($sidebarResult)
            ? (string)($sidebarResult['html'] ?? '')
            : (is_string($sidebarResult) ? $sidebarResult : '');

        $ctx['sidebar_region'] = [
            'present' => $sidebarEnabled && $sidebarHtml !== '',
            'enabled' => $sidebarEnabled,
            'html' => $sidebarHtml,
            'position' => $sidebarPosition,
            'width' => $sidebarWidth,
            'source' => $sidebarHtml !== '' ? 'theme_region' : 'none',
        ];
    } catch (Throwable $e) {
        $ctx['sidebar_region'] = [
            'present' => false,
            'enabled' => false,
            'html' => '',
            'position' => 'right',
            'width' => '300',
            'source' => 'error',
        ];
    }
    // @deprecated 6.1 — legacy aliases for sidebar_region.html/present.
    // Use {ikb_region name="sidebar"} in DiSyL templates instead.
    $ctx['customized_sidebar'] = !empty($ctx['sidebar_region']['present'])
        ? (string)($ctx['sidebar_region']['html'] ?? '')
        : '';
    $ctx['has_customized_sidebar'] = !empty($ctx['sidebar_region']['present']);
    $ctx['sidebar_position'] = (string)($ctx['sidebar_region']['position'] ?? 'right');
    $ctx['sidebar_width'] = (string)($ctx['sidebar_region']['width'] ?? '300');
    if ($detailedTimingEnabled) {
        cmsPublicContextLogStage('customized_sidebar', $stageStart, ['theme' => $activeThemeSlug, 'request_type' => $requestType]);
    }

    // Render colors/general <style> override (injected by public layout into <head>)
    $stageStart = $timingEnabled ? microtime(true) : 0.0;
    try {
        $ctx['colors_style'] = cmsPublicContextHasSection($sectionAvailability, 'colors')
            ? cmsRenderColorsStyle($db)
            : '';
    } catch (Throwable $e) {
        $ctx['colors_style'] = '';
    }
    if ($detailedTimingEnabled) {
        cmsPublicContextLogStage('colors_style', $stageStart, ['theme' => $activeThemeSlug, 'request_type' => $requestType]);
    }

    // Render custom code output (CSS overrides, head/body code, scroll-to-top, etc.)
    $stageStart = $timingEnabled ? microtime(true) : 0.0;
    try {
        $cc = cmsPublicContextHasSection($sectionAvailability, 'custom_code')
            ? cmsRenderCustomCodeOutput($db)
            : ['custom_css' => '', 'head_code' => '', 'body_end_code' => ''];
        $ctx['custom_css']      = $cc['custom_css'] ?? '';
        $ctx['head_code']       = $cc['head_code'] ?? '';
        $ctx['body_end_code']   = $cc['body_end_code'] ?? '';
    } catch (Throwable $e) {
        $ctx['custom_css']    = '';
        $ctx['head_code']     = '';
        $ctx['body_end_code'] = '';
    }
    if ($detailedTimingEnabled) {
        cmsPublicContextLogStage('custom_code', $stageStart, ['theme' => $activeThemeSlug, 'request_type' => $requestType]);
    }

    // Render theme layout style (CSS custom properties + layout rules)
    $stageStart = $timingEnabled ? microtime(true) : 0.0;
    try {
        $hasThemeSection = cmsPublicContextHasSection($sectionAvailability, 'theme');
        $hasEntityPresentationSection = cmsPublicContextHasSection($sectionAvailability, 'entity_presentation');

        if ($hasThemeSection) {
            $themeLayout = cmsCustomizerGet($db, 'theme', $activeCustomizerScope);
            $ctx['theme_settings'] = $themeLayout['settings'];
        } else {
            $ctx['theme_settings'] = cmsThemeLayoutSettingsDefaults();
        }

        $entityPresentation = cmsCustomizerGet($db, 'entity_presentation', $activeCustomizerScope);
        $ctx['entity_presentation_settings'] = is_array($entityPresentation['settings'] ?? null)
            ? $entityPresentation['settings']
            : cmsEntityPresentationSectionDefaults($activeCustomizerScope);
        $ctx['entity_presentation_source'] = (string)($entityPresentation['source_section'] ?? 'defaults');
        $ctx['theme_settings'] = array_merge($ctx['theme_settings'], $ctx['entity_presentation_settings']);

        $ctx['theme_layout_style'] = ($hasThemeSection || $hasEntityPresentationSection)
            ? cmsRenderPublicThemeStyle($ctx['theme_settings'], $activeCustomizerScope, $hasThemeSection, $hasEntityPresentationSection)
            : '';
    } catch (Throwable $e) {
        $ctx['theme_layout_style'] = '';
        $ctx['theme_settings'] = cmsThemeLayoutSettingsDefaults();
        $ctx['entity_presentation_settings'] = cmsEntityPresentationSectionDefaults($activeCustomizerScope);
        $ctx['entity_presentation_source'] = 'defaults';
    }
    if ($detailedTimingEnabled) {
        cmsPublicContextLogStage('theme_layout', $stageStart, ['theme' => $activeThemeSlug, 'request_type' => $requestType]);
    }

    if ($derivedCacheTtl > 0) {
        try {
            app()->cache()->setWithTags(
                cmsCacheInstance(),
                $derivedCacheKey,
                ['ctx' => $ctx],
                ['cms:public_context', 'cms:settings', 'cms:menus', 'cms:customizer'],
                $derivedCacheTtl
            );
        } catch (Throwable $e) {
            // Non-fatal: fall back to uncached behavior.
        }
    }
    }

    // Inject entity capability context when rendering a specific entity
    if (!empty($extra['entity']['id'])) {
        $entityId = (int)$extra['entity']['id'];
        $stageStart = $timingEnabled ? microtime(true) : 0.0;
        $ctx = array_merge($ctx, cmsEntityRenderProjection($extra['entity'], [
            'base_url' => $baseUrl,
            'include_cart' => true,
            'include_action_sections' => true,
            'log_error_event' => 'cms.public_context.capability_data_error',
        ]));
        if ($detailedTimingEnabled) {
            cmsPublicContextLogStage('entity_capabilities', $stageStart, [
                'theme' => $activeThemeSlug,
                'request_type' => $requestType,
                'entity_id' => $entityId,
            ]);
        }
    } else {
        $ctx['capabilities']    = [];
        $ctx['capability_data'] = [];
        $ctx['entity_context']  = [];
    }

    if (!array_key_exists('action_sections', $ctx)) {
        $ctx['action_sections'] = '';
    }

    // Ensure cart_enabled/cart_action_url are available at page level for entity lists
    if (!array_key_exists('cart_enabled', $ctx)) {
        $ctx['cart_enabled']    = false;
        $ctx['cart_action_url'] = '';
        try {
            if (app()->capabilities()->has('cms.cart.add@1')) {
                $ctx['cart_enabled']    = true;
                $ctx['cart_action_url'] = $baseUrl . '/ecommerce/cart/add';
            }
        } catch (\Throwable $e) {}
    }

    // Inject cart_count when ecommerce module is available
    if (!array_key_exists('cart_count', $ctx) && !array_key_exists('cart_count', $extra)) {
        $ctx['cart_count'] = 0;
        try {
            if (app()->capabilities()->has('ecommerce.cart.get@1')) {
                $cart = app()->capabilities()->call('ecommerce.cart.get@1');
                $ctx['cart_count'] = (int)($cart['totals']['item_count'] ?? 0);
            }
        } catch (\Throwable $e) {
            $ctx['cart_count'] = 0;
        }
    }

    if ($timingEnabled) {
        cmsPublicContextLogStage('total', $totalStart, [
            'theme' => $activeThemeSlug,
            'request_type' => $requestType,
            'has_sidebar' => !empty($ctx['has_customized_sidebar']),
            'has_header' => !empty($ctx['has_customized_header']),
            'has_footer' => !empty($ctx['has_customized_footer']),
        ]);
    }

    $result = array_merge($ctx, $extra);

    // Per-request cache: avoid rebuilding context when called multiple times
    // with the same extra parameters within a single request.
    $cached = $result;
    $cachedExtra = $extra;

    return $result;
}

function cmsCanonicalRenderTemplateContract(string $template): string
{
    $template = str_replace('\\', '/', trim($template));
    if ($template === '') {
        return '';
    }

    if (str_ends_with($template, 'entity.view.disyl')) {
        return 'entity.view';
    }

    if (str_ends_with($template, 'entity.list.disyl')) {
        return 'entity.list';
    }

    return '';
}

function cmsCanonicalRenderSchemaId(string $template): string
{
    return match (cmsCanonicalRenderTemplateContract($template)) {
        'entity.view' => 'cms.public.entity.view@1',
        'entity.list' => 'cms.public.entity.list@1',
        default => '',
    };
}

function cmsCanonicalRenderProfileId(string $template): string
{
    if (cmsCanonicalRenderTemplateContract($template) === '') {
        return '';
    }

    return function_exists('kernelRenderContextProfileDefinition') && kernelRenderContextProfileDefinition('cms_public') === null
        ? ''
        : 'cms_public';
}

/**
 * @return string[]
 */
function cmsCanonicalRenderSchemaStack(string $template): array
{
    $profileId = cmsCanonicalRenderProfileId($template);
    $schemaId = cmsCanonicalRenderSchemaId($template);
    $stack = function_exists('kernelRenderContextProfileShellSchemaStack')
        ? kernelRenderContextProfileShellSchemaStack($profileId)
        : [];

    if ($schemaId !== '') {
        $stack[] = $schemaId;
    }

    return array_values(array_unique(array_filter(array_map('strval', $stack), static fn(string $candidate): bool => trim($candidate) !== '')));
}

function cmsCanonicalRenderContractStrictMode(): bool
{
    if (function_exists('kernelRenderContextContractStrictMode') && kernelRenderContextContractStrictMode()) {
        return true;
    }

    $explicit = $_ENV['CMS_RENDER_CONTRACT_STRICT'] ?? null;
    if (is_string($explicit) && $explicit !== '') {
        return filter_var($explicit, FILTER_VALIDATE_BOOLEAN);
    }

    $ci = $_ENV['CI'] ?? null;
    if (is_string($ci) && $ci !== '') {
        return filter_var($ci, FILTER_VALIDATE_BOOLEAN);
    }

    if (function_exists('config')) {
        return strtolower((string)config('app.env', 'development')) === 'testing';
    }

    return false;
}

function cmsCanonicalRenderContractMismatchMessage(string $template, string $contract, array $missingKeys, array $typeMismatches): string
{
    $parts = ['Canonical render context mismatch for ' . $template . ' (' . $contract . ')'];
    if ($missingKeys !== []) {
        $parts[] = 'missing keys: ' . implode(', ', $missingKeys);
    }
    if ($typeMismatches !== []) {
        $pairs = [];
        foreach ($typeMismatches as $key => $type) {
            $pairs[] = $key . '=' . $type;
        }
        $parts[] = 'type mismatches: ' . implode(', ', $pairs);
    }

    return implode('; ', $parts);
}

function cmsCanonicalRenderContextNormalize(array $context, string $template): array
{
    $contract = cmsCanonicalRenderTemplateContract($template);
    if ($contract === '') {
        return $context;
    }

    $profileId = cmsCanonicalRenderProfileId($template);
    $schemaStack = cmsCanonicalRenderSchemaStack($template);
    if (function_exists('kernelApplyResolvedRenderContextMetadata')) {
        $context = kernelApplyResolvedRenderContextMetadata($context, $profileId, $schemaStack);
    } else {
        $context['render_profile_id'] = $profileId;
        $context['render_schema_stack'] = $schemaStack;
    }

    $shouldLog = !empty($context['__render_contract_validate']);
    unset($context['__render_contract_validate']);

    $missingKeys = [];
    $typeMismatches = [];

    if ($contract === 'entity.view') {
        $defaults = [
            'entity' => [],
            'capabilities' => [],
            'capability_data' => [],
            'entity_context' => [],
            'entity_view_context' => [],
            'entity_presentation' => [],
            'entity_presentation_settings' => [],
            'entity_taxonomies' => ['categories' => [], 'tags' => []],
            'show_entity_categories' => false,
            'show_entity_tags' => false,
            'entity_back_link_url' => '',
            'entity_back_link_label' => 'Back',
            'post_html' => '',
            'builder_enabled' => false,
            'builder_page_settings' => [],
            'cms_head' => '',
            'structured_data' => '',
            'cart_enabled' => false,
            'cart_action_url' => '',
            'action_sections' => '',
            'public_render_origin' => 'cms',
            'public_route_kind' => 'generic',
            'public_presentation_mode' => 'canonical',
            'storefront' => [],
            'theme_settings' => [],
        ];
        $requiredKeys = [
            'entity',
            'capabilities',
            'capability_data',
            'entity_context',
            'entity_view_context',
            'entity_presentation',
            'entity_taxonomies',
            'post_html',
            'builder_enabled',
            'builder_page_settings',
            'cart_enabled',
            'cart_action_url',
            'action_sections',
            'public_render_origin',
            'public_route_kind',
            'public_presentation_mode',
        ];
    } else {
        $defaults = [
            'items' => [],
            'entity_list_context' => [],
            'entity_presentation' => [],
            'pagination' => [],
            'cms_head' => '',
            'public_render_origin' => 'cms',
            'public_route_kind' => 'generic',
            'public_presentation_mode' => 'canonical',
            'storefront' => [],
        ];
        $requiredKeys = [
            'items',
            'entity_list_context',
            'entity_presentation',
            'pagination',
            'public_render_origin',
            'public_route_kind',
            'public_presentation_mode',
        ];
    }

    foreach ($defaults as $key => $defaultValue) {
        if (!array_key_exists($key, $context)) {
            $context[$key] = $defaultValue;
            if (in_array($key, $requiredKeys, true)) {
                $missingKeys[] = $key;
            }
            continue;
        }

        $value = $context[$key];
        if (is_array($defaultValue)) {
            if (!is_array($value)) {
                $context[$key] = $defaultValue;
                if (in_array($key, $requiredKeys, true)) {
                    $typeMismatches[$key] = gettype($value);
                }
            }
            continue;
        }

        if (is_bool($defaultValue)) {
            if (!is_bool($value)) {
                if (in_array($key, $requiredKeys, true)) {
                    $typeMismatches[$key] = gettype($value);
                }
                $context[$key] = (bool)$value;
            }
            continue;
        }

        if (!is_scalar($value) && $value !== null) {
            $context[$key] = $defaultValue;
            if (in_array($key, $requiredKeys, true)) {
                $typeMismatches[$key] = gettype($value);
            }
            continue;
        }

        $context[$key] = (string)$value;
    }

    if ($contract === 'entity.view') {
        $context['entity_view_context'] = array_merge([
            'show_header' => true,
            'show_meta' => true,
            'show_media' => true,
            'show_summary' => true,
            'show_lessons' => true,
            'show_taxonomies' => true,
        ], $context['entity_view_context']);
        $context['entity_taxonomies'] = array_merge([
            'categories' => [],
            'tags' => [],
        ], $context['entity_taxonomies']);
        if (!is_array($context['entity_taxonomies']['categories'] ?? null)) {
            $context['entity_taxonomies']['categories'] = [];
        }
        if (!is_array($context['entity_taxonomies']['tags'] ?? null)) {
            $context['entity_taxonomies']['tags'] = [];
        }
    } else {
        $context['entity_list_context'] = array_merge([
            'available_categories' => [],
            'search_action_url' => '',
            'base_list_url' => '',
            'all_items_url' => '',
            'search' => '',
            'category_slug' => '',
            'result_count' => 0,
            'active_filter_count' => 0,
        ], $context['entity_list_context']);
        if (!is_array($context['entity_list_context']['available_categories'] ?? null)) {
            $context['entity_list_context']['available_categories'] = [];
        }

        $normalizedItems = [];
        foreach ($context['items'] as $index => $item) {
            if (!is_array($item)) {
                $typeMismatches['items[' . $index . ']'] = gettype($item);
                continue;
            }

            if (!is_array($item['capabilities'] ?? null)) {
                $item['capabilities'] = [];
            }
            if (!is_array($item['capability_data'] ?? null)) {
                $item['capability_data'] = [];
            }
            if (!is_array($item['entity_context'] ?? null)) {
                $item['entity_context'] = [];
            }

            foreach (['url', 'primary_image_url', 'list_card_excerpt', 'list_card_pricing_html', 'list_card_inventory_html', 'list_card_progress_html', 'list_card_action_html'] as $key) {
                $value = $item[$key] ?? '';
                $item[$key] = is_scalar($value) || $value === null ? (string)$value : '';
            }

            $normalizedItems[] = $item;
        }
        $context['items'] = $normalizedItems;
    }

    if ($shouldLog && ($missingKeys !== [] || $typeMismatches !== [])) {
        if (function_exists('kernelAppendRenderTraceNormalizationAction')) {
            $context = kernelAppendRenderTraceNormalizationAction($context, [
                'source' => 'cms_canonical',
                'contract' => $contract,
                'schema_id' => cmsCanonicalRenderSchemaId($template),
                'missing_keys' => $missingKeys,
                'type_mismatches' => $typeMismatches,
            ]);
        }

        write_log('warn', 'cms.render_context.contract_mismatch', [
            'template' => $template,
            'contract' => $contract,
            'render_profile_id' => trim((string)($context['render_profile_id'] ?? '')),
            'render_schema_stack' => is_array($context['render_schema_stack'] ?? null) ? array_values($context['render_schema_stack']) : [],
            'missing_keys' => $missingKeys,
            'type_mismatches' => $typeMismatches,
        ]);

        $context['__render_contract_mismatch'] = [
            'contract' => $contract,
            'render_profile_id' => trim((string)($context['render_profile_id'] ?? '')),
            'render_schema_stack' => is_array($context['render_schema_stack'] ?? null) ? array_values($context['render_schema_stack']) : [],
            'missing_keys' => $missingKeys,
            'type_mismatches' => $typeMismatches,
        ];
    } elseif (($missingKeys !== [] || $typeMismatches !== []) && function_exists('kernelAppendRenderTraceNormalizationAction')) {
        $context = kernelAppendRenderTraceNormalizationAction($context, [
            'source' => 'cms_canonical',
            'contract' => $contract,
            'schema_id' => cmsCanonicalRenderSchemaId($template),
            'missing_keys' => $missingKeys,
            'type_mismatches' => $typeMismatches,
        ]);
    } else {
        unset($context['__render_contract_mismatch']);
    }

    return $context;
}

function cmsRenderCanonicalTemplate(string $template, array $context = []): string
{
    if (cmsCanonicalRenderTemplateContract($template) !== '') {
        $context['__render_contract_validate'] = true;

        if (cmsCanonicalRenderContractStrictMode()) {
            $context = cmsCanonicalRenderContextNormalize($context, $template);
            $mismatch = is_array($context['__render_contract_mismatch'] ?? null) ? $context['__render_contract_mismatch'] : null;
            unset($context['__render_contract_mismatch']);
            if ($mismatch !== null) {
                throw new RuntimeException(cmsCanonicalRenderContractMismatchMessage(
                    $template,
                    (string)($mismatch['contract'] ?? ''),
                    is_array($mismatch['missing_keys'] ?? null) ? $mismatch['missing_keys'] : [],
                    is_array($mismatch['type_mismatches'] ?? null) ? $mismatch['type_mismatches'] : []
                ));
            }
        }
    }

    return cmsRenderThemeAwareTemplate($template, $context);
}

app()->hooks()->on('kernel.render_context.finalize', function (array $context, string $template): array {
    $context = cmsCanonicalRenderContextNormalize($context, $template);
    unset($context['__render_contract_mismatch']);
    return $context;
}, 100);

/**
 * Extract social links array from CMS settings.
 */

function cmsPublicSocialLinks(array $settings): array
{
    $socialLinks = [];
    $socialKeys = ['social_facebook', 'social_twitter', 'social_instagram', 'social_youtube', 'social_linkedin'];
    foreach ($socialKeys as $key) {
        $url = trim((string)($settings[$key] ?? ''));
        if ($url !== '') {
            $name = str_replace('social_', '', $key);
            $socialLinks[] = ['name' => $name, 'url' => $url, 'label' => ucfirst($name)];
        }
    }
    return $socialLinks;
}

// ═══════════════════════════════════════════════════════════════════════
// THEME CUSTOMIZER HELPERS
// ═══════════════════════════════════════════════════════════════════════

/**
 * Default footer customizer settings.
 */
