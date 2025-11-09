<?php

/**
 * Zakra compatibility class for Elementor Header Footer.
 *
 * @package zakra
 */

/**
 * Do not allow direct script access.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\Elementor\Plugin' ) || ! class_exists( 'Header_Footer_Elementor' ) ) {
	return;
}

if ( ! class_exists( 'Zakra_Elementor_Header_Footer' ) ) :

	/**
	 * Elementor compatibility.
	 */
	class Zakra_Elementor_Header_Footer {


		/**
		 * Singleton instance of the class.
		 *
		 * @var object
		 */
		private static $instance;

		/**
		 * Elementor location manager
		 *
		 * @var object
		 */
		public $elementor_location_manager;

		/**
		 * Instance.
		 *
		 * @return Zakra_Elementor_Header_Footer
		 */
		public static function instance() {

			if ( ! isset( self::$instance ) && ! ( self::$instance instanceof Zakra_Elementor_Header_Footer ) ) {
				self::$instance = new Zakra_Elementor_Header_Footer();
			}

			return self::$instance;
		}

		/**
		 * Constructor.
		 */
		public function __construct() {
			$this->init_hooks();
		}

		/**
		 * Run all the Actions / Filters.
		 */
		private function init_hooks() {
				add_action( 'hfe_header', 'zakra_main_start', 11 );
				add_action( 'hfe_header', 'zakra_content_start', 12 );
				add_action( 'hfe_footer_before', 'zakra_content_end' );
		}
	}

	endif;

Zakra_Elementor_Header_Footer::instance();
