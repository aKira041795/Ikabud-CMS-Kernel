<?php
/**
 * Footer widgets template file.
 *
 * @package zakra
 *
 * TODO: @since.
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;
?>

<div class="zak-footer-cols <?php zakra_footer_widget_container_class(); ?>">
	<div class="<?php zakra_css_class( 'zakra_footer_widgets_container_class' ); ?>">
		<div class="zak-row">

			<?php get_sidebar( 'footer' ); ?>

		</div> <!-- /.zak-row-->
	</div><!-- /.zak-container-->
</div><!-- /.zak-site-footer-widgets -->
