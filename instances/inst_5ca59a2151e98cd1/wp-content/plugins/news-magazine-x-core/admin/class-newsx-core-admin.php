<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://https://wp-royal-themes.com/
 * @since      1.0.0
 *
 * @package    Newsx_Core
 * @subpackage Newsx_Core/admin
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    Newsx_Core
 * @subpackage Newsx_Core/admin
 * @author     WP Royal <info.wproyal@gmail.com>
 */
class Newsx_Core_Admin {

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param      string    $plugin_name       The name of this plugin.
	 * @param      string    $version    The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version = $version;

		// Include Files.
		$this->_includes();

	}

	/**
	 * Include Files.
	 *
	 * @since    1.0.0
	 */
	public function _includes() {
		require_once NEWSX_CORE_PATH . 'admin/helpers/class-newsx-core-helpers.php';
		require_once NEWSX_CORE_PATH . 'admin/import/importers/class-newsx-core-xml-importer.php';
		require_once NEWSX_CORE_PATH . 'admin/import/importers/class-newsx-core-widgets-importer.php';
		require_once NEWSX_CORE_PATH . 'admin/import/importers/class-newsx-core-customizer-importer.php';
		require_once NEWSX_CORE_PATH . 'admin/import/class-newsx-core-import.php';
	}

	/**
	 * Register the stylesheets for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles() {
		if ( isset($_GET['page']) && ('newsx-options' === $_GET['page'] || 'news-magazine-x-core' === $_GET['page']) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'assets/css/newsx-core-admin.css', array(), $this->version, 'all' );
		}

	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts() {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Newsx_Core_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Newsx_Core_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		if ( isset($_GET['page']) && ('newsx-options' === $_GET['page'] || 'news-magazine-x-core' === $_GET['page']) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        	wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'assets/js/newsx-core-admin.js', array( 'jquery' ), $this->version, false );

			// Localize script
			wp_localize_script( $this->plugin_name, 'NEWSXCoreAdmin', [
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'newsx-core-admin' ),
			]);
		}

	}

	/**
	 * Add custom user socials contact methods.
	 *
	 * @since    1.0.0
	 */
	public function newsx_add_user_socials_contactmethods( $contactmethods ) {
		$contactmethods['job'] = 'Author Job / Title';
		$contactmethods['facebook'] = 'Facebook';
		$contactmethods['x_twitter'] = 'X (Twitter)';
		$contactmethods['instagram'] = 'Instagram';
		$contactmethods['pinterest'] = 'Pinterest';
		$contactmethods['linkedin'] = 'LinkedIn';
		$contactmethods['tumblr'] = 'Tumblr';
		$contactmethods['flickr'] = 'Flickr';
		$contactmethods['skype'] = 'Skype';
		$contactmethods['snapchat'] = 'Snapchat';
		$contactmethods['youtube'] = 'YouTube';
		$contactmethods['digg'] = 'Digg';
		$contactmethods['dribbble'] = 'Dribbble';
		$contactmethods['soundcloud'] = 'SoundCloud';
		$contactmethods['vimeo'] = 'Vimeo';
		$contactmethods['reddit'] = 'Reddit';
		$contactmethods['vkontakte'] = 'VKontakte';
		$contactmethods['telegram'] = 'Telegram';
		$contactmethods['whatsapp'] = 'WhatsApp';
		$contactmethods['rss'] = 'RSS';
	
		return $contactmethods;
	}

	/**
	 * Add admin menu page
	 *
	 * @since    1.0.0
	 */
	public function add_admin_menu() {
		add_menu_page(
			'News Magazine X',
			'News Magazine X',
			'manage_options',
			'news-magazine-x-core',
			[$this, 'render_admin_page'],
			'dashicons-admin-generic',
			59
		);
	}

	/**
	 * Render admin page content
	 *
	 * @since    1.0.0
	 */
	public function render_admin_page() {
		require_once NEWSX_CORE_PATH . 'admin/partials/newsx-core-admin-display.php';
	}

}
