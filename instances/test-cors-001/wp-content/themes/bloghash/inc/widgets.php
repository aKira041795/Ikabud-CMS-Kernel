<?php
/**
 * Widget customization and register sidebar widget areas.
 *
 * @package BlogHash
 * @author  Peregrine Themes
 * @since   1.0.0
 */

/**
 * Do not allow direct script access.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'bloghash_widgets_init' ) ) :
	/**
	 * Register widget area.
	 *
	 * @link https://developer.wordpress.org/themes/functionality/sidebars/#registering-a-sidebar
	 * @since 1.0.0
	 */
	function bloghash_widgets_init() {

		// Default Sidebar.
		register_sidebar(
			array(
				'name'          => esc_html__( 'Default Sidebar', 'bloghash' ),
				'id'            => 'sidebar-1',
				'description'   => esc_html__( 'Widgets in this area are displayed in the left or right sidebar area based on your Default Sidebar Position settings.', 'bloghash' ),
				'before_widget' => '<div id="%1$s" class="bloghash-sidebar-widget bloghash-widget bloghash-entry widget %2$s">',
				'after_widget'  => '</div>',
				'before_title'  => '<div class="h4 widget-title">',
				'after_title'   => '</div>',
			)
		);

		// Footer 1.
		register_sidebar(
			array(
				'name'          => esc_html__( 'Footer 1', 'bloghash' ),
				'id'            => 'bloghash-footer-1',
				'description'   => esc_html__( 'Widgets in this area are displayed in the first footer column.', 'bloghash' ),
				'before_widget' => '<div id="%1$s" class="bloghash-footer-widget bloghash-widget bloghash-entry widget %2$s">',
				'after_widget'  => '</div>',
				'before_title'  => '<div class="h4 widget-title">',
				'after_title'   => '</div>',
			)
		);

		// Footer 2.
		register_sidebar(
			array(
				'name'          => esc_html__( 'Footer 2', 'bloghash' ),
				'id'            => 'bloghash-footer-2',
				'description'   => esc_html__( 'Widgets in this area are displayed in the second footer column.', 'bloghash' ),
				'before_widget' => '<div id="%1$s" class="bloghash-footer-widget bloghash-widget bloghash-entry widget %2$s">',
				'after_widget'  => '</div>',
				'before_title'  => '<div class="h4 widget-title">',
				'after_title'   => '</div>',
			)
		);

		// Footer 3.
		register_sidebar(
			array(
				'name'          => esc_html__( 'Footer 3', 'bloghash' ),
				'id'            => 'bloghash-footer-3',
				'description'   => esc_html__( 'Widgets in this area are displayed in the third footer column.', 'bloghash' ),
				'before_widget' => '<div id="%1$s" class="bloghash-footer-widget bloghash-widget bloghash-entry widget %2$s">',
				'after_widget'  => '</div>',
				'before_title'  => '<div class="h4 widget-title">',
				'after_title'   => '</div>',
			)
		);

		// Footer 4.
		register_sidebar(
			array(
				'name'          => esc_html__( 'Footer 4', 'bloghash' ),
				'id'            => 'bloghash-footer-4',
				'description'   => esc_html__( 'Widgets in this area are displayed in the fourth footer column.', 'bloghash' ),
				'before_widget' => '<div id="%1$s" class="bloghash-footer-widget bloghash-widget bloghash-entry widget %2$s clr">',
				'after_widget'  => '</div>',
				'before_title'  => '<div class="h4 widget-title">',
				'after_title'   => '</div>',
			)
		);
	}
endif;
add_action( 'widgets_init', 'bloghash_widgets_init' );
