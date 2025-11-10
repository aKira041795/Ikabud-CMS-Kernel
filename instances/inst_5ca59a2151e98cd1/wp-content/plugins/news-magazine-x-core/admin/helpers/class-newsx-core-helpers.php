<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( !is_admin() ) {
    return;
}

class Newsx_Core_Helpers {
    public static function initialize_wp_filesystem() {
        global $wp_filesystem;
    
        // Load the required WordPress file if not already included
        if ( ! function_exists( 'request_filesystem_credentials' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
    
        // Initialize the global $wp_filesystem
        if ( ! WP_Filesystem() ) {
            return new \WP_Error( 'filesystem_init_failed', __( 'Could not initialize WP_Filesystem.', 'news-magazine-x-core' ) );
        }
    
        // Return the initialized $wp_filesystem
        return $wp_filesystem;
    }

    public static function get_data_from_file( $file_path ) {
        // Initialize WP_Filesystem and handle errors
        $wp_filesystem = self::initialize_wp_filesystem();
    
        if ( is_wp_error( $wp_filesystem ) ) {
            return $wp_filesystem; // Return the error if initialization failed
        }
    
        // Attempt to read the file
        $data = $wp_filesystem->get_contents( $file_path );
    
        if ( ! $data ) {
            return new \WP_Error(
                'failed_reading_file_from_server',
                sprintf(
                    /* translators: %1$s - br HTML tag, %2$s - file path */
                    __( 'An error occurred while reading a file from your server! Tried reading file from path: %1$s%2$s.', 'news-magazine-x-core' ),
                    '<br>',
                    $file_path
                )
            );
        }
    
        // Return the file data.
        return $data;
    }

    public static function delete_kirki_font_transients() {
        global $wpdb;
        
        // Delete site-specific transients
        $transients = $wpdb->get_col("
            SELECT option_name FROM {$wpdb->options} 
            WHERE option_name LIKE '_site_transient_kirki_googlefonts%'
        ");

        foreach ($transients as $transient) {
            $transient_name = str_replace('_site_transient_', '', $transient);
            delete_site_transient($transient_name);
        }

        // Handle multisite network-wide transients
        if (is_multisite()) {
            $network_transients = $wpdb->get_col("
                SELECT meta_key FROM {$wpdb->sitemeta} 
                WHERE meta_key LIKE '_site_transient_kirki_googlefonts%'
            ");

            foreach ($network_transients as $transient) {
                $transient_name = str_replace('_site_transient_', '', $transient);
                delete_site_transient($transient_name);
            }
        }
    }
}