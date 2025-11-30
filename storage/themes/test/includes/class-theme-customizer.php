<?php
/**
 * Theme Customizer
 * 
 * Registers customizer settings based on manifest.json
 * 
 * @package test
 */

class Test_Customizer {
    
    public function __construct() {
        add_action('customize_register', array($this, 'register'));
    }
    
    public function register($wp_customize) {
        // Colors Section
        $wp_customize->add_section('test_colors', array(
            'title'    => __('Theme Colors', 'test'),
            'priority' => 30,
        ));
        
        // Primary Color
        $wp_customize->add_setting('test_primary_color', array(
            'default'           => '#3b82f6',
            'sanitize_callback' => 'sanitize_hex_color',
            'transport'         => 'postMessage',
        ));
        
        $wp_customize->add_control(new WP_Customize_Color_Control($wp_customize, 'test_primary_color', array(
            'label'   => __('Primary Color', 'test'),
            'section' => 'test_colors',
        )));
        
        // Header Section
        $wp_customize->add_section('test_header', array(
            'title'    => __('Header Settings', 'test'),
            'priority' => 40,
        ));
        
        // Sticky Header
        $wp_customize->add_setting('test_sticky_header', array(
            'default'           => true,
            'sanitize_callback' => 'wp_validate_boolean',
        ));
        
        $wp_customize->add_control('test_sticky_header', array(
            'label'   => __('Enable Sticky Header', 'test'),
            'section' => 'test_header',
            'type'    => 'checkbox',
        ));
        
        // Footer Section
        $wp_customize->add_section('test_footer', array(
            'title'    => __('Footer Settings', 'test'),
            'priority' => 50,
        ));
        
        // Copyright Text
        $wp_customize->add_setting('test_copyright', array(
            'default'           => '© ' . date('Y') . ' Test. All rights reserved.',
            'sanitize_callback' => 'sanitize_text_field',
        ));
        
        $wp_customize->add_control('test_copyright', array(
            'label'   => __('Copyright Text', 'test'),
            'section' => 'test_footer',
            'type'    => 'text',
        ));
    }
}

// Initialize customizer
new Test_Customizer();
