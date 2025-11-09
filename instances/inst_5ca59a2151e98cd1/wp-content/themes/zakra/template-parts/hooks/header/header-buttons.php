<?php
/**
 * Header actions hooks.
 *
 * @package zakra
 *
 * @since 3.0.0
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;


if ( ! function_exists( 'zakra_header_buttons' ) ) :

	/**
	 * Render HTML for header buttons.
	 *
	 * @return void
	 *
	 * @since 3.0.0
	 *
	 */
	function zakra_header_buttons( $is_desktop ) {

		$args = array( 'is_desktop' => $is_desktop );

		get_template_part( 'template-parts/header/header-buttons/header', 'buttons', $args );
	}
endif;

add_action( 'zakra_header_buttons', 'zakra_header_buttons', 10, 1 );
