<?php
/**
 * Template part for displaying posts
 *
 * @link    https://developer.wordpress.org/themes/basics/template-hierarchy/
 *
 * @package zakra
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

$content_orders = get_theme_mod(
	'zakra_single_post_elements', array(
		'featured_image',
		'title',
		'meta',
		'content',
	)
);

$meta_orders = get_theme_mod(
	'zakra_single_meta_elements', array(
		'author',
		'date',
		'categories',
		'tags',
		'comments',
	)
);

$meta_style = get_theme_mod( 'zakra_post_meta_style', 'style-1' );
$meta_style = 'zak-' . $meta_style;
?>

<article id="post-<?php the_ID(); ?>" <?php post_class( $meta_style ); ?>>
	<?php do_action( 'zakra_before_single_post' ); ?>

	<?php
	foreach ( $content_orders as $key => $content_order ) {

		if ( 'featured_image' === $content_order ) {

			get_template_part( 'template-parts/entry/entry', 'thumbnail' );
		}

		elseif ( 'title' === $content_order ) {

			get_template_part( 'template-parts/entry/entry', 'header' );
		}

		elseif ( 'meta' === $content_order && 'post' === get_post_type() ) {

			get_template_part( 'template-parts/entry/entry', 'meta' );
		}

		elseif ( 'content' === $content_order ) {

			get_template_part( 'template-parts/entry/entry', 'content' );
		}
	}
	?>

	<?php do_action( 'zakra_after_single_post' ); ?>
</article><!-- #post-<?php the_ID(); ?> -->

