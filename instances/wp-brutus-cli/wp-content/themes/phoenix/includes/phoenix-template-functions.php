<?php
/**
 * Phoenix Template Functions
 * 
 * Helper functions for use in DiSyL templates
 * 
 * @package Phoenix
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get component prop value
 * 
 * @param string $component_id Component ID
 * @param string $prop_id Prop ID
 * @param mixed $default Default value
 * @return mixed
 */
function phoenix_get_prop($component_id, $prop_id, $default = null) {
    return Phoenix_Component_Bridge::get_prop($component_id, $prop_id, $default);
}

/**
 * Check if widget area should be displayed
 * 
 * @param string $area_id Widget area ID
 * @return bool
 */
function phoenix_should_show_widget($area_id) {
    return Phoenix_Component_Bridge::should_show_widget($area_id);
}

/**
 * Get theme color
 * 
 * @param string $color_name Color name (primary, secondary, accent, text, background)
 * @param string $default Default color
 * @return string
 */
function phoenix_get_color($color_name, $default = '') {
    $color_map = [
        'primary' => get_theme_mod('phoenix_primary_color', '#667eea'),
        'secondary' => get_theme_mod('phoenix_secondary_color', '#764ba2'),
        'accent' => get_theme_mod('phoenix_accent_color', '#4facfe'),
        'text' => get_theme_mod('phoenix_text_color', '#2d3748'),
        'background' => get_theme_mod('phoenix_background_color', '#ffffff'),
    ];
    
    return $color_map[$color_name] ?? $default;
}

/**
 * Get theme font
 * 
 * @param string $font_type Font type (heading, body)
 * @param string $default Default font
 * @return string
 */
function phoenix_get_font($font_type, $default = '') {
    $font_map = [
        'heading' => get_theme_mod('phoenix_heading_font', 'Poppins'),
        'body' => get_theme_mod('phoenix_body_font', 'Inter'),
    ];
    
    return $font_map[$font_type] ?? $default;
}

/**
 * Get hero section title
 * 
 * @return string
 */
function phoenix_get_hero_title() {
    return get_theme_mod('phoenix_hero_title', 'Welcome to Phoenix');
}

/**
 * Get hero section subtitle
 * 
 * @return string
 */
function phoenix_get_hero_subtitle() {
    return get_theme_mod('phoenix_hero_subtitle', 'A beautiful DiSyL-powered WordPress theme');
}

/**
 * Get slider settings
 * 
 * @return array
 */
function phoenix_get_slider_settings() {
    return [
        'autoplay' => phoenix_get_prop('slider', 'autoplay', true),
        'interval' => phoenix_get_prop('slider', 'interval', 5000),
        'transition' => phoenix_get_prop('slider', 'transition', 'fade'),
        'show_arrows' => phoenix_get_prop('slider', 'show_arrows', true),
        'show_dots' => phoenix_get_prop('slider', 'show_dots', true),
    ];
}

/**
 * Get header settings
 * 
 * @return array
 */
function phoenix_get_header_settings() {
    return [
        'logo' => phoenix_get_prop('header', 'logo', null),
        'sticky' => phoenix_get_prop('header', 'sticky', true),
        'show_search' => phoenix_get_prop('header', 'show_search', true),
    ];
}

/**
 * Get footer settings
 * 
 * @return array
 */
function phoenix_get_footer_settings() {
    return [
        'columns' => phoenix_get_prop('footer', 'columns', 4),
        'show_social' => phoenix_get_prop('footer', 'show_social', true),
        'copyright_text' => phoenix_get_prop('footer', 'copyright_text', '© 2025 Phoenix Theme. All rights reserved.'),
    ];
}

/**
 * Get sidebar settings
 * 
 * @return array
 */
function phoenix_get_sidebar_settings() {
    return [
        'position' => phoenix_get_prop('sidebar', 'position', 'right'),
        'width' => phoenix_get_prop('sidebar', 'width', '25%'),
    ];
}

/**
 * Get comments settings
 * 
 * @return array
 */
function phoenix_get_comments_settings() {
    return [
        'show_avatars' => phoenix_get_prop('comments', 'show_avatars', true),
        'nested_depth' => phoenix_get_prop('comments', 'nested_depth', 3),
    ];
}

/**
 * Output slider settings as JSON for JavaScript
 */
function phoenix_output_slider_settings() {
    $settings = phoenix_get_slider_settings();
    echo '<script>window.phoenixSliderSettings = ' . json_encode($settings) . ';</script>';
}

/**
 * Get widget area with visibility check
 * 
 * @param string $sidebar_id Widget area ID
 * @return array
 */
function phoenix_get_widget_area_safe($sidebar_id) {
    // Check visibility setting
    if (!phoenix_should_show_widget($sidebar_id)) {
        return [
            'active' => false,
            'content' => '',
            'visible' => false,
        ];
    }
    
    // Get widget area content
    $widget_data = phoenix_get_widget_area($sidebar_id);
    $widget_data['visible'] = true;
    
    return $widget_data;
}
