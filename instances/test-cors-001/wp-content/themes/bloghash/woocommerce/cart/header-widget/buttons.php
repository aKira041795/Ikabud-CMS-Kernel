<?php
/**
 * Header Cart Widget cart & checkout buttons.
 *
 * @package BlogHash
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<div class="bloghash-cart-buttons">
	<a href="<?php echo esc_url( wc_get_cart_url() ); ?>" class="bloghash-btn btn-text-1" role="button">
		<span><?php esc_html_e( 'View Cart', 'bloghash' ); ?></span>
	</a>

	<a href="<?php echo esc_url( wc_get_checkout_url() ); ?>" class="bloghash-btn btn-fw" role="button">
		<span><?php esc_html_e( 'Checkout', 'bloghash' ); ?></span>
	</a>
</div>
