<?php
/**
 * Template part for entry summary.
 *
 * @link    https://developer.wordpress.org/themes/basics/template-hierarchy/
 *
 * @package Zakra
 * TODO: @since.
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;
$post_content   = get_theme_mod( 'zakra_blog_excerpt_type', 'excerpt' );
?>

<div class="zak-entry-summary">
		<?php
		if ( 'excerpt' === $post_content ) {
			the_excerpt();
		} elseif ( 'content' === $post_content ) {
			the_content(
				sprintf(
					wp_kses(
					/* translators: %s: Name of current post. Only visible to screen readers */
						__( 'Continue reading<span class="screen-reader-text"> "%s"</span>', 'zakra' ),
						array(
							'span' => array(
								'class' => array(),
							),
						)
					),
					get_the_title()
				)
			);
		}

		wp_link_pages(
			array(
				'before' => '<div class="page-links">' . esc_html__( 'Pages:', 'zakra' ),
				'after'  => '</div>',
			)
		);

		?>

</div><!-- .zak-entry-summary -->
