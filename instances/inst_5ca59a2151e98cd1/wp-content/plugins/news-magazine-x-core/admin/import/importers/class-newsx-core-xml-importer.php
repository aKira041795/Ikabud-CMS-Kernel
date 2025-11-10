<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( !is_admin() ) {
    return;
}

class Newsx_Core_XML_Importer {
    public static function import() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'newsx-core-admin' ) || !current_user_can('manage_options') ) {
            wp_send_json_error( 'Invalid nonce' );
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/class-wp-importer.php';
        require_once NEWSX_CORE_PATH . 'admin/import/importers/logger.php';
        require_once NEWSX_CORE_PATH . 'admin/import/importers/wxr-importer.php';

        // Turn off PHP output compression
        $previous = error_reporting( error_reporting() ^ E_WARNING );
        ini_set( 'output_buffering', 'off' );
        ini_set( 'zlib.output_compression', false );
        error_reporting( $previous );

        if ( $GLOBALS['is_nginx'] ) {
            // Setting this header instructs Nginx to disable fastcgi_buffering
            // and disable gzip for this request.
            header( 'X-Accel-Buffering: no' );
            header( 'Content-Encoding: none' );
        }

        // Start the event stream.
        header( 'Content-Type: text/event-stream' );
    
        // Time to run the import
        set_time_limit( 0 );

        // Ensure we're not buffered.
        wp_ob_end_flush_all();
        flush();

        $template = isset( $_POST['template'] ) ? $_POST['template'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $templateType = isset( $_POST['template_type'] ) ? $_POST['template_type'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

        // Ensure XML file exists
		if ( $templateType === 'pro' && defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
			$import_file_path = NEWSX_CORE_PRO_PATH . 'admin/import/data/'. sanitize_file_name(wp_unslash($template)) .'/demo.xml';
		} else {
			$import_file_path = NEWSX_CORE_PATH . 'admin/import/data/'. sanitize_file_name(wp_unslash($template)) .'/demo.xml';
		}

        if ( ! file_exists( $import_file_path ) ) {
            wp_send_json_error( 'XML file not found' );
            return;
        }
    
        // Logger and Importer Setup
        $logger = new Newsx_Core_WP_Importer_Logger_ServerSentEvents();
        $importer = new Newsx_Core_WXR_Importer( [ 'fetch_attachments' => true ] );
        $importer->set_logger( $logger );

        // Flush once more.
        flush();
    
        // Run Import
        $err = $importer->import( $import_file_path );

        // Let the browser know we're done.
        $complete = array(
            'action' => 'complete',
            'error' => false,
        );
        if ( is_wp_error( $err ) ) {
            $complete['error'] = $err->get_error_message();
        }
    
        // Send Success Response
        wp_send_json_success( 'XML Import complete.' );
    }
}
