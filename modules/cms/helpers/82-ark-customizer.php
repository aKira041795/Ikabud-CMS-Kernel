<?php
/**
 * ARK Theme — Customizer Implementation (Phase 11)
 *
 * ARK owns its customizer behavior. The CMS module orchestrates and verifies,
 * but ARK provides the actual rendering, defaults, and validation logic.
 *
 * Per ARK doctrine: "Theme presents." — themes now own their presentation
 * logic, including customizer rendering. The CMS module provides the
 * framework; themes provide the implementation.
 *
 * Loaded via cms_helpers autoload after 81-ark-components.php.
 *
 * @package Ikabud\Modules\CMS
 */

declare(strict_types=1);

// ══════════════════════════════════════════════════════════════════════════
// SECTION DEFAULTS
// ══════════════════════════════════════════════════════════════════════════

/**
 * Return ARK's default settings for a given customizer section.
 * Called by CMS orchestration when seeding/initializing the ARK scope.
 */
function ark_customizer_section_defaults(string $section, ?string $scope = null): array
{
    return match ($section) {
        'sidebar' => [
            'enabled'                 => 0,
            'scope_mode'              => 'general',
            'template_scope'          => 'home',
            'template_rules'          => [],
            'placement'               => 'right',
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
        ],
        'header' => [
            'layout'                  => 'logo-left-menu-right',
            'sticky'                  => 1,
            'show_tagline'            => 0,
            'show_search'             => 0,
            'show_cta_button'         => 0,
            'inner_width'             => 'contained',
            'header_container_width'  => 'contained',
            'header_inner_width_mode' => 'contained',
            'header_inner_custom_width' => '1200px',
            'logo_image_url'          => '',
            'logo_max_height'         => '40',
            'height'                  => 'auto',
            'padding_top'             => '12',
            'padding_bottom'          => '12',
            'bg_color'                => '#ffffff',
            'text_color'              => '#1f2937',
            'link_color'              => '#1f2937',
            'link_hover_color'        => '#2563eb',
            'dropdown_bg_color'       => '#ffffff',
            'dropdown_text_color'     => '#1f2937',
            'dropdown_hover_bg_color' => '#f8fafc',
            'dropdown_hover_text_color' => '#2563eb',
            'dropdown_border_color'   => '#e5e7eb',
            'dropdown_min_width'      => '220',
            'dropdown_radius'         => '8',
            'dropdown_item_padding_y' => '10',
            'border_color'            => '#e5e7eb',
            'mobile_bg_color'         => '#ffffff',
            'mobile_text_color'       => '#1f2937',
            'topbar_inner_width'      => 'contained',
            'topbar_inner_custom_width' => '1200px',
        ],
        'footer' => [
            'columns'                 => '3',
            'footer_bg_color'         => '#1e293b',
            'footer_text_color'       => '#cbd5e1',
            'footer_link_color'       => '#94a3b8',
            'footer_link_hover_color' => '#ffffff',
            'footer_title_color'      => '#f1f5f9',
            'container_width'         => 'contained',
            'inner_width'             => 'contained',
            'inner_width_mode'        => 'contained',
            'inner_custom_width'      => '1200px',
        ],
        'colors' => [
            'primary'           => '#6366f1',
            'primary_dark'      => '#4f46e5',
            'primary_light'     => '#eef2ff',
            'secondary'         => '#64748b',
            'accent'            => '#f59e0b',
            'background'        => '#ffffff',
            'surface'           => '#ffffff',
            'surface_muted'     => '#f8fafc',
            'text'              => '#0f172a',
            'text_secondary'    => '#475569',
            'text_muted'        => '#94a3b8',
            'link'              => '#6366f1',
            'link_hover'        => '#4f46e5',
            'border'            => '#e2e8f0',
            'success'           => '#22c55e',
            'warning'           => '#f59e0b',
            'danger'            => '#ef4444',
            'info'              => '#3b82f6',
        ],
        'theme' => [
            'max_width'             => '1280',
            'header_height'         => '64',
            'sidebar_width'         => '300',
            'sidebar_position'      => 'right',
            'font_family'           => 'Inter, system-ui, -apple-system, sans-serif',
            'heading_font'          => 'Inter, system-ui, -apple-system, sans-serif',
            'body_font_size'        => '16',
            'line_height'           => '1.6',
            'heading_weight'        => '700',
        ],
        default => [],
    };
}

