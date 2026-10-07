<?php
/** Emit the declared ARK region-widget matrix using the production bridge. */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
// Use the CMS integration tenant so data-backed offered widgets exercise their real query path.
$_SERVER['HTTP_HOST'] = 'cmsnew.test';
require_once $root . '/bootstrap.php';
require_once $root . '/src/helpers/module-manager.php';
require_once $root . '/modules/cms/helpers.php';

use Ikabud\Kernel\DiSyL\TemplateEngine;
use Ikabud\Kernel\Services\ThemeRegionRenderer;

$panel = (string) file_get_contents($root . '/templates/modules/cms/admin/theme-customizer.disyl');

/** @return list<string> */
function declaredTypes(string $source, string $list): array
{
    if (preg_match('/\\b' . preg_quote($list, '/') . '\\s*:\\s*\\[(.*?)\\n\\s*\\],/s', $source, $match) !== 1) {
        throw new RuntimeException("Cannot read {$list} declaration");
    }
    preg_match_all("/type:\\s*'([^']+)'/", $match[1], $types);
    return array_values(array_unique($types[1] ?? []));
}

$regions = [
    'header' => declaredTypes($panel, 'headerWidgetTypes'),
    'topbar' => declaredTypes($panel, 'headerWidgetTypes'),
    'sidebar' => declaredTypes($panel, 'sidebarWidgetTypes'),
    'footer' => declaredTypes($panel, 'widgetTypes'),
];
$props = [
    'text' => ['content' => 'A short announcement'],
    'custom_html' => ['content' => '<strong>Open today</strong>'],
    'social_links' => ['title' => 'Follow Us'],
    'contact_info' => ['title' => 'Contact Info', 'address' => '123 Market Street, Manila', 'phone' => '+63 900 000 0000', 'email' => 'hello@example.com'],
    'nav_menu' => ['title' => 'Navigation', 'menu_id' => 0],
    'button' => ['text' => 'Visit', 'url' => '/visit'],
    'opening_hours' => ['title' => 'Opening Hours', 'text' => 'Monday–Friday, 9:00 AM–6:00 PM', 'icon' => 1],
    'recent_posts' => ['title' => 'Latest Posts', 'count' => 3],
    'search_box' => ['placeholder' => 'Search', 'button_label' => 'Go'],
    'categories' => ['title' => 'Categories'],
    'tag_cloud' => ['title' => 'Popular Tags'],
    'archives' => ['title' => 'Archives'],
    'cta_button' => ['text' => 'Learn More', 'url' => '/learn'],
];
$method = new ReflectionMethod(ThemeRegionRenderer::class, 'renderWidgets');
$cases = [];
foreach ($regions as $region => $types) {
    foreach ($types as $type) {
        // topbar is a location within the header region; pass both facts to the production boundary.
        $renderRegion = $region === 'topbar' ? 'header' : $region;
        $location = $region === 'topbar' ? 'topbar' : $region;
        $html = (string) $method->invoke(null, [[
            'id' => "matrix-{$region}-{$type}",
            'type' => $type,
            'props' => $props[$type] ?? [],
        ]], $renderRegion, $location);
        $cases[] = ['region' => $region, 'type' => $type, 'html' => $html];
    }
}

$engine = new TemplateEngine($root . '/templates', sys_get_temp_dir() . '/ark_widget_matrix_' . getmypid(), false);
$common = [
    'theme' => 'ark',
    'site' => ['url' => '/', 'title' => 'ARK', 'tagline' => 'A useful tagline'],
    'navigation' => ['primary' => '<a href="/">Home</a>', 'footer' => ''],
    'entity_context' => ['kind' => 'page'],
    'widgets_html' => '<div class="matrix-footer-content">Footer content</div>',
    'widgets_topbar_html' => '<span>Topbar item</span>',
    'current_year' => '2026',
];
$headerTemplate = (string) file_get_contents($root . '/storage/cms-themes/ark/templates/regions/header.disyl');
$footerTemplate = (string) file_get_contents($root . '/storage/cms-themes/ark/templates/regions/footer.disyl');
$alignment = [
    'header' => $engine->renderString($headerTemplate, $common + ['section_settings' => [
        'header_container_width' => 'full', 'header_inner_width_mode' => 'full',
        'show_topbar' => '1', 'topbar_container_width' => 'full', 'topbar_inner_width_mode' => 'full',
    ]]),
    'footer' => $engine->renderString($footerTemplate, $common + ['section_settings' => [
        'container_width' => 'full', 'widget_container_width' => 'full', 'widget_inner_width_mode' => 'full',
        'show_footer_bar' => '0', 'columns' => '1',
    ]]),
];

echo json_encode([
    'cases' => $cases,
    'alignment' => $alignment,
    'css' => (string) file_get_contents($root . '/storage/cms-themes/ark/style.css'),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
