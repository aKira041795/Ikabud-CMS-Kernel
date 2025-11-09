<?php
/**
 * Scroll to top template file.
 *
 * @package zakra
 *
 * TODO: @since.
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

$scroll_to_top = get_theme_mod( 'zakra_scroll_to_top_icon', 'default' );
?>

<a href="#" id="zak-scroll-to-top" class="<?php zakra_css_class( 'zakra_scroll_to_top_class' ); ?>">
	<?php if ( 'default' === $scroll_to_top ) : ?>

		<?php zakra_get_icon( 'chevron-up' ); ?>
		<span class="screen-reader-text"><?php esc_html_e( 'Scroll to top', 'zakra' ); ?></span>

	<?php else : ?>

	<i class="<?php echo esc_attr( apply_filters( 'zakra_scroll_to_top_icon_class', 'zak-icon' ) ); ?> <?php echo esc_attr( apply_filters( 'zakra_scroll_to_top_icon', 'tg-icon-arrow-up' ) ); ?>">
		<span class="screen-reader-text"><?php esc_html_e( 'Scroll to top', 'zakra' ); ?></span>
	</i>

	<?php endif; ?>

</a>

<div class="zak-overlay-wrapper"></div>
