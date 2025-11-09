<?php
/**
 * Entry content template file.
 *
 * @package zakra
 *
 * TODO: @since.
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

$post_content       = get_theme_mod( 'zakra_post_content_archive_blog', 'excerpt' );
?>

<div class="entry-content">
	<?php
	if ( is_single() ) {

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
	} else {

		the_content();
	}

	wp_link_pages(
		array(
			'before' => '<div class="page-links">' . esc_html__( 'Pages:', 'zakra' ),
			'after'  => '</div>',
		)
	);
	?>
</div><!-- .entry-content -->
