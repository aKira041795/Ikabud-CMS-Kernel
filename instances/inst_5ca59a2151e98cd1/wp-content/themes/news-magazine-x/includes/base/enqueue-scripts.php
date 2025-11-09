<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/*
** Enqueue Scripts and Styles.
*/
function newsx_enqueue_scripts_and_styles() {
	// Theme Stylesheet
	wp_enqueue_style( 'newsx-main', NEWSX_ASSETS_URL .'/css/main.min.css', [], NEWSX_THEME_VERSION );

    // Dynamic CSS
    wp_add_inline_style( 'newsx-main', newsx_get_dynamic_css() );

	// Dark Mode
	if ( 'dark' === newsx_get_option('dark_switcher_default') && defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {	
		wp_add_inline_style( 'newsx-main', newsx_get_dark_mode_css() );
		
		add_filter( 'body_class', function( $classes ) {
			$classes[] = 'newsx-dark-mode';
			return $classes;
		});
	}

	// Swiper Slider
	newsx_swiper_enqueue();

	// Marquee
	newsx_marquee_enqueue();
  
	// Tipsy Tooltips
	newsx_tipsy_enqueue();

	// Theme Scripts
	wp_enqueue_script( 'newsx-main', NEWSX_ASSETS_URL .'/js/main.min.js', ['jquery'], NEWSX_THEME_VERSION, true );
    
	// Localize Theme Scripts
	wp_localize_script( 'newsx-main', 'NewsxMain', [
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'newsx-main' ),
			'tablet_bp' => newsx_get_tablet_breakpoint(),
			'mobile_bp' => newsx_get_mobile_breakpoint(),
			'dark_mode' => newsx_get_dark_mode_css()
		]
	);

	// Comment reply link
	if ( is_singular() && comments_open() && get_option( 'thread_comments' ) ) {
		wp_enqueue_script( 'comment-reply' );
	}
}

add_action( 'wp_enqueue_scripts', 'newsx_enqueue_scripts_and_styles' );

// Swiper Slider
function newsx_swiper_enqueue() {
	wp_enqueue_style( 'newsx-swiper', NEWSX_ASSETS_URL .'/lib/swiper/swiper.min.css', [], '11.0.3' );
	wp_enqueue_script( 'newsx-swiper', NEWSX_ASSETS_URL .'/lib/swiper/swiper.min.js', ['jquery'], '11.0.3', true );
}

// Marquee
function newsx_marquee_enqueue() {
	wp_enqueue_script( 'newsx-marquee', NEWSX_ASSETS_URL .'/lib/marquee/marquee.min.js', ['jquery'], NEWSX_THEME_VERSION, true );
}

// Tipsy Tooltips
function newsx_tipsy_enqueue() {
	wp_enqueue_script( 'newsx-tipsy', NEWSX_ASSETS_URL .'/lib/tipsy/jquery.tipsy.min.js', ['jquery'], '1.7.1', true );
}

function newsx_gutenberg_editor_styles() {
    // Enqueue the editor styles
    add_editor_style('editor-style.css');
    
    // Alternatively, enqueue your stylesheet manually
    wp_enqueue_style(
        'newsx-editor-styles', 
        NEWSX_ADMIN_URL . '/assets/css/editor-style.css',
        [],
        NEWSX_THEME_VERSION
    );
}
add_action('enqueue_block_editor_assets', 'newsx_gutenberg_editor_styles');
