<?php
/**
 * Top bar hooks.
 *
 * @package zakra
 *
 * TODO: @since
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/*========================================= Hooks > Header Top ==========================================*/

if ( ! function_exists( 'zakra_header_top' ) ) :

	/**
	 * Header top.
	 * TODO: @since
	 */
	function zakra_header_top() {

		if ( ( ! zakra_is_top_bar_enabled() || ( 'none' === get_theme_mod( 'zakra_top_bar_column_1_content_type', 'text_html' ) && 'none' === get_theme_mod( 'zakra_top_bar_column_2_content_type', 'text_html' ) ) ) ) {

			return;
		}

		get_template_part( 'template-parts/header/top-bar/top', 'bar' );
	}
endif;

add_action( 'zakra_action_header_top', 'zakra_header_top', 10 );


if ( ! function_exists( 'zakra_header_top_content' ) ) :

	/**
	 * Output content for top bar on different position.
	 *
	 * @since 3.0.0
	 */
	function zakra_header_top_content( $position ) {

		$content_type = get_theme_mod( "zakra_top_bar_column_{$position}_content_type", 'text_html' );
		$content_html = get_theme_mod( "zakra_top_bar_column_{$position}_html", '' );
		$content_menu = get_theme_mod( "zakra_top_bar_column_{$position}_menu", 'none' );
		$sidebar_id   = "top-bar-col-{$position}-sidebar";

		switch ( $content_type ) {

			case 'text_html':

				echo do_shortcode( wp_kses_post( $content_html ) );

				break;
			case 'menu':

				if ( 'none' === $content_menu ) {

					return;
				}

				wp_nav_menu(
					array(
						'theme_location' => "menu-header-top-$position",
						'menu'           => $content_menu,
						'menu_id'        => "header-top-{$position}-menu",
						'container'      => '',
						'depth'          => apply_filters( 'zakra_header_top_menu_depth', -1 ),
					)
				);

				break;
			case 'widget':

				if ( is_active_sidebar( $sidebar_id ) ) {

					dynamic_sidebar( $sidebar_id );
				}

				break;
		}
	}
endif;

add_action(
	'zakra_action_header_column_1_content',
	'zakra_header_top_content',
	10,
	1
);

add_action(
	'zakra_action_header_column_2_content',
	'zakra_header_top_content',
	10,
	1
);
