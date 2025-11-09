<?php
/**
 * Scroll to top hooks.
 *
 * @package zakra
 *
 * TODO: @since
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/* ------------------------------ Footer > Scroll to Top ------------------------------ */

if ( ! function_exists( 'zakra_scroll_to_top' ) ) :

	/**
	 * Shows scroll to top
	 */
	function zakra_scroll_to_top() {

		// If scroll to top is disabled.
		if ( ! get_theme_mod( 'zakra_enable_scroll_to_top', true ) ) {
			return;
		}

		get_template_part( 'template-parts/footer/scroll', 'to-top' );
	}
endif;

add_action( 'zakra_action_after', 'zakra_scroll_to_top', 20 );
