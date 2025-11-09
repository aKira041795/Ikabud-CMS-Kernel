<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/*
** Enqueue custom scripts for Widgets admin area and customizer
*/
function newsx_enqueue_widget_scripts( $hook_suffix ) {
    $suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';

    // Widgets admin area
    if ( 'widgets.php' === $hook_suffix ) {
        // Color Picker
        wp_enqueue_script( 'wp-color-picker' );
        wp_enqueue_style( 'wp-color-picker' );

        // Select2 CSS
        wp_enqueue_style(
            'newsx-select2',
            NEWSX_ASSETS_URL . '/lib/select2/select2' . $suffix . '.css',
            [],
            '4.1.0'
        );

        // Select2 JS
        wp_enqueue_script(
            'newsx-select2',
            NEWSX_ASSETS_URL . '/lib/select2/select2' . $suffix . '.js',
            [ 'jquery' ],
            '4.1.0',
            true
        );
    }

    // Widgets admin area and customizer
    if ( 'widgets.php' === $hook_suffix || is_customize_preview() ) {

        // Extend widgets CSS file.
        wp_enqueue_style(
            'newsx-widget-styles',
            NEWSX_WIDGETS_URL . '/assets/css/widget-styles' . $suffix . '.css',
            [],
            NEWSX_THEME_VERSION
        );

        // Extend widgets JS file.
        wp_enqueue_script(
            'newsx-widget-scripts',
            NEWSX_WIDGETS_URL . '/assets/js/widget-scripts' . $suffix . '.js',
            [ 'jquery' ],
            NEWSX_THEME_VERSION,
            true
        );
    }
}

// Enqueue scripts.
add_action( 'admin_enqueue_scripts', 'newsx_enqueue_widget_scripts' );
add_action( 'customize_controls_enqueue_scripts', 'newsx_enqueue_widget_scripts' );