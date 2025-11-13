<?php
if ( ! function_exists( 'blockspare_fs' ) ) {
    // Create a helper function for easy SDK access.
    function blockspare_fs() {
        global $blockspare_fs;

        if ( ! isset( $blockspare_fs ) ) {
            // Include Freemius SDK.
            require_once dirname(__FILE__) . '/freemius/start.php';

            $blockspare_fs = fs_dynamic_init( array(
                'id'                  => '9379',
                'slug'                => 'blockspare',
                'premium_slug'        => 'blockspare-pro',
                'type'                => 'plugin',
                'public_key'          => 'pk_29829adcdce7852dbe329f64cd6f3',
                'is_premium'          => false,
                'is_premium_only'     => false,
                'has_addons'          => false,
                'has_paid_plans'      => true,
                'menu'                => array(
                    'slug'           => 'blockspare',
                    'first-path'     => 'admin.php?page=blockspare',
                    'support'        => false,
                ),
            ) );
        }

        return $blockspare_fs;
    }

    // Init Freemius.
    blockspare_fs();
    // Signal that SDK was initiated.
    do_action( 'blockspare_fs_loaded' );
}