<?php
/**
 * Template part for displaying video format entry.
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

if ( post_password_required() ) {
	return;
}

if ( has_post_thumbnail() ) :

	get_template_part( 'template-parts/entry/format/media' );

else :

	$bloghash_media = bloghash_get_post_media( 'video' );

	if ( $bloghash_media ) : ?>

		<div class="post-thumb entry-media thumbnail">
			<div class="bloghash-video-container wp-embed-responsive">
				<figure class="is-type-video wp-embed-aspect-16-9 wp-has-aspect-ratio">
					<div class="wp-block-embed__wrapper">
						<?php echo $bloghash_media; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
				</figure>
			</div>
		</div>

		<?php
	endif;

endif;
