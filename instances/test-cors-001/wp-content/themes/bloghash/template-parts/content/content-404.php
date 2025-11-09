<?php
/**
 * Template part for displaying 404 page.
 *
 * @link https://codex.wordpress.org/Template_Hierarchy
 *
 * @package     Bloghash
 * @author      Peregrine Themes
 * @since       1.0.0
 */

?>

<section class="error-404 not-found">

	<?php do_action( 'bloghash_before_404_content' ); ?>

	<header class="page-header">
		<h1 class="page-title"><?php echo wp_kses( __( '404', 'bloghash' ), bloghash_get_allowed_html_tags() ); ?></h1>
		<p class="h2"><?php echo wp_kses( __( 'Oops! Page not found.', 'bloghash' ), bloghash_get_allowed_html_tags() ); ?></p>
	</header><!-- .page-header -->

	<div class="page-content bloghash-entry">
		<p><?php echo wp_kses( __( 'We couldn&rsquo;t find the page you are looking for. Perhaps you can try searching:', 'bloghash' ), bloghash_get_allowed_html_tags() ); ?></p>

		<?php get_search_form(); ?>

		<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="bloghash-btn btn-left-icon btn-reveal" role="button">
			<span><?php esc_html_e( 'Return to Home', 'bloghash' ); ?></span>
			<?php echo bloghash()->icons->get_svg( 'arrow-left', array( 'aria-hidden' => 'true' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</a>
	</div><!-- .page-content -->

	<?php do_action( 'bloghash_after_404_content' ); ?>

</section><!-- .error-404 -->
