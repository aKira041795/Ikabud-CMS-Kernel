<?php
/**
 * Template part for displaying page header.
 *
 * @package BlogHash
 * @author Peregrine Themes
 * @since   1.0.0
 */

?>

<div <?php bloghash_page_header_classes(); ?><?php bloghash_page_header_atts(); ?>>
	<div class="bloghash-container">

	<?php do_action( 'bloghash_page_header_start' ); ?>

	<?php if ( bloghash_page_header_has_title() ) { ?>

		<div class="bloghash-page-header-wrapper">

			<div class="bloghash-page-header-title">
				<?php bloghash_page_header_title(); ?>
			</div>

			<?php $bloghash_description = apply_filters( 'bloghash_page_header_description', bloghash_get_the_description() ); ?>

			<?php if ( $bloghash_description ) { ?>

				<div class="bloghash-page-header-description">
					<?php echo wp_kses( $bloghash_description, bloghash_get_allowed_html_tags() ); ?>
				</div>

			<?php } ?>
		</div>

	<?php } ?>

	<?php do_action( 'bloghash_page_header_end' ); ?>

	</div>
</div>
