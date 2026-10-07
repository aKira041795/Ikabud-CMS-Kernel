<?php

/**
 * Focused coverage for the customizer widgets → theme region bridge.
 *
 * The defect: cms_theme_customizer.widgets_json was saved and the CMS owned
 * renderers for those widget types, but ThemeRenderContext carried no widget
 * data, so ARK's region templates could not render a single configured widget.
 *
 * Run: php tests/cms_customizer_widgets_bridge_test.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/bootstrap.php';
require_once $root . '/src/helpers/module-manager.php';
require_once $root . '/modules/cms/helpers.php';

use Ikabud\Kernel\Contracts\ThemeCustomizationScope;
use Ikabud\Kernel\Contracts\ThemeRenderContext;
use Ikabud\Kernel\DiSyL\TemplateEngine;
use Ikabud\Kernel\Services\ThemeRegionRenderer;

$passed = 0;
$failed = 0;

function widgetBridgeAssert(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        ++$passed;
        echo "  PASS: {$label}\n";
        return;
    }
    ++$failed;
    echo "  FAIL: {$label}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
}

/** Invoke the private pre-render bridge directly, avoiding template rendering. */
function widgetBridgeRender(array $widgets, string $region = 'sidebar', ?string $location = null): string
{
    $method = new ReflectionMethod(ThemeRegionRenderer::class, 'renderWidgets');
    return (string)$method->invoke(null, $widgets, $region, $location);
}

/** @return array<int, array<string, mixed>> */
function widgetBridgeHeaderWidgetsAt(array $widgets, string $location): array
{
    $method = new ReflectionMethod(ThemeRegionRenderer::class, 'headerWidgetsAt');
    return (array)$method->invoke(null, $widgets, $location);
}

$scope = new ThemeCustomizationScope(themeSlug: 'ark');

// Additive contract: existing construction sites keep working unchanged.
$bare = new ThemeRenderContext(
    theme: 'ark',
    scope: $scope,
    settings: [],
    tokens: [],
    site: [],
    navigation: [],
    entityContext: [],
    slotContributions: [],
);
widgetBridgeAssert('ThemeRenderContext defaults widgets to an empty array', $bare->widgets === []);
widgetBridgeAssert('widgetsFor() returns nothing for an unconfigured region', $bare->widgetsFor('sidebar') === []);

$configured = new ThemeRenderContext(
    theme: 'ark',
    scope: $scope,
    settings: [],
    tokens: [],
    site: [],
    navigation: [],
    entityContext: [],
    slotContributions: [],
    widgets: ['sidebar' => [['type' => 'search_box', 'props' => ['placeholder' => 'Search…']]]],
);
widgetBridgeAssert('widgetsFor() exposes the configured region widgets', count($configured->widgetsFor('sidebar')) === 1);

// Empty case: nothing configured must render nothing, so no stray container.
widgetBridgeAssert('no widgets renders an empty string', widgetBridgeRender([]) === '');

// The CMS owns the renderers; the bridge must dispatch through them and not
// re-implement escaping. These needles are markup, not bare words.
$search = widgetBridgeRender([[
    'type' => 'search_box',
    'props' => ['placeholder' => 'Search…', 'button_label' => 'Find'],
]]);
widgetBridgeAssert(
    'search_box renders through the CMS widget renderer',
    str_contains($search, 'type="search" name="q"'),
    $search
);
widgetBridgeAssert('button_label aliases to buttonText', str_contains($search, '>Find</button>'), $search);

$openingHours = widgetBridgeRender([[
    'type' => 'opening_hours',
    'props' => ['text' => 'Always open', 'icon' => 0],
]]);
widgetBridgeAssert('opening_hours icon aliases to boolean showIcon', !str_contains($openingHours, '<svg'), $openingHours);

$button = widgetBridgeRender([[
    'type' => 'button',
    'props' => ['text' => 'Visit', 'url' => '/visit', 'new_tab' => 1],
]]);
widgetBridgeAssert(
    'truthy new_tab translates to the target contract',
    str_contains($button, 'target="_blank"') && str_contains($button, 'rel="noopener noreferrer"'),
    $button
);

$nav = widgetBridgeRender([[
    'type' => 'nav_menu',
    'props' => ['title' => 'Nav', 'menu_id' => 1],
]]);
widgetBridgeAssert(
    'nav_menu renders the theme widget shell',
    str_contains($nav, 'font-size:16px;font-weight:700;line-height:1.3;color:#0f172a'),
    $nav
);

$headerDefaultWidth = widgetBridgeRender([['type' => 'opening_hours', 'props' => ['text' => 'Open']]], 'header');
$headerExplicitWidth = widgetBridgeRender([[
    'type' => 'opening_hours',
    'props' => ['text' => 'Open'],
    'style' => ['width' => '50%'],
]], 'header');
$sidebarDefaultWidth = widgetBridgeRender([['type' => 'opening_hours', 'props' => ['text' => 'Open']]], 'sidebar');
widgetBridgeAssert('header drops only the shared default width', !str_contains($headerDefaultWidth, 'width:100%'), $headerDefaultWidth);
widgetBridgeAssert('header preserves explicit widget width', str_contains($headerExplicitWidth, 'width:50%'), $headerExplicitWidth);
widgetBridgeAssert('non-header regions retain shared default width', str_contains($sidebarDefaultWidth, 'width:100%'), $sidebarDefaultWidth);

