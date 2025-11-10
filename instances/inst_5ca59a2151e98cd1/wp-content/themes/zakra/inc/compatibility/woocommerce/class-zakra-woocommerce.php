<?php
/**
 * WooCommerce Compatibility.
 *
 * @package zakra
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Zakra_WooCommerce' ) ) {

	/**
	 * Class Zakra_WooCommerce
	 */
	class Zakra_WooCommerce {

		/**
		 * Zakra_WooCommerce constructor.
		 */
		public function __construct() {

			add_action( 'after_setup_theme', array( $this, 'woocommerce_setup' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'woocommerce_scripts' ) );
			add_filter( 'body_class', array( $this, 'woocommerce_active_body_class' ) );

			add_action( 'woocommerce_before_quantity_input_field', array( $this, 'product_quantity_minus_button' ) );
			add_action( 'woocommerce_after_quantity_input_field', array( $this, 'product_quantity_plus_button' ) );
			add_action( 'wp_footer', array( $this, 'product_quantity' ) );

			// Remove WC wrappers.
			remove_action( 'woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10 );
			remove_action( 'woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10 );

			add_action( 'woocommerce_before_main_content', array( $this, 'woocommerce_wrapper_before' ) );
			add_action( 'woocommerce_after_main_content', array( $this, 'woocommerce_wrapper_after' ) );
			add_filter( 'woocommerce_add_to_cart_fragments', array( $this, 'woocommerce_header_add_to_cart_fragment' ) );
			add_filter( 'zakra_woocommerce_header_cart', array( __CLASS__, 'woocommerce_header_cart' ) );
			add_filter( 'woocommerce_show_page_title', array( $this, 'woocommerce_page_title' ) );
			add_action( 'wp_loaded', array( $this, 'woocommerce_remove_product_title' ) );

			// Remove WC sidebar.
			remove_action( 'woocommerce_sidebar', array( $this, 'woocommerce_get_sidebar' ), 10 );

			add_filter( 'zakra_get_sidebar', array( $this, 'get_sidebar' ), 15 );
		}

		/**
		 * WooCommerce setup function.
		 *
		 * @link https://docs.woocommerce.com/document/third-party-custom-theme-compatibility/
		 * @link https://github.com/woocommerce/woocommerce/wiki/Enabling-product-gallery-features-(zoom,-swipe,-lightbox)-in-3.0.0
		 *
		 * @return void
		 */
		public function woocommerce_setup() {
			add_theme_support( 'woocommerce' );
			add_theme_support( 'wc-product-gallery-zoom' );
			add_theme_support( 'wc-product-gallery-lightbox' );
			add_theme_support( 'wc-product-gallery-slider' );
		}

		/**
		 * WooCommerce scripts and styles.
		 *
		 * @return void
		 */
		public function woocommerce_scripts() {
			wp_enqueue_style( 'zakra-woocommerce-style', ZAKRA_PARENT_URI . '/woocommerce.css', '', ZAKRA_THEME_VERSION );

			add_filter( 'zakra_dynamic_theme_wc_css', array( 'Zakra_Dynamic_CSS', 'render_wc_output' ) );

			$theme_wc_dynamic_css = apply_filters( 'zakra_dynamic_theme_wc_css', '' );
			wp_add_inline_style( 'zakra-woocommerce-style', $theme_wc_dynamic_css );

			$font_path   = WC()->plugin_url() . '/assets/fonts/';
			$inline_font = '
			@font-face {
				font-family: "star";
				src: url("' . $font_path . 'star.eot");
				src: url("' . $font_path . 'star.eot?#iefix") format("embedded-opentype"),
					url("' . $font_path . 'star.woff") format("woff"),
					url("' . $font_path . 'star.ttf") format("truetype"),
					url("' . $font_path . 'star.svg#star") format("svg");
				font-weight: normal;
				font-style: normal;
			}
			@font-face {
				font-family: "WooCommerce";
				src: url("' . $font_path . 'WooCommerce.eot");
				src: url("' . $font_path . 'WooCommerce.eot?#iefix") format("embedded-opentype"),
					url("' . $font_path . 'WooCommerce.woff") format("woff"),
					url("' . $font_path . 'WooCommerce.ttf") format("truetype"),
					url("' . $font_path . 'WooCommerce.svg#star") format("svg");
				font-weight: normal;
				font-style: normal;
			}
			';

			wp_add_inline_style( 'zakra-woocommerce-style', $inline_font );
		}

		public function product_quantity_minus_button() {
			?>
			<button type="button" class="zak-qty-controller zak-qty-minus" data-qty="minus">-</button>
			<?php
		}

		function product_quantity_plus_button() {
			?>
			<button type="button" class="zak-qty-controller zak-qty-plus" data-qty="plus">+</button>
			<?php
		}

		public function product_quantity() {

			wc_enqueue_js(
				"
      $(document).on( 'click', '.zak-qty-plus, .zak-qty-minus', function() {

         var qty = $( this ).parent( '.quantity' ).find( '.qty' );
         var val = parseFloat(qty.val());
         var max = parseFloat(qty.attr( 'max' ));
         var min = parseFloat(qty.attr( 'min' ));
         var step = parseFloat(qty.attr( 'step' ));

         if ( $( this ).is( '.zak-qty-plus' ) ) {
            if ( max && ( max <= val ) ) {
               qty.val( max ).change();
            } else {
            	if(val) {
               qty.val( val + step ).change();
               }
               else {
               qty.val( 0 + step ).change();
               }
            }
         } else {
            if ( min && ( min >= val ) ) {
               qty.val( min ).change();
            } else if ( val > min ) {
               qty.val( val - step ).change();
            }
         }

      });

   "
			);
		}

		/**
		 * Add 'woocommerce-active' class to the body tag.
		 *
		 * @param  array $classes CSS classes applied to the body tag.
		 * @return array $classes modified to include 'woocommerce-active' class.
		 */
		public function woocommerce_active_body_class( $classes ) {

			$classes[] = 'woocommerce-active';

			return $classes;
		}

		/**
		 * Before Content.
		 *
		 * Wraps all WooCommerce content in wrappers which match the theme markup.
		 *
		 * @return void
		 */
		public function woocommerce_wrapper_before() {
			?>
			<main id="zak-primary" class="zak-primary">
			<?php
		}

		/**
		 * After Content.
		 *
		 * Closes the wrapping divs.
		 *
		 * @return void
		 */
		public function woocommerce_wrapper_after() {
			?>
			</main><!-- /.zak-primary -->
			<?php
		}

		/**
		 * After Content.
		 *
		 * WooCommerce shopping cart.
		 *
		 * @param array $fragments Section to refresh via AJAX.
		 * @return array
		 */
		public function woocommerce_header_add_to_cart_fragment( $fragments ) {

			ob_start();

			echo self::woocommerce_cart_link(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

			$fragments['.cart-page-link'] = ob_get_clean();

			return $fragments;
		}

		/**
		 * Cart Link.
		 *
		 * Displayed a link to the cart including the number of items present and the cart total.
		 *
		 * @return string
		 */
		public static function woocommerce_cart_link() {

			$output = '<a class="' . (is_customize_preview() ? 'zak-cart-link' : 'cart-page-link') . '" href="' . esc_url( wc_get_cart_url() ) . '" title="' . __( 'View your shopping cart', 'zakra' ) . '">';
			$item_count_text = sprintf(
				/* translators: number of items in the mini cart. */
				'%d',
				WC()->cart->get_cart_contents_count()
			);
			$output .= apply_filters( 'zakra_woocommerce_cart_icon', '<svg class="zak-icon zakra-icon--cart" xmlns="http://www.w3.org/2000/svg" xml:space="preserve" viewBox="0 0 24 24"><path d="M18.5 22c-1 0-1.8-.8-1.8-1.8s.8-1.8 1.8-1.8 1.8.8 1.8 1.8-.8 1.8-1.8 1.8zm0-2c-.2 0-.2 0-.2.2s0 .2.2.2.2 0 .2-.2 0-.2-.2-.2zm-8.9 2c-1 0-1.8-.8-1.8-1.8s.8-1.8 1.8-1.8 1.8.8 1.8 1.8-.8 1.8-1.8 1.8zm0-2c-.2 0-.2 0-.2.2s0 .2.2.2.2 0 .2-.2 0-.2-.2-.2zm8.4-2.9h-7.9c-1.3 0-2.4-.9-2.6-2.2L6.1 8.2v-.1L5.4 4H3c-.6 0-1-.4-1-1s.4-1 1-1h3.3c.5 0 .9.4 1 .8L8 7h12.9c.3 0 .6.1.8.4.2.2.3.5.2.8L20.6 15c-.3 1.3-1.3 2.1-2.6 2.1zM8.3 9l1.2 5.6c.1.4.4.5.6.5H18c.1 0 .5 0 .6-.5L19.7 9H8.3z"/></svg>' );
			$output .= '<span class="count">' . esc_html( $item_count_text ) . '</span>';
			$output .= '</a>';

			return $output;
		}

		/**
		 * Display Header Cart.
		 *
		 * @return string
		 */
		public static function woocommerce_header_cart() {

			$output = '';

			if ( is_cart() ) {
				$class = 'current-menu-item';
			} else {
				$class = '';
			}

			$output .= '<li class="menu-item zak-menu-item zak-menu-item-cart ' . $class . '">';
			$output .= self::woocommerce_cart_link();
			$output .= '</li>';

			return $output;
		}

		/**
		 * Manage WooCommerce page title.
		 *
		 * @return bool
		 */
		public function woocommerce_page_title() {

			if ( 'page-header' === get_theme_mod( 'zakra_page_title_position', 'page-header' ) ) {
				return false;
			}

			return true;
		}

		/**
		 * Remove product title if it's shown in page header.
		 */
		public function woocommerce_remove_product_title() {

			if ( 'page-header' === get_theme_mod( 'zakra_page_title_position', 'page-header' ) ) {
				remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_title', 5 );
			}
		}

		/**
		 * Get sidebar for WC pages based on the current layout.
		 *
		 * @param $sidebar
		 * @return mixed|string
		 */
		public function get_sidebar( $sidebar ) {

			if (
				( is_woocommerce() || is_cart() || is_checkout() ) &&
				'zak-site-layout--left' === zakra_get_current_layout()
			) {
				return 'wc-left-sidebar';
			}

			if (
				( ( is_woocommerce() || is_cart() || is_checkout() ) && 'zak-site-layout--right' === zakra_get_current_layout() ) ||
				( ( is_woocommerce() || is_cart() || is_checkout() ) && 'zak-site-layout--2-sidebars' === zakra_get_current_layout() )
			) {
				return 'wc-right-sidebar';
			}

			return $sidebar;
		}
	}

	new Zakra_WooCommerce();
}

add_filter( 'woocommerce_enqueue_styles', '__return_empty_array' );

/**
 * Opening element for filter wrapper at the top of WC pages.
 *
 * @since 1.0.0
 *
 * @return void
	 */
function zakra_woocommerce_filter_wrapper_before() {
	echo '<div class="zak-wc-filter">';
}

/**
 * Closing element for filter wrapper at the top of WC pages.
 *
 * @since 1.0.0
 *
 * @return void
 */
function zakra_woocommerce_filter_wrapper_after() {
	echo '</div><!-- /.zak-wc-filter -->';
}

// Add filter wrapper.
add_action( 'woocommerce_before_shop_loop', 'zakra_woocommerce_filter_wrapper_before', 10 );
add_action( 'woocommerce_before_shop_loop', 'zakra_woocommerce_filter_wrapper_after', 30 );
