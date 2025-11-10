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


if ( ! function_exists( 'zakra_header_actions' ) ) :

	/**
	 * Render HTML for header actions.
	 *
	 * @return
	 *
	 * @since 3.0.0
	 *
	 */
	function zakra_header_actions( $is_desktop ) {

		$args = array( 'is_desktop' => $is_desktop );

		get_template_part( 'template-parts/header/header-actions/header', 'actions', $args );
	}
endif;

add_action( 'zakra_header_actions', 'zakra_header_actions', 10, 1 );
