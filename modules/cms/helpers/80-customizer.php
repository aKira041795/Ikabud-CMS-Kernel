<?php

declare(strict_types=1);

function cmsFooterSettingsDefaults(): array
{
    return [
        'columns'           => 3,
        'inner_width'       => 'contained',
        'bg_color'          => '#1e293b',
        'text_color'        => '#cbd5e1',
        'link_color'        => '#94a3b8',
        'link_hover_color'  => '#ffffff',
        'title_color'       => '#f1f5f9',
        'bar_bg_color'      => '#0f172a',
        'bar_text_color'    => '#64748b',
        'bar_link_color'    => '#94a3b8',
        'bar_link_hover_color' => '#ffffff',
        'copyright_text'    => '© {current_year} {site_title}. All rights reserved.',
        'show_footer_bar'   => 1,
        'show_admin_link'   => 1,
        'padding_top'       => '40',
        'padding_bottom'    => '40',
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
        $val = trim((string)($input[$key] ?? $defaults[$key]));
        // Strip anything suspicious
        $val = preg_replace('/[^a-zA-Z0-9\s\-_,\'\'"\.]/', '', $val);
        $validated[$key] = $val !== '' ? $val : $defaults[$key];
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

function cmsCustomizerRequestCacheKey(string $suffix): string
{
    return 'cms_customizer_' . $suffix . '_t' . cmsRuntimeTenantId();
}

function cmsCustomizerPersistentCacheTtl(): int
{
    return max(0, (int)($_ENV['CMS_CUSTOMIZER_CACHE_TTL'] ?? 300));
}

function cmsCustomizerPersistentCacheInstance(): string
{
    return 'cms_customizer_t' . cmsRuntimeTenantId();
}

function cmsCustomizerPersistentCacheKey(string $section): string
{
    return 'customizer:section:' . $section . ':v1';
}

function cmsCustomizerFragmentCacheKey(string $fragment): string
{
    return 'customizer:fragment:' . $fragment . ':v2';
}

function cmsCustomizerFragmentCacheGet(string $fragment): ?array
{
    return cmsCacheGet(cmsCustomizerFragmentCacheKey($fragment));
}

function cmsCustomizerFragmentCacheSet(string $fragment, array $data, array $tags = []): void
{
    $defaultTags = ['cms:customizer', 'cms:customizer:fragment:' . $fragment];
    cmsCacheSet(
        cmsCustomizerFragmentCacheKey($fragment),
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

function cmsCustomizerClearPersistentCache(?string $section = null): void
{
    if (cmsCustomizerPersistentCacheTtl() <= 0) {
        return;
    }

    $tags = ['cms:customizer'];
    if ($section !== null && $section !== '') {
        $tags[] = 'cms:customizer:' . $section;
    }

    app()->cache()->clearByTags(cmsCustomizerPersistentCacheInstance(), $tags);
}

/**
 * Preload all customizer sections into request cache in a single DB query.
 * Call once at the start of public context building to avoid N separate
 * queries when each section is read individually.
 */
function cmsCustomizerPreloadAll(object $db): void
{
    $cacheKey = cmsCustomizerRequestCacheKey('section_row');
    if (!empty($GLOBALS[$cacheKey])) {
        return; // Already preloaded this request
    }

    $knownSections = ['footer', 'header', 'sidebar', 'colors', 'custom_code', 'theme'];
    $cache = [];
    $ttl = cmsCustomizerPersistentCacheTtl();
    $instance = cmsCustomizerPersistentCacheInstance();

    // Try persistent cache first
    if ($ttl > 0) {
        // Fast path: single bundle read instead of 6 individual reads
        $bundleCacheKey = 'customizer:bundle:v1';
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
            $persistent = app()->cache()->get($instance, cmsCustomizerPersistentCacheKey($s));
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
        $stmt = $db->query("SELECT section, settings_json, widgets_json FROM cms_theme_customizer");
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $section = $row['section'];
            $cache[$section] = ['settings_json' => $row['settings_json'], 'widgets_json' => $row['widgets_json']];
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
                cmsCustomizerPersistentCacheKey($s),
                $cacheValue,
                ['cms:customizer', 'cms:customizer:' . $s],
                $ttl
            );
        }
        // Write bundle so the next request pays 1 cache read instead of 6
        app()->cache()->setWithTags(
            $instance,
            'customizer:bundle:v1',
            $cache,
            ['cms:customizer'],
            $ttl
        );
    }
}

function cmsCustomizerSectionRecord(object $db, string $section): ?array
{
    $cacheKey = cmsCustomizerRequestCacheKey('section_row');
    $cache = $GLOBALS[$cacheKey] ?? [];
    if (array_key_exists($section, $cache)) {
        return $cache[$section];
    }

    if (cmsCustomizerPersistentCacheTtl() > 0) {
        $persistent = app()->cache()->get(cmsCustomizerPersistentCacheInstance(), cmsCustomizerPersistentCacheKey($section));
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
        $stmt->execute([':s' => $section]);
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
            cmsCustomizerPersistentCacheInstance(),
            cmsCustomizerPersistentCacheKey($section),
            $cacheValue,
            ['cms:customizer', 'cms:customizer:' . $section],
            cmsCustomizerPersistentCacheTtl()
        );
    }
    return $row;
}

function cmsCustomizerSectionExists(object $db, string $section): bool
{
    return cmsCustomizerSectionRecord($db, $section) !== null;
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

    $fontBody    = htmlspecialchars($s['font_body'] ?? 'Inter');
    $fontHeading = htmlspecialchars($s['font_heading'] ?? 'Inter');

    $systemFonts = ['system-ui', 'Georgia', 'serif', 'sans-serif', 'monospace'];
    $loadedFamilies = [];
    foreach ([$fontBody, $fontHeading] as $face) {
        if (!in_array($face, $systemFonts, true) && !isset($loadedFamilies[$face])) {
            $loadedFamilies[$face] = str_replace('%20', '+', rawurlencode($face));
        }
    }

    $googleFontsHtml = '';
    if ($loadedFamilies !== []) {
        $fontParams = [];
        foreach ($loadedFamilies as $familyParam) {
            $fontParams[] = 'family=' . $familyParam . ':wght@400;500;600;700';
        }
        $fontHref = 'https://fonts.googleapis.com/css2?' . implode('&', $fontParams) . '&display=swap';
        $escapedFontHref = htmlspecialchars($fontHref, ENT_QUOTES, 'UTF-8');
        $googleFontsHtml = '<link rel="stylesheet" href="' . $escapedFontHref . '" media="print" onload="this.media=\'all\'">'
            . '<noscript><link rel="stylesheet" href="' . $escapedFontHref . '"></noscript>';
    }

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
    $css .= '--font-body:\'' . $fontBody . '\',-apple-system,BlinkMacSystemFont,sans-serif;';
    $css .= '--font-heading:\'' . $fontHeading . '\',-apple-system,BlinkMacSystemFont,sans-serif;';
    $css .= '--container-width:' . ($s['container_width'] ?? '1200') . 'px;';
    $css .= '--radius-sm:' . round(((float)($s['border_radius'] ?? 0.5)) * 0.5, 2) . 'rem;';
    $css .= '--radius-md:' . ($s['border_radius'] ?? '0.5') . 'rem;';
    $css .= '--radius-lg:' . round(((float)($s['border_radius'] ?? 0.5)) * 2, 2) . 'rem;';
    $css .= '}';

    // Body base
    $css .= 'html{font-size:' . ($s['font_size_base'] ?? '16') . 'px;}';
    $css .= 'body{line-height:' . ($s['line_height'] ?? '1.6') . ';';
    $css .= 'color:var(--color-text);background-color:var(--color-background);}';

    // Links
    $css .= 'a{color:var(--color-link);}';
    $css .= 'a:hover{color:var(--color-link-hover);}';

    // Headings
    $headingColor = trim((string)($s['heading_color'] ?? ''));
    if ($headingColor !== '') {
        $css .= 'h1,h2,h3,h4,h5,h6{color:' . $headingColor . ';}';
    }
    $css .= 'h1{font-size:' . ($s['h1_size'] ?? '2.5') . 'rem;}';
    $css .= 'h2{font-size:' . ($s['h2_size'] ?? '2') . 'rem;}';
    $css .= 'h3{font-size:' . ($s['h3_size'] ?? '1.5') . 'rem;}';
    $css .= 'h4{font-size:' . ($s['h4_size'] ?? '1.25') . 'rem;}';

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
        'scope_mode'              => 'general', // general | template
        'template_scope'          => $defaultScope,
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

/**
 * Default settings for the Theme Layout customizer section.
 * Controls global layout structure, container sizing, and blog listing style.
 */
function cmsThemeLayoutSettingsDefaults(): array
{
    return [
        // ── Site Layout ──────────────────────────────
        'layout_mode'             => 'contained',       // contained | boxed | full-width
        'site_max_width'          => '1280',             // px – max width of outer container (boxed/contained)
        'content_max_width'       => '768',              // px – max width of content column (prose)
        'content_padding_x'      => '16',                // px – horizontal padding inside main area
        'content_padding_top'    => '32',                // px – top padding
        'content_padding_bottom' => '32',                // px – bottom padding

        // ── Blog / Archive Layout ────────────────────
        'blog_layout'             => 'list',             // list | grid | cards
        'blog_columns'            => '2',                // 2 | 3 | 4  (when grid/cards)
        'blog_gap'                => '24',               // px – gap between items
        'blog_card_border'        => '1',                // show card border: 0 | 1
        'blog_card_shadow'        => '1',                // show card hover shadow: 0 | 1
        'blog_card_radius'        => '8',                // px – card border radius
        'blog_featured_image'     => '1',                // show featured images: 0 | 1
        'blog_image_height'       => '208',              // px – featured image height
        'blog_image_ratio'        => 'auto',             // auto | 16:9 | 4:3 | 1:1
        'blog_show_author'        => '1',                // show author: 0 | 1
        'blog_show_date'          => '1',                // show date: 0 | 1
        'blog_show_excerpt'       => '1',                // show excerpt: 0 | 1
        'blog_show_readmore'      => '1',                // show "Read more" link: 0 | 1
        'blog_readmore_text'      => 'Read more →',      // custom read more text

        // ── Single Post / Page Layout ────────────────
        'single_max_width'        => '768',              // px – single post content area
        'single_show_author'      => '1',
        'single_show_date'        => '1',
        'single_show_categories'  => '1',
        'single_show_tags'        => '1',
        'single_show_nav'         => '1',                // prev/next navigation
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
        'single_max_width'  => [480, 1200, 768],
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

    // Blog layout
    $validated['blog_layout'] = in_array(($input['blog_layout'] ?? ''), ['list', 'grid', 'cards'], true)
        ? (string)$input['blog_layout'] : $defaults['blog_layout'];

    // Blog columns: 2-4
    $bc = (int)($input['blog_columns'] ?? $defaults['blog_columns']);
    $validated['blog_columns'] = (string)max(2, min(4, $bc ?: 2));

    // Blog gap: 0-64px
    $bg = (int)($input['blog_gap'] ?? $defaults['blog_gap']);
    $validated['blog_gap'] = (string)max(0, min(64, $bg));

    // Boolean-ish fields
    $boolFields = ['blog_card_border', 'blog_card_shadow', 'blog_featured_image',
                   'blog_show_author', 'blog_show_date', 'blog_show_excerpt', 'blog_show_readmore',
                   'single_show_author', 'single_show_date', 'single_show_categories',
                   'single_show_tags', 'single_show_nav'];
    foreach ($boolFields as $key) {
        $validated[$key] = (int)(bool)($input[$key] ?? $defaults[$key]);
    }

    // Blog card radius: 0-24px
    $validated['blog_card_radius'] = (string)max(0, min(24, (int)($input['blog_card_radius'] ?? $defaults['blog_card_radius'])));

    // Blog image height: 100-500px
    $validated['blog_image_height'] = (string)max(100, min(500, (int)($input['blog_image_height'] ?? $defaults['blog_image_height'])));

    // Blog image ratio
    $validated['blog_image_ratio'] = in_array(($input['blog_image_ratio'] ?? ''), ['auto', '16:9', '4:3', '1:1'], true)
        ? (string)$input['blog_image_ratio'] : $defaults['blog_image_ratio'];

    // String fields – sanitise
    $validated['blog_readmore_text'] = htmlspecialchars(trim((string)($input['blog_readmore_text'] ?? $defaults['blog_readmore_text'])), ENT_QUOTES);
    if ($validated['blog_readmore_text'] === '') {
        $validated['blog_readmore_text'] = $defaults['blog_readmore_text'];
    }

    return $validated;
}

/**
 * Render <style> block with CSS custom properties from Theme Layout settings.
 * Injected into public layout <head>.
 */
function cmsRenderThemeLayoutStyle(object $db): string
{
    $cached = cmsCustomizerFragmentCacheGet('theme_layout_style');
    if (is_array($cached) && array_key_exists('html', $cached)) {
        return (string)$cached['html'];
    }

    if (!cmsCustomizerSectionExists($db, 'theme')) {
        cmsCustomizerFragmentCacheSet('theme_layout_style', ['html' => ''], ['cms:customizer:theme']);
        return '';
    }

    $data = cmsCustomizerGet($db, 'theme');
    $s = $data['settings'];

    $css = ':root{';
    $css .= '--theme-site-max-width:' . ($s['site_max_width'] ?? '1280') . 'px;';
    $css .= '--theme-content-max-width:' . ($s['content_max_width'] ?? '768') . 'px;';
    $css .= '--theme-single-max-width:' . ($s['single_max_width'] ?? '768') . 'px;';
    $css .= '--theme-content-px:' . ($s['content_padding_x'] ?? '16') . 'px;';
    $css .= '--theme-content-pt:' . ($s['content_padding_top'] ?? '32') . 'px;';
    $css .= '--theme-content-pb:' . ($s['content_padding_bottom'] ?? '32') . 'px;';
    $css .= '--theme-blog-gap:' . ($s['blog_gap'] ?? '24') . 'px;';
    $css .= '--theme-blog-cols:' . ($s['blog_columns'] ?? '2') . ';';
    $css .= '--theme-card-radius:' . ($s['blog_card_radius'] ?? '8') . 'px;';
    $css .= '--theme-image-height:' . ($s['blog_image_height'] ?? '208') . 'px;';
    $css .= '}';

    $mode = $s['layout_mode'] ?? 'contained';
    if ($mode === 'boxed') {
        $css .= 'body{max-width:var(--theme-site-max-width);margin-left:auto;margin-right:auto;box-shadow:0 0 40px rgba(0,0,0,0.08);}';
    }

    // Main content area
    $css .= '.cms-public-main{max-width:var(--theme-site-max-width);margin-left:auto;margin-right:auto;';
    $css .= 'padding:var(--theme-content-pt) var(--theme-content-px) var(--theme-content-pb);}';

    // Prose / single column
    $css .= '.cms-content-prose{max-width:var(--theme-content-max-width);margin-left:auto;margin-right:auto;}';
    $css .= '.cms-single-prose{max-width:var(--theme-single-max-width);margin-left:auto;margin-right:auto;}';

    // Blog layout – grid/cards mode
    $layout = $s['blog_layout'] ?? 'list';
    if ($layout === 'grid' || $layout === 'cards') {
        $css .= '.cms-blog-listing{display:grid;grid-template-columns:repeat(var(--theme-blog-cols),1fr);gap:var(--theme-blog-gap);}';
        $css .= '@media(max-width:768px){.cms-blog-listing{grid-template-columns:1fr;}}';
    } else {
        $css .= '.cms-blog-listing{display:flex;flex-direction:column;gap:var(--theme-blog-gap);}';
    }

    // Image ratio
    $ratio = $s['blog_image_ratio'] ?? 'auto';
    if ($ratio !== 'auto') {
        $ratioMap = ['16:9' => '56.25%', '4:3' => '75%', '1:1' => '100%'];
        $pct = $ratioMap[$ratio] ?? '56.25%';
        $css .= '.cms-blog-listing .cms-post-image{position:relative;width:100%;padding-bottom:' . $pct . ';overflow:hidden;}';
        $css .= '.cms-blog-listing .cms-post-image img{position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover;}';
    }

    // Card styling
    $css .= '.cms-blog-card{border-radius:var(--theme-card-radius);}';
    if (!($s['blog_card_border'] ?? 1)) {
        $css .= '.cms-blog-listing article{border:none;}';
    }
    if (($s['blog_card_shadow'] ?? 0)) {
        $css .= '.cms-blog-listing article:hover{box-shadow:0 4px 12px rgba(0,0,0,0.1);}';
    }

    $html = '<style id="cz-theme-layout-override">' . $css . '</style>';
    cmsCustomizerFragmentCacheSet('theme_layout_style', ['html' => $html], ['cms:customizer:theme']);
    return $html;
}

/**
 * Read a customizer section from the database.
 * Returns ['settings' => [...], 'widgets' => [...]]
 */

function cmsCustomizerGet(object $db, string $section): array
{
    $sectionDefaults = [
        'footer'      => 'cmsFooterSettingsDefaults',
        'sidebar'     => 'cmsSidebarSettingsDefaults',
        'header'      => 'cmsHeaderSettingsDefaults',
        'colors'      => 'cmsColorsSettingsDefaults',
        'custom_code' => 'cmsCustomCodeSettingsDefaults',
        'theme'       => 'cmsThemeLayoutSettingsDefaults',
    ];
    $defaults = isset($sectionDefaults[$section]) ? ($sectionDefaults[$section])() : [];

    try {
        $row = cmsCustomizerSectionRecord($db, $section);
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

    return $validated;
}

function cmsValidateSidebarSettings(array $input): array
{
    $defaults = cmsSidebarSettingsDefaults();
    $validated = [];
    $allowedTargets = array_map(static fn($t) => (string)($t['key'] ?? ''), cmsSidebarTemplateTargets());
    $fallbackTarget = !empty($allowedTargets[0]) ? $allowedTargets[0] : (string)$defaults['template_scope'];

    $validated['enabled'] = (int)(bool)($input['enabled'] ?? $defaults['enabled']);
    $validated['scope_mode'] = in_array(($input['scope_mode'] ?? ''), ['general', 'template'], true)
        ? (string)$input['scope_mode'] : $defaults['scope_mode'];
    $validated['template_scope'] = in_array((string)($input['template_scope'] ?? ''), $allowedTargets, true)
        ? (string)$input['template_scope'] : $fallbackTarget;
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
    $innerWidth     = ($settings['inner_width'] ?? 'contained') === 'full-width' ? '' : ' container';

    $html = '<div class="footer-widgets" style="'
        . '--footer-bg:' . $bgColor . ';'
        . '--footer-text:' . $textColor . ';'
        . '--footer-link:' . $linkColor . ';'
        . '--footer-link-hover:' . $linkHoverColor . ';'
        . '--footer-title-color:' . $titleColor . ';'
        . 'background:' . $bgColor . ';'
        . 'color:' . $textColor . ';'
        . 'padding:' . $paddingTop . 'px 0 ' . $paddingBottom . 'px;">';

    $html .= '<div class="' . trim($innerWidth) . '">';
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
    return $html;
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

function cmsRenderCustomizedFooter(object $db): string
{
    $cached = cmsCustomizerFragmentCacheGet('footer_html');
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
        $barBg    = htmlspecialchars($settings['bar_bg_color'] ?? '#0f172a');
        $barText  = htmlspecialchars($settings['bar_text_color'] ?? '#64748b');
        $barLink  = htmlspecialchars($settings['bar_link_color'] ?? '#94a3b8');
        $barLinkH = htmlspecialchars($settings['bar_link_hover_color'] ?? '#ffffff');

        $copyright = (string)($settings['copyright_text'] ?? '');
        $copyright = str_replace('{current_year}', date('Y'), $copyright);
        $copyright = str_replace('{site_title}', htmlspecialchars($cmsSettings['site_title'] ?? ''), $copyright);

        $html .= '<div class="footer-bottom" style="background:' . $barBg . ';color:' . $barText . ';'
                . '--footer-link:' . $barLink . ';--footer-link-hover:' . $barLinkH . ';">';
        $html .= '<div class="container" style="text-align:center;">';
        $html .= '<span>' . $copyright . '</span>';

        if ((int)($settings['show_admin_link'] ?? 1)) {
            $html .= ' <span style="margin-left:0.5rem;opacity:0.5;">·</span>';
            $html .= ' <a href="' . $baseUrl . '/cms/admin" style="color:' . $barLink . ';margin-left:0.5rem;font-size:0.8rem;">Admin</a>';
        }
        $html .= '</div></div>';
    }

    cmsCustomizerFragmentCacheSet('footer_html', ['html' => $html], ['cms:customizer:footer', 'cms:settings', 'cms:menus']);
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
    $templateScope = (string)($settings['template_scope'] ?? $defaultTarget);
    $showForThisTemplate = ($scopeMode === 'general') || ($scopeMode === 'template' && $templateScope === $templateKey);
    if (!$showForThisTemplate) {
        return ['enabled' => false, 'position' => ($settings['placement'] ?? 'right'), 'width' => ($settings['width'] ?? '300'), 'html' => ''];
    }

    $cacheFragment = 'sidebar_html:' . sha1($templateKey);
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
        $widgetsHtml .= cmsRenderSingleSidebarWidget((array)$widget, $db, $cmsSettings, $baseUrl);
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

    $html = $styleHtml . $bodyHtml;

    $result = [
        'enabled' => true,
        'position' => in_array(($settings['placement'] ?? ''), ['left', 'right'], true) ? $settings['placement'] : 'right',
        'width' => (string)$width,
        'html' => $html,
    ];

    cmsCustomizerFragmentCacheSet($cacheFragment, $result, ['cms:customizer:sidebar', 'cms:settings', 'cms:menus']);

    return $result;
}

function cmsRenderSingleSidebarWidget(array $widget, object $db, array $cmsSettings, string $baseUrl): string
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
                $html .= cmsRenderMenuById($db, $menuId, 'sidebar-menu');
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
            $html .= '<form class="sidebar-search-form" action="' . htmlspecialchars($baseUrl . '/cms/blog') . '" method="get">';
            $html .= '<input class="sidebar-search-input" type="search" name="q" placeholder="' . htmlspecialchars($placeholder) . '">';
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
    return $html;
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
    $cached = cmsCustomizerFragmentCacheGet('header_html:' . sha1(cmsCustomizerCurrentPathCacheToken()));
    if (is_array($cached) && array_key_exists('html', $cached)) {
        return (string)$cached['html'];
    }

    if (!cmsCustomizerSectionExists($db, 'header')) {
        cmsCustomizerFragmentCacheSet('header_html:' . sha1(cmsCustomizerCurrentPathCacheToken()), ['html' => ''], ['cms:customizer:header', 'cms:settings', 'cms:menus']);
        return '';
    }

    $data = cmsCustomizerGet($db, 'header');
    $settings = $data['settings'];
    $widgets  = $data['widgets'] ?? [];

    $cmsSettings = readCmsSettings();
    $baseUrl = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');

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
    $innerWidth    = ($settings['inner_width'] ?? 'contained') === 'full-width' ? '' : ' container';
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
    $wrapperClass = 'header-wrapper';
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
        $html .= '<div class="' . trim($innerWidth) . '">';
        $html .= '<div class="header-topbar-inner" style="justify-content:' . $tbJustify . ';">';
        foreach ($widgets as $widget) {
            $html .= cmsRenderSingleHeaderWidget($widget, $db, $cmsSettings, $baseUrl);
        }
        $html .= '</div></div></div>';
    }

    // ── Main Header ──────────────────────────────────────────────────
    $html .= '<header class="site-header' . $stickyClass . $layoutClass . '" style="' . $cssVars . '">';
    $html .= '<div class="' . trim($innerWidth) . '">';
    $html .= '<div class="header-inner" style="' . $heightStyle . 'padding:' . $paddingTop . 'px 0 ' . $paddingBottom . 'px;">';

    // Branding
    $html .= '<div class="site-branding">';
    if ($logoUrl !== '') {
        $html .= '<a href="' . $baseUrl . '/cms" class="site-logo site-logo--image">'
            . '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES) . '" alt="' . $siteTitle . '" '
            . 'style="max-height:' . $logoMaxH . 'px;width:auto;" class="header-logo-img">'
            . '</a>';
    } else {
        $html .= '<a href="' . $baseUrl . '/cms" class="site-logo" style="color:var(--header-logo-color);">' . $siteTitle . '</a>';
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
        ]);
    } catch (Throwable $e) {}

    if ($menuHtml !== '') {
        $html .= $menuHtml;
    } else {
        $html .= '<nav class="main-navigation">';
        $html .= '<ul class="nav-menu">';
        $html .= '<li><a href="' . $baseUrl . '/cms">Home</a></li>';
        $html .= '<li><a href="' . $baseUrl . '/cms/blog">Blog</a></li>';
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
            ]);
        } catch (Throwable $e) {}
        if ($canvasNavHtml !== '') {
            $canvasNavHtml = preg_replace('/^<nav([^>]*)>/', '<nav$1 style="' . $canvasVarStyle . '">' . $canvasHeaderHtml, $canvasNavHtml, 1) ?: $canvasNavHtml;
            $html .= $canvasNavHtml;
        } else {
            $html .= '<nav class="canvas-navigation mobile-canvas-target" style="' . $canvasVarStyle . '"><ul class="nav-menu">';
            $html .= $canvasHeaderHtml;
            $html .= '<li><a href="' . $baseUrl . '/cms">Home</a></li>';
            $html .= '<li><a href="' . $baseUrl . '/cms/blog">Blog</a></li>';
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
        $searchAction = htmlspecialchars($baseUrl . '/cms/search');
        $html .= '<div id="header-search-overlay" class="header-search-overlay">'
            . '<div class="header-search-overlay-inner">'
            . '<form action="' . $searchAction . '" method="GET" class="header-search-form">'
            . '<input type="text" name="q" class="header-search-input" placeholder="Search…" autocomplete="off" autofocus>'
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

    cmsCustomizerFragmentCacheSet('header_html:' . sha1(cmsCustomizerCurrentPathCacheToken()), ['html' => $html], ['cms:customizer:header', 'cms:settings', 'cms:menus']);
    return $html;
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
    return $html;
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
