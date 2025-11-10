<?php
/**
 * Theme hooks.
 *
 * @package Zakra
 *
 * @since 3.0.0
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Header.
 */
if ( ! function_exists( 'zakra_header' ) ) {

	/**
	 * Header.
	 *
	 * @return void
	 */
	function zakra_header() {

		/**
		 * Hook for header.
		 *
		 * @hooked zakra_header_markup - 10.
		 */
		do_action( 'zakra_header' );
	}
}

if ( ! function_exists( 'zakra_header_block_one' ) ) :
	/**
	 * TODO: Comment.
	 *
	 * @return void
	 * @since  1.5.0
	 *
	 */
	function zakra_header_block_one() {

		do_action( 'zakra_header_block_one' );
	}
endif;

if ( ! function_exists( 'zakra_header_block_two' ) ) :
	/**
	 * TODO: Comment.
	 *
	 * @return void
	 * @since  1.5.0
	 *
	 */
	function zakra_header_block_two() {

		do_action( 'zakra_header_block_two' );
	}
endif;

if ( ! function_exists( 'zakra_header_block_three' ) ) :
	/**
	 * TODO: Comment.
	 *
	 * @return void
	 * @since  1.5.0
	 *
	 */
	function zakra_header_block_three() {

		do_action( 'zakra_header_block_three' );
	}
endif;
