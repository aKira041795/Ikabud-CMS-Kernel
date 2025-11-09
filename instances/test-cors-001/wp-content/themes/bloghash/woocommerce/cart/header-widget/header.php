<?php
/**
 * Header Cart Widget dropdown header.
 *
 * @package BlogHash
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$bloghash_cart_count    = WC()->cart->get_cart_contents_count();
$bloghash_cart_subtotal = WC()->cart->get_cart_subtotal();

?>
<div class="wc-cart-widget-header">
	<span class="bloghash-cart-count">
		<?php
		/* translators: %s: the number of cart items; */
		echo wp_kses_post( sprintf( _n( '%s item', '%s items', $bloghash_cart_count, 'bloghash' ), $bloghash_cart_count ) );
		?>
	</span>

	<span class="bloghash-cart-subtotal">
		<?php
		/* translators: %s is the cart subtotal. */
		echo wp_kses_post( sprintf( __( 'Subtotal: %s', 'bloghash' ), '<span>' . $bloghash_cart_subtotal . '</span>' ) );
		?>
	</span>
</div>
