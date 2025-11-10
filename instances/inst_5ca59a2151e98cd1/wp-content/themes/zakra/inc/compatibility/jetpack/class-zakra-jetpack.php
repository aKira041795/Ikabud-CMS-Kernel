<?php
/**
 * Jetpack Compatibility File
 *
 * @link    https://jetpack.com/
 *
 * @package zakra
 *
 * TODO: @since.
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Zakra_Jetpack' ) ) {

	/**
	 * Zakra_Jetpack class.
	 */
	class Zakra_Jetpack {

		/**
		 * Instance.
		 *
		 * @access private
		 * @var object Singleton instance of Zakra_JetPack class
		 */
		private static $instance;

		/**
		 * Singleton instance.
		 *
		 * @return Zakra_Jetpack
		 *
		 */
		public static function get_instance() {

			if ( ! isset( self::$instance ) ) {

				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Zakra_Jetpack constructor.
		 */
		private function __construct() {

			add_action( 'after_setup_theme', array( $this, 'jetpack_setup' ) );

			add_filter( 'infinite_scroll_js_settings', array( $this, 'infinite_scroll_js_settings' ) );
		}

		/**
		 * Jetpack setup function.
		 *
		 * @see https://jetpack.com/support/infinite-scroll/
		 * @see https://jetpack.com/support/responsive-videos/
		 * @see https://jetpack.com/support/content-options/
		 */
		public function jetpack_setup() {

			// Add theme support for Infinite Scroll.
			add_theme_support(
				'infinite-scroll',
				apply_filters(
					'zakra_infinite_scroll_args',
					array(
						'container' => 'primary',
						'render'    => array( $this, 'infinite_scroll_render' ),
						'footer'    => 'page',
						'wrapper'   => true,
					)
				)
			);

			// Add theme support for Responsive Videos.
			add_theme_support( 'jetpack-responsive-videos' );

			// Add theme support for Content Options.
			add_theme_support(
				'jetpack-content-options',
				array(
					'post-details'    => array(
						'stylesheet' => 'zakra-style',
						'date'       => '.zak-posted-on',
						'categories' => '.zak-cat-links',
						'tags'       => '.zak-tags-links',
						'author'     => '.zak-byline',
						'comment'    => '.zak-comments-link',
					),
					'featured-images' => array(
						'archive' => true,
						'post'    => true,
						'page'    => true,
					),
				)
			);
		}

		/**
		 * Custom render function for Infinite Scroll.
		 */
		public function infinite_scroll_render() {

			if ( zakra_is_product_archive() ) {

				woocommerce_product_loop_start();
			}

			while ( have_posts() ) :

				the_post();

				if ( is_search() ) :

					get_template_part( 'template-parts/content', 'search' );
				else :

					if ( zakra_is_product_archive() ) :

						wc_get_template_part( 'content', 'product' );
					else :

						get_template_part( 'template-parts/content', get_post_type() );
					endif;
				endif;
			endwhile;

			if ( zakra_is_product_archive() ) {

				woocommerce_product_loop_end();
			}
		}

		/**
		 * Filter the Infinite Scroll JS settings outputted in the head.
		 *
		 * @param $settings
		 *
		 * @return mixed
		 */
		public function infinite_scroll_js_settings( $settings ) {

			if ( ! zakra_is_product_archive() ) {

				$settings['text'] = esc_html__( 'Load More', 'zakra' );
			}

			return $settings;
		}
	}

	Zakra_Jetpack::get_instance();
}
