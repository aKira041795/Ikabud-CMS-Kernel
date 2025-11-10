<?php //phpcs:ignore
/**
 * Theme functions and definitions.
 *
 * @package BlogHash
 * @author Peregrine Themes
 * @since   1.0.0
 */

/**
 * Main Bloghash class.
 *
 * @since 1.0.0
 */
final class Bloghash {

	/**
	 * Theme options
	 *
	 * @since 1.0.0
	 * @var object
	 */
	public $options;

	/**
	 * Theme fonts
	 *
	 * @since 1.0.0
	 * @var object
	 */
	public $fonts;

	/**
	 * Theme icons
	 *
	 * @since 1.0.0
	 * @var object
	 */
	public $icons;

	/**
	 * Theme customizer
	 *
	 * @since 1.0.0
	 * @var object
	 */
	public $customizer;

	/**
	 * Theme admin
	 *
	 * @since 1.0.0
	 * @var object
	 */
	public $admin;

	/**
	 * Singleton instance of the class.
	 *
	 * @since 1.0.0
	 * @var object
	 */
	private static $instance;
	/**
	 * Theme version.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public $version = '1.0.25';
	/**
	 * Main Bloghash Instance.
	 *
	 * Insures that only one instance of Bloghash exists in memory at any one
	 * time. Also prevents needing to define globals all over the place.
	 *
	 * @since 1.0.0
	 * @return Bloghash
	 */
	public static function instance() {
		if ( ! isset( self::$instance ) && ! ( self::$instance instanceof Bloghash ) ) {
			self::$instance = new Bloghash();
			self::$instance->constants();
			self::$instance->includes();
			self::$instance->objects();
			// Hook now that all of the Bloghash stuff is loaded.
			do_action( 'bloghash_loaded' );
		}
		return self::$instance;
	}

	/**
	 * Setup constants.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function constants() {
		if ( ! defined( 'BLOGHASH_THEME_VERSION' ) ) {
			define( 'BLOGHASH_THEME_VERSION', $this->version );
		}
		if ( ! defined( 'BLOGHASH_THEME_URI' ) ) {
			define( 'BLOGHASH_THEME_URI', get_parent_theme_file_uri() );
		}
		if ( ! defined( 'BLOGHASH_THEME_PATH' ) ) {
			define( 'BLOGHASH_THEME_PATH', get_parent_theme_file_path() );
		}
	}
	/**
	 * Include files.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function includes() {
		require_once BLOGHASH_THEME_PATH . '/inc/common.php';
		require_once BLOGHASH_THEME_PATH . '/inc/helpers.php';
		require_once BLOGHASH_THEME_PATH . '/inc/widgets.php';
		require_once BLOGHASH_THEME_PATH . '/inc/template-tags.php';
		require_once BLOGHASH_THEME_PATH . '/inc/template-parts.php';
		require_once BLOGHASH_THEME_PATH . '/inc/icon-functions.php';
		require_once BLOGHASH_THEME_PATH . '/inc/breadcrumbs.php';
		require_once BLOGHASH_THEME_PATH . '/inc/class-bloghash-dynamic-styles.php';
		// Core.
		require_once BLOGHASH_THEME_PATH . '/inc/core/class-bloghash-options.php';
		require_once BLOGHASH_THEME_PATH . '/inc/core/class-bloghash-enqueue-scripts.php';
		require_once BLOGHASH_THEME_PATH . '/inc/core/class-bloghash-fonts.php';
		require_once BLOGHASH_THEME_PATH . '/inc/core/class-bloghash-theme-setup.php';
		// Compatibility.
		require_once BLOGHASH_THEME_PATH . '/inc/compatibility/woocommerce/class-bloghash-woocommerce.php';
		require_once BLOGHASH_THEME_PATH . '/inc/compatibility/socialsnap/class-bloghash-socialsnap.php';
		require_once BLOGHASH_THEME_PATH . '/inc/compatibility/class-bloghash-wpforms.php';
		require_once BLOGHASH_THEME_PATH . '/inc/compatibility/class-bloghash-jetpack.php';
		require_once BLOGHASH_THEME_PATH . '/inc/compatibility/class-bloghash-beaver-themer.php';
		require_once BLOGHASH_THEME_PATH . '/inc/compatibility/class-bloghash-elementor.php';
		require_once BLOGHASH_THEME_PATH . '/inc/compatibility/class-bloghash-elementor-pro.php';
		require_once BLOGHASH_THEME_PATH . '/inc/compatibility/class-bloghash-hfe.php';

		if ( is_admin() ) {
			require_once BLOGHASH_THEME_PATH . '/inc/utilities/class-bloghash-plugin-utilities.php';
			require_once BLOGHASH_THEME_PATH . '/inc/admin/class-bloghash-admin.php';

		}
		new Bloghash_Enqueue_Scripts();
		// Customizer.
		require_once BLOGHASH_THEME_PATH . '/inc/customizer/class-bloghash-customizer.php';
		require_once BLOGHASH_THEME_PATH . '/inc/customizer/customizer-callbacks.php';
		require_once BLOGHASH_THEME_PATH . '/inc/customizer/class-bloghash-section-ordering.php';
	}
	/**
	 * Setup objects to be used throughout the theme.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function objects() {

		bloghash()->options    = new Bloghash_Options();
		bloghash()->fonts      = new Bloghash_Fonts();
		bloghash()->icons      = new Bloghash_Icons();
		bloghash()->customizer = new Bloghash_Customizer();
		if ( is_admin() ) {
			bloghash()->admin = new Bloghash_Admin();
		}
	}
}

/**
 * The function which returns the one Bloghash instance.
 *
 * Use this function like you would a global variable, except without needing
 * to declare the global.
 *
 * Example: <?php $bloghash = bloghash(); ?>
 *
 * @since 1.0.0
 * @return object
 */
function bloghash() {
	return Bloghash::instance();
}

bloghash();

