<?php
/**
 * Footer bar template file.
 *
 * @package zakra
 *
 * @since 3.0.0
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;
?>

<div class="zak-footer-bar <?php zakra_footer_bar_classes(); ?>">
	<div class="<?php zakra_css_class( 'zakra_footer_bottom_bar_container_class' ); ?>">
		<div class="zak-row">
			<div class="zak-footer-bar__1">

				<?php
				/**
				 * Hook - zakra_action_footer_bottom_bar
				 *
				 * @hooked zakra_footer_bottom_bar_one - 10
				 */
				do_action( 'zakra_action_footer_bottom_bar_one' );
				?>

			</div> <!-- /.zak-footer-bar__1 -->

			<?php if ( 'none' !== get_theme_mod( 'zakra_footer_bar_column_2_content_type', 'none' ) ) : ?>

			<div class="zak-footer-bar__2">

				<?php
				/**
				 * Hook - zakra_action_footer_bottom_bar_two
				 *
				 * @hooked zakra_footer_bottom_bar_two - 10
				 */
				do_action( 'zakra_action_footer_bottom_bar_two' );
				?>

			</div> <!-- /.zak-footer-bar__2 -->

			<?php endif; ?>
		</div> <!-- /.zak-row-->
	</div> <!-- /.zak-container-->
</div> <!-- /.zak-site-footer-bar -->
