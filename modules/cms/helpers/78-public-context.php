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

// ── Public Context Enrichment ────────────────────────────────────────

/**
 * Build common render context for public CMS templates.
 * Includes menus, social links, current year, site settings.
 */

function cmsPublicContext(array $extra = []): array
{
    $settings = readCmsSettings();
    $baseUrl = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
    $activeThemeSlug = cmsActiveTheme() ?? 'native-default';

    // Build primary menu
    $primaryMenu = '';
    try {
        $primaryMenu = cmsRenderMenu('primary');
    } catch (Throwable $e) {
        // Menu may not exist yet
    }

    // Build footer menu
    $footerMenu = '';
    try {
        $footerMenu = cmsRenderMenu('footer');
    } catch (Throwable $e) {}

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
    try {
        $db = cmsDb();
        $customizedFooter = cmsRenderCustomizedFooter($db);
        $ctx['customized_footer'] = $customizedFooter;
        $ctx['has_customized_footer'] = ($customizedFooter !== '');
    } catch (Throwable $e) {
        $ctx['customized_footer'] = '';
        $ctx['has_customized_footer'] = false;
    }

    // Render customized header if customizer data exists
    try {
        $db = $db ?? cmsDb();
        $customizedHeader = cmsRenderCustomizedHeader($db, $ctx);
        $ctx['customized_header'] = $customizedHeader;
        $ctx['has_customized_header'] = ($customizedHeader !== '');
    } catch (Throwable $e) {
        $ctx['customized_header'] = '';
        $ctx['has_customized_header'] = false;
    }

    // Render customized sidebar if enabled by customizer settings
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

    // Render colors/general <style> override (injected by public layout into <head>)
    try {
        $db = $db ?? cmsDb();
        $ctx['colors_style'] = cmsRenderColorsStyle($db);
    } catch (Throwable $e) {
        $ctx['colors_style'] = '';
    }

    // Render custom code output (CSS overrides, head/body code, scroll-to-top, etc.)
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

    // Render theme layout style (CSS custom properties + layout rules)
    try {
        $db = $db ?? cmsDb();
        $ctx['theme_layout_style'] = cmsRenderThemeLayoutStyle($db);
        $themeLayout = cmsCustomizerGet($db, 'theme');
        $ctx['theme_settings'] = $themeLayout['settings'];
    } catch (Throwable $e) {
        $ctx['theme_layout_style'] = '';
        $ctx['theme_settings'] = cmsThemeLayoutSettingsDefaults();
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