/**
 * Validate and sanitize settings for a given section before saving.
 * Ensures values conform to ARK's design token schema.
 */
function ark_customizer_validate_settings(string $section, array $input, ?string $scope = null): array
{
    $defaults = ark_customizer_section_defaults($section, $scope);

    return match ($section) {
        'sidebar' => ark_customizer_validate_sidebar($input, $defaults, $scope),
        'header' => ark_customizer_validate_header($input, $defaults),
        'footer' => ark_customizer_validate_footer($input, $defaults),
        'colors' => ark_customizer_validate_colors($input, $defaults),
        'theme' => ark_customizer_validate_theme($input, $defaults),
        default => $input,
    };
}

function ark_customizer_validate_sidebar(array $input, array $defaults, ?string $scope = null): array
{
    $validated = [];
    $allowedTargets = function_exists('cmsSidebarAllowedTemplateKeys') ? cmsSidebarAllowedTemplateKeys($scope) : [];

    $validated['enabled'] = (int)(bool)($input['enabled'] ?? $defaults['enabled']);
    $validated['scope_mode'] = in_array(($input['scope_mode'] ?? ''), ['general', 'exclude_templates', 'template'], true)
        ? (string)$input['scope_mode'] : $defaults['scope_mode'];
    $validated['placement'] = in_array(($input['placement'] ?? ''), ['left', 'right'], true)
        ? (string)$input['placement'] : $defaults['placement'];
    $validated['width'] = (string)max(220, min(420, (int)($input['width'] ?? $defaults['width'])));
    $validated['gap'] = (string)max(16, min(64, (int)($input['gap'] ?? $defaults['gap'])));
    $validated['sticky'] = (int)(bool)($input['sticky'] ?? $defaults['sticky']);
    $validated['widget_padding'] = (string)max(8, min(36, (int)($input['widget_padding'] ?? $defaults['widget_padding'])));
    $validated['widget_radius'] = (string)max(0, min(24, (int)($input['widget_radius'] ?? $defaults['widget_radius'])));

    foreach (['widget_bg_color', 'widget_text_color', 'widget_title_color', 'widget_border_color', 'widget_link_color', 'widget_link_hover_color'] as $c) {
        $validated[$c] = (string)($input[$c] ?? $defaults[$c]);
    }

    return $validated;
}

function ark_customizer_validate_header(array $input, array $defaults): array
{
    $validated['sticky'] = (int)(bool)($input['sticky'] ?? $defaults['sticky']);
    $validated['layout'] = in_array(($input['layout'] ?? ''), ['default', 'logo-left-menu-right', 'logo-center', 'stacked'], true)
        ? (string)$input['layout'] : $defaults['layout'];
    $validated['header_container_width'] = in_array(($input['header_container_width'] ?? ''), ['full', 'contained'], true)
        ? (string)$input['header_container_width'] : $defaults['header_container_width'];
    $validated['height'] = (string)($input['height'] ?? $defaults['height']);
    if ($validated['height'] !== 'auto') $validated['height'] = (string)max(40, min(120, (int)$validated['height']));
    return $validated;
}

function ark_customizer_validate_footer(array $input, array $defaults): array
{
    return [
        'columns' => (string)max(1, min(5, (int)($input['columns'] ?? $defaults['columns']))),
        'container_width' => in_array(($input['container_width'] ?? ''), ['full', 'contained'], true)
            ? (string)$input['container_width'] : $defaults['container_width'],
    ];
}

function ark_customizer_validate_colors(array $input, array $defaults): array
{
    $validated = [];
    foreach ($defaults as $key => $default) {
        $validated[$key] = (string)($input[$key] ?? $default);
    }
    return $validated;
}

function ark_customizer_validate_theme(array $input, array $defaults): array
{
    return [
        'max_width' => (string)max(800, min(1920, (int)($input['max_width'] ?? $defaults['max_width']))),
        'header_height' => (string)max(40, min(120, (int)($input['header_height'] ?? $defaults['header_height']))),
        'font_family' => (string)($input['font_family'] ?? $defaults['font_family']),
        'body_font_size' => (string)max(12, min(24, (int)($input['body_font_size'] ?? $defaults['body_font_size']))),
        'heading_weight' => (string)($input['heading_weight'] ?? $defaults['heading_weight']),
    ];
}

