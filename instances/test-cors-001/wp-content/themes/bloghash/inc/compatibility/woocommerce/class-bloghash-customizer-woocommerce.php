<?php
/**
 * Bloghash WooCommerce section in Customizer.
 *
 * @package BlogHash
 * @author Peregrine Themes
 * @since   1.0.0
 */

/**
 * Do not allow direct script access.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Bloghash_Customizer_WooCommerce' ) ) :
	/**
	 * Bloghash WooCommerce section in Customizer.
	 */
	class Bloghash_Customizer_WooCommerce {

		/**
		 * Primary class constructor.
		 *
		 * @since 1.0.0
		 */
		public function __construct() {

			// Registers our custom options in Customizer.
			add_filter( 'bloghash_customizer_options', array( $this, 'register_options' ), 20 );
			add_action( 'customize_register', array( $this, 'customizer_tweak' ), 20 );

			// Add default values for WooCommerce options.
			add_filter( 'bloghash_default_option_values', array( $this, 'default_customizer_values' ) );

			// Add localized strings to script.
			add_filter( 'bloghash_customizer_localized', array( $this, 'customizer_localized_strings' ) );
		}

		/**
		 * Add defaults for new WooCommerce customizer options.
		 *
		 * @param  array $defaults Array of default values.
		 * @return array           Array of default values.
		 */
		public function default_customizer_values( $defaults ) {

			$defaults['bloghash_wc_product_gallery_lightbox'] = true;
			$defaults['bloghash_wc_product_gallery_zoom']     = true;
			$defaults['bloghash_shop_product_hover']          = 'none';
			$defaults['bloghash_product_sale_badge']          = 'percentage';
			$defaults['bloghash_product_sale_badge_text']     = esc_html__( 'Sale!', 'bloghash' );
			$defaults['bloghash_wc_product_slider_arrows']    = true;
			$defaults['bloghash_wc_product_gallery_style']    = 'default';
			$defaults['bloghash_wc_product_sidebar_position'] = 'no-sidebar';
			$defaults['bloghash_wc_sidebar_position']         = 'no-sidebar';
			$defaults['bloghash_wc_upsell_products']          = true;
			$defaults['bloghash_wc_upsells_columns']          = 4;
			$defaults['bloghash_wc_upsells_rows']             = 1;
			$defaults['bloghash_wc_related_products']         = true;
			$defaults['bloghash_wc_related_columns']          = 4;
			$defaults['bloghash_wc_related_rows']             = 1;
			$defaults['bloghash_wc_cross_sell_products']      = true;
			$defaults['bloghash_wc_cross_sell_rows']          = 1;
			$defaults['bloghash_product_catalog_elements']    = array(
				'category' => true,
				'title'    => true,
				'ratings'  => true,
				'price'    => true,
			);

			return $defaults;
		}

		/**
		 * Tweak Customizer.
		 *
		 * @since 1.0.0
		 * @param WP_Customize_Manager $customizer Instance of WP_Customize_Manager class.
		 */
		public function customizer_tweak( $customizer ) {
			// Move WooCommerce panel.
			$customizer->get_panel( 'woocommerce' )->priority = 10;

			return $customizer;
		}

		/**
		 * Registers our custom options in Customizer.
		 *
		 * @since 1.0.0
		 * @param array $options Array of customizer options.
		 */
		public function register_options( $options ) {

			// Shop image hover effect.
			$options['setting']['bloghash_shop_product_hover'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_select',
				'control'           => array(
					'type'        => 'bloghash-select',
					'section'     => 'woocommerce_product_catalog',
					'label'       => esc_html__( 'Product image hover', 'bloghash' ),
					'description' => esc_html__( 'Effect for product image on hover', 'bloghash' ),
					'choices'     => array(
						'none'       => esc_html__( 'No Effect', 'bloghash' ),
						'image-swap' => esc_html__( 'Image Swap', 'bloghash' ),
					),
				),
			);

			// Sale badge.
			$options['setting']['bloghash_product_sale_badge'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_select',
				'control'           => array(
					'type'        => 'bloghash-select',
					'section'     => 'woocommerce_product_catalog',
					'label'       => esc_html__( 'Product sale badge', 'bloghash' ),
					'description' => esc_html__( 'Choose what to display on the product sale badge.', 'bloghash' ),
					'choices'     => array(
						'hide'       => esc_html__( 'Hide badge', 'bloghash' ),
						'percentage' => esc_html__( 'Show percentage', 'bloghash' ),
						'text'       => esc_html__( 'Show text', 'bloghash' ),
					),
				),
			);

			// Sale badge text.
			$options['setting']['bloghash_product_sale_badge_text'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'sanitize_text_field',
				'control'           => array(
					'type'        => 'bloghash-text',
					'label'       => esc_html__( 'Sale badge text', 'bloghash' ),
					'description' => esc_html__( 'Add custom text for the product sale badge.', 'bloghash' ),
					'placeholder' => esc_html__( 'Sale!', 'bloghash' ),
					'section'     => 'woocommerce_product_catalog',
					'required'    => array(
						array(
							'control'  => 'bloghash_product_sale_badge',
							'value'    => 'text',
							'operator' => '==',
						),
					),
				),
			);

			// Catalog product elements.
			$options['setting']['bloghash_product_catalog_elements'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_sortable',
				'control'           => array(
					'type'        => 'bloghash-sortable',
					'section'     => 'woocommerce_product_catalog',
					'label'       => esc_html__( 'Product details', 'bloghash' ),
					'description' => esc_html__( 'Set order and visibility for product details.', 'bloghash' ),
					'choices'     => array(
						'title'    => esc_html__( 'Title', 'bloghash' ),
						'ratings'  => esc_html__( 'Ratings', 'bloghash' ),
						'price'    => esc_html__( 'Price', 'bloghash' ),
						'category' => esc_html__( 'Category', 'bloghash' ),
					),
				),
			);

			// Section.
			$options['section']['bloghash_woocommerce_single_product'] = array(
				'title'    => esc_html__( 'Single Product', 'bloghash' ),
				'priority' => 50,
				'panel'    => 'woocommerce',
			);

			// Product Gallery Zoom.
			$options['setting']['bloghash_wc_product_gallery_zoom'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_toggle',
				'control'           => array(
					'type'        => 'bloghash-toggle',
					'label'       => esc_html__( 'Gallery Zoom', 'bloghash' ),
					'description' => esc_html__( 'Enable zoom effect when hovering product gallery.', 'bloghash' ),
					'section'     => 'bloghash_woocommerce_single_product',
					'space'       => true,
				),
			);

			// Product Gallery Lightbox.
			$options['setting']['bloghash_wc_product_gallery_lightbox'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_toggle',
				'control'           => array(
					'type'        => 'bloghash-toggle',
					'label'       => esc_html__( 'Gallery Lightbox', 'bloghash' ),
					'description' => esc_html__( 'Open product gallery images in lightbox.', 'bloghash' ),
					'section'     => 'bloghash_woocommerce_single_product',
					'space'       => true,
				),
			);

			// Product slider arrows.
			$options['setting']['bloghash_wc_product_slider_arrows'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_toggle',
				'control'           => array(
					'type'        => 'bloghash-toggle',
					'label'       => esc_html__( 'Slider Arrows', 'bloghash' ),
					'description' => esc_html__( 'Enable left and right arrows on product gallery slider.', 'bloghash' ),
					'section'     => 'bloghash_woocommerce_single_product',
					'space'       => true,
				),
			);

			// Related Products.
			$options['setting']['bloghash_wc_related_products'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_toggle',
				'control'           => array(
					'type'        => 'bloghash-toggle',
					'label'       => esc_html__( 'Related Products', 'bloghash' ),
					'description' => esc_html__( 'Display related products.', 'bloghash' ),
					'section'     => 'bloghash_woocommerce_single_product',
					'space'       => true,
				),
			);

			// Related product column count.
			$options['setting']['bloghash_wc_related_columns'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_range',
				'control'           => array(
					'type'        => 'bloghash-range',
					'label'       => esc_html__( 'Related Products Columns', 'bloghash' ),
					'description' => esc_html__( 'How many related products should be shown per row?', 'bloghash' ),
					'section'     => 'bloghash_woocommerce_single_product',
					'min'         => 1,
					'max'         => 6,
					'step'        => 1,
					'required'    => array(
						array(
							'control'  => 'bloghash_wc_related_products',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
			);

			// Related product row count.
			$options['setting']['bloghash_wc_related_rows'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_range',
				'control'           => array(
					'type'        => 'bloghash-range',
					'label'       => esc_html__( 'Related Products Rows', 'bloghash' ),
					'description' => esc_html__( 'How many rows of related products should be shown?', 'bloghash' ),
					'section'     => 'bloghash_woocommerce_single_product',
					'min'         => 1,
					'max'         => 5,
					'step'        => 1,
					'required'    => array(
						array(
							'control'  => 'bloghash_wc_related_products',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
			);

			// Up-Sell Products.
			$options['setting']['bloghash_wc_upsell_products'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_toggle',
				'control'           => array(
					'type'        => 'bloghash-toggle',
					'label'       => esc_html__( 'Up-Sell Products', 'bloghash' ),
					'description' => esc_html__( 'Display linked upsell products.', 'bloghash' ),
					'section'     => 'bloghash_woocommerce_single_product',
					'space'       => true,
				),
			);

			// Up-Sells column count.
			$options['setting']['bloghash_wc_upsells_columns'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_range',
				'control'           => array(
					'type'        => 'bloghash-range',
					'label'       => esc_html__( 'Up-Sell Products Columns', 'bloghash' ),
					'description' => esc_html__( 'How many up-sell products should be shown per row?', 'bloghash' ),
					'section'     => 'bloghash_woocommerce_single_product',
					'min'         => 1,
					'max'         => 6,
					'step'        => 1,
					'required'    => array(
						array(
							'control'  => 'bloghash_wc_upsell_products',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
			);

			// Up-Sells rows count.
			$options['setting']['bloghash_wc_upsells_rows'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_range',
				'control'           => array(
					'type'        => 'bloghash-range',
					'label'       => esc_html__( 'Up-Sell Products Rows', 'bloghash' ),
					'description' => esc_html__( 'How many rows of up-sell products should be shown?', 'bloghash' ),
					'section'     => 'bloghash_woocommerce_single_product',
					'min'         => 1,
					'max'         => 6,
					'step'        => 1,
					'required'    => array(
						array(
							'control'  => 'bloghash_wc_upsell_products',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
			);

			// Cross-Sell Products.
			$options['setting']['bloghash_wc_cross_sell_products'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_toggle',
				'control'           => array(
					'type'        => 'bloghash-toggle',
					'label'       => esc_html__( 'Cross-Sell Products', 'bloghash' ),
					'description' => esc_html__( 'Display linked cross-sell products on cart page.', 'bloghash' ),
					'section'     => 'bloghash_woocommerce_single_product',
					'space'       => true,
				),
			);

			// Cross-Sells rows count.
			$options['setting']['bloghash_wc_cross_sell_rows'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_range',
				'control'           => array(
					'type'        => 'bloghash-range',
					'label'       => esc_html__( 'Cross-Sell Products Rows', 'bloghash' ),
					'description' => esc_html__( 'How many rows of cross-sell products should be shown?', 'bloghash' ),
					'section'     => 'bloghash_woocommerce_single_product',
					'min'         => 1,
					'max'         => 6,
					'step'        => 1,
					'required'    => array(
						array(
							'control'  => 'bloghash_wc_cross_sells_products',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
			);

			$sidebar_options = array();

			$sidebar_options['bloghash_wc_sidebar_position'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_select',
				'control'           => array(
					'type'        => 'bloghash-select',
					'label'       => esc_html__( 'WooCommerce', 'bloghash' ),
					'description' => esc_html__( 'Choose default sidebar position for cart, checkout and catalog pages. You can change this setting per page via metabox settings.', 'bloghash' ),
					'section'     => 'bloghash_section_sidebar',
					'choices'     => array(
						'default'       => esc_html__( 'Default', 'bloghash' ),
						'no-sidebar'    => esc_html__( 'No Sidebar', 'bloghash' ),
						'left-sidebar'  => esc_html__( 'Left Sidebar', 'bloghash' ),
						'right-sidebar' => esc_html__( 'Right Sidebar', 'bloghash' ),
					),
				),
			);

			$sidebar_options['bloghash_wc_product_sidebar_position'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_select',
				'control'           => array(
					'type'        => 'bloghash-select',
					'label'       => esc_html__( 'WooCommerce - Single Product', 'bloghash' ),
					'description' => esc_html__( 'Choose default sidebar position layout for product pages. You can change this setting per product via metabox settings.', 'bloghash' ),
					'section'     => 'bloghash_section_sidebar',
					'choices'     => array(
						'default'       => esc_html__( 'Default', 'bloghash' ),
						'no-sidebar'    => esc_html__( 'No Sidebar', 'bloghash' ),
						'left-sidebar'  => esc_html__( 'Left Sidebar', 'bloghash' ),
						'right-sidebar' => esc_html__( 'Right Sidebar', 'bloghash' ),
					),
				),
			);

			$options['setting'] = bloghash_array_insert( $options['setting'], $sidebar_options, 'bloghash_archive_sidebar_position' );

			return $options;
		}

		/**
		 * Add localize strings.
		 *
		 * @param  array $strings Array of strings to be localized.
		 * @return array          Modified string array.
		 */
		public function customizer_localized_strings( $strings ) {

			// Preview a random single product for WooCommerce > Single Product section.
			$products = get_posts(
				array(
					'post_type'      => 'product',
					'posts_per_page' => 1,
					'orderby'        => 'rand',
				)
			);

			if ( count( $products ) ) {
				$strings['preview_url_for_section']['bloghash_woocommerce_single_product'] = get_permalink( $products[0] );
			}

			return $strings;
		}
	}
endif;
new Bloghash_Customizer_WooCommerce();
