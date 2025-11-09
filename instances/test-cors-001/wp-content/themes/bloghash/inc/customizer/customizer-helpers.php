<?php
/**
 * Bloghash Customizer helper functions.
 *
 * @package     Bloghash
 * @author      Peregrine Themes
 * @since       1.0.0
 */

/**
 * Do not allow direct script access.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns array of available widgets.
 *
 * @since 1.0.0
 * @return array, $widgets array of available widgets.
 */
function bloghash_get_customizer_widgets() {

	$widgets = array(
		'text'           => 'Bloghash_Customizer_Widget_Text',
		'advertisements' => 'Bloghash_Customizer_Widget_Advertisements',
		'nav'            => 'Bloghash_Customizer_Widget_Nav',
		'socials'        => 'Bloghash_Customizer_Widget_Socials',
		'search'         => 'Bloghash_Customizer_Widget_Search',
		'darkmode'       => 'Bloghash_Customizer_Widget_Darkmode',
		'button'         => 'Bloghash_Customizer_Widget_Button',
	);

	return apply_filters( 'bloghash_customizer_widgets', $widgets );
}

/**
 * Get choices for "Hide on" customizer options.
 *
 * @since  1.0.0
 * @return array
 */
function bloghash_get_display_choices() {

	// Default options.
	$return = array(
		'home'       => array(
			'title' => esc_html__( 'Home Page', 'bloghash' ),
		),
		'posts_page' => array(
			'title' => esc_html__( 'Blog / Posts Page', 'bloghash' ),
		),
		'search'     => array(
			'title' => esc_html__( 'Search', 'bloghash' ),
		),
		'archive'    => array(
			'title' => esc_html__( 'Archive', 'bloghash' ),
			'desc'  => esc_html__( 'Dynamic pages such as categories, tags, custom taxonomies...', 'bloghash' ),
		),
		'post'       => array(
			'title' => esc_html__( 'Single Post', 'bloghash' ),
		),
		'page'       => array(
			'title' => esc_html__( 'Single Page', 'bloghash' ),
		),
	);

	// Get additionally registered post types.
	$post_types = get_post_types(
		array(
			'public'   => true,
			'_builtin' => false,
		),
		'objects'
	);

	if ( is_array( $post_types ) && ! empty( $post_types ) ) {
		foreach ( $post_types as $slug => $post_type ) {
			$return[ $slug ] = array(
				'title' => $post_type->label,
			);
		}
	}

	return apply_filters( 'bloghash_display_choices', $return );
}

/**
 * Get device choices for "Display on" customizer options.
 *
 * @since  1.0.0
 * @return array
 */
function bloghash_get_device_choices() {

	// Default options.
	$return = array(
		'desktop' => array(
			'title' => esc_html__( 'Hide On Desktop', 'bloghash' ),
		),
		'tablet'  => array(
			'title' => esc_html__( 'Hide On Tablet', 'bloghash' ),
		),
		'mobile'  => array(
			'title' => esc_html__( 'Hide On Mobile', 'bloghash' ),
		),
	);

	return apply_filters( 'bloghash_device_choices', $return );
}
