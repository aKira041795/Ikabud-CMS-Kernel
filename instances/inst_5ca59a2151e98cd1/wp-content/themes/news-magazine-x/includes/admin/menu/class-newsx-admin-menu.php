<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( !is_admin() ) {
    return;
}

class Newsx_Admin_Menu {

    public function __construct() {
        add_action( 'admin_menu', [$this, 'admin_menu'] );
		add_action( 'admin_init', [$this, 'enqueue_scripts'] );
    }

    public function admin_menu() {
        add_menu_page(
            esc_html__( 'News Magazine X', 'news-magazine-x' ),
            esc_html__( 'News Magazine X', 'news-magazine-x' ),
            'manage_options',
            'newsx-options',
            [$this, 'options_page'],
            'dashicons-art',
            59
        );
    }

    public function options_page() {
        require_once NEWSX_INCLUDES_DIR .'/admin/menu/page-content.php';
    }

    public function enqueue_scripts() {
        if ( isset($_GET['page']) && 'newsx-options' === $_GET['page'] ) {
            // Enqueue Styles
            wp_enqueue_style( 'newsx-admin-menu', NEWSX_ADMIN_URL .'/assets/css/admin-menu.css', [], NEWSX_THEME_VERSION );

            // Enqueue Scripts
            wp_enqueue_script( 'newsx-admin-menu', NEWSX_ADMIN_URL .'/assets/js/admin-menu.js', ['jquery', 'updates'], NEWSX_THEME_VERSION, true );

            // Localize Theme Scripts
            wp_localize_script( 'newsx-admin-menu', 'NewsxAdminMenu', [
                    'ajaxurl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('newsx-activate-required-plugins'),
                    'tablet_bp' => newsx_get_tablet_breakpoint(),
                    'mobile_bp' => newsx_get_mobile_breakpoint()
                ]
            );
        }
    }
}

new Newsx_Admin_Menu();
