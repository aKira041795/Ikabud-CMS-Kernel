<?php
/**
 * The template for displaying theme footer.
 *
 * @package     Bloghash
 * @author      Peregrine Themes
 * @since       1.0.0
 */

?>

<?php do_action( 'bloghash_before_footer' ); ?>
<div id="bloghash-footer" <?php bloghash_footer_classes(); ?>>
	<div class="bloghash-container">
		<div class="bloghash-flex-row" id="bloghash-footer-widgets">

			<?php bloghash_footer_widgets(); ?>

		</div><!-- END .bloghash-flex-row -->
	</div><!-- END .bloghash-container -->
</div><!-- END #bloghash-footer -->
<?php do_action( 'bloghash_after_footer' ); ?>
