<?php
/**
 * Theme Customizer
 * 
 * Registers customizer settings based on manifest.json
 * 
 * @package test-theme
 */

class TestTheme_Customizer {
    
    public function __construct() {
        add_action('customize_register', array($this, 'register'));
    }
    
    public function register($wp_customize) {
        // Colors Section
        $wp_customize->add_section('test-theme_colors', array(
            'title'    => __('Theme Colors', 'test-theme'),
            'priority' => 30,
        ));
        
        // Primary Color
        $wp_customize->add_setting('test-theme_primary_color', array(
            'default'           => '#3b82f6',
            'sanitize_callback' => 'sanitize_hex_color',
            'transport'         => 'postMessage',
        ));
        
        $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'test-theme_primary_color', array(
            'label'   => __('Primary Color', 'test-theme'),
            'section' => 'test-theme_colors',
        )));
        
        // Header Section
        $wp_customize->add_section('test-theme_header', array(
            'title'    => __('Header Settings', 'test-theme'),
            'priority' => 40,
        ));
        
        // Sticky Header
        $wp_customize->add_setting('test-theme_sticky_header', array(
            'default'           => true,
            'sanitize_callback' => 'wp_validate_boolean',
        ));
        
        $wp_customize->add_control('test-theme_sticky_header', array(
            'label'   => __('Enable Sticky Header', 'test-theme'),
            'section' => 'test-theme_header',
            'type'    => 'checkbox',
        ));
        
        // Footer Section
        $wp_customize->add_section('test-theme_footer', array(
            'title'    => __('Footer Settings', 'test-theme'),
            'priority' => 50,
        ));
        
        // Copyright Text
        $wp_customize->add_setting('test-theme_copyright', array(
            'default'           => '© ' . date('Y') . ' Test Theme. All rights reserved.',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        
        $wp_customize->add_control('test-theme_copyright', array(
            'label'   => __('Copyright Text', 'test-theme'),
            'section' => 'test-theme_footer',
            'type'    => 'text',
        ));
    }
}

// Initialize customizer
new TestTheme_Customizer();
