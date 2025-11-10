<?php
/**
 * Footer bar hooks.
 *
 * @package zakra
 *
 * TODO: @since
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/* ------------------------------ Footer > Footer Widgets ------------------------------ */

if ( ! function_exists( 'zakra_footer_bottom_bar' ) ) :

	/**
	 * Footer bar
	 */
	function zakra_footer_bottom_bar() {

		if ( ! zakra_is_footer_bar_enabled() ) {

			return;
		}

		get_template_part( 'template-parts/footer/footer', 'bar' );
	}
endif;

add_action( 'zakra_action_footer_bottom_bar', 'zakra_footer_bottom_bar' );


if ( ! function_exists( 'zakra_footer_bottom_bar_one' ) ) :
	/**
	 * Footer bar section one.
	 */
	function zakra_footer_bottom_bar_one() {

		$footer_bar_one = get_theme_mod( 'zakra_footer_bar_column_1_content_type', 'text_html' );

		switch ( $footer_bar_one ) :

			case 'none':
				break;

			case 'text_html':
				echo do_shortcode( wp_kses_post( zakra_footer_copyright( '1' ) ) );

				break;

			case 'menu':
				$menu = get_theme_mod( 'zakra_footer_bar_column_1_menu', 'none' );

				if ( 'none' === $menu ) {
					return;
				}

				wp_nav_menu(
					array(
						'theme_location' => '',
						'menu'           => $menu,
						'menu_id'        => 'footer-bar-one-menu',
						'container'      => '',
						'depth'          => -1,
						'fallback_cb'    => false,
					)
				);

				break;

			case 'widget':
				if ( is_active_sidebar( 'footer-bar-col-1-sidebar' ) ) {
					dynamic_sidebar( 'footer-bar-col-1-sidebar' );
				}

				break;

			default:
				echo wp_kses_post( zakra_footer_copyright( '1' ) );

		endswitch;

	}
endif;

add_action( 'zakra_action_footer_bottom_bar_one', 'zakra_footer_bottom_bar_one' );


if ( ! function_exists( 'zakra_footer_bottom_bar_two' ) ) :

	/**
	 * Footer bar section two.
	 */
	function zakra_footer_bottom_bar_two() {

		$footer_bar_two = get_theme_mod( 'zakra_footer_bar_column_2_content_type', 'none' );

		switch ( $footer_bar_two ) :

			case 'text_html':
				echo do_shortcode( wp_kses_post( zakra_footer_copyright( '2' ) ) );

				break;

			case 'menu':
				$menu = get_theme_mod( 'zakra_footer_bar_column_2_menu', 'none' );

				if ( 'none' === $menu ) {
					return;
				}

				wp_nav_menu(
					array(
						'theme_location' => '',
						'menu'           => $menu,
						'menu_id'        => 'footer-bar-two-menu',
						'container'      => '',
						'depth'          => -1,
						'fallback_cb'    => false,
					)
				);
				break;

			case 'widget':
				if ( is_active_sidebar( 'footer-bar-col-2-sidebar' ) ) {
					dynamic_sidebar( 'footer-bar-col-2-sidebar' );
				}

				break;

			default:
				do_action( 'zakra_footer_bar_section_two_option_case', $footer_bar_two );

		endswitch;

	}
endif;

add_action( 'zakra_action_footer_bottom_bar_two', 'zakra_footer_bottom_bar_two' );
