<?php
/**
 * Main header markup file.
 *
 * @package zakra
 *
 * @since 3.0.0
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;


$header_dual_menu   = get_theme_mod( 'zakra_main_header_layout_3_style', 'style-1' );
$main_header_layout = get_theme_mod( 'zakra_main_header_layout', 'layout-1' );

if ( 'style-1' === $header_dual_menu && 'layout-3' === $main_header_layout && zakra_is_zakra_pro_active() ) {

	return;
}

/**
 * Fires before block one of header main area.
 *
 * @since 1.5.0
 *
 */
do_action( 'zakra_header_block_one_before' );
?>

	<div class="zak-header-col zak-header-col--1">

		<?php zakra_header_block_one(); ?>

	</div> <!-- /.zak-header__block--one -->

<?php
/**
 * Fires before block two of header main area.
 *
 * @since 1.5.0
 *
 */
do_action( 'zakra_header_block_two_before' );
?>
<?php
$main_header_layout            = get_post_meta( zakra_get_post_id(), 'zakra_main_header_style', true );
$customizer_main_header_layout = get_theme_mod( 'zakra_main_header_layout', 'layout-1' );

?>
	<div class="zak-header-col zak-header-col--2">

		<?php if ( 'layout-2' === $customizer_main_header_layout || 'zak-layout-2-style-1' === $main_header_layout || 'zak-layout-2-style-3' === $main_header_layout ) : ?>
		<div class="<?php zakra_css_class( 'zakra_header_main_container_class' ); ?>">
			<?php endif; ?>
			<?php zakra_header_block_two(); ?>

			<?php if ( 'layout-2' === $customizer_main_header_layout || 'zak-layout-2-style-1' === $main_header_layout ) : ?>
		</div>
	<?php endif; ?>
	</div> <!-- /.zak-header__block-two -->

<?php


