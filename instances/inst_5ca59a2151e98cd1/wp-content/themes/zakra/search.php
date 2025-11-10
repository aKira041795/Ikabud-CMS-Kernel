<?php
/**
 * The template for displaying search results pages
 *
 * @link    https://developer.wordpress.org/themes/basics/template-hierarchy/#search-result
 *
 * @package zakra
 */

get_header();
?>

	<main id="zak-primary" class="zak-primary">
		<?php echo apply_filters( 'zakra_after_primary_start_filter', false ); // WPCS: XSS OK. ?>

		<?php
		/**
		 * Hook for search content.
		 *
		 * @hooked zakra_content_loop - 10
		 */
		do_action( 'zakra_content_search' );
		?>

		<?php echo apply_filters( 'zakra_after_primary_end_filter', false ); // WPCS: XSS OK. ?>
	</main> <!-- /.zak-primary -->

<?php
get_sidebar();
get_footer();
