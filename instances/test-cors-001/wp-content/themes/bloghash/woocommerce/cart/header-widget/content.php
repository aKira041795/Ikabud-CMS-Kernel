<?php
/**
 * Header Cart Widget dropdown content.
 *
 * @package BlogHash
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$bloghash_cart_items = WC()->cart->get_cart();
?>
<div class="wc-cart-widget-content">
	<?php foreach ( $bloghash_cart_items as $cart_item_key => $cart_item ) { // phpcs:ignore ?>

		<?php
		$bloghash_product    = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );
		$bloghash_product_id = apply_filters( 'woocommerce_cart_item_product_id', $cart_item['product_id'], $cart_item, $cart_item_key );

		if ( $bloghash_product && $bloghash_product->exists() && $cart_item['quantity'] > 0 && apply_filters( 'woocommerce_cart_item_visible', true, $cart_item, $cart_item_key ) ) {
			$bloghash_product_permalink = apply_filters( 'woocommerce_cart_item_permalink', $bloghash_product->is_visible() ? $bloghash_product->get_permalink( $cart_item ) : '', $cart_item, $cart_item_key );
			?>
			<div class="bloghash-cart-item">
				<div class="bloghash-cart-image">
					<?php
					$thumbnail = apply_filters( 'woocommerce_cart_item_thumbnail', $bloghash_product->get_image(), $cart_item, $cart_item_key );

					if ( ! $bloghash_product_permalink ) {
						echo $thumbnail; // phpcs:ignore
					} else {
						printf( '<a href="%s" class="bloghash-woo-thumb">%s</a>', esc_url( $bloghash_product_permalink ), $thumbnail ); // phpcs:ignore
					}
					?>
				</div>

				<div class="bloghash-cart-item-details">
					<p class="bloghash-cart-item-title">
						<?php
						if ( ! $bloghash_product_permalink ) {
							echo wp_kses_post( apply_filters( 'woocommerce_cart_item_name', esc_html( $bloghash_product->get_name() ), $cart_item, $cart_item_key ) . '&nbsp;' );
						} else {
							echo wp_kses_post( apply_filters( 'woocommerce_cart_item_name', sprintf( '<a href="%s">%s</a>', esc_url( $bloghash_product_permalink ), esc_html( $bloghash_product->get_name() ) ), $cart_item, $cart_item_key ) );
						}
						?>
					</p>
					<div class="bloghash-cart-item-meta">

					<?php if ( $cart_item['quantity'] > 1 ) { ?>
							<span class="bloghash-cart-item-quantity"><?php echo esc_html( $cart_item['quantity'] ); ?></span>
						<?php } ?>

						<span class="bloghash-cart-item-price"><?php echo $bloghash_product->get_price_html(); // phpcs:ignore ?></span>
					</div>
				</div>

				<?php /* translators: %s is cart item title. */ ?>
				<a href="<?php echo esc_url( wc_get_cart_remove_url( $cart_item ) ); ?>" class="bloghash-remove-cart-item" data-product_key="<?php echo esc_attr( $cart_item['key'] ); ?>" title="<?php echo esc_html( sprintf( __( 'Remove %s from cart', 'bloghash' ), $bloghash_product->get_title() ) ); ?>">
					<?php echo bloghash()->icons->get_svg( 'x', array( 'aria-hidden' => 'true' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php /* translators: %s is cart item title. */ ?>
					<span class="screen-reader-text"><?php echo esc_html( sprintf( __( 'Remove %s from cart', 'bloghash' ), $bloghash_product->get_title() ) ); ?></span>
				</a>
			</div>
		<?php } ?>
	<?php } ?>
</div><!-- END .wc-cart-widget-content -->
