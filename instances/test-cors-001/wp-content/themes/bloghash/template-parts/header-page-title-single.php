<?php
/**
 * Template part for displaying page header for single post.
 *
 * @package BlogHash
 * @author Peregrine Themes
 * @since   1.0.0
 */

?>

<div <?php bloghash_page_header_classes(); ?><?php bloghash_page_header_atts(); ?>>

	<?php do_action( 'bloghash_page_header_start' ); ?>

	<?php if ( 'in-page-header' === bloghash_option( 'single_title_position' ) ) { ?>

		<div class="bloghash-container">
			<div class="bloghash-page-header-wrapper">

				<?php
				if ( bloghash_single_post_displays( 'category' ) ) {
					get_template_part( 'template-parts/entry/entry', 'category' );
				}

				if ( bloghash_page_header_has_title() ) {
					echo '<div class="bloghash-page-header-title">';
					bloghash_page_header_title();
					echo '</div>';
				}

				if ( bloghash_has_entry_meta_elements() ) {
					get_template_part( 'template-parts/entry/entry', 'meta' );
				}
				?>

			</div>
		</div>

	<?php } ?>

	<?php do_action( 'bloghash_page_header_end' ); ?>

</div>
