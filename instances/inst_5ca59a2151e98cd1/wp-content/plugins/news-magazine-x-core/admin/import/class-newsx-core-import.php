<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( !is_admin() ) {
    return;
}

class Newsx_Core_Import {
    public function __construct() {
        // Reset
        add_action('wp_ajax_newsx_reset_previous_import', [$this, 'reset_previous_import']);
        add_action('wp_ajax_nopriv_newsx_reset_previous_import', [$this, 'reset_previous_import']);

        // Import XML Template
        $xml_importer = new Newsx_Core_XML_Importer();
        add_action('wp_ajax_newsx_import_xml_template', [$xml_importer, 'import']);
        add_action('wp_ajax_nopriv_newsx_import_xml_template', [$xml_importer, 'import']);

        
        $theme_slug = wp_get_theme()->get_template();

        if ( $theme_slug === 'news-magazine-x' ) {
            // Activate Required Plugins
            add_action( 'wp_ajax_newsx_activate_required_plugins', [$this, 'activate_required_plugins'] );
            add_action( 'wp_ajax_nopriv_newsx_activate_required_plugins', [$this, 'activate_required_plugins'] );

            // Import Widgets
            $widget_importer = new Newsx_Core_Widgets_Importer();
            add_action('wp_ajax_newsx_import_widgets_data', [$widget_importer, 'import']);
            add_action('wp_ajax_nopriv_newsx_import_widgets_data', [$widget_importer, 'import']);

            // Import Custmizer Data
            $customizer_importer = new Newsx_Core_Customizer_Importer();
            add_action('wp_ajax_newsx_import_customizer_data', [$customizer_importer, 'import']);
            add_action('wp_ajax_nopriv_newsx_import_customizer_data', [$customizer_importer, 'import']);

            // Setup General Settings
            add_action('wp_ajax_newsx_setup_general_settings', [$this, 'setup_general_settings']);
            add_action('wp_ajax_nopriv_newsx_setup_general_settings', [$this, 'setup_general_settings']);
        }
    }

    public static function activate_required_plugins() {
        if ( 'POST' !== $_SERVER['REQUEST_METHOD'] || ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'newsx-core-admin' ) || !current_user_can('manage_options') ) {
            wp_send_json_error( 'Invalid request or insufficient permissions' );
            return;
        }

        if ( !isset($_POST['plugin']) ) {
            wp_send_json_error( 'No plugin specified' );
            return;
        }

        $plugin = sanitize_text_field( $_POST['plugin'] );
        $result = false;

        switch ($plugin) {
            case 'contact-form-7':
                $plugin_file = 'contact-form-7/wp-contact-form-7.php';
                break;
            case 'mailchimp-for-wp':
                $plugin_file = 'mailchimp-for-wp/mailchimp-for-wp.php';
                break;
            default:
                wp_send_json_error( 'Invalid plugin specified' );
                return;
        }

        if ( !is_plugin_active( $plugin_file ) ) {
            $result = activate_plugin( $plugin_file );
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( $result->get_error_message() );
                return;
            }
        }

        wp_send_json_success( array(
            'activated' => true,
            'plugin' => $plugin,
            'wasActive' => $result === false
        ) );
    }
    
    public static function reset_previous_import() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'newsx-core-admin' ) || !current_user_can('manage_options') ) {
            wp_send_json_error( 'Invalid nonce' );
            return;
        }
        
        // Reset Previous Import Items
        $args = [
            'post_type' => [
                'page',
                'post',
                'product',
                'attachment',
            ],
            'post_status' => 'any',
            'posts_per_page' => '-1',
            'meta_key' => '_newsx_demo_import_item' // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key	
        ];
    
        $imported_items = new WP_Query ( $args );
    
        if ( $imported_items->have_posts() ) {
            while ( $imported_items->have_posts() ) {
                $imported_items->the_post();
    
                // Delete Posts
                wp_delete_post( get_the_ID(), true );
            }
    
            // Reset
            wp_reset_postdata();
    
            $imported_terms = get_terms([
                'meta_key' => '_newsx_demo_import_item', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key	
                'posts_per_page' => -1,
                'hide_empty' => false,
            ]);
    
            if ( !empty($imported_terms) ) {
                foreach( $imported_terms as $imported_term ) {
                    // Delete Terms
                    wp_delete_term( $imported_term->term_id, $imported_term->taxonomy );
                }
            }
    
            wp_send_json_success( esc_html__('Previous Import Files have been successfully Reset.', 'news-magazine-x-core') );
        } else {
            wp_send_json_success( esc_html__('There is no Data for Reset.', 'news-magazine-x-core') );
        }
    }

    public static function setup_general_settings() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'newsx-core-admin' ) || !current_user_can('manage_options') ) {
            wp_send_json_error( 'Invalid nonce' );
            return;
        }

        // Setup Nav Menus
		$primary_menu = get_term_by( 'slug', 'newsx-primary-menu', 'nav_menu' );
		$secondary_menu = get_term_by( 'slug', 'newsx-secondary-menu', 'nav_menu' );
        $footer_menu = get_term_by( 'slug', 'newsx-footer-menu', 'nav_menu' );

		$menu_locations = [];
		
		if ( $primary_menu ) {
			$menu_locations['primary'] = $primary_menu->term_id;
		}

		if ( $secondary_menu ) {
			$menu_locations['secondary'] = $secondary_menu->term_id;
		}

        if ( $footer_menu ) {
			$menu_locations['footer'] = $footer_menu->term_id;
		}

		set_theme_mod( 'nav_menu_locations', $menu_locations );

        // Set Site Title & Tagline
        if ( isset($_POST['site_identity']) && !empty($_POST['site_identity']) ) {
            $site_title = isset($_POST['site_identity']['title']) ? sanitize_text_field(wp_unslash($_POST['site_identity']['title'])) : '';
            $site_tagline = isset($_POST['site_identity']['tagline']) ? sanitize_text_field(wp_unslash($_POST['site_identity']['tagline'])) : '';

            if ( !empty($site_title) ) {
                update_option('blogname', $site_title);
            }

            if ( !empty($site_tagline) ) {
                update_option('blogdescription', $site_tagline);
            }
        }

        // Delete "Hello World" Post
        $hello_world_post = get_page_by_path( 'hello-world', OBJECT, 'post' );

        if ( ! is_null( $hello_world_post ) ) {
            wp_delete_post( $hello_world_post->ID, true );
        }

        // Set Homepage and Posts page
        $home_page = get_page_by_path('home');
        $blog_page = get_page_by_path('blog');

        if ( null !== $home_page ) {
            update_option('show_on_front', 'page');
            update_option('page_on_front', $home_page->ID);
        }

        if ( null !== $blog_page ) {
            update_option('page_for_posts', $blog_page->ID);
        }
    }
}

new Newsx_Core_Import();
