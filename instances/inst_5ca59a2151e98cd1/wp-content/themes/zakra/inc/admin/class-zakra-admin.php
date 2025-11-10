<?php
/**
 * Zakra main admin class.
 *
 * @package Zakra
 */

use JsonMachine\Items;

defined( 'ABSPATH' ) || exit;

/**
 * Class Zakra_Admin
 */
class Zakra_Admin {

	/**
	 * Zakra_Admin constructor.
	 */
	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	public function register_rest_routes() {
		register_rest_route(
			'zakra/v1',
			'/settings',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'update_settings' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		( new Zakra_Changelog_Controller() )->register_routes();
	}

	/**
	 * @param WP_REST_Request $request
	 */
	public function update_settings( $request ) {
		$params = $request->get_params();
		if ( isset( $params['enable_mega_menu'] ) ) {
			$params['enable_mega_menu'] = filter_var( $params['enable_mega_menu'], FILTER_VALIDATE_BOOLEAN );
		}

		$settings = get_option( '_zakra_settings', array() );

		$settings = wp_parse_args( $params, $settings );

		update_option( '_zakra_settings', $settings );

		return rest_ensure_response( $request->get_params() );
	}

	/**
	 * Localize array for import button AJAX request.
	 */
	public function enqueue_scripts() {

		wp_enqueue_style( 'zakra-admin-style', get_template_directory_uri() . '/inc/admin/css/admin.css', array(), ZAKRA_THEME_VERSION );
		wp_enqueue_script( 'zakra-plugin-install-helper', ZAKRA_PARENT_INC_URI . '/admin/js/admin.js', array( 'jquery' ), ZAKRA_THEME_VERSION, true );

		$welcome_data = array(
			'uri'       => esc_url( zakra_is_zakra_pro_active() && zakra_plugin_version_compare( 'zakra-pro/zakra-pro.php', '3.1.0', '>=' ) ? admin_url( 'admin.php?page=zakra' ) : admin_url( 'themes.php?page=zakra' ), ),
			'btn_text'  => esc_html__( 'Processing...', 'zakra' ),
			'admin_url' => esc_url( admin_url() ),
		);

		// Only add nonce and ajaxurl if user has appropriate capabilities
		if ( current_user_can( 'manage_options' ) ) {
			$welcome_data['nonce']   = wp_create_nonce( 'zakra_demo_import_nonce' );
			$welcome_data['ajaxurl'] = admin_url( 'admin-ajax.php' );
		}

		wp_localize_script( 'zakra-plugin-install-helper', 'zakraRedirectDemoPage', $welcome_data );

		$screen = get_current_screen();
		if ( ! in_array( $screen->id, array( 'toplevel_page_zakra', 'zakra_page_zakra-starter-templates', 'appearance_page_zakra', 'appearance_page_zakra-starter-templates' ), true ) ) {
			return;
		}

		$build_dir_uri        = apply_filters( 'zakra_build_dir_uri', get_template_directory_uri() . '/assets/build/' );
		$build_dir_path       = apply_filters( 'zakra_build_dir_path', get_template_directory() . '/assets/build/' );
		$dashboard_asset_file = $build_dir_path . 'dashboard.asset.php';
		if ( file_exists( $dashboard_asset_file ) ) {
			$dashboard_asset = require $dashboard_asset_file;
			wp_enqueue_script( 'zakra-dashboard', $build_dir_uri . 'dashboard.js', $dashboard_asset['dependencies'], $dashboard_asset['version'], true );
			wp_enqueue_style( 'zakra-dashboard', $build_dir_uri . 'dashboard.css', array( 'wp-components' ), time() );
		}

		//Dashboard script localization.
		$zakra_setting = get_option( '_zakra_settings', array() );

		wp_localize_script( 'zakra-dashboard', '__ZAKRA_SETTINGS__', $zakra_setting );

		// Localize the script for dashboard.
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'wp_get_themes' ) ) {
			require_once ABSPATH . 'wp-admin/includes/theme.php';
		}
		$installed_plugin_slugs = array_keys( get_plugins() );
		$allowed_plugin_slugs   = array(
			'everest-forms/everest-forms.php',
			'user-registration/user-registration.php',
			'learning-management-system/lms.php',
			'magazine-blocks/magazine-blocks.php',
			'themegrill-demo-importer/themegrill-demo-importer.php',
		);

		$installed_theme_slugs = array_keys( wp_get_themes() );
		$current_theme         = get_stylesheet();

		$localized_scripts = apply_filters(
			'zakra_localize_admin_scripts',
			array(
				'name' => '_ZAKRA_DASHBOARD_',
				'data' => array(
					'version'              => ZAKRA_THEME_VERSION,
					'plugins'              => array_reduce(
						$allowed_plugin_slugs,
						function ( $acc, $curr ) use ( $installed_plugin_slugs ) {
							if ( in_array( $curr, $installed_plugin_slugs, true ) ) {
								if ( is_plugin_active( $curr ) ) {
									$acc[ $curr ] = 'active';
								} else {
									$acc[ $curr ] = 'inactive';
								}
							} else {
								$acc[ $curr ] = 'not-installed';
							}
							return $acc;
						},
						array()
					),
					'builder'              => zakra_maybe_enable_builder(),
					'demoUrl'              => zakra_is_zakra_pro_active() && zakra_plugin_version_compare( 'zakra-pro/zakra-pro.php', '3.1.0', '>=' ) ? admin_url( 'admin.php?page=zakra-starter-templates' ) : admin_url( 'themes.php?page=zakra-starter-templates' ),
					'demoImporter'         => is_plugin_active( 'themegrill-demo-importer/themegrill-demo-importer.php' ) ? 'active' : 'inactive',
					'zakraProUpdateNotice' => zakra_is_zakra_pro_active() && zakra_plugin_version_compare( 'zakra-pro/zakra-pro.php', '3.1.0', '<' ) ? true : false,
					'zakraProUpdateUrl'    => admin_url( 'plugins.php' ),
					'customizerUrl'        => admin_url( 'customize.php' ),
					'themes'               => array(
						'zakra'    => strpos( $current_theme, 'zakra' ) !== false ? 'active' : (
						in_array( 'zakra', $installed_theme_slugs, true ) ? 'inactive' : 'not-installed'
						),
						'colormag' => strpos( $current_theme, 'colormag' ) !== false || strpos( $current_theme, 'colormag-pro' ) !== false ? 'active' : (
						in_array( 'colormag', $installed_theme_slugs, true ) || in_array( 'colormag-pro', $installed_theme_slugs, true ) ? 'inactive' : 'not-installed'
						),
					),
					'adminUrl'             => admin_url(),
				),
			)
		);

		$handle = apply_filters( 'zakra_localize_admin_scripts_handle', 'zakra-dashboard' );
		wp_localize_script( $handle, $localized_scripts['name'], $localized_scripts['data'] );
	}
}

new Zakra_Admin();
