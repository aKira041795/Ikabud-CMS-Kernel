<?php
/**
 * Phoenix Customizer Integration
 * 
 * Automatically generates WordPress Customizer controls from manifest.json
 * 
 * @package Phoenix
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Phoenix_Customizer {
    
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
        
        // Hook into customizer
        add_action('customize_register', [$this, 'register_customizer'], 20);
        add_action('wp_head', [$this, 'output_custom_css'], 999);
    }
    
    /**
     * Register customizer sections, settings, and controls
     */
    public function register_customizer($wp_customize) {
        // Register sections from manifest
        $this->register_sections($wp_customize);
        
        // Register component props as settings/controls
        $this->register_component_controls($wp_customize);
        
        // Register widget area visibility controls
        $this->register_widget_controls($wp_customize);
        
        // Register global settings (colors, typography, etc.)
        $this->register_global_settings($wp_customize);
    }
    
    /**
     * Register customizer sections
     */
    private function register_sections($wp_customize) {
        $sections = $this->manifest->get_customizer_sections();
        
        foreach ($sections as $section_id => $section) {
            // Skip if section already exists
            if ($wp_customize->get_section($section_id)) {
                continue;
            }
            
            $wp_customize->add_section("phoenix_{$section_id}", [
                'title' => $section['title'] ?? ucwords(str_replace('_', ' ', $section_id)),
                'priority' => $section['priority'] ?? 100,
                'description' => $section['description'] ?? '',
            ]);
        }
    }
    
    /**
     * Register component props as customizer controls
     */
    private function register_component_controls($wp_customize) {
        $props = $this->manifest->get_customizer_props();
        
        foreach ($props as $setting_id => $prop) {
            $customizer = $prop['customizer'];
            $section = "phoenix_{$customizer['section']}";
            
            // Add setting
            $wp_customize->add_setting("phoenix_{$setting_id}", [
                'default' => $prop['default'] ?? '',
                'sanitize_callback' => $this->get_sanitize_callback($prop['type']),
                'transport' => 'refresh', // Can be 'postMessage' for live preview
            ]);
            
            // Add control
            $control_args = [
                'label' => $customizer['label'] ?? ucwords(str_replace('_', ' ', $prop['prop_id'])),
                'section' => $section,
                'priority' => $customizer['priority'] ?? 10,
                'type' => $this->map_control_type($customizer['control_type'] ?? $prop['type']),
            ];
            
            // Add choices for select/radio
            if (isset($customizer['choices'])) {
                $control_args['choices'] = $customizer['choices'];
            }
            
            // Add input attributes for range/number
            if ($prop['type'] === 'number' || $customizer['control_type'] === 'range') {
                $control_args['input_attrs'] = array_filter([
                    'min' => $prop['min'] ?? null,
                    'max' => $prop['max'] ?? null,
                    'step' => $prop['step'] ?? null,
                ]);
            }
            
            // Use appropriate control class
            $control_class = $this->get_control_class($customizer['control_type'] ?? $prop['type']);
            
            if ($control_class === 'WP_Customize_Control') {
                $wp_customize->add_control("phoenix_{$setting_id}", $control_args);
            } else {
                $wp_customize->add_control(
                    new $control_class($wp_customize, "phoenix_{$setting_id}", $control_args)
                );
            }
        }
    }
    
    /**
     * Register widget area visibility controls
     */
    private function register_widget_controls($wp_customize) {
        $widget_areas = $this->manifest->get_widget_areas();
        
        // Add widget areas section if it doesn't exist
        if (!$wp_customize->get_section('phoenix_widget_areas')) {
            $wp_customize->add_section('phoenix_widget_areas', [
                'title' => __('Widget Areas', 'phoenix'),
                'priority' => 60,
                'description' => __('Control widget area visibility', 'phoenix'),
            ]);
        }
        
        foreach ($widget_areas as $area_id => $area) {
            if (!isset($area['customizer']['visibility_control']) || !$area['customizer']['visibility_control']) {
                continue;
            }
            
            $setting_id = "phoenix_show_widget_{$area_id}";
            
            // Add setting
            $wp_customize->add_setting($setting_id, [
                'default' => true,
                'sanitize_callback' => 'wp_validate_boolean',
                'transport' => 'refresh',
            ]);
            
            // Add control
            $wp_customize->add_control($setting_id, [
                'label' => sprintf(__('Show %s', 'phoenix'), $area['name']),
                'section' => 'phoenix_widget_areas',
                'type' => 'checkbox',
                'priority' => $area['customizer']['priority'] ?? 10,
            ]);
        }
    }
    
    /**
     * Register global settings (colors, typography, etc.)
     */
    private function register_global_settings($wp_customize) {
        $sections = $this->manifest->get_customizer_sections();
        
        foreach ($sections as $section_id => $section) {
            if (!isset($section['settings'])) {
                continue;
            }
            
            foreach ($section['settings'] as $setting_id => $setting) {
                $full_setting_id = "phoenix_{$setting_id}";
                
                // Add setting
                $wp_customize->add_setting($full_setting_id, [
                    'default' => $setting['default'] ?? '',
                    'sanitize_callback' => $this->get_sanitize_callback($setting['type']),
                    'transport' => 'postMessage', // Live preview for global settings
                ]);
                
                // Add control
                $control_args = [
                    'label' => $setting['label'] ?? ucwords(str_replace('_', ' ', $setting_id)),
                    'section' => "phoenix_{$section_id}",
                    'description' => $setting['description'] ?? '',
                    'type' => $this->map_control_type($setting['type']),
                ];
                
                // Add choices for select
                if (isset($setting['choices'])) {
                    $control_args['choices'] = $setting['choices'];
                }
                
                // Add input attributes for range/number
                if (in_array($setting['type'], ['number', 'range'])) {
                    $control_args['input_attrs'] = array_filter([
                        'min' => $setting['min'] ?? null,
                        'max' => $setting['max'] ?? null,
                        'step' => $setting['step'] ?? null,
                    ]);
                }
                
                // Use appropriate control class
                $control_class = $this->get_control_class($setting['type']);
                
                if ($control_class === 'WP_Customize_Control') {
                    $wp_customize->add_control($full_setting_id, $control_args);
                } else {
                    $wp_customize->add_control(
                        new $control_class($wp_customize, $full_setting_id, $control_args)
                    );
                }
            }
        }
    }
    
    /**
     * Map manifest type to WordPress control type
     */
    private function map_control_type($type) {
        $map = [
            'text' => 'text',
            'textarea' => 'textarea',
            'number' => 'number',
            'range' => 'range',
            'color' => 'color',
            'image' => 'image',
            'select' => 'select',
            'radio' => 'radio',
            'checkbox' => 'checkbox',
            'boolean' => 'checkbox',
        ];
        
        return $map[$type] ?? 'text';
    }
    
    /**
     * Get control class for type
     */
    private function get_control_class($type) {
        $map = [
            'color' => 'WP_Customize_Color_Control',
            'image' => 'WP_Customize_Image_Control',
            'upload' => 'WP_Customize_Upload_Control',
        ];
        
        return $map[$type] ?? 'WP_Customize_Control';
    }
    
    /**
     * Get sanitize callback for type
     */
    private function get_sanitize_callback($type) {
        $map = [
            'text' => 'sanitize_text_field',
            'textarea' => 'sanitize_textarea_field',
            'number' => 'absint',
            'range' => 'absint',
            'color' => 'sanitize_hex_color',
            'image' => 'esc_url_raw',
            'select' => 'sanitize_text_field',
            'radio' => 'sanitize_text_field',
            'checkbox' => 'wp_validate_boolean',
            'boolean' => 'wp_validate_boolean',
        ];
        
        return $map[$type] ?? 'sanitize_text_field';
    }
    
    /**
     * Output custom CSS based on customizer settings
     */
    public function output_custom_css() {
        $css = $this->generate_custom_css();
        
        if (!empty($css)) {
            echo "<style id='phoenix-customizer-css'>\n{$css}\n</style>\n";
        }
    }
    
    /**
     * Generate custom CSS from customizer settings
     */
    private function generate_custom_css() {
        $css = [];
        
        // Get color settings
        $primary_color = get_theme_mod('phoenix_primary_color', '#667eea');
        $secondary_color = get_theme_mod('phoenix_secondary_color', '#764ba2');
        $accent_color = get_theme_mod('phoenix_accent_color', '#4facfe');
        $text_color = get_theme_mod('phoenix_text_color', '#2d3748');
        $background_color = get_theme_mod('phoenix_background_color', '#ffffff');
        
        // Override CSS variables
        $css[] = ':root {';
        $css[] = "    --color-primary: {$primary_color};";
        $css[] = "    --color-secondary: {$secondary_color};";
        $css[] = "    --color-accent: {$accent_color};";
        $css[] = "    --color-text: {$text_color};";
        $css[] = "    --color-background: {$background_color};";
        $css[] = "    --gradient-primary: linear-gradient(135deg, {$primary_color} 0%, {$secondary_color} 100%);";
        $css[] = '}';
        
        // Typography settings
        $heading_font = get_theme_mod('phoenix_heading_font', 'Poppins');
        $body_font = get_theme_mod('phoenix_body_font', 'Inter');
        $base_font_size = get_theme_mod('phoenix_base_font_size', 16);
        
        if ($heading_font !== 'Poppins') {
            $css[] = "h1, h2, h3, h4, h5, h6 { font-family: '{$heading_font}', sans-serif; }";
        }
        
        if ($body_font !== 'Inter') {
            $css[] = "body { font-family: '{$body_font}', sans-serif; }";
        }
        
        if ($base_font_size != 16) {
            $css[] = "html { font-size: {$base_font_size}px; }";
        }
        
        // Layout settings
        $container_width = get_theme_mod('phoenix_container_width', '1200px');
        if ($container_width !== '1200px') {
            $css[] = ".container { max-width: {$container_width}; }";
        }
        
        return implode("\n", $css);
    }
    
    /**
     * Get component prop value (from customizer or default)
     */
    public static function get_prop($component_id, $prop_id, $default = null) {
        $manifest = Phoenix_Manifest::get_instance();
        $props = $manifest->get_component_props($component_id);
        
        if (!isset($props[$prop_id])) {
            return $default;
        }
        
        $prop = $props[$prop_id];
        $setting_id = "phoenix_{$component_id}_{$prop_id}";
        
        return get_theme_mod($setting_id, $prop['default'] ?? $default);
    }
    
    /**
     * Check if widget area should be displayed
     */
    public static function should_show_widget_area($area_id) {
        $setting_id = "phoenix_show_widget_{$area_id}";
        return get_theme_mod($setting_id, true);
    }
}

// Initialize
new Phoenix_Customizer();
