<?php
/**
 * Top bar template file.
 *
 * @package zakra
 *
 * TODO: @since.
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;
?>

<div class="<?php zakra_css_class( 'zakra_header_top_class' ); ?>">
	<div class="<?php zakra_css_class( 'zakra_header_top_container_class' ); ?>">
		<div class="zak-row">
			<div class="zak-top-bar__1">

				<?php
				$top_bar_content_position = '1';

				/**
				 * Hook - zakra_action_header_column_1_content
				 *
				 * @hooked zakra_action_header_column_1_content - 10
				 */
				do_action( 'zakra_action_header_column_1_content', $top_bar_content_position );
				?>

			</div> <!-- /.zak-top-bar__1 -->

				<?php
				if ( 'layout-1' !== get_theme_mod( 'zakra_top_bar_layout', 'layout-2' ) && 'none' !== get_theme_mod( 'zakra_top_bar_column_2_content_type', 'none' ) ) {
					?>

			<div class="zak-top-bar__2">

					<?php
					$top_bar_content_position = '2';

					/**
					 * Hook - zakra_action_header_column_2_content
					 *
					 * @hooked zakra_action_header_column_2_content - 10
					 */
					do_action( 'zakra_action_header_column_2_content', $top_bar_content_position );
					?>

			</div> <!-- /.zak-top-bar__2 -->

					<?php
				}
				?>
		</div> <!-- /.zak-row -->
	</div> <!-- /.zak-container -->
</div> <!-- /.zak-top-bar -->
