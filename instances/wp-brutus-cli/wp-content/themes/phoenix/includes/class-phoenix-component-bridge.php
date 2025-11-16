<?php
/**
 * Phoenix Component Bridge
 * 
 * Bridges DiSyL components with WordPress Customizer settings
 * Extends DiSyL context with customizer values
 * 
 * @package Phoenix
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Phoenix_Component_Bridge {
    
    /**
     * Manifest instance
     * @var Phoenix_Manifest
     */
    private $manifest;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->manifest = Phoenix_Manifest::get_instance();
        
        // Extend DiSyL context with component props
        add_filter('ikabud_disyl_context', [$this, 'extend_context'], 5);
    }
    
    /**
     * Extend DiSyL context with component props from customizer
     */
    public function extend_context($context) {
        // Add component props
        $context['components'] = $this->get_component_props_context();
        
        // Add theme settings
        $context['theme'] = $this->get_theme_settings_context();
        
        // Add widget visibility
        $context['widget_visibility'] = $this->get_widget_visibility_context();
        
        // Debug: Log context extension
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Phoenix Bridge: Context extended with ' . count($context['components']) . ' components');
            error_log('Phoenix Bridge: Hero title = ' . ($context['theme']['hero']['title'] ?? 'NOT SET'));
        }
        
        return $context;
    }
    
    /**
     * Get component props context
     */
    private function get_component_props_context() {
        $components = [];
        
        foreach ($this->manifest->get_components() as $component_id => $component) {
            $props = [];
            
            if (isset($component['props'])) {
                foreach ($component['props'] as $prop_id => $prop) {
                    $setting_id = "phoenix_{$component_id}_{$prop_id}";
                    $props[$prop_id] = get_theme_mod($setting_id, $prop['default'] ?? null);
                }
            }
            
            $components[$component_id] = $props;
        }
        
        return $components;
    }
    
    /**
     * Get theme settings context
     */
    private function get_theme_settings_context() {
        return [
            'colors' => [
                'primary' => get_theme_mod('phoenix_primary_color', '#667eea'),
                'secondary' => get_theme_mod('phoenix_secondary_color', '#764ba2'),
                'accent' => get_theme_mod('phoenix_accent_color', '#4facfe'),
                'text' => get_theme_mod('phoenix_text_color', '#2d3748'),
                'background' => get_theme_mod('phoenix_background_color', '#ffffff'),
            ],
            'typography' => [
                'heading_font' => get_theme_mod('phoenix_heading_font', 'Poppins'),
                'body_font' => get_theme_mod('phoenix_body_font', 'Inter'),
                'base_font_size' => get_theme_mod('phoenix_base_font_size', 16),
            ],
            'layout' => [
                'container_width' => get_theme_mod('phoenix_container_width', '1200px'),
                'sidebar_position' => get_theme_mod('phoenix_sidebar_position', 'right'),
                'sidebar_width' => get_theme_mod('phoenix_sidebar_width', '25%'),
            ],
            'hero' => [
                'title' => get_theme_mod('phoenix_hero_title', get_theme_mod('phoenix_hero_title', 'Welcome to Phoenix')),
                'subtitle' => get_theme_mod('phoenix_hero_subtitle', get_theme_mod('phoenix_hero_subtitle', 'A beautiful DiSyL-powered WordPress theme')),
            ],
        ];
    }
    
    /**
     * Get widget visibility context
     */
    private function get_widget_visibility_context() {
        $visibility = [];
        
        foreach ($this->manifest->get_widget_areas() as $area_id => $area) {
            $setting_id = "phoenix_show_widget_{$area_id}";
            $visibility[$area_id] = get_theme_mod($setting_id, true);
        }
        
        return $visibility;
    }
    
    /**
     * Get component prop value
     * 
     * Usage in DiSyL: {components.header.logo | esc_url}
     */
    public static function get_prop($component_id, $prop_id, $default = null) {
        return Phoenix_Customizer::get_prop($component_id, $prop_id, $default);
    }
    
    /**
     * Check if widget area should be displayed
     * 
     * Usage in DiSyL: {if condition="widget_visibility.sidebar-1"}
     */
    public static function should_show_widget($area_id) {
        return Phoenix_Customizer::should_show_widget_area($area_id);
    }
}

// Initialize
new Phoenix_Component_Bridge();
