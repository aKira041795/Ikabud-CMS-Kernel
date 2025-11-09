<?php
/**
 * Zakra Customizer partials.
 *
 * @package zakra
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Zakra_Customizer_Partials' ) ) {

	/**
	 * Customizer Partials.
	 */
	class Zakra_Customizer_Partials {

		/**
		 * Instance.
		 *
		 * @access private
		 * @var object
		 */
		private static $instance;

		/**
		 * Initiator.
		 */
		public static function get_instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Render the site title for the selective refresh partial.
		 *
		 * @return string
		 */
		public static function customize_partial_blogname() {
			return esc_html( get_bloginfo( 'name' ) );
		}

		/**
		 * Render the site tagline for the selective refresh partial.
		 *
		 * @return string
		 */
		public static function customize_partial_blogdescription() {
			return esc_html( get_bloginfo( 'description' ) );
		}

		public static function customize_partial_top_bar_column_1_html() {
			return do_shortcode( get_theme_mod( 'zakra_top_bar_column_1_html', '' ) );
		}

		/**
		 * Deprecating customize_partial_header_top_left_content_html method.
		 *
		 * @since 3.0.0
		 * @deprecated
		 */
		public static function customize_partial_header_top_left_content_html() {

			_deprecated_function( __METHOD__, '3.0.0', 'customize_partial_top_bar_column_1_html' );

			return self::customize_partial_top_bar_column_1_html();
		}

		public static function customize_partial_top_bar_column_2_html() {
			return do_shortcode( get_theme_mod( 'zakra_top_bar_column_2_html', '' ) );
		}

		/**
		 * Deprecating customize_partial_header_top_right_content_html method.
		 *
		 * @since 3.0.0
		 * @deprecated
		 */
		public static function customize_partial_header_top_right_content_html() {

			_deprecated_function( __METHOD__, '3.0.0', 'customize_partial_top_bar_column_2_html' );

			return self::customize_partial_top_bar_column_2_html();
		}

		public static function customize_partial_footer_bar_1_html() {
			return zakra_footer_copyright( '1' );
		}

		/**
		 * Deprecating customize_partial_footer_bar_section_one_html method.
		 *
		 * @since 3.0.0
		 * @deprecated
		 */
		public static function customize_partial_footer_bar_section_one_html() {

			_deprecated_function( __METHOD__, '3.0.0', 'customize_partial_footer_bar_1_html' );

			return self::customize_partial_footer_bar_1_html();
		}

		public static function customize_partial_footer_bar_2_html() {
			return zakra_footer_copyright( '2' );
		}

		/**
		 * Deprecating customize_partial_footer_bar_section_two_html method.
		 *
		 * @since 3.0.0
		 * @deprecated
		 */
		public static function customize_partial_footer_bar_section_two_html() {

			_deprecated_function( __METHOD__, '3.0.0', 'customize_partial_footer_bar_2_html' );

			return self::customize_partial_footer_bar_2_html();
		}

		public static function customize_partial_header_main() {
			return zakra_header_markup( );
		}
	}
}

Zakra_Customizer_Partials::get_instance();
