<?php
/**
 * The template for displaying theme sidebar.
 *
 * @package     Bloghash
 * @author      Peregrine Themes
 * @since       1.0.0
 */

if ( ! bloghash_is_sidebar_displayed() ) {
	return;
}

$bloghash_sidebar = bloghash_get_sidebar();
?>

<aside id="secondary" class="widget-area bloghash-sidebar-container"<?php bloghash_schema_markup( 'sidebar' ); ?> role="complementary">

	<div class="bloghash-sidebar-inner">
		<?php do_action( 'bloghash_before_sidebar' ); ?>

		<?php
		if ( is_active_sidebar( $bloghash_sidebar ) ) {

			dynamic_sidebar( $bloghash_sidebar );

		} elseif ( current_user_can( 'edit_theme_options' ) ) {

			$bloghash_sidebar_name = bloghash_get_sidebar_name_by_id( $bloghash_sidebar );
			?>
			<div class="bloghash-sidebar-widget bloghash-widget bloghash-no-widget">

				<div class='h4 widget-title'><?php echo esc_html( $bloghash_sidebar_name ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div> 

				<p class='no-widget-text'>
					<?php if ( is_customize_preview() ) { ?>
						<a href='#' class="bloghash-set-widget" data-sidebar-id="<?php echo esc_attr( $bloghash_sidebar ); ?>">
					<?php } else { ?>
						<a href='<?php echo esc_url( admin_url( 'widgets.php' ) ); ?>'>
					<?php } ?>
						<?php esc_html_e( 'Click here to assign a widget.', 'bloghash' ); ?>
					</a>
				</p>
			</div>
			<?php
		}
		?>

		<?php do_action( 'bloghash_after_sidebar' ); ?>
	</div>

</aside><!--#secondary .widget-area -->

<?php
