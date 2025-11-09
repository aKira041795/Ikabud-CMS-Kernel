<?php
/**
 * Zakra function deprecated.
 *
 * @package zakra
 *
 * TODO: @since.
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Deprecating zakra_block_editor_styles function.
 *
 * @since 3.0.0
 * @deprecated
 */
function zakra_block_editor_styles() {

	_deprecated_function( __FUNCTION__, '3.0.0', 'block_editor_styles' );

	block_editor_styles();
}


/**
 * Deprecating zakra_header_main_action function.
 *
 * @since 3.0.0
 * @deprecated
 */
function zakra_header_main_action() {

	_deprecated_function( __FUNCTION__, '3.0.0', 'zakra_header_actions' );

	zakra_header_actions('is_desktop');
}

/**
 * Deprecating zakra_is_page_title_enabled function.
 *
 * @since 3.0.0
 * @deprecated
 */
function zakra_is_page_title_enabled() {

	_deprecated_function( __FUNCTION__, '3.0.0', 'zakra_page_title_position' );

	return zakra_page_title_position();
}

/**
 * Deprecating zakra_main_end function.
 *
 * @since 3.0.0
 * @deprecated
 */
function zakra_main_end() {

	_deprecated_function( __FUNCTION__, '3.0.0', '' );

	?>
	</main>
	<?php
}

/**
 * Renders search icon menu item.
 */

/**
 * Deprecating zakra_header_class function.
 *
 * @since 3.0.0
 * @deprecated
 */
function zakra_header_class( $class = '' ) {

	_deprecated_function( __FUNCTION__, '3.0.0', '' );

	$classes = array();

	$classes = array_map( 'esc_attr', $classes );

	$classes = apply_filters( 'zakra_header_class', $classes, $class );
	$classes = array_unique( $classes );

	echo join( ' ', $classes ); // WPCS: XSS ok.
}

/**
 * Deprecating zakra_header_top_class function.
 *
 * @since 3.0.0
 * @deprecated
 */
if ( ! function_exists( 'zakra_header_top_class' ) ) {
	function zakra_header_top_class( $class = '' ) {

		_deprecated_function( __FUNCTION__, '3.0.0', '' );

		$classes = array();

		$classes = array_map( 'esc_attr', $classes );

		$classes = apply_filters( 'zakra_header_top_class', $classes, $class );
		$classes = array_unique( $classes );

		echo join( ' ', $classes );
	}
}

/**
 * Deprecating zakra_header_button_append function.
 *
 * @since 3.0.0
 * @deprecated
 */
function zakra_header_button_append( $items, $args ) {

	_deprecated_function( __FUNCTION__, '3.0.0', '' );

	$button_text   = get_theme_mod( 'zakra_header_button_text' );
	$button_link   = get_theme_mod( 'zakra_header_button_link' );
	$button_target = get_theme_mod( 'zakra_header_button_target' );
	$button_target = $button_target ? ' target="_blank"' : '';

	if ( 'menu-primary' === $args->theme_location && $button_text ) {

		$items .= '<li class="menu-item tg-header-button-wrap tg-header-button-one">';
		$items .= '<a href="' . esc_url( $button_link ) . '"' . esc_attr( $button_target ) . ' class = "' . zakra_css_class( 'zakra_header_button_class', false ) . '">';
		$items .= $button_text;
		$items .= '</a>';
		$items .= '</li>';

	}

	return $items;
}

/**
 * Deprecating zakra_header_button_append_to_mobile function.
 *
 * @since 3.0.0
 * @deprecated
 */
function zakra_header_button_append_to_mobile( $items, $args ) {

	_deprecated_function( __FUNCTION__, '3.0.0', '' );

	$button_text   = get_theme_mod( 'zakra_header_button_text' );
	$button_link   = get_theme_mod( 'zakra_header_button_link' );
	$button_target = get_theme_mod( 'zakra_header_button_target' );
	$button_target = $button_target ? ' target="_blank"' : '';

	if ( 'menu-mobile' === $args->theme_location && $button_text ) {

		$items .= '<li class="menu-item tg-header-button-wrap tg-header-button-one">';
		$items .= '<a href="' . esc_url( $button_link ) . '"' . esc_attr( $button_target ) . ' class = "' . zakra_css_class( 'zakra_header_button_class', false ) . '">';
		$items .= $button_text;
		$items .= '</a>';
		$items .= '</li>';

	}

	return $items;
}

/**
 * Deprecating zakra_header_top_left_content function.
 *
 * @since 3.0.0
 * @deprecated
 */
function zakra_header_top_left_content() {

	_deprecated_function( __FUNCTION__, '3.0.0', 'zakra_header_top_content( $position )' );
}

/**
 * Deprecating zakra_header_top_right_content function.
 *
 * @since 3.0.0
 * @deprecated
 */
function zakra_header_top_right_content() {

	_deprecated_function( __FUNCTION__, '3.0.0', 'zakra_header_top_content( $position )' );
}

/**
 * Deprecating zakra_mobile_navigation_toggle function.
 *
 * @since 3.0.0
 * @deprecated
 */
function zakra_mobile_navigation_toggle() {

	_deprecated_function( __FUNCTION__, '3.0.0', 'zakra_mobile_navigation' );
}


// Function to method deprecation.

/**
 * Deprecating zakra_setup function.
 *
 * @since 3.0.0
 * @deprecated
 */
function zakra_setup() {

	_deprecated_function( __FUNCTION__, '3.0.0', 'Zakra_After_Setup_Theme::get_instance()->setup_theme()' );

	Zakra_After_Setup_Theme::get_instance()->setup_theme();
}

/**
 * Deprecating zakra_widgets_init function.
 *
 * @since 3.0.0
 * @deprecated
 */
function zakra_widgets_init() {

	_deprecated_function( __FUNCTION__, '3.0.0', 'Zakra_Widgets::get_instance()->get_sidebars_list()' );

	Zakra_Widgets::get_instance()->get_sidebars_list();
}

/**
 * Deprecating zakra_scripts function.
 *
 * @since 3.0.0
 * @deprecated
 */
function zakra_scripts() {

	_deprecated_function( __FUNCTION__, '3.0.0', 'Zakra_Enqueue_Scripts::get_instance()->enqueue_scripts()' );

	Zakra_Enqueue_Scripts::get_instance()->enqueue_scripts();
}

/**
 * Deprecating zakra_get_fonts function.
 *
 * @since 3.0.0
 * @deprecated
 */
function zakra_get_fonts() {


	_deprecated_function( __FUNCTION__, '3.0.0', 'Zakra_Enqueue_Scripts::get_instance()->get_fonts()' );

	Zakra_Enqueue_Scripts::get_instance()->get_fonts();
}
