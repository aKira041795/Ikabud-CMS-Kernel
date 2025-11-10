<?php
/**
 * Blog post layout template file.
 *
 * @package zakra
 *
 * @since 3.0.0
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;
$post_content   = get_theme_mod( 'zakra_blog_excerpt_type', 'excerpt' );

// TODO: refactor this to remove duplicate code in blog layouts available in Zakra Pro.

$content_orders = get_theme_mod(
	'zakra_blog_post_elements', array(
		'featured_image',
		'title',
		'meta',
		'content',
	)
);

foreach ( $content_orders as $key => $content_order ) {

	if ( 'featured_image' === $content_order ) {

		get_template_part( 'template-parts/entry/entry', 'thumbnail' );
	}

	elseif ( 'title' === $content_order ) {

		get_template_part( 'template-parts/entry/entry', 'header' );
	}

	elseif ( 'meta' === $content_order ) {

		get_template_part( 'template-parts/entry/entry', 'meta' );
	}

	elseif ( 'content' === $content_order ) {

		get_template_part( 'template-parts/entry/entry', 'summary' );
	}
}

if ( $post_content === 'excerpt' ) {
	get_template_part( 'template-parts/entry/entry', 'footer' );
}
