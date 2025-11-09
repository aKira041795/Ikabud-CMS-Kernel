<?php
/**
 * Bloghash compatibility class for Elementor Pro.
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
if ( ! class_exists( '\Elementor\Plugin' ) || ! class_exists( 'ElementorPro\Modules\ThemeBuilder\Module' ) ) {
	return;
}

// PHP 5.3+ is required.
if ( ! version_compare( PHP_VERSION, '5.3', '>=' ) ) {
	return;
}

if ( ! class_exists( 'Bloghash_Elementor_Pro' ) ) :

	/**
	 * Elementor compatibility.
	 */
	class Bloghash_Elementor_Pro {

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
		 * @since 1.0.0
		 * @return Bloghash_Elementor_Pro
		 */
		public static function instance() {
			if ( ! isset( self::$instance ) && ! ( self::$instance instanceof Bloghash_Elementor_Pro ) ) {
				self::$instance = new Bloghash_Elementor_Pro();
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

			// Register theme locations.
			add_action( 'elementor/theme/register_locations', array( $this, 'register_locations' ) );

			// Override templates.
			add_action( 'bloghash_header', array( $this, 'do_header' ), 0 );
			add_action( 'bloghash_footer', array( $this, 'do_footer' ), 0 );
			add_action( 'bloghash_content_404', array( $this, 'do_content_none' ), 0 );
			add_action( 'bloghash_content_single', array( $this, 'do_content_single_post' ), 0 );
			add_action( 'bloghash_content_page', array( $this, 'do_content_single_page' ), 0 );
			add_action( 'bloghash_content_archive', array( $this, 'do_archive' ), 0 );
		}

		/**
		 * Register Theme Location for Elementor.
		 *
		 * @param object $manager Elementor object.
		 * @since 1.0.0
		 * @return void
		 */
		public function register_locations( $manager ) {
			$manager->register_all_core_location();

			$this->elementor_location_manager = \ElementorPro\Modules\ThemeBuilder\Module::instance()->get_locations_manager(); // phpcs:ignore PHPCompatibility.Syntax.NewDynamicAccessToStatic.Found
		}

		/**
		 * Override Header.
		 *
		 * @since 1.0.0
		 * @return void
		 */
		public function do_header() {
			$did_location = $this->elementor_location_manager->do_location( 'header' );

			if ( $did_location ) {
				remove_action( 'bloghash_header', 'bloghash_topbar_output', 10 );
				remove_action( 'bloghash_header', 'bloghash_header_output', 20 );
			}
		}

		/**
		 * Override Footer.
		 *
		 * @since 1.0.0
		 * @return void
		 */
		public function do_footer() {
			$did_location = $this->elementor_location_manager->do_location( 'footer' );

			if ( $did_location ) {
				remove_action( 'bloghash_footer', 'bloghash_footer_output', 20 );
				remove_action( 'bloghash_footer', 'bloghash_copyright_bar_output', 30 );
			}
		}

		/**
		 * Override Footer.
		 *
		 * @since 1.0.0
		 * @return void
		 */
		public function do_content_none() {
			if ( ! is_404() ) {
				return;
			}

			$did_location = $this->elementor_location_manager->do_location( 'single' );

			if ( $did_location ) {
				remove_action( 'bloghash_content_404', 'bloghash_404_page_content' );
			}
		}

		/**
		 * Override Single Post.
		 *
		 * @since 1.0.0
		 * @return void
		 */
		public function do_content_single_post() {
			$did_location = $this->elementor_location_manager->do_location( 'single' );

			if ( $did_location ) {
				remove_action( 'bloghash_content_single', 'bloghash_content_single' );
				remove_action( 'bloghash_after_singular', 'bloghash_output_comments' );
			}
		}

		/**
		 * Override Single Page.
		 *
		 * @since 1.0.0
		 * @return void
		 */
		public function do_content_single_page() {
			$did_location = $this->elementor_location_manager->do_location( 'single' );

			if ( $did_location ) {
				remove_action( 'bloghash_content_page', 'bloghash_content_page' );
				remove_action( 'bloghash_after_singular', 'bloghash_output_comments' );
			}
		}

		/**
		 * Override Archive.
		 *
		 * @since 1.0.0
		 * @return void
		 */
		public function do_archive() {
			$did_location = $this->elementor_location_manager->do_location( 'archive' );

			if ( $did_location ) {
				remove_action( 'bloghash_before_content', 'bloghash_archive_info' );
				remove_action( 'bloghash_content_archive', 'bloghash_content' );
			}
		}
	}

endif;

/**
 * Returns the one Bloghash_Elementor_Pro instance.
 */
function bloghash_elementor_pro() {
	return Bloghash_Elementor_Pro::instance();
}

bloghash_elementor_pro();
