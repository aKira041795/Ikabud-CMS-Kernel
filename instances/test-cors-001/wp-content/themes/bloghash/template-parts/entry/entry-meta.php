<?php
/**
 * Template part for displaying entry meta info.
 *
 * @link https://codex.wordpress.org/Template_Hierarchy
 *
 * @package     Bloghash
 * @author      Peregrine Themes
 * @since       1.0.0
 */

/**
 * Do not allow direct script access.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Only show meta tags for posts.
 */
if ( ! in_array( get_post_type(), (array) apply_filters( 'bloghash_entry_meta_post_type', array( 'post' ) ), true ) ) {
	return;
}

do_action( 'bloghash_before_entry_meta' );

// Get meta items to be displayed.
$bloghash_meta_elements = bloghash_get_entry_meta_elements();

if ( isset( $args['bloghash_meta_callback'] ) ) {
	$bloghash_meta_elements = call_user_func( $args['bloghash_meta_callback'] );
}

if ( ! empty( $bloghash_meta_elements ) ) {

	echo '<div class="entry-meta"><div class="entry-meta-elements">';

	do_action( 'bloghash_before_entry_meta_elements' );

	// Loop through meta items.
	foreach ( $bloghash_meta_elements as $bloghash_meta_item ) {

		// Call a template tag function.
		if ( function_exists( 'bloghash_entry_meta_' . $bloghash_meta_item ) ) {
			call_user_func( 'bloghash_entry_meta_' . $bloghash_meta_item );
		}
	}

	// Add edit post link.
	$bloghash_edit_icon = bloghash()->icons->get_meta_icon( 'edit', bloghash()->icons->get_svg( 'edit-3', array( 'aria-hidden' => 'true' ) ) );

	bloghash_edit_post_link(
		sprintf(
			wp_kses(
				/* translators: %s: Name of current post. Only visible to screen readers */
				$bloghash_edit_icon . __( 'Edit <span class="screen-reader-text">%s</span>', 'bloghash' ),
				bloghash_get_allowed_html_tags()
			),
			get_the_title()
		),
		'<span class="edit-link">',
		'</span>'
	);

	do_action( 'bloghash_after_entry_meta_elements' );

	echo '</div></div>';
}

do_action( 'bloghash_after_entry_meta' );
