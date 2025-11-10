<?php
/**
 * Header hooks.
 *
 * @package zakra
 *
 * @since 3.0.0
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/* ------------------------------ Header ------------------------------ */

if ( ! function_exists( 'zakra_doctype' ) ) :

	/**
	 * Header doctype
	 */
	function zakra_doctype() {
		?><!doctype html>
		<html <?php language_attributes(); ?>>
		<?php
	}
endif;

add_action( 'zakra_action_doctype', 'zakra_doctype', 10 );


if ( ! function_exists( 'zakra_head' ) ) :

	/**
	 * HTML head
	 */
	function zakra_head() {
		?>
		<meta charset="<?php bloginfo( 'charset' ); ?>">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<link rel="profile" href="http://gmpg.org/xfn/11">
		<?php
	}
endif;

add_action( 'zakra_action_head', 'zakra_head', 10 );


if ( ! function_exists( 'zakra_page_start' ) ) :

	/**
	 * Page starts
	 */
	function zakra_page_start() {
		?>
		<div id="page" class="zak-site">
		<?php
	}
endif;

add_action( 'zakra_action_before', 'zakra_page_start', 10 );


if ( ! function_exists( 'zakra_skip_content_link' ) ) :

	/**
	 * Skip to content
	 */
	function zakra_skip_content_link() {
		?>
		<a class="skip-link screen-reader-text" href="#zak-content"><?php esc_html_e( 'Skip to content', 'zakra' ); ?></a>
		<?php
	}
endif;

add_action( 'zakra_action_before', 'zakra_skip_content_link', 15 );


if ( ! function_exists( 'zakra_header_markup' ) ) {

	/**
	 * Adds MagazineX header markup.
	 *
	 * @return void
	 */
	function zakra_header_markup() {
		/**
		 * Hook - zakra_action_before_header
		 *
		 * @hooked zakra_header_start - 10
		 * @hooked zakra_transparent_header_start - 20
		 */
		do_action( 'zakra_action_before_header' );
		?>

			<?php
			/**
			 * Hook - zakra_before_header_top
			 *
			 * @hooked Zakra_Pro->sticky_header_start()
			 */
			do_action( 'zakra_before_header_top' );

				/**
				 * Hook - zakra_action_header_top
				 *
				 * @hooked zakra_header_top - 10
				 */
				do_action( 'zakra_action_header_top' );

			/**
			 * Hook - zakra_after_header_top
			 */
			do_action( 'zakra_after_header_top' );
			?>

			<?php
			/**
			 * Hook - zakra_action_before_header_main
			 *
			 * @hooked zakra_before_header_main - 10
			 */
			do_action( 'zakra_action_before_header_main' );

				/**
				 * Hook - zakra_action_header_main
				 *
				 * @hooked zakra_header_main() - 10
				 */
				do_action( 'zakra_action_header_main' );

			/**
			 * Hook - zakra_action_after_header_main
			 *
			 * @hooked zakra_header - 10
			 */
			do_action( 'zakra_action_after_header_main' );
			?>

		<?php
		/**
		 * Hook - zakra_action_after_header
		 *
		 * @hooked zakra_transparent_header_end - 10
		 * @hooked zakra_header_end - 15
		 * @hooked zakra_header_media_markup - 20
		 */
		do_action( 'zakra_action_after_header' );

		/**
		 * Hook for page header markup.
		 *
		 * @hooked zakra_page_header - 10.
		 */
		do_action( 'zakra_page_header' );
	}

	add_action( 'zakra_header', 'zakra_header_markup' );
}


if ( ! function_exists( 'zakra_header_start' ) ) :

	/**
	 * Header starts
	 */
	function zakra_header_start() {
		?>
		<header id="zak-masthead" class="<?php zakra_css_class( 'zakra_header_class' ); ?>">
		<?php
	}

	add_action( 'zakra_action_before_header', 'zakra_header_start', 15 );
endif;


if ( ! function_exists( 'zakra_header_end' ) ) :

	/**
	 * Header ends.
	 */
	function zakra_header_end() {
		?>
		</header><!-- #zak-masthead -->
		<?php
	}

	add_action( 'zakra_action_after_header', 'zakra_header_end', 15 );
endif;
