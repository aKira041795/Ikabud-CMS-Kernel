<?php
/**
 * Zakra action deprecated.
 *
 * @package zakra
 *
 * TODO: @since.
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'zakra_do_action_deprecated' ) ) {
	/**
	 * Astra Filter Deprecated
	 *
	 * @param string $tag          Name of the filter hook.
	 * @param array $args          Array of additional function arguments to be passed to apply_filters().
	 * @param string $version      The version of WordPress that deprecated the hook.
	 * @param string $replacement  Optional. The hook that should have been used. Default false.
	 * @param string $message      Optional. A message regarding the change. Default null.
	 *
	 * @since 3.0.0
	 */
	function zakra_do_action_deprecated( $tag, $args, $version, $replacement = false, $message = null ) {

		do_action_deprecated( $tag, $args, $version, $replacement, $message );
	}
}

/**
 * Zakra header top left content action deprecated.
 *
 * @package zakra
 * @since 3.0.0
 */
function zakra_action_header_top_left_content_deprecated() {

	return zakra_do_action_deprecated( 'zakra_action_header_top_left_content', array(), '3.0.0', 'zakra_action_header_column_1_content', '' );
}
add_action( 'zakra_action_header_column_1_content', 'zakra_action_header_top_left_content_deprecated', 10 );

/**
 * Zakra header top right content action deprecated.
 *
 * @package zakra
 * @since 3.0.0
 */
function zakra_action_header_top_right_content_deprecated() {

	return zakra_do_action_deprecated( 'zakra_action_header_top_right_content', array(), '3.0.0', 'zakra_action_header_column_2_content', '' );
}
add_action( 'zakra_action_header_column_2_content', 'zakra_action_header_top_right_content_deprecated', 10 );

/**
 * Zakra zakra_after_page_header hook deprecated.
 *
 * @package zakra
 * @since 3.0.0
 */
function zakra_after_page_header_deprecated() {

	return zakra_do_action_deprecated( 'zakra_after_page_header', array(), '3.0.0', 'zakra_page_header', '' );
}
add_action( 'zakra_page_header', 'zakra_after_page_header_deprecated', 10 );

/**
 * Deprecating zakra_header_nav_toggle hook.
 *
 * @since 3.0.0
 */
function zakra_header_nav_toggle_deprecated() {

	return _deprecated_hook( 'zakra_header_nav_toggle', '3.0.0', false, null );
}
add_filter( 'zakra_header_nav_toggle', 'zakra_header_nav_toggle_deprecated' );
