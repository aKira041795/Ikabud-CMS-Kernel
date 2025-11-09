<?php
/**
 * The template for displaying theme copyright bar.
 *
 * @package     Bloghash
 * @author      Peregrine Themes
 * @since       1.0.0
 */

?>

<?php do_action( 'bloghash_before_copyright' ); ?>
<div id="bloghash-copyright" <?php bloghash_copyright_classes(); ?>>
	<div class="bloghash-container">
		<div class="bloghash-flex-row">

			<div class="col-xs-12 center-xs col-md flex-basis-auto start-md"><?php do_action( 'bloghash_copyright_widgets', 'start' ); ?></div>
			<div class="col-xs-12 center-xs col-md flex-basis-auto end-md"><?php do_action( 'bloghash_copyright_widgets', 'end' ); ?></div>

		</div><!-- END .bloghash-flex-row -->
	</div>
</div><!-- END #bloghash-copyright -->
<?php do_action( 'bloghash_after_copyright' ); ?>
