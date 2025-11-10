<?php
if ( ! function_exists( 'templatespare_fs' ) ) {
    // Create a helper function for easy SDK access.
    function templatespare_fs() {
        global $templatespare_fs;

        if ( ! isset( $templatespare_fs ) ) {
            // Include Freemius SDK.
            require_once dirname(__FILE__) . '/freemius/start.php';

            $templatespare_fs = fs_dynamic_init( array(
                'id'                  => '7637',
                'slug'                => 'all_themes_plan',
                'premium_slug'        => 'all_themes_plan',
                'type'                => 'bundle',
                'public_key'          => 'pk_5f4c56aa7d0bac4236a8e650bb520',
                'is_premium'          => false,
                'is_premium_only'     => false,
                'has_addons'          => false,
                'has_paid_plans'      => true,
                'menu'                => array(
                    'slug'           => 'templatespare-main-dashboard',
                    'first-path'     => 'admin.php?page=wizard-page',
                    'support'        => false,
                ),
            ) );
        }

        return $templatespare_fs;
    }

    // Init Freemius.
    templatespare_fs();
    // Signal that SDK was initiated.
    do_action( 'templatespare_fs_loaded' );
}