<?php
/**
 * Template Name: Bloghash Fullwidth
 *
 * 100% wide page template without vertical spacing.
 *
 * @package     Bloghash
 * @author      Peregrine Themes
 * @since       1.0.0
 */

get_header();
do_action( 'bloghash_before_singular_container' );
if ( have_posts() ) :
	while ( have_posts() ) :
		the_post();

		get_template_part( 'template-parts/content/content', 'bloghash-fullwidth' );
	endwhile;
endif;
do_action( 'bloghash_after_singular_container' );
get_footer();
