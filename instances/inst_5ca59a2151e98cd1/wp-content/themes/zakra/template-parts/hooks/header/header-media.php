<?php
/**
 * Header media hooks.
 *
 * @package zakra
 *
 * TODO: @since
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/* ------------------------------ Header Media ------------------------------ */

if ( ! function_exists( 'zakra_header_media_markup' ) ) :

	/**
	 * Header media tag.
	 */
	function zakra_header_media_markup() {

		the_custom_header_markup();
	}
endif;

add_action( 'zakra_action_after_header', 'zakra_header_media_markup', 20 );
