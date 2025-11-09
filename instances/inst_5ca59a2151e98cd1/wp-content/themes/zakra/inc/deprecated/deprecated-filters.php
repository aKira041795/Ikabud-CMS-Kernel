<?php

/**
 * Zakra filter deprecated.
 *
 * @package zakra
 *
 * TODO: @since.
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'zakra_apply_filters_deprecated' ) ) {
	/**
	 * Zakra
	 *
	 * @since 3.0.0
	 * @param string $tag         Name of the filter hook.
	 * @param array  $args        Array of additional function arguments to be passed to apply_filters().
	 * @param string $version     The version of WordPress that deprecated the hook.
	 * @param string $replacement Optional. The hook that should have been used. Default false.
	 * @param string $message     Optional. A message regarding the change. Default null.
	 */
	function zakra_apply_filters_deprecated( $tag, $args, $version, $replacement = false, $message = null ) {

		return apply_filters_deprecated( $tag, $args, $version, $replacement, $message );
	}
}

/**
 * Zakra filter deprecated.
 *
 * @package zakra
 * @since 3.0.0
 */

$filter_list = array(
	'zakra_base_typography_heading_filter'      => 'zakra_heading_typography_filter',
	'zakra_typography_h1_filter'                => 'zakra_h1_typography_filter',
	'zakra_typography_h2_filter'                => 'zakra_h2_typography_filter',
	'zakra_typography_h3_filter'                => 'zakra_h3_typography_filter',
	'zakra_typography_h4_filter'                => 'zakra_h4_typography_filter',
	'zakra_typography_h5_filter'                => 'zakra_h5_typography_filter',
	'zakra_typography_h6_filter'                => 'zakra_h6_typography_filter',
	'zakra_typography_post_page_title_filter'   => 'zakra_post_page_title_typography_filter',
	'zakra_typography_blog_post_title_selector' => 'zakra_blog_post_title_typography_selector',
	'zakra_typography_widget_heading_filter'    => 'zakra_widget_title_typography_filter',
	'zakra_typography_widget_content_filter'    => 'zakra_widget_content_typography_filter',
	'zakra_page_title_enabled'                  => 'zakra_page_title_position',
	'zakra_breadcrumbs_enabled'                 => 'zakra_enable_breadcrumb',
	'zakra_header_top_enabled'                  => 'zakra_enable_top_bar',
	'zakra_footer_widgets_style'                => 'zakra_footer_column_layout_1_style',
);

foreach ( $filter_list as $old_filter => $new_filter ) {

	$functionName = function ( $element ) use ( $new_filter, $old_filter ) {

		return zakra_apply_filters_deprecated( $old_filter, array( $element ), '3.0.0', $new_filter, '' );
	};
	add_filter( $new_filter, $functionName, 10, 1 );
}

/**
 * Deprecating zakra_header_search_icon_data_attrs hook.
 *
 * @since 3.0.0
 */
function zakra_search_icon_deprecated() {

	return _deprecated_hook( 'zakra_search_icon', '3.0.0', false, null );
}
add_filter( 'zakra_search_icon', 'zakra_search_icon_deprecated' );

/**
 * Deprecating zakra_header_search_data_attrs hook.
 *
 * @since 3.0.0
 */
function zakra_header_search_data_attrs_deprecated() {

	return _deprecated_hook( 'zakra_header_search_data_attrs', '3.0.0', false, null );
}
add_filter( 'zakra_header_search_data_attrs', 'zakra_header_search_data_attrs_deprecated' );

/**
 * Deprecating zakra_breadcrumbs_font_size_selector hook.
 *
 * @since 3.0.0
 */
function zakra_breadcrumbs_font_size_selector_deprecated() {

	return _deprecated_hook( 'zakra_breadcrumbs_font_size_selector', '3.0.0', false, null );
}
add_filter( 'zakra_breadcrumbs_font_size_selector', 'zakra_breadcrumbs_font_size_selector_deprecated' );

