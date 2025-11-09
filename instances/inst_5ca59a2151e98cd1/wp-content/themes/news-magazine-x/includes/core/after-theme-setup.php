<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

function newsx_theme_setup() {
	// Let WordPress manage the document title for us
	add_theme_support( 'title-tag' );

	// Enable support for Post Thumbnails on posts and pages
	add_theme_support( 'post-thumbnails' );

	// Gutenberg wide images.
    add_theme_support( 'align-wide' );

    // Enable Block styles
    add_theme_support( 'wp-block-styles' );

	// Add theme support for Custom Logo.
	$custom_logo_defaults = array(
		'width'       => 450,
		'height'      => 200,
		'flex-width'  => true,
		'flex-height' => true,
	);
	add_theme_support( 'custom-logo', $custom_logo_defaults );

	// This theme uses wp_nav_menu() in two locations
	register_nav_menus( array(
		'primary'	=> __( 'Primary Menu', 'news-magazine-x' ),
		'secondary'	=> __( 'Secondary Menu', 'news-magazine-x' ),
		'footer' 	=> __( 'Footer Menu', 'news-magazine-x' ),
	) );

	// Automatic feed links
	add_theme_support( 'automatic-feed-links' );

	// Set the default content width.
	$GLOBALS['content_width'] = 960;

	// Switch default core markup for search form, comment form, and comments to output valid HTML5
	add_theme_support( 'html5', array(
		'comment-form',
		'comment-list',
		'gallery',
		'caption',
	) );

	// Gutenberg Embeds
	add_theme_support( 'responsive-embeds' ); 

	// Add Image Sizes
	add_image_size( 'newsx-150x100', 150, 100, true );
	add_image_size( 'newsx-330x220', 330, 220, true );
	add_image_size( 'newsx-420x280', 420, 280, true );
	add_image_size( 'newsx-510x340', 510, 340, true );
	add_image_size( 'newsx-640x480', 640, 480, true );
	add_image_size( 'newsx-670x370', 670, 370, true );
	add_image_size( 'newsx-860x570', 860, 570, true );
	add_image_size( 'newsx-1000x750', 1000, 750, true );
	add_image_size( 'newsx-1200x600', 1200, 600, true );
  
	// Widgets Selective Refresh
	// Works only for WP Widgets and Blocks, disabled for Newsx Widgets
	add_theme_support('customize-selective-refresh-widgets');

	// Post Format support
	if ( class_exists('Newsx_Core') ) {
		add_theme_support( 'post-formats', array( 'gallery', 'video', 'audio' ) );
	}

    // Enable editor styles.
    add_theme_support('editor-styles');

    // Add default color palette support // TODO Disable for now (Custom color for gutenberg paragraph was not working. support case: FMfcgzQZVJxnnmsTWPsWmtMFmdKsTwpk)
    // add_theme_support('editor-color-palette', array(
    //     array(
    //         'name'  => esc_html__('Accent', 'news-magazine-x'),
    //         'slug'  => 'accent',
    //         'color' => newsx_get_option('global_color_accent'),
    //     ),
    //     array(
    //         'name'  => esc_html__('Links', 'news-magazine-x'),
    //         'slug'  => 'links',
    //         'color' => newsx_get_option('global_color_links')['normal'],
    //     ),
    //     array(
    //         'name'  => esc_html__('Headings', 'news-magazine-x'),
    //         'slug'  => 'headings',
    //         'color' => newsx_get_option('global_color_headings'),
    //     ),
    //     array(
    //         'name'  => esc_html__('Text', 'news-magazine-x'),
    //         'slug'  => 'text',
    //         'color' => newsx_get_option('global_color_body_text'),
    //     ),
    //     array( 
    //         'name'  => esc_html__('Meta', 'news-magazine-x'),
    //         'slug'  => 'meta',
    //         'color' => newsx_get_option('global_color_meta'),
    //     ),
    // ));

    // Add support for custom text color.
    add_theme_support('editor-font-colors');
}

add_action( 'after_setup_theme', 'newsx_theme_setup' );
