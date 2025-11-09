<?php
/**
 *
 * Wishlist ajax count added in header action.
 *
 * @package WebShop
 * @since   1.1.0
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;


	// check if woocommerce is activated.

if ( class_exists( 'woocommerce' ) && defined( 'YITH_WCWL' ) ) {
	// Filter to change markup of wishlist after add to cart to before image.
	add_filter(
		'yith_wcwl_add_to_wishlisth_button_html',
		function ( $template, $wishlist_url, $product_type, $exists, $atts ) {

			$atts['is_single'] = null;

			$atts['loop_position']                     = 'before_image';
			$atts['fragment_options']['loop_position'] = 'before_image';
			return yith_wcwl_get_template( 'add-to-wishlist.php', $atts, true );
		},
		10,
		5
	);

	// Filter to add custom class zak-add-to-wishlist-{yith_wcwl_loop_position} to wishlist div.
	add_filter(
		'woocommerce_post_class',
		function ( $classes ) {

			$prop = wc_get_loop_prop( 'name' );

			if ( in_array( 'add-to-wishlist-before_add_to_cart', $classes, true ) ) {
				unset( $classes[ array_search( 'add-to-wishlist-before_add_to_cart', $classes ) ] );
			} elseif ( in_array( 'add-to-wishlist-after_add_to_cart', $classes, true ) ) {
				unset( $classes[ array_search( 'add-to-wishlist-after_add_to_cart', $classes ) ] );
			}
			$classes[] = 'add-to-wishlist-before_image';
			$classes[] = 'zak-add-to-wishlist-' . get_option( 'yith_wcwl_loop_position', 'after_add_to_cart' );
			return $classes;
		},
		11
	);

	// Replace "Add to wishlist" button class in block item.

	if ( zakra_is_zakra_pro_active() ) {

		add_filter( 'woocommerce_blocks_product_grid_item_html', 'replace_add_to_wishlist_button_class', 11, 3 );
		function replace_add_to_wishlist_button_class( $item_html, $data, $product ) {

			$position = get_option( 'yith_wcwl_loop_position', 'after_add_to_cart' );
			$button   = YITH_WCWL_Frontend()->get_button( $product->get_id() );
			$parts    = array();

			preg_match( '/(<li class=".*?">)[\S|\s]*?(<a .*?>[\S|\s]*?<\/a>)([\S|\s]*?)(<\/li>)/', $item_html, $parts );

			if ( ! $parts || count( $parts ) < 5 ) {
				return $item_html;
			}

			// removes first match (entire match).
			array_shift( $parts );

			// removes empty parts.
			$parts = array_filter( $parts );

			// searches for index to cut parts array.
			switch ( $position ) {
				case 'before_image':
					$index = 1;
					break;
				case 'before_add_to_cart':
					$index = 2;
					break;
				case 'after_add_to_cart':
					$index = 3;
					break;
			}

			// if index is found, stitch button in correct position.
			if ( $index ) {
				$first_set  = array_slice( $parts, 0, $index );
				$second_set = array_slice( $parts, $index );

				$parts = array_merge(
					$first_set,
					(array) $button,
					$second_set
				);

				// replace li classes.
				$parts[0] = preg_replace( '/class="(.*)"/', 'class="$1 add-to-wishlist-' . $position . '"', $parts[0] );
			}

			// join all parts together.
			$item_html = implode( '', $parts );
			$item_html = preg_replace( '/(<li class=".*?">)/', "<li class=\"wc-block-grid__product add-to-wishlist-before_image zak-add-to-wishlist-$position\">", $item_html, 1 );

			// return item.
			return $item_html;
		}
	} else {

		add_filter( 'woocommerce_blocks_product_grid_item_html', 'replace_add_to_wishlist_button_class', 11, 3 );
		function replace_add_to_wishlist_button_class( $item_html, $data, $product ) {

			$position = get_option( 'yith_wcwl_loop_position', 'after_add_to_cart' );

			$item_html = preg_replace( '/(<li class=".*?">)/', "<li class=\"wc-block-grid__product add-to-wishlist-before_image zak-add-to-wishlist-$position\">", $item_html, 1 );

			return $item_html;
		}
	}
}
if ( defined( 'YITH_WCWL' ) && ! function_exists( 'yith_wcwl_get_items_count' ) ) {
	function yith_wcwl_display_items_count() {
		ob_start();
		?>
		<a href="<?php echo esc_url( YITH_WCWL()->get_wishlist_url() ); ?>">
		<span class="yith-wcwl-items-count">
			<i class="yith-wcwl-icon fa fa-heart-o"><span><?php echo esc_html( yith_wcwl_count_all_products() ); ?></span></i>
		</span>
		</a>
		<?php
		$output = ob_get_clean();

		echo $output; // Output the items count HTML
	}

	add_action( 'yith_wcwl_items_count', 'yith_wcwl_display_items_count' );
}

if ( defined( 'YITH_WCWL' ) && ! function_exists( 'yith_wcwl_ajax_update_count' ) ) {
	function yith_wcwl_ajax_update_count() {
		wp_send_json(
			array(
				'count' => yith_wcwl_count_all_products(),
			)
		);
	}

	add_action( 'wp_ajax_yith_wcwl_update_wishlist_count', 'yith_wcwl_ajax_update_count' );
	add_action( 'wp_ajax_nopriv_yith_wcwl_update_wishlist_count', 'yith_wcwl_ajax_update_count' );
}

if ( defined( 'YITH_WCWL' ) && ! function_exists( 'yith_wcwl_enqueue_custom_script' ) ) {
	function yith_wcwl_enqueue_custom_script() {
		wp_add_inline_script(
			'jquery-yith-wcwl',
			"
        jQuery( function( $ ) {
          $( document ).on( 'added_to_wishlist removed_from_wishlist', function() {
            $.get( yith_wcwl_l10n.ajax_url, {
              action: 'yith_wcwl_update_wishlist_count'
            }, function( data ) {
              $('.yith-wcwl-items-count i').children('span').html( data.count );
            } );
          } );
        } );
      "
		);
	}

	add_action( 'wp_enqueue_scripts', 'yith_wcwl_enqueue_custom_script', 20 );
}
