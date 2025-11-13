<?php
if ( ! function_exists( 'wpap_fs' ) ) {
    // Create a helper function for easy SDK access.
    function wpap_fs() {
        global $wpap_fs;

        if ( ! isset( $wpap_fs ) ) {
            // Include Freemius SDK.
            require_once dirname(__FILE__) . '/freemius/start.php';

            $wpap_fs = fs_dynamic_init( array(
                'id'                  => '13903',
                'slug'                => 'wp-post-author',
                'premium_slug'        => 'wp-post-author-pro',
                'type'                => 'plugin',
                'public_key'          => 'pk_95fbf1b20158050d436e5ef32203c',
                'is_premium'          => false,
                'is_premium_only'     => false,
                'has_addons'          => false,
                'has_paid_plans'      => true,
                'menu'                => array(
                    'slug'           => 'wp-post-author',
                    'first-path'     => 'admin.php?page=wp-post-author',
                ),
            ) );
        }

        return $wpap_fs;
    }

    // Init Freemius.
    wpap_fs();
    // Signal that SDK was initiated.
    do_action( 'wpap_fs_loaded' );
}