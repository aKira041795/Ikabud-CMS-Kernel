<?php
/**
 * Bloghash compatibility class for Header Footer Elementor plugin.
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

// Return if Elementor not active.
if ( ! class_exists( '\Elementor\Plugin' ) ) {
	return;
}

// Return if HFE not active.
if ( ! class_exists( 'Header_Footer_Elementor' ) ) {
	return false;
}

if ( ! class_exists( 'Bloghash_HFE' ) ) :

	/**
	 * HFE compatibility.
	 */
	class Bloghash_HFE {

		/**
		 * Singleton instance of the class.
		 *
		 * @var object
		 */
		private static $instance;

		/**
		 * Instance.
		 *
		 * @since 1.0.0
		 * @return Bloghash_HFE
		 */
		public static function instance() {
			if ( ! isset( self::$instance ) && ! ( self::$instance instanceof Bloghash_HFE ) ) {
				self::$instance = new Bloghash_HFE();
			}
			return self::$instance;
		}

		/**
		 * Primary class constructor.
		 *
		 * @since 1.0.0
		 * @return void
		 */
		public function __construct() {
			add_action( 'after_setup_theme', array( $this, 'add_theme_support' ) );
			add_action( 'bloghash_header', array( $this, 'do_header' ), 0 );
			add_action( 'bloghash_footer', array( $this, 'do_footer' ), 0 );
		}

		/**
		 * Add theme support
		 *
		 * @since 1.0.0
		 */
		public function add_theme_support() {
			add_theme_support( 'header-footer-elementor' );
		}

		/**
		 * Override Header
		 *
		 * @since 1.0.0
		 * @return void
		 */
		public function do_header() {
			if ( ! hfe_header_enabled() ) {
				return;
			}

			hfe_render_header();

			remove_action( 'bloghash_header', 'bloghash_topbar_output', 10 );
			remove_action( 'bloghash_header', 'bloghash_header_output', 20 );
		}

		/**
		 * Override Footer
		 *
		 * @since 1.0.0
		 * @return void
		 */
		public function do_footer() {
			if ( ! hfe_footer_enabled() ) {
				return;
			}

			hfe_render_footer();

			remove_action( 'bloghash_footer', 'bloghash_footer_output', 20 );
			remove_action( 'bloghash_footer', 'bloghash_copyright_bar_output', 30 );
		}

	}

endif;

/**
 * Returns the one Bloghash_HFE instance.
 */
function bloghash_hfe() {
	return Bloghash_HFE::instance();
}

bloghash_hfe();
