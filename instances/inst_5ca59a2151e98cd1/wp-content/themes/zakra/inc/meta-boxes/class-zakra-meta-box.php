<?php

/**
 * Base meta box class.
 *
 * @package zakra
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Zakra_Meta_Box
 */
class Zakra_Meta_Box {



	/**
	 * Keep record if meta box is saved once.
	 *
	 * @var boolean
	 */
	private static $saved_meta_boxes = false;

	/**
	 * Hook into the appropriate actions when the class is constructed.
	 */
	public function __construct() {
		if ( $this->is_classic_editor_active() ) {
			add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
			add_action( 'admin_print_styles-post-new.php', array( $this, 'enqueue' ) );
			add_action( 'admin_print_styles-post.php', array( $this, 'enqueue' ) );
			add_action( 'save_post', array( $this, 'save_meta_boxes' ), 1, 2 );
		} else {
			$this->register_meta_fields();
		}
		add_action(
			'enqueue_block_editor_assets',
			function () {
				$meta_asset_file = get_template_directory() . '/assets/build/meta.asset.php';
				if ( get_current_screen()->id === 'customize' ) {
					return;
				}
				if ( file_exists( $meta_asset_file ) ) {
					$meta_asset = require $meta_asset_file;
					wp_enqueue_script( 'zakra-meta', get_template_directory_uri() . '/assets/build/meta.js', $meta_asset['dependencies'], $meta_asset['version'], true );
					wp_enqueue_style( 'zakra-meta', get_template_directory_uri() . '/assets/build/meta.css', array(), time() );
				}
			}
		);

		// Save Page Settings Meta Boxes.
		add_action( 'zakra_process_page_settings_meta', 'Zakra_Meta_Box_Page_Settings::save', 10, 2 );
	}
	private function is_classic_editor_active() {

		include_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( is_plugin_active( 'classic-editor/classic-editor.php' ) ) {
			return true;
		}

		if ( ! apply_filters( 'use_block_editor_for_post', true, get_post() ) ) {
			return true;
		}

		return false;
	}
	private function register_meta_fields() {
		register_post_meta(
			'',
			'zakra_sidebar_layout',
			array(
				'show_in_rest'  => true,
				'single'        => true,
				'default'       => 'customizer',
				'type'          => 'string',
				'auth_callback' => '__return_true',
			)
		);
		register_post_meta(
			'',
			'zakra_remove_content_margin',
			array(
				'show_in_rest'  => true,
				'single'        => true,
				'default'       => 0,
				'type'          => 'boolean',
				'auth_callback' => '__return_true',
			)
		);
		register_post_meta(
			'',
			'zakra_sidebar',
			array(
				'show_in_rest'  => true,
				'single'        => true,
				'default'       => 'customizer',
				'type'          => 'string',
				'auth_callback' => '__return_true',
			)
		);
		register_post_meta(
			'',
			'zakra_transparent_header',
			array(
				'show_in_rest'  => true,
				'single'        => true,
				'default'       => 'customizer',
				'type'          => 'string',
				'auth_callback' => '__return_true',
			)
		);
		register_post_meta(
			'',
			'zakra_logo',
			array(
				'show_in_rest'  => true,
				'single'        => true,
				'default'       => 0,
				'type'          => 'integer',
				'auth_callback' => '__return_true',
			)
		);
		register_post_meta(
			'',
			'zakra_main_header_style',
			array(
				'show_in_rest'  => true,
				'single'        => true,
				'default'       => 'default',
				'type'          => 'string',
				'auth_callback' => '__return_true',
			)
		);
		register_post_meta(
			'',
			'zakra_menu_item_color',
			array(
				'show_in_rest'  => true,
				'single'        => true,
				'default'       => get_theme_mod( 'zakra_menu_item_color', '' ),
				'type'          => 'string',
				'auth_callback' => '__return_true',
			)
		);
		register_post_meta(
			'',
			'zakra_menu_item_hover_color',
			array(
				'show_in_rest'  => true,
				'single'        => true,
				'default'       => get_theme_mod( 'zakra_menu_item_hover_color', '' ),
				'type'          => 'string',
				'auth_callback' => '__return_true',
			)
		);
		register_post_meta(
			'',
			'zakra_menu_item_active_color',
			array(
				'show_in_rest'  => true,
				'single'        => true,
				'default'       => get_theme_mod( 'zakra_menu_item_active_color', '' ),
				'type'          => 'string',
				'auth_callback' => '__return_true',
			)
		);
		register_post_meta(
			'',
			'zakra_menu_active_style',
			array(
				'show_in_rest'  => true,
				'single'        => true,
				'default'       => '',
				'type'          => 'string',
				'auth_callback' => '__return_true',
			)
		);
		register_post_meta(
			'',
			'zakra_page_header',
			array(
				'show_in_rest'  => true,
				'single'        => true,
				'default'       => 1,
				'type'          => 'boolean',
				'auth_callback' => '__return_true',
			)
		);
	}

	/**
	 * Adds the meta box container.
	 */
	public function add_meta_boxes() {
		add_meta_box(
			'zakra-page-setting',
			esc_html__( 'Page Settings', 'zakra' ),
			'Zakra_Meta_Box_Page_Settings::render',
			array(
				'post',
				'page',
			)
		);
	}

	/**
	 * Enqueue scripts.
	 */
	public function enqueue() {
		wp_enqueue_script( 'jquery-ui-tabs' );
		wp_enqueue_script( 'zakra-meta-box', ZAKRA_PARENT_INC_URI . '/meta-boxes/assets/js/meta-box.js', array( 'jquery-ui-tabs' ), ZAKRA_THEME_VERSION, true );
		wp_enqueue_style( 'zakra-meta-box', ZAKRA_PARENT_INC_URI . '/meta-boxes/assets/css/meta-box.css', array(), ZAKRA_THEME_VERSION );
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
	}

	/**
	 * Handles saving the meta box.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 *
	 * @return null
	 */
	public function save_meta_boxes( $post_id, $post ) {
		// Check the nonce.
		if ( ! isset( $_POST['zakra_meta_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['zakra_meta_nonce'] ), 'zakra_nonce_action' ) ) {
			return;
		}

		// $post_id and $post are required.
		if ( empty( $post_id ) || empty( $post ) || self::$saved_meta_boxes ) {
			return;
		}

		// Check for revisions or autosaves.
		if ( defined( 'DOING_AUTOSAVE' ) || is_int( wp_is_post_revision( $post ) ) || is_int( wp_is_post_autosave( $post ) ) ) {
			return;
		}

		// Check the post being saved == the $post_id to prevent triggering this call for other save_post events.
		if ( empty( $_POST['post_ID'] ) || intval( $_POST['post_ID'] ) !== $post_id ) {
			return;
		}

		// Check user's permisstion.
		if ( isset( $_POST['post_type'] ) && ( 'page' === $_POST['post_type'] ) ) {
			if ( ! current_user_can( 'edit_page', $post_id ) ) {
				return $post_id;
			}
		} elseif ( ! current_user_can( 'edit_post', $post_id ) ) {
			return $post_id;
		}

		self::$saved_meta_boxes = true;

		// Trigger action.
		$process_actions = array( 'page_settings' );
		foreach ( $process_actions as $process_action ) {
			do_action( 'zakra_process_' . $process_action . '_meta', $post_id, $post );
		}
	}
}

new Zakra_Meta_Box();
