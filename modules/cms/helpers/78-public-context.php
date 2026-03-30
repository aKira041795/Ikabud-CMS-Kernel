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
    $timingEnabled = cmsPublicContextTimingEnabled();
    $detailedTimingEnabled = $timingEnabled && cmsPublicContextDetailedTimingEnabled();
    $totalStart = $timingEnabled ? microtime(true) : 0.0;
    $stageStart = $timingEnabled ? microtime(true) : 0.0;
    $settings = readCmsSettings();
    $db = cmsDb();
    $baseUrl = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
    $requestType = !empty($extra['entity']['id']) ? 'entity' : (!empty($extra['content']['id']) ? 'content' : 'generic');
    $builderEnabled = !empty($extra['builder_enabled']);
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

    $ctx = [
        'site_title'    => (string)($settings['site_title'] ?? ''),
        'site_tagline'  => (string)($settings['site_tagline'] ?? ''),
        'current_year'  => date('Y'),
        'active_theme_slug' => $activeThemeSlug,
        'theme_style_url' => cmsThemeAssetUrl('style.css', $baseUrl),
        'theme_script_url' => cmsThemeAssetUrl('script.js', $baseUrl),
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
    ];

    // Render customized footer if customizer data exists
    $stageStart = $timingEnabled ? microtime(true) : 0.0;
    try {
        if (cmsPublicContextHasSection($sectionAvailability, 'footer')) {
            $customizedFooter = cmsRenderCustomizedFooter($db, $ctx);
            $ctx['customized_footer'] = $customizedFooter;
            $ctx['has_customized_footer'] = ($customizedFooter !== '');
        } else {
            $ctx['customized_footer'] = '';
            $ctx['has_customized_footer'] = false;
        }
    } catch (Throwable $e) {
        $ctx['customized_footer'] = '';
        $ctx['has_customized_footer'] = false;
    }
    if ($detailedTimingEnabled) {
        cmsPublicContextLogStage('customized_footer', $stageStart, ['theme' => $activeThemeSlug, 'request_type' => $requestType]);
    }

    // Render customized header if customizer data exists
    $stageStart = $timingEnabled ? microtime(true) : 0.0;
    try {
        if (cmsPublicContextHasSection($sectionAvailability, 'header')) {
            $customizedHeader = cmsRenderCustomizedHeader($db, $ctx);
            $ctx['customized_header'] = $customizedHeader;
            $ctx['has_customized_header'] = ($customizedHeader !== '');
        } else {
            $ctx['customized_header'] = '';
            $ctx['has_customized_header'] = false;
        }
    } catch (Throwable $e) {
        $ctx['customized_header'] = '';
        $ctx['has_customized_header'] = false;
    }
    if ($detailedTimingEnabled) {
        cmsPublicContextLogStage('customized_header', $stageStart, ['theme' => $activeThemeSlug, 'request_type' => $requestType]);
    }

    // Render customized sidebar if enabled by customizer settings
    $stageStart = $timingEnabled ? microtime(true) : 0.0;
    try {
        if ($builderEnabled || !cmsPublicContextHasSection($sectionAvailability, 'sidebar')) {
            $ctx['customized_sidebar'] = '';
            $ctx['has_customized_sidebar'] = false;
            $ctx['sidebar_position'] = 'right';
            $ctx['sidebar_width'] = '300';
        } else {
            $sidebarCtx = array_merge($ctx, $extra);
            $sidebar = cmsRenderCustomizedSidebar($db, $sidebarCtx);
            $ctx['customized_sidebar'] = (string)($sidebar['html'] ?? '');
            $ctx['has_customized_sidebar'] = (bool)($sidebar['enabled'] ?? false);
            $ctx['sidebar_position'] = (string)($sidebar['position'] ?? 'right');
            $ctx['sidebar_width'] = (string)($sidebar['width'] ?? '300');
        }
    } catch (Throwable $e) {
        $ctx['customized_sidebar'] = '';
        $ctx['has_customized_sidebar'] = false;
        $ctx['sidebar_position'] = 'right';
        $ctx['sidebar_width'] = '300';
    }
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
        if (cmsPublicContextHasSection($sectionAvailability, 'theme')) {
            $ctx['theme_layout_style'] = cmsRenderThemeLayoutStyle($db);
            $themeLayout = cmsCustomizerGet($db, 'theme');
            $ctx['theme_settings'] = $themeLayout['settings'];
        } else {
            $ctx['theme_layout_style'] = '';
            $ctx['theme_settings'] = cmsThemeLayoutSettingsDefaults();
        }

        $entityPresentation = cmsCustomizerGet($db, 'entity_presentation');
        $ctx['entity_presentation_settings'] = is_array($entityPresentation['settings'] ?? null)
            ? $entityPresentation['settings']
            : cmsEntityPresentationSectionDefaults($activeCustomizerScope);
        $ctx['entity_presentation_source'] = (string)($entityPresentation['source_section'] ?? 'defaults');
        $ctx['theme_settings'] = array_merge($ctx['theme_settings'], $ctx['entity_presentation_settings']);

        $ctx['theme_layout_style'] .= cmsRenderEntityPresentationStyle($db);
    } catch (Throwable $e) {
        $ctx['theme_layout_style'] = '';
        $ctx['theme_settings'] = cmsThemeLayoutSettingsDefaults();
        $ctx['entity_presentation_settings'] = cmsEntityPresentationSectionDefaults($activeCustomizerScope);
        $ctx['entity_presentation_source'] = 'defaults';
    }
    if ($detailedTimingEnabled) {
        cmsPublicContextLogStage('theme_layout', $stageStart, ['theme' => $activeThemeSlug, 'request_type' => $requestType]);
    }

    // Inject entity capability context when rendering a specific entity
    if (!empty($extra['entity']['id'])) {
        $entityId = (int)$extra['entity']['id'];
        $stageStart = $timingEnabled ? microtime(true) : 0.0;
        try {
            $runtime = cmsEntityCapabilityRuntimeState($entityId, $extra['entity']);
            $ctx['capabilities']    = is_array($runtime['capabilities'] ?? null) ? $runtime['capabilities'] : [];
            $ctx['capability_data'] = is_array($runtime['capability_data'] ?? null) ? $runtime['capability_data'] : [];
            $ctx['entity_context']  = is_array($runtime['resolved_context'] ?? null) ? $runtime['resolved_context'] : [];
        } catch (\Throwable $e) {
            write_log('warn', 'cms.public_context.capability_data_error', [
                'entity_id' => $entityId,
                'error'     => $e->getMessage(),
            ]);
            $ctx['capabilities']    = [];
            $ctx['capability_data'] = [];
            $ctx['entity_context']  = [];
        }
        if ($detailedTimingEnabled) {
            cmsPublicContextLogStage('entity_capabilities', $stageStart, [
                'theme' => $activeThemeSlug,
                'request_type' => $requestType,
                'entity_id' => $entityId,
            ]);
        }

        // Risk 1: Cart availability — gate buy-button on cart.add capability
        $ctx['cart_enabled']    = false;
        $ctx['cart_action_url'] = '';
        try {
            if (!empty($ctx['capabilities']['pricing']) && app()->capabilities()->has('cms.cart.add@1')) {
                $ctx['cart_enabled']    = true;
                $ctx['cart_action_url'] = $baseUrl . '/ecommerce/cart/add';
            }
        } catch (\Throwable $e) {}

        // Risk 4: Hook-based action sections for extensibility
        $ctx['action_sections'] = '';
        try {
            $sections = app()->hooks()->filter('cms.entity.action_block.sections', [], [
                'entity'          => $extra['entity'],
                'capabilities'    => $ctx['capabilities'],
                'capability_data' => $ctx['capability_data'],
                'base_url'        => $baseUrl,
            ]);
            if (is_string($sections) && $sections !== '') {
                $ctx['action_sections'] = $sections;
            }
        } catch (\Throwable $e) {}
    } else {
        $ctx['capabilities']    = [];
        $ctx['capability_data'] = [];
        $ctx['entity_context']  = [];
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

    return array_merge($ctx, $extra);
}

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
