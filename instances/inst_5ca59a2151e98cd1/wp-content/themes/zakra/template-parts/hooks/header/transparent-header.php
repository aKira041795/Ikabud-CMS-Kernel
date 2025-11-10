<?php
/**
 * Header hooks.
 *
 * @package zakra
 *
 * TODO: @since
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/* ------------------------------ Transparent Header ------------------------------ */

if ( ! function_exists( 'zakra_transparent_header_start' ) ) :

	/**
	 * Transparent header starts
	 */
	function zakra_transparent_header_start() {

		if ( zakra_is_header_transparent_enabled() ) {
			?>
			<div class="zak-header-transparent-wrapper">
			<?php
		}
	}
endif;

add_action( 'zakra_action_before_header', 'zakra_transparent_header_start', 20 );

if ( ! function_exists( 'zakra_transparent_header_end' ) ) :

	/**
	 * Transparent header ends
	 */
	function zakra_transparent_header_end() {

		if ( zakra_is_header_transparent_enabled() ) {
			?>
			</div> <!-- /.zak-header-transparent-wrapper -->
			<?php
		}
	}
endif;

add_action( 'zakra_action_after_header', 'zakra_transparent_header_end', 10 );
