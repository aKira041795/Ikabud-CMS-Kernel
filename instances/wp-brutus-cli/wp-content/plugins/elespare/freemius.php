<?php
if ( ! function_exists( 'elespare_fs' ) ) {
    // Create a helper function for easy SDK access.
    function elespare_fs() {
        global $elespare_fs;

        if ( ! isset( $elespare_fs ) ) {
            // Include Freemius SDK.
            require_once dirname(__FILE__) . '/freemius/start.php';

            $elespare_fs = fs_dynamic_init( array(
                'id'                  => '9051',
                'slug'                => 'elespare',
                'premium_slug'        => 'elespare-pro',
                'type'                => 'plugin',
                'public_key'          => 'pk_78419e95afc674082d48231297c93',
                'is_premium'          => false,
                'is_premium_only'     => false,
                'has_addons'          => false,
                'has_paid_plans'      => true,
                'menu'                => array(
                    'slug'           => 'elespare_dashboard',
                    'support'        => false,
                ),
            ) );
        }

        return $elespare_fs;
    }

    // Init Freemius.
    elespare_fs();
    // Signal that SDK was initiated.
    do_action( 'elespare_fs_loaded' );
}