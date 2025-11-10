<?php
/**
 * Dashboard page class.
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Zakra_Dashboard class.
 */
class Zakra_Dashboard {

	/**
	 * Holds single instance of this class.
	 *
	 * @var Zakra_Dashboard
	 */
	private static $instance = null;

	/**
	 * Holds demo packages.
	 *
	 * @var array|object
	 */
	public $demo_packages;

	/**
	 * Theme.
	 *
	 * @var WP_Theme|null
	 */
	public $theme = null;

	/**
	 * Tabs.
	 *
	 * @var array
	 */
	public $tabs = array();

	/**
	 * Active tab.
	 *
	 * @var string
	 */
	public $active_tab = '';

	/**
	 * Instance.
	 *
	 * @return Zakra_Dashboard
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->setup_hooks();
	}
	/**
	 * Setup hooks.
	 *
	 * @return void
	 */
	private function setup_hooks() {
		add_action( 'admin_menu', array( $this, 'create_menu' ) );
		add_action( 'in_admin_header', array( $this, 'hide_admin_notices' ) );
	}

	/**
	 * Create dashboard menu.
	 *
	 * @return void
	 */
	public function create_menu() {
		$this->theme = is_child_theme() ? wp_get_theme()->parent() : wp_get_theme();

		add_theme_page(
			$this->get_menu_name(),
			$this->get_menu_name(),
			$this->get_menu_capability(),
			$this->get_menu_slug(),
			array(
				$this,
				'render_dashboard_page',
			)
		);

		if ( is_plugin_active( 'themegrill-demo-importer/themegrill-demo-importer.php' ) ) {
				add_theme_page(
					__( 'Demo Importer', 'zakra' ),
					__( 'Demo Importer', 'zakra' ),
					$this->get_menu_capability(),
					'zakra-starter-templates',
					array(
						$this,
						'render_starter_templates_tab',
					)
				);
			remove_submenu_page( 'themes.php', 'demo-importer' );
		}
	}

	/**
	 * Get menu capability.
	 *
	 * @return string
	 */
	public function get_menu_capability() {
		return apply_filters( 'zakra_dashboard_menu_capability', 'edit_theme_options' );
	}

	/**
	 * Get menu name.
	 *
	 * @return string
	 */
	public function get_menu_name() {
		return apply_filters( 'zakra_dashboard_menu_title', 'Zakra' );
	}

	/**
	 * Get menu slug.
	 *
	 * @return string
	 */
	public function get_menu_slug() {
		return apply_filters( 'zakra_dashboard_menu_slug', 'zakra' );
	}

	/**
	 * Hide admin notices from BlockArt admin pages.
	 */
	public function hide_admin_notices() {
		// Bail if we're not on a Zakra screen or page.
		$_page = sanitize_text_field( $_GET['page'] ?? '' );

		if ( ! in_array( $_page, [ 'zakra', 'zakra-starter-templates' ], true ) ) {
			return;
		}

		global $wp_filter;
		$ignore_notices = apply_filters( 'zakra_ignore_hide_admin_notices', array() );

		foreach ( array( 'user_admin_notices', 'admin_notices', 'all_admin_notices' ) as $wp_notice ) {
			if ( empty( $wp_filter[ $wp_notice ] ) ) {
				continue;
			}

			$hook_callbacks = $wp_filter[ $wp_notice ]->callbacks;

			if ( empty( $hook_callbacks ) || ! is_array( $hook_callbacks ) ) {
				continue;
			}

			foreach ( $hook_callbacks as $priority => $hooks ) {
				foreach ( $hooks as $name => $callback ) {
					if ( ! empty( $name ) && in_array( $name, $ignore_notices, true ) ) {
						continue;
					}
					if (
						! empty( $callback['function'] ) &&
						! is_a( $callback['function'], '\Closure' ) &&
						isset( $callback['function'][0], $callback['function'][1] ) &&
						is_object( $callback['function'][0] ) &&
						in_array( $callback['function'][1], $ignore_notices, true )
					) {
						continue;
					}
					unset( $wp_filter[ $wp_notice ]->callbacks[ $priority ][ $name ] );
				}
			}
		}
	}

	/**
	 * Dashboard page.
	 *
	 * @return void
	 */
	public function render_dashboard_page() {

		// Include main layout view file.
		include __DIR__ . '/views/layout.php';
		/**
		 * Runs before the dashboard page.
		 *
		 * @param Zakra_Dashboard $this Dashboard page.
		 */
		//      do_action( 'zakra_dashboard_page_init', $this );

		//      if ( isset( $_GET['demo'] ) ) {
		//          if ( is_plugin_active( 'themegrill-demo-importer/themegrill-demo-importer.php' ) ) {
		//              wp_enqueue_style( 'tg-demo-importer' );
		//              wp_enqueue_script( 'tg-demo-importer' );
		//              $this->demo_packages = get_transient( 'themegrill_demo_importer_packages' );
		//              include plugin_dir_path( TGDM_PLUGIN_FILE ) . '/includes/admin/views/html-admin-page-importer.php';
		//              return;
		//          }
		//
		//          include __DIR__ . '/views/starter-templates.php';
		//      }
	}


	/**
	 * Render starter templates tab.
	 *
	 * @return void
	 */
	public function render_starter_templates_tab() {
		if ( is_plugin_active( 'themegrill-demo-importer/themegrill-demo-importer.php' ) ) {
			wp_enqueue_style( 'tg-demo-importer' );
			wp_enqueue_script( 'tg-demo-importer' );
			$this->demo_packages = get_transient( 'themegrill_demo_importer_packages' );
			include __DIR__ . '/views/header.php';
		}

		//      include __DIR__ . '/views/starter-templates.php';
	}
}

Zakra_Dashboard::instance();
