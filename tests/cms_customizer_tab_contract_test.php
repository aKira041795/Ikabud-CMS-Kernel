<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/cms/handlers.php';
require_once __DIR__ . '/../modules/ecommerce/helpers.php';

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
    } else {
        $fail++;
        $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
        echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

function saveCustomizerSection(object $db, string $section, array $settings, array $widgets = [], string $scope = 'native'): void
{
    cmsUpsertCustomizerSection($db, $section, $settings, $widgets, null, $scope);
    cmsCustomizerClearPersistentCache($section, $scope);
    cmsCacheInvalidateByTags(['cms:customizer']);
    $GLOBALS[cmsCustomizerRequestCacheKey('section_row', $scope)] = [];
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

$db = cmsDb();
$baseUrl = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
$customizerTemplateContent = file_get_contents(BASE_PATH . '/templates/modules/cms/admin/theme-customizer.disyl') ?: '';

echo "\n=== CUSTOMIZER UI CONTRACT ===\n";

t(
    'customizer exposes custom code fields and behavior toggles',
    str_contains($customizerTemplateContent, 'customCodeSettings.custom_css')
        && str_contains($customizerTemplateContent, 'customCodeSettings.head_code')
        && str_contains($customizerTemplateContent, 'customCodeSettings.body_end_code')
        && str_contains($customizerTemplateContent, 'customCodeSettings.smooth_scroll')
        && str_contains($customizerTemplateContent, 'customCodeSettings.page_transition_style')
        && str_contains($customizerTemplateContent, 'customCodeSettings.scroll_to_top_position'),
    $customizerTemplateContent
);
t(
    'customizer exposes advanced navigation canvas and dropdown controls',
    str_contains($customizerTemplateContent, 'Canvas Menu')
        && str_contains($customizerTemplateContent, 'Slide Direction')
        && str_contains($customizerTemplateContent, 'Close on Link Click')
        && str_contains($customizerTemplateContent, 'Dropdown Menu')
        && str_contains($customizerTemplateContent, 'Dropdown Min Width')
        && str_contains($customizerTemplateContent, 'Top Bar'),
    $customizerTemplateContent
);

$oldSettings = getModuleSettings('cms');
$restoreTheme = trim((string)($oldSettings['active_theme'] ?? ''));

try {
    $pocSettings = $oldSettings;
    $pocSettings['active_theme'] = 'entity-commerce-poc';
    saveModuleSettings('cms', $pocSettings);
    cmsResetThemeRuntimeCache();
    cmsActivateThemeSymlink('entity-commerce-poc');

    echo "\n=== CUSTOM CODE CONTRACT ===\n";

    $invalidCustomCode = cmsValidateCustomCodeSettings([
        'custom_css' => 'body{color:red;}</style><script>bad()</script>',
        'scroll_to_top_bg' => 'bad-color',
        'scroll_to_top_color' => 'rgba(1,2,3,0.5)',
        'scroll_to_top_size' => '200',
        'scroll_to_top_radius' => '-10',
        'scroll_to_top_position' => 'center',
        'scroll_to_top_offset' => '2',
        'page_transition_style' => 'spin',
    ]);
    t('custom code validation strips closing style tags', !str_contains((string)($invalidCustomCode['custom_css'] ?? ''), '</style>'), (string)($invalidCustomCode['custom_css'] ?? ''));
    t('custom code validation falls back invalid scroll button background color', ($invalidCustomCode['scroll_to_top_bg'] ?? '') === '#3b82f6', json_encode($invalidCustomCode));
    t('custom code validation keeps rgba scroll button icon color', ($invalidCustomCode['scroll_to_top_color'] ?? '') === 'rgba(1,2,3,0.5)', json_encode($invalidCustomCode));
    t('custom code validation clamps scroll button size and offset', ($invalidCustomCode['scroll_to_top_size'] ?? '') === '72' && ($invalidCustomCode['scroll_to_top_offset'] ?? '') === '8', json_encode($invalidCustomCode));
    t('custom code validation falls back invalid scroll position and transition style', ($invalidCustomCode['scroll_to_top_position'] ?? '') === 'right' && ($invalidCustomCode['page_transition_style'] ?? '') === 'fade', json_encode($invalidCustomCode));

    $customCodeSettings = cmsValidateCustomCodeSettings([
        'custom_css' => 'body{background:#123456;}</style>',
        'head_code' => '<meta name="custom-head" content="1">',
        'body_end_code' => '<script>window.customBodyEnd=1</script>',
        'scroll_to_top' => 1,
        'scroll_to_top_bg' => '#111111',
        'scroll_to_top_color' => '#fafafa',
        'scroll_to_top_size' => '52',
        'scroll_to_top_radius' => '24',
        'scroll_to_top_position' => 'left',
        'scroll_to_top_offset' => '18',
        'smooth_scroll' => 1,
        'page_transition' => 1,
        'page_transition_style' => 'zoom',
    ]);
    saveCustomizerSection($db, 'custom_code', $customCodeSettings, [], 'ecommerce');

    $customCodeOutput = cmsRenderCustomCodeOutput($db);
    t('custom code render emits sanitized custom css style tag', str_contains((string)($customCodeOutput['custom_css'] ?? ''), '<style id="cz-custom-css">body{background:#123456;}') && !str_contains((string)($customCodeOutput['custom_css'] ?? ''), '</style></style>'), (string)($customCodeOutput['custom_css'] ?? ''));
    t('custom code render emits head and body injection blocks', str_contains((string)($customCodeOutput['head_code'] ?? ''), '<meta name="custom-head" content="1">') && str_contains((string)($customCodeOutput['body_end_code'] ?? ''), 'window.customBodyEnd=1'), json_encode($customCodeOutput));
    t('custom code render emits smooth scroll and zoom transition helpers', str_contains((string)($customCodeOutput['body_end_code'] ?? ''), 'id="cz-smooth-scroll"') && str_contains((string)($customCodeOutput['body_end_code'] ?? ''), '@keyframes czZoomIn') && str_contains((string)($customCodeOutput['body_end_code'] ?? ''), 'requestAnimationFrame(r)'), (string)($customCodeOutput['body_end_code'] ?? ''));
    t('custom code render emits scroll to top button with left-side geometry', str_contains((string)($customCodeOutput['body_end_code'] ?? ''), 'id="cz-scroll-top"') && str_contains((string)($customCodeOutput['body_end_code'] ?? ''), 'left:18px;') && str_contains((string)($customCodeOutput['body_end_code'] ?? ''), 'width:52px;height:52px;') && str_contains((string)($customCodeOutput['body_end_code'] ?? ''), 'border-radius:24%;background:#111111;color:#fafafa;') && str_contains((string)($customCodeOutput['body_end_code'] ?? ''), 'window.scrollTo({top:0,behavior:"smooth"})'), (string)($customCodeOutput['body_end_code'] ?? ''));

    echo "\n=== ROUTE-AWARE CHROME HELPERS ===\n";

    $storefrontCtx = [
        'active_customizer_scope' => 'ecommerce',
        'public_render_origin' => 'ecommerce',
        'public_route_kind' => 'shop_index',
        'public_presentation_mode' => 'traditional',
    ];
    $canonicalCtx = [
        'active_customizer_scope' => 'ecommerce',
        'public_render_origin' => 'cms',
        'public_route_kind' => 'page',
        'public_presentation_mode' => 'canonical',
    ];
    $frontPageCtx = [
        'active_customizer_scope' => 'ecommerce',
        'public_render_origin' => 'cms',
        'public_route_kind' => 'front-page',
        'public_presentation_mode' => 'canonical',
    ];

    $storefrontSearch = cmsCustomizerSearchConfig('ecommerce', $storefrontCtx);
    $canonicalSearch = cmsCustomizerSearchConfig('ecommerce', $canonicalCtx);
    $storefrontFallback = cmsCustomizerFallbackNavItems($baseUrl, 'ecommerce', $storefrontCtx);
    $canonicalFallback = cmsCustomizerFallbackNavItems($baseUrl, 'ecommerce', $canonicalCtx);
    t('storefront chrome helpers keep ecommerce home and search endpoints', cmsCustomizerHomeUrl($baseUrl, 'ecommerce', $storefrontCtx) === $baseUrl . '/ecommerce/shop' && ($storefrontSearch['action_path'] ?? '') === '/ecommerce/shop' && ($storefrontSearch['query_param'] ?? '') === 'search', json_encode($storefrontSearch));
    t('canonical CMS chrome helpers switch ecommerce-scoped shell back to CMS routes', cmsCustomizerHomeUrl($baseUrl, 'ecommerce', $canonicalCtx) === $baseUrl . '/cms' && ($canonicalSearch['action_path'] ?? '') === '/cms/search' && ($canonicalSearch['query_param'] ?? '') === 'q', json_encode($canonicalSearch));
    t('storefront fallback navigation stays commerce-oriented', ($storefrontFallback[0]['label'] ?? '') === 'Shop' && ($storefrontFallback[1]['href'] ?? '') === $baseUrl . '/ecommerce/my-orders', json_encode($storefrontFallback));
    t('canonical fallback navigation reverts to CMS home and blog links', ($canonicalFallback[0]['href'] ?? '') === $baseUrl . '/cms' && ($canonicalFallback[1]['href'] ?? '') === $baseUrl . '/cms/blog', json_encode($canonicalFallback));

    echo "\n=== HEADER AND NAVIGATION CONTRACT ===\n";

    $headerSettings = cmsValidateHeaderSettings(array_merge(cmsHeaderSettingsDefaults(), [
        'show_topbar' => 1,
        'show_search' => 1,
        'show_cta_button' => 1,
        'cta_text' => 'Buy Now',
        'cta_url' => '/buy-now',
        'menu_location' => '__missing_menu__',
        'topbar_align' => 'right',
        'topbar_bg_color' => '#101820',
        'topbar_text_color' => '#f8fafc',
        'topbar_link_color' => '#f59e0b',
        'topbar_link_hover_color' => '#ffffff',
        'mobile_menu_style' => 'canvas',
        'mobile_canvas_direction' => 'right',
        'mobile_canvas_width' => '340',
        'mobile_menu_align' => 'center',
        'mobile_hover_bg_color' => '#123456',
        'mobile_active_bg_color' => '#654321',
        'mobile_close_on_link' => 0,
        'mobile_breakpoint' => '1024',
        'dropdown_bg_color' => '#111827',
        'dropdown_text_color' => '#f8fafc',
        'dropdown_hover_bg_color' => '#1f2937',
        'dropdown_hover_text_color' => '#f59e0b',
        'dropdown_border_color' => '#334155',
        'dropdown_min_width' => '260',
        'dropdown_radius' => '14',
        'dropdown_item_padding_y' => '12',
        'transparent_home' => 1,
        'transparent_text_color' => '#ffeeaa',
        'transparent_logo_color' => '#ffdd88',
        'header_bg_opacity' => '70',
    ]));
    $headerWidgets = [[
        'id' => 'header-contract-text',
        'type' => 'text',
        'props' => [
            'content' => 'Free shipping',
        ],
    ]];
    saveCustomizerSection($db, 'header', $headerSettings, $headerWidgets, 'ecommerce');

    $storefrontHeaderHtml = cmsRenderCustomizedHeader($db, $storefrontCtx);
    $canonicalHeaderHtml = cmsRenderCustomizedHeader($db, $canonicalCtx);

    t('transparent header helper limits transparent_home to storefront and CMS front-page routes', cmsHeaderTransparencyEnabled($headerSettings, $storefrontCtx) && cmsHeaderTransparencyEnabled($headerSettings, $frontPageCtx) && !cmsHeaderTransparencyEnabled($headerSettings, $canonicalCtx));
    t('advanced header render includes top bar widget content and alignment styling', str_contains($storefrontHeaderHtml, 'Free shipping') && str_contains($storefrontHeaderHtml, 'justify-content:flex-end;') && str_contains($storefrontHeaderHtml, '--topbar-link:#f59e0b;'), $storefrontHeaderHtml);
    t('advanced header render includes canvas menu CSS and close-on-link contract', str_contains($storefrontHeaderHtml, 'data-close-on-link="0"') && str_contains($storefrontHeaderHtml, '.mobile-canvas-target{position:fixed;top:0;right:0;bottom:0;width:340px') && str_contains($storefrontHeaderHtml, '.mobile-canvas-target .nav-menu{flex-direction:column;align-items:center;') && str_contains($storefrontHeaderHtml, '.mobile-canvas-target .nav-menu a:hover{background:#123456;}') && str_contains($storefrontHeaderHtml, '.mobile-canvas-target .nav-menu li.current-menu-item>a{background:#654321;}'), $storefrontHeaderHtml);
    t('advanced header render includes dropdown geometry and color contract', str_contains($storefrontHeaderHtml, '--nav-dropdown-bg:#111827;') && str_contains($storefrontHeaderHtml, '--nav-dropdown-border:#334155;') && str_contains($storefrontHeaderHtml, '--nav-dropdown-radius:14px;') && str_contains($storefrontHeaderHtml, '--nav-dropdown-item-padding:12px 1rem;') && str_contains($storefrontHeaderHtml, 'min-width:260px'), $storefrontHeaderHtml);
    t('advanced header render includes home-only transparent header script contract', str_contains($storefrontHeaderHtml, 'var tx="#ffeeaa",lg="#ffdd88";') && str_contains($storefrontHeaderHtml, 'var r=255,g=255,b=255,op=0.7;') && str_contains($storefrontHeaderHtml, 'h.style.setProperty("--header-bg","rgba("+r+","+g+","+b+","+op+")")') && str_contains($storefrontHeaderHtml, 'h.style.setProperty("--header-bg",oBg);') && !str_contains($canonicalHeaderHtml, 'var tx="#ffeeaa",lg="#ffdd88";'), $storefrontHeaderHtml . "\n---\n" . $canonicalHeaderHtml);
    t('route-aware header render keeps storefront search and fallback nav for ecommerce routes', str_contains($storefrontHeaderHtml, 'action="' . $baseUrl . '/ecommerce/shop"') && str_contains($storefrontHeaderHtml, 'name="search"') && str_contains($storefrontHeaderHtml, '>Shop<') && str_contains($storefrontHeaderHtml, '/ecommerce/my-orders'), $storefrontHeaderHtml);
    t('route-aware header render switches the same cached fragment family back to CMS search and nav for canonical CMS routes', $storefrontHeaderHtml !== $canonicalHeaderHtml && str_contains($canonicalHeaderHtml, 'action="' . $baseUrl . '/cms/search"') && str_contains($canonicalHeaderHtml, 'name="q"') && str_contains($canonicalHeaderHtml, 'href="' . $baseUrl . '/cms" class="site-logo"') && str_contains($canonicalHeaderHtml, '>Home<') && str_contains($canonicalHeaderHtml, '>Blog<'), $canonicalHeaderHtml);

    echo "\n=== FULL PAGE INJECTION ===\n";

    $canonicalPageHtml = cmsPublicCanonicalRenderEntityView([
        'id' => 0,
        'title' => 'Customizer Contract Page',
        'slug' => 'customizer-contract-page',
        'body' => '<p>Body</p>',
        'type' => 'page',
    ], [
        'content_type' => 'page',
        'rendered_html' => '<p>Body</p>',
        'public_render_origin' => 'cms',
        'public_route_kind' => 'page',
        'public_presentation_mode' => 'canonical',
    ]);

    t('canonical page render injects custom css and head code into the shared layout', str_contains($canonicalPageHtml, '<style id="cz-custom-css">body{background:#123456;}') && str_contains($canonicalPageHtml, '<meta name="custom-head" content="1">'), $canonicalPageHtml);
    t('canonical page render injects body-end code and enhancement helpers into the shared layout', str_contains($canonicalPageHtml, 'window.customBodyEnd=1') && str_contains($canonicalPageHtml, 'id="cz-smooth-scroll"') && str_contains($canonicalPageHtml, '@keyframes czZoomIn') && str_contains($canonicalPageHtml, 'id="cz-scroll-top"'), $canonicalPageHtml);

    $appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
    $errLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';
    $criticalLines = array_values(array_filter(explode("\n", $appLog), static fn(string $line): bool => str_contains($line, '[critical]')));
    $unexpectedErrorLines = array_values(array_filter(
        explode("\n", $errLog),
        static fn(string $line): bool => trim($line) !== '' && !str_contains($line, 'Ikabud Cache:')
    ));

    echo "\n=== LOGS ===\n";

    t('no app.log critical errors after tab contract render pass', empty($criticalLines), implode('; ', $criticalLines));
    t('no unexpected PHP errors after tab contract render pass', empty($unexpectedErrorLines), implode('; ', $unexpectedErrorLines));
} finally {
    saveModuleSettings('cms', $oldSettings);
    cmsResetThemeRuntimeCache();
    if ($restoreTheme === '' || $restoreTheme === 'default') {
        cmsActivateThemeSymlink(null);
    } else {
        cmsActivateThemeSymlink($restoreTheme);
    }
}

echo "\n══════════════════════════════════════════════════\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
echo "══════════════════════════════════════════════════\n";

if (!empty($errors)) {
    echo "\nFailed tests:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

exit($fail > 0 ? 1 : 0);