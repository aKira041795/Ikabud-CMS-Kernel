<?php

// Exit if accessed directly.
if ( !defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin slug.
defined( 'BLOCKSPARE_CLOUDFRONT_URL' ) || define( 'BLOCKSPARE_CLOUDFRONT_URL', 'https://d3gt1urn7320t9.cloudfront.net' );
// CloudFront CDN URL
/********************************************************************************************
 * Activation & PHP version checks.
 ********************************************************************************************/

if ( !function_exists( 'blockspare_php_requirement_activation_check' ) ) {
    /**
     * Upon activation, check if we have the proper PHP version.
     * Show an error if needed and don't continue with the plugin.
     *
     * @since 1.9
     */
    function blockspare_php_requirement_activation_check()
    {

        if ( version_compare( PHP_VERSION, '5.3.0', '<' ) ) {
            deactivate_plugins( basename( __FILE__ ) );
            wp_die( sprintf(
                __( '%s"Blockspare" can not be activated. %s It requires PHP version 5.3.0 or higher, but PHP version %s is used on the site. Please upgrade your PHP version first ✌️ %s Back %s', 'blockspare' ),
                '<strong>',
                '</strong><br><br>',
                PHP_VERSION,
                '<br /><br /><a href="' . esc_url( get_dashboard_url( get_current_user_id(), 'plugins.php' ) ) . '" class="button button-primary">',
                '</a>'
            ) );
        }

    }

    register_activation_hook( __FILE__, 'blockspare_php_requirement_activation_check' );
}

/**
 * Always check the PHP version at the start.
 * If the PHP version isn't sufficient, don't continue to prevent any unwanted errors.
 *
 * @since 1.9
 */

if ( version_compare( PHP_VERSION, '5.3.0', '<' ) ) {
    if ( !function_exists( 'blockspare_php_requirement_notice' ) ) {
        function blockspare_php_requirement_notice()
        {
            printf( '<div class="notice notice-error"><p>%s</p></div>', sprintf( __( '"Blockspare" requires PHP version 5.3.0 or higher, but PHP version %s is used on the site.', 'blockspare' ), PHP_VERSION ) );
        }

    }
    add_action( 'admin_notices', 'blockspare_php_requirement_notice' );
    return;
}

/**
 * Always keep note of the Blockspare version.
 *
 * @since 2.0
 */

if ( !function_exists( 'blockspare_version_upgrade_check' ) ) {
    function blockspare_version_upgrade_check()
    {
        // This is triggered only when V1 was previously activated, and this is the first time V2 is activated.
        // Will not trigger after successive V2 activations.
        if ( get_option( 'blockspare_activation_date' ) && !get_option( 'blockspare_current_version_installed' ) ) {
            update_option( 'blockspare_current_version_installed', '1' );
        }
        // Always check the current version installed. Trigger if it changes.

        if ( get_option( 'blockspare_current_version_installed' ) !== BLOCKSPARE_VERSION ) {
            do_action( 'blockspare_version_upgraded', get_option( 'blockspare_current_version_installed' ), BLOCKSPARE_VERSION );
            update_option( 'blockspare_current_version_installed', BLOCKSPARE_VERSION );
        }

    }

    add_action( 'admin_menu', 'blockspare_version_upgrade_check', 1 );
}

/********************************************************************************************
 * END Activation & PHP version checks.
 ********************************************************************************************/

/**
 * Ready for Welcome screen.
 */

include(BLOCKSPARE_PLUGIN_DIR . 'admin/admin-init.php');
include(BLOCKSPARE_PLUGIN_DIR . 'admin/admin-block-list.php');
