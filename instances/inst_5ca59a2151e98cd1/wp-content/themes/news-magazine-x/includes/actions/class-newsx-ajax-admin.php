<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Newsx_Ajax_Admin {
    public function __construct() {
        add_action('wp_ajax_newsx_activate_required_plugins', [$this, 'activate_required_plugins_callback']);
        add_action('wp_ajax_nopriv_newsx_activate_required_plugins', [$this, 'activate_required_plugins_callback']);
    }
  
    public function activate_required_plugins_callback() {
		if ( !isset( $_POST['nonce'] ) || !wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'newsx-activate-required-plugins' ) ) {
            return;
        }

        $slug = isset($_POST['plugin']) ? $_POST['plugin'] : false;

        if ( $slug ) {
            if ( 'news-magazine-x-core' == $slug ) {
                if ( !is_plugin_active( 'news-magazine-x-core/news-magazine-x-core.php' ) ) {
                    activate_plugin( 'news-magazine-x-core/news-magazine-x-core.php' );
                }
            } elseif ( 'post-views-counter' == $slug ) {
                if ( !is_plugin_active( 'post-views-counter/post-views-counter.php' ) ) {
                    activate_plugin( 'post-views-counter/post-views-counter.php' );
                }
            }
        }

        wp_send_json_success( 'Plugin activated' );
        wp_die();
    }

}

new Newsx_Ajax_Admin();
