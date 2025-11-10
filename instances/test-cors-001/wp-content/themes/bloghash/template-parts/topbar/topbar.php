<?php
/**
 * The template for displaying theme top bar.
 *
 * @see https://developer.wordpress.org/themes/basics/template-files/#template-partials
 *
 * @package BlogHash
 * @author Peregrine Themes
 * @since   1.0.0
 */

?>

<?php do_action( 'bloghash_before_topbar' ); ?>
<div id="bloghash-topbar" <?php bloghash_top_bar_classes(); ?>>
	<div class="bloghash-container">
		<div class="bloghash-flex-row">
			<div class="col-md flex-basis-auto start-sm"><?php do_action( 'bloghash_topbar_widgets', 'left' ); ?></div>
			<div class="col-md flex-basis-auto end-sm"><?php do_action( 'bloghash_topbar_widgets', 'right' ); ?></div>
		</div>
	</div>
</div><!-- END #bloghash-topbar -->
<?php do_action( 'bloghash_after_topbar' ); ?>
