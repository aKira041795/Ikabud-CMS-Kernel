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

// ── Public Context Enrichment ────────────────────────────────────────

/**
 * Build common render context for public CMS templates.
 * Includes menus, social links, current year, site settings.
 */

function cmsPublicContext(array $extra = []): array
{
    $timingEnabled = cmsPublicContextTimingEnabled();
    $totalStart = $timingEnabled ? microtime(true) : 0.0;
    $settings = readCmsSettings();
    $baseUrl = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
    $activeThemeSlug = cmsActiveTheme() ?? 'native-default';
    $requestType = !empty($extra['entity']['id']) ? 'entity' : (!empty($extra['content']['id']) ? 'content' : 'generic');

    // Preload all customizer sections in one DB query instead of 6 separate ones
    try {
        cmsCustomizerPreloadAll(cmsDb());
    } catch (Throwable $e) {
        // Non-fatal — individual lookups will fall back to per-section queries
    }

    // Build primary menu
    $primaryMenu = '';
    $stageStart = $timingEnabled ? microtime(true) : 0.0;
    try {
        $primaryMenu = cmsRenderMenu('primary');
    } catch (Throwable $e) {
        // Menu may not exist yet
    }
    if ($timingEnabled) {
        cmsPublicContextLogStage('primary_menu', $stageStart, ['theme' => $activeThemeSlug, 'request_type' => $requestType]);
    }

    // Build footer menu
    $footerMenu = '';
    $stageStart = $timingEnabled ? microtime(true) : 0.0;
    try {
        $footerMenu = cmsRenderMenu('footer');
    } catch (Throwable $e) {}
    if ($timingEnabled) {
        cmsPublicContextLogStage('footer_menu', $stageStart, ['theme' => $activeThemeSlug, 'request_type' => $requestType]);
    }

    // Social links from settings
    $socialLinks = [];
    $socialKeys = ['social_facebook', 'social_twitter', 'social_instagram', 'social_youtube', 'social_linkedin'];
    foreach ($socialKeys as $key) {
        $url = trim((string)($settings[$key] ?? ''));
        if ($url !== '') {
            $name = str_replace('social_', '', $key);
            $socialLinks[] = ['name' => $name, 'url' => $url, 'label' => ucfirst($name)];
        }
    }

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
    ];

    // Render customized footer if customizer data exists
    $stageStart = $timingEnabled ? microtime(true) : 0.0;
    try {
        $db = cmsDb();
        $customizedFooter = cmsRenderCustomizedFooter($db);
        $ctx['customized_footer'] = $customizedFooter;
        $ctx['has_customized_footer'] = ($customizedFooter !== '');
    } catch (Throwable $e) {
        $ctx['customized_footer'] = '';
        $ctx['has_customized_footer'] = false;
    }
    if ($timingEnabled) {
        cmsPublicContextLogStage('customized_footer', $stageStart, ['theme' => $activeThemeSlug, 'request_type' => $requestType]);
    }

    // Render customized header if customizer data exists
    $stageStart = $timingEnabled ? microtime(true) : 0.0;
    try {
        $db = $db ?? cmsDb();
        $customizedHeader = cmsRenderCustomizedHeader($db, $ctx);
        $ctx['customized_header'] = $customizedHeader;
        $ctx['has_customized_header'] = ($customizedHeader !== '');
    } catch (Throwable $e) {
        $ctx['customized_header'] = '';
        $ctx['has_customized_header'] = false;
    }
    if ($timingEnabled) {
        cmsPublicContextLogStage('customized_header', $stageStart, ['theme' => $activeThemeSlug, 'request_type' => $requestType]);
    }

    // Render customized sidebar if enabled by customizer settings
    $stageStart = $timingEnabled ? microtime(true) : 0.0;
    try {
        $db = $db ?? cmsDb();
        $sidebarCtx = array_merge($ctx, $extra);
        $sidebar = cmsRenderCustomizedSidebar($db, $sidebarCtx);
        $ctx['customized_sidebar'] = (string)($sidebar['html'] ?? '');
        $ctx['has_customized_sidebar'] = (bool)($sidebar['enabled'] ?? false);
        $ctx['sidebar_position'] = (string)($sidebar['position'] ?? 'right');
        $ctx['sidebar_width'] = (string)($sidebar['width'] ?? '300');
    } catch (Throwable $e) {
        $ctx['customized_sidebar'] = '';
        $ctx['has_customized_sidebar'] = false;
        $ctx['sidebar_position'] = 'right';
        $ctx['sidebar_width'] = '300';
    }
    if ($timingEnabled) {
        cmsPublicContextLogStage('customized_sidebar', $stageStart, ['theme' => $activeThemeSlug, 'request_type' => $requestType]);
    }

    // Render colors/general <style> override (injected by public layout into <head>)
    $stageStart = $timingEnabled ? microtime(true) : 0.0;
    try {
        $db = $db ?? cmsDb();
        $ctx['colors_style'] = cmsRenderColorsStyle($db);
    } catch (Throwable $e) {
        $ctx['colors_style'] = '';
    }
    if ($timingEnabled) {
        cmsPublicContextLogStage('colors_style', $stageStart, ['theme' => $activeThemeSlug, 'request_type' => $requestType]);
    }

    // Render custom code output (CSS overrides, head/body code, scroll-to-top, etc.)
    $stageStart = $timingEnabled ? microtime(true) : 0.0;
    try {
        $db = $db ?? cmsDb();
        $cc = cmsRenderCustomCodeOutput($db);
        $ctx['custom_css']      = $cc['custom_css'] ?? '';
        $ctx['head_code']       = $cc['head_code'] ?? '';
        $ctx['body_end_code']   = $cc['body_end_code'] ?? '';
    } catch (Throwable $e) {
        $ctx['custom_css']    = '';
        $ctx['head_code']     = '';
        $ctx['body_end_code'] = '';
    }
    if ($timingEnabled) {
        cmsPublicContextLogStage('custom_code', $stageStart, ['theme' => $activeThemeSlug, 'request_type' => $requestType]);
    }

    // Render theme layout style (CSS custom properties + layout rules)
    $stageStart = $timingEnabled ? microtime(true) : 0.0;
    try {
        $db = $db ?? cmsDb();
        $ctx['theme_layout_style'] = cmsRenderThemeLayoutStyle($db);
        $themeLayout = cmsCustomizerGet($db, 'theme');
        $ctx['theme_settings'] = $themeLayout['settings'];
    } catch (Throwable $e) {
        $ctx['theme_layout_style'] = '';
        $ctx['theme_settings'] = cmsThemeLayoutSettingsDefaults();
    }
    if ($timingEnabled) {
        cmsPublicContextLogStage('theme_layout', $stageStart, ['theme' => $activeThemeSlug, 'request_type' => $requestType]);
    }

    // Inject entity capability context when rendering a specific entity
    if (!empty($extra['entity']['id'])) {
        $entityId = (int)$extra['entity']['id'];
        $stageStart = $timingEnabled ? microtime(true) : 0.0;
        try {
            $ctx['capabilities']    = cmsEntityCapabilityContext($entityId);
            $ctx['capability_data'] = cmsEntityCapabilityData($entityId, $extra['entity']);
        } catch (\Throwable $e) {
            write_log('warn', 'cms.public_context.capability_data_error', [
                'entity_id' => $entityId,
                'error'     => $e->getMessage(),
            ]);
            $ctx['capabilities']    = [];
            $ctx['capability_data'] = [];
        }
        if ($timingEnabled) {
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