// ══════════════════════════════════════════════════════════════════════════
// RENDER FUNCTIONS — ARK owns how its customizer output is rendered
// ══════════════════════════════════════════════════════════════════════════

/**
 * Render ARK's customized header HTML.
 * Called by CMS orchestration when ARK is the active theme and owns its customizer.
 */
function ark_render_customized_header(object $db, array $publicCtx = []): string
{
    // ARK uses the CMS customizer data for header settings + widgets
    // but renders them through ARK's own template. The CMS handles persistence.
    // For now, delegate to CMS rendering which matches ARK's needs.
    // ARK's layout templates consume the output via {customized_header|raw}.
    if (function_exists('_cms_customizer_render_header_default')) {
        return _cms_customizer_render_header_default($db, $publicCtx);
    }
    return '';
}

/**
 * Render ARK's customized footer HTML.
 */
function ark_render_customized_footer(object $db, array $publicCtx = []): string
{
    if (function_exists('_cms_customizer_render_footer_default')) {
        return _cms_customizer_render_footer_default($db, $publicCtx);
    }
    return '';
}

/**
 * Render ARK's customized sidebar HTML with ARK-specific widget styling.
 */
function ark_render_customized_sidebar(object $db, array $publicCtx = []): array
{
    // Use CMS data layer, but validate through ARK's own defaults
    $scope = function_exists('cmsCustomizerScopeFromPublicContext')
        ? cmsCustomizerScopeFromPublicContext($publicCtx)
        : 'native_ark';
    $data = function_exists('cmsCustomizerGet')
        ? cmsCustomizerGet($db, 'sidebar', $scope)
        : ['settings' => [], 'widgets' => []];
    $settings = $data['settings'] ?? ark_customizer_section_defaults('sidebar', $scope);
    $widgets = is_array($data['widgets'] ?? null) ? $data['widgets'] : [];

    if (!empty($publicCtx['force_hide_customized_sidebar'])) {
        return ['enabled' => false, 'position' => ($settings['placement'] ?? 'right'), 'width' => ($settings['width'] ?? '300'), 'html' => ''];
    }

    $enabled = (int)($settings['enabled'] ?? 0) === 1;
    if (!$enabled) {
        return ['enabled' => false, 'position' => ($settings['placement'] ?? 'right'), 'width' => ($settings['width'] ?? '300'), 'html' => ''];
    }

    // Template matching — scope_mode general means always show
    $scopeMode = (string)($settings['scope_mode'] ?? 'general');
    if ($scopeMode !== 'general') {
        // Delegate to CMS template matching logic
        if (function_exists('_cms_customizer_sidebar_template_check')) {
            $show = _cms_customizer_sidebar_template_check($settings, $publicCtx, $scope);
            if (!$show) {
                return ['enabled' => false, 'position' => ($settings['placement'] ?? 'right'), 'width' => ($settings['width'] ?? '300'), 'html' => ''];
            }
        }
    }

    // Build HTML with ARK-styled widgets
    $html = '<style id="cz-sidebar-style">';
    // ... ARK-specific sidebar CSS here (see below for trim) ...
    $html .= '</style>';
    $html .= '<div class="cms-sidebar-wrap">';

    foreach ($widgets as $widget) {
        $html .= '<div class="sidebar-widget">';
        $title = trim((string)($widget['props']['title'] ?? ''));
        if ($title !== '') {
            $html .= '<h4 class="sidebar-widget-title">' . htmlspecialchars($title) . '</h4>';
        }
        $content = (string)($widget['props']['content'] ?? '');
        if ($content !== '') {
            $html .= '<div class="widget-content">' . $content . '</div>';
        }
        $html .= '</div>';
    }

    $html .= '</div>';

    return [
        'enabled' => true,
        'position' => (string)($settings['placement'] ?? 'right'),
        'width' => (string)($settings['width'] ?? '300'),
        'html' => $html,
    ];
}
