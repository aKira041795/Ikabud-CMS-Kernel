<?php
/**
 * Define constants.
 *
 * @package zakra
 *
 * TODO: @since.
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Zakra_Constants' ) ) {

	/**
	 * Zakra_Constants class.
	 */
	class Zakra_Constants {

		/**
		 * Instance.
		 *
		 * @access private
		 * @var object Singleton instance of Zakra_Constants class
		 */
		private static $instance;

		/**
		 * Array of constants and their values.
		 *
		 * @access private
		 * @var array
		 */
		private $constants;

		/**
		 * Singleton instance.
		 *
		 * @return Zakra_Constants
		 *
		 */
		public static function get_instance() {

			if ( ! isset( self::$instance ) ) {

				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Constructor.
		 */
		public function __construct() {

			// TODO: reuse constant instead of calling function for each constant.
			// TODO: sending constant data as constructor argument.
			$this->constants = array(
				'ZAKRA_THEME_VERSION'         => wp_get_theme( get_template() )->get( 'Version' ),
				'ZAKRA_PARENT_DIR'            => get_template_directory(),
				'ZAKRA_PARENT_INC_DIR'        => get_template_directory() . '/inc',
				'ZAKRA_PARENT_CUSTOMIZER_DIR' => get_template_directory() . '/inc/customizer',
				'ZAKRA_PARENT_CUSTOMIZER_URI' => get_template_directory_uri() . '/inc/customizer',
				'ZAKRA_PARENT_URI'            => get_template_directory_uri(),
				'ZAKRA_PARENT_ASSETS_URI'     => get_template_directory_uri() . '/assets',
				'ZAKRA_PARENT_INC_URI'        => get_template_directory_uri() . '/inc',
				'ZAKRA_PARENT_INC_ICON_URI'   => get_template_directory_uri() . '/assets/img/icons',

			);

			foreach ( $this->constants as $name => $value ) {

				$this->define_constant( $name, $value );
			}

		}


		/**
		 * Define constant safely.
		 *
		 * TODO: @since.
		 *
		 * @param string $name  Constant name.
		 * @param mixed  $value Constant value.
		 *
		 * @return void
		 */
		public function define_constant( $name, $value ) {


			if ( ! defined( $name ) ) {

				define( $name, $value );
			}
		}

	}

}

Zakra_Constants::get_instance();
