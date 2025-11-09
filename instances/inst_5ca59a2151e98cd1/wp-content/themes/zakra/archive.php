<?php
/**
 * The template for displaying archive pages
 *
 * @link    https://developer.wordpress.org/themes/basics/template-hierarchy/
 *
 * @package zakra
 */

get_header();
?>

	<main id="zak-primary" class="zak-primary">
		<?php echo apply_filters( 'zakra_after_primary_start_filter', false ); // WPCS: XSS OK. ?>

		<?php do_action( 'zakra_content' ); ?>

		<?php echo apply_filters( 'zakra_after_primary_end_filter', false ); // // WPCS: XSS OK. ?>
	</main> <!-- /.zak-primary -->

<?php
get_sidebar();
get_footer();