$contactWidget = [[
    'type' => 'contact_info',
    'props' => ['title' => 'Contact Info', 'address' => '123 Market Street', 'phone' => '+63 900 000 0000', 'email' => 'hello@example.com'],
]];
$headerContact = widgetBridgeRender($contactWidget, 'header');
$topbarContact = widgetBridgeRender($contactWidget, 'header', 'topbar');
$sidebarContact = widgetBridgeRender($contactWidget, 'sidebar');
$footerContact = widgetBridgeRender($contactWidget, 'footer');
foreach (['header' => $headerContact, 'topbar' => $topbarContact] as $band => $html) {
    widgetBridgeAssert("{$band} contact_info is compact phone + email", str_contains($html, 'tel:') && str_contains($html, 'mailto:'), $html);
    widgetBridgeAssert("{$band} contact_info omits card-only address/title/labels", !str_contains($html, '123 Market Street') && !str_contains($html, '>Contact Info<') && !str_contains($html, '>Phone<') && !str_contains($html, '>Email<'), $html);
    widgetBridgeAssert("{$band} contact_info omits shared card chrome", !str_contains($html, 'border:1px solid #e5e7eb') && !str_contains($html, 'padding:20px'), $html);
}
widgetBridgeAssert('sidebar contact_info remains the full card', str_contains($sidebarContact, '123 Market Street') && str_contains($sidebarContact, '>Contact Info<') && str_contains($sidebarContact, '>Phone<'), $sidebarContact);
widgetBridgeAssert('footer contact_info remains the full card', str_contains($footerContact, '123 Market Street') && str_contains($footerContact, '>Contact Info<') && str_contains($footerContact, '>Phone<'), $footerContact);

foreach (['social_links', 'nav_menu', 'opening_hours'] as $compactType) {
    $compactHtml = widgetBridgeRender([['type' => $compactType, 'props' => []]], 'header');
    widgetBridgeAssert("header {$compactType} omits shared card chrome", !str_contains($compactHtml, 'border:1px solid #e5e7eb') && !str_contains($compactHtml, 'padding:20px'), $compactHtml);
}

$locatedWidgets = [
    ['id' => 'legacy', 'type' => 'text', 'props' => ['content' => 'Legacy']],
    ['id' => 'top', 'type' => 'text', 'location' => 'topbar', 'props' => ['content' => 'Top']],
];
widgetBridgeAssert(
    'missing location remains in the header-row collection',
    array_column(widgetBridgeHeaderWidgetsAt($locatedWidgets, 'header'), 'id') === ['legacy']
);
widgetBridgeAssert(
    'topbar location moves only that widget to the topbar collection',
    array_column(widgetBridgeHeaderWidgetsAt($locatedWidgets, 'topbar'), 'id') === ['top']
);

// The ARK templates must consume the bridge output, and the safety policy must
// allowlist the raw key.
$sidebar = (string)file_get_contents($root . '/storage/cms-themes/ark/templates/regions/sidebar.disyl');
widgetBridgeAssert('ARK sidebar emits widgets_html', str_contains($sidebar, '{widgets_html|raw}'));
widgetBridgeAssert('ARK sidebar placeholder is gone', !str_contains($sidebar, 'Configure sidebar widgets in Appearance'));

$header = (string)file_get_contents($root . '/storage/cms-themes/ark/templates/regions/header.disyl');
widgetBridgeAssert(
    'ARK topbar conditionally emits widgets_topbar_html in its right column',
    str_contains($header, '{if widgets_topbar_html}')
        && str_contains($header, '<div class="ark-topbar__right">{widgets_topbar_html|raw}</div>')
);

$policy = (string)file_get_contents($root . '/storage/cms-themes/ark/safety-policy.json');
widgetBridgeAssert('safety policy allowlists widgets_html', str_contains($policy, '"widgets_html"'));
widgetBridgeAssert('safety policy allowlists widgets_topbar_html', str_contains($policy, '"widgets_topbar_html"'));

// S3, at the template level: an enabled region with no widgets must not emit
// a stray widget container, while widgets must render inside it when present.
$cachePath = sys_get_temp_dir() . '/cms_widgets_bridge_' . getmypid();
@mkdir($cachePath, 0777, true);
$engine = new TemplateEngine($root . '/templates', $cachePath, false);
$renderSidebar = static function (string $widgetsHtml) use ($engine, $root): string {
    $template = (string)file_get_contents($root . '/storage/cms-themes/ark/templates/regions/sidebar.disyl');
    return (string)$engine->renderString($template, [
        'theme' => 'ark',
        'site' => ['title' => 'Site', 'url' => 'http://cmsnew.test', 'tagline' => ''],
        'section_settings' => ['enabled' => '1'],
        'navigation' => [],
        'widgets_html' => $widgetsHtml,
    ]);
};

$emptySidebar = $renderSidebar('');
widgetBridgeAssert(
    'enabled sidebar with no widgets emits no widget container',
    !str_contains($emptySidebar, 'ark-region-sidebar__widget'),
    $emptySidebar
);
$filledSidebar = $renderSidebar('<form data-probe="widget"></form>');
widgetBridgeAssert(
    'widgets render inside the sidebar container',
    str_contains($filledSidebar, 'ark-region-sidebar__widget') && str_contains($filledSidebar, '<form data-probe="widget">'),
    $filledSidebar
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
