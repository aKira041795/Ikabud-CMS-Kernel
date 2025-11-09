<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/*
** Enqueue custom scripts for customize panels, sections and controls.
*/
function newsx_enqueue_customizer_scripts() {
    $suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';

    // Extend customizer CSS file.
    wp_enqueue_style(
        'newsx-customizer-styles',
        NEWSX_CUSTOMIZER_URL . '/assets/css/customizer-styles' . $suffix . '.css',
        [],
        NEWSX_THEME_VERSION
    );

    // Extend customizer JS file.
    wp_enqueue_script(
        'newsx-customizer-scripts',
        NEWSX_CUSTOMIZER_URL . '/assets/js/customizer-scripts' . $suffix . '.js',
       [ 'jquery' ],
        NEWSX_THEME_VERSION,
        true
    );

    // Localize Theme Scripts
    wp_localize_script( 'newsx-customizer-scripts', 'NewsxCustomizerSettings', [
        'themeurl' => get_template_directory_uri(),
        'premium_version' => defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ? 'newsx-cstmzr-premium' : 'newsx-cstmzr-fr',
        'widget_area_info_text' => sprintf( 
            /* translators: %1$s: Search term, %2$s: Search field name */
            esc_html__( 'Note: Type %1$s in the %2$s to find all available widgets for the News Magazine X theme.', 'news-magazine-x' ),
            '<strong>"newsx"</strong>',
            '<strong>Block Search field</strong>'
        ),
    ]);
}

// Enqueue scripts.
add_action( 'customize_controls_enqueue_scripts', 'newsx_enqueue_customizer_scripts' );

/*
** Enqueue custom scripts for customize preview, customize partials.
*/
function newsx_enqueue_customizer_preview_scripts() {
    $suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';

    // Extend customizer CSS file.
    wp_enqueue_style(
        'newsx-customizer-preview-styles',
        NEWSX_CUSTOMIZER_URL . '/assets/css/customizer-preview-styles' . $suffix . '.css',
        [],
        NEWSX_THEME_VERSION
    );

    // Extend customizer JS file.
    wp_enqueue_script(
        'newsx-customizer-preview-scripts',
        NEWSX_CUSTOMIZER_URL . '/assets/js/customizer-preview-scripts' . $suffix . '.js',
       [ 'jquery' ],
        NEWSX_THEME_VERSION,
        true
    );
}

// Enqueue scripts.
add_action( 'customize_preview_init', 'newsx_enqueue_customizer_preview_scripts' );