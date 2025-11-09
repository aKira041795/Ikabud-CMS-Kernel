<?php
/**
 * The template for displaying the footer in our theme.
 *
 * @link https://developer.wordpress.org/themes/basics/template-files/#template-partials
 *
 * @package     Bloghash
 * @author      Peregrine Themes
 * @since       1.0.0
 */

?>
		<?php do_action( 'bloghash_main_end' ); ?>
		
	</div><!-- #main .site-main -->
	<?php do_action( 'bloghash_after_main' ); ?>

	<?php do_action( 'bloghash_before_colophon', 'before_footer' ); ?>

	<?php if ( bloghash_is_colophon_displayed() ) { ?>
		<footer id="colophon" class="site-footer" role="contentinfo"<?php bloghash_schema_markup( 'footer' ); ?>>

			<?php do_action( 'bloghash_footer' ); ?>

		</footer><!-- #colophon .site-footer -->
	<?php } ?>

	<?php do_action( 'bloghash_after_colophon', 'after_footer' ); ?>

</div><!-- END #page -->
<?php do_action( 'bloghash_after_page_wrapper' ); ?>

<?php wp_footer(); ?>

</body>
</html>
