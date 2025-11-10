<?php
/**
 * Footer widgets area hooks.
 *
 * @package zakra
 *
 * TODO: @since
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/* ------------------------------ Footer > Footer Widgets ------------------------------ */

if ( ! function_exists( 'zakra_footer_widgets' ) ) :

	/**
	 * Footer widgets.
	 */
	function zakra_footer_widgets() {

		if ( ! zakra_is_footer_widgets_enabled() ||
			 ! is_active_sidebar( 'footer-sidebar-1' ) &&
			 ! is_active_sidebar( 'footer-sidebar-2' ) &&
			 ! is_active_sidebar( 'footer-sidebar-3' ) &&
			 ! is_active_sidebar( 'footer-sidebar-4' ) ) {

			return;
		}

		get_template_part( 'template-parts/footer/footer', 'widgets' );
	}
endif;

add_action( 'zakra_action_footer_widgets', 'zakra_footer_widgets' );
