<?php
/**
 * Header Cart Widget empty cart.
 *
 * @package BlogHash
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<div class="bloghash-empty-cart">
	<?php echo bloghash()->icons->get_svg( 'shopping-empty', array( 'aria-hidden' => 'true' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	<p><?php esc_html_e( 'No products in the cart.', 'bloghash' ); ?></p>
</div>
