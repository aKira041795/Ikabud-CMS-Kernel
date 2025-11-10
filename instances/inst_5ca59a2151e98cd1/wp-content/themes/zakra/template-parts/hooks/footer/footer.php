<?php
/**
 * Footer hooks.
 *
 * @package zakra
 *
 * TODO: @since
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/* ------------------------------ Footer ------------------------------ */

if ( ! function_exists( 'zakra_footer_start' ) ) :

	/**
	 * Footer starts.
	 */
	function zakra_footer_start() {
		?>
		<footer id="zak-footer" class="zak-footer <?php zakra_footer_class(); ?>">
		<?php
	}

	add_action( 'zakra_action_before_footer', 'zakra_footer_start' );
endif;


if ( ! function_exists( 'zakra_footer_end' ) ) :

	/**
	 * Footer ends.
	 */
	function zakra_footer_end() {
		?>
		</footer><!-- #zak-footer -->
		<?php
	}

	add_action( 'zakra_action_after_footer', 'zakra_footer_end' );
endif;

if ( ! function_exists( 'zakra_page_end' ) ) :

	/**
	 * Page ends.
	 */
	function zakra_page_end() {
		?>
		</div><!-- #page -->
		<?php
	}
endif;

add_action( 'zakra_action_after', 'zakra_page_end' );
