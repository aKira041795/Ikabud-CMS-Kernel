<?php
/**
 * Template part for displaying page content in page.php
 *
 * @link    https://developer.wordpress.org/themes/basics/template-hierarchy/
 *
 * @package zakra
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

$content_orders = get_theme_mod(
	'zakra_page_content_elements',
	array(
		'title',
		'featured_image',
		'content',
	)
);
?>

<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>

	<?php
	foreach ( $content_orders as $key => $content_order ) {

		if ( 'title' === $content_order ) {

			get_template_part( 'template-parts/entry/entry', 'header' );
		}

		elseif ( 'featured_image' === $content_order ) {

			get_template_part( 'template-parts/entry/entry', 'thumbnail' );
		}

		elseif ( 'content' === $content_order ) {

			get_template_part( 'template-parts/entry/entry', 'content' );
		}
	}

	if ( get_edit_post_link() ) :
		?>
		<footer class="zak-entry-footer">
			<?php
			edit_post_link(
				sprintf(
					wp_kses(
					/* translators: %s: Name of current post. Only visible to screen readers */
						__( 'Edit <span class="screen-reader-text">%s</span>', 'zakra' ),
						array(
							'span' => array(
								'class' => array(),
							),
						)
					),
					get_the_title()
				),
				'<span class="edit-link">',
				'</span>'
			);
			?>
		</footer><!-- .zak-entry-footer -->
		<?php
	endif;
	?>
</article><!-- #post-<?php the_ID(); ?> -->
