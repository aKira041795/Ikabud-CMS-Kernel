<?php
/**
 * Blog hooks.
 *
 * @package zakra
 *
 * TODO: @since
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/* ------------------------------ Blog ------------------------------ */

if ( ! function_exists( 'zakra_posts_navigation' ) ) :

	/**
	 * Archive navigation.
	 */
	function zakra_posts_navigation() {

		the_posts_navigation();
	}
endif;

add_action( 'zakra_after_posts_the_loop', 'zakra_posts_navigation' );


if ( ! function_exists( 'zakra_post_navigation' ) ) :

	/**
	 * Archive navigation.
	 */
	function zakra_post_navigation() {

		the_post_navigation();
	}
endif;

add_action( 'zakra_after_single_post_content', 'zakra_post_navigation' );


if ( ! function_exists( 'zakra_entry_content' ) ) :

	/**
	 * Blog layout 1.
	 */
	function zakra_entry_content() {

		get_template_part( 'template-parts/blog/blog', 'layout-1' );
	}
endif;

add_action( 'zakra_action_entry_content', 'zakra_entry_content', 10 );
