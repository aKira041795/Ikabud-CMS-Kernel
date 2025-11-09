<?php
/**
 * The template for displaying 404 pages (not found).
 *
 * @link https://codex.wordpress.org/Creating_an_Error_404_Page
 *
 * @package     Bloghash
 * @author      Peregrine Themes
 * @since       1.0.0
 */

?>

<?php get_header(); ?>

<div class="bloghash-container">

	<?php do_action( 'bloghash_before_content_area', 'before_post_archive' ); ?>

	<div id="primary" class="content-area">

		<?php do_action( 'bloghash_before_content' ); ?>

		<main id="content" class="site-content" role="main"<?php bloghash_schema_markup( 'main' ); ?>>

			<?php do_action( 'bloghash_content_404' ); ?>

		</main><!-- #content .site-content -->

		<?php do_action( 'bloghash_after_content' ); ?>

	</div><!-- #primary .content-area -->

	<?php do_action( 'bloghash_after_content_area' ); ?>

</div><!-- END .bloghash-container -->

<?php
get_footer();
