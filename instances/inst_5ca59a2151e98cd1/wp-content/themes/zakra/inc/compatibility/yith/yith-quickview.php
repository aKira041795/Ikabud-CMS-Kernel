<?php
/**
 * Replace QuickView Button with SVG Icon
 *
 * This code removes the original QuickView button from the YITH WooCommerce Quick View plugin
 * and replaces it with a custom SVG icon.
 *
 * @package Kirana
 */

// Hook to remove the original QuickView button and replace it with an SVG icon
if ( class_exists( 'YITH_WCQV' ) && class_exists( 'Woocommerce' ) ) {

	/**
	 * Remove Quick View Button Action
	 *
	 * This function removes the 'init' action hook that calls the 'add_button' function.
	 */
	function remove_quick_view_button() {
		remove_action( 'woocommerce_after_shop_loop_item', array( YITH_WCQV_Frontend(), 'yith_add_quick_view_button' ), 15 );
	}
	add_action( 'init', 'remove_quick_view_button' );

	if ( ! function_exists( 'custom_yith_add_quick_view_button' ) ) {

		/**
		 * Custom Quick View Button with SVG Icon
		 *
		 * Replaces the original QuickView button with a custom SVG icon.
		 */
		function custom_yith_add_quick_view_button() {
			global $product;

			/**
			 * Return if ! apply_filters( 'yith_wcqv_show_quick_view_button' )
			 *
			 * @since 1.0.0
			 *
			 * @return 'original markup'
			 */
			if ( ! apply_filters( 'yith_wcqv_show_quick_view_button', true, $product->get_id() ) ) {
				return;
			}

			$button     = '';
			$product_id = $product->get_id();
			$icon_svg   = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg">
<path d="M1 12C1 12 5 4 12 4C19 4 23 12 23 12C23 12 19 20 12 20C5 20 1 12 1 12Z"></path>
<circle cx="12" cy="12.1377" r="3"></circle>

</svg>';

			if ( $product_id ) {
				$button = '<a href="#" class="yith-wcqv-button zak-quickview" data-product_id="' . esc_attr( $product_id ) . '">' . $icon_svg . '</a>';
			}

			echo wp_kses(
				$button,
				array(
					'a'    => array(
						'href'            => array(),
						'class'           => array(),
						'data-product_id' => array(),
					),
					'svg'  => array(
						'xmlns'   => array(),
						'stroke-width'   => array(),
						'width'   => array(),
						'stroke'   => array(),
						'stroke-linejoin'   => array(),
						'stroke-linecap'   => array(),
						'height'  => array(),
						'viewbox' => array(),
						'fill'    => array(),
					),
					'path' => array(
						'd'               => array(),
						'stroke'          => array(),
						'stroke-width'    => array(),
						'stroke-linecap'  => array(),
						'stroke-linejoin' => array(),
					),
					'circle' => array(
						'cx'               => array(),
						'cy'          => array(),
						'r'    => array(),
					),
				)
			);
		}

		/**
		 * Add Custom Quick View Button Action
		 *
		 * This function adds the custom 'custom_yith_add_quick_view_button' action.
		 */
		add_action( 'woocommerce_after_shop_loop_item', 'custom_yith_add_quick_view_button', 9 );
	}
}
