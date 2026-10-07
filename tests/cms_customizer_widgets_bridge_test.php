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
function widgetBridgeRender(array $widgets): string
{
    $method = new ReflectionMethod(ThemeRegionRenderer::class, 'renderWidgets');
    $method->setAccessible(true);
    return (string)$method->invoke(null, $widgets);
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
    'props' => ['placeholder' => 'Search…', 'button_label' => 'Search'],
]]);
widgetBridgeAssert(
    'search_box renders through the CMS widget renderer',
    str_contains($search, 'type="search" name="q"'),
    $search
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

// The ARK templates must consume the bridge output, and the safety policy must
// allowlist the raw key.
$sidebar = (string)file_get_contents($root . '/storage/cms-themes/ark/templates/regions/sidebar.disyl');
widgetBridgeAssert('ARK sidebar emits widgets_html', str_contains($sidebar, '{widgets_html|raw}'));
widgetBridgeAssert('ARK sidebar placeholder is gone', !str_contains($sidebar, 'Configure sidebar widgets in Appearance'));

$policy = (string)file_get_contents($root . '/storage/cms-themes/ark/safety-policy.json');
widgetBridgeAssert('safety policy allowlists widgets_html', str_contains($policy, '"widgets_html"'));

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
