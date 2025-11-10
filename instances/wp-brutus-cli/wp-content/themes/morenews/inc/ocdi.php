<?php
/**
 * OCDI support.
 *
 * @package MoreNews
 */

// Disable PT branding.
add_filter( 'pt-ocdi/disable_pt_branding', '__return_true' );

/**
 * OCDI after import.
 *
 * @since 1.0.0
 */
function morenews_ocdi_after_import() {


    // Assign navigation menu locations.
    $menu_location_details = array(
        'aft-primary-nav'     => 'main-menu-items',
        'aft-footer-nav'      => 'footer-menu-items',
        'aft-social-nav'      => 'social-menu-items',
    );

    if ( ! empty( $menu_location_details ) ) {
        $navigation_settings = array();
        $current_navigation_menus = wp_get_nav_menus();
        if ( ! empty( $current_navigation_menus ) && ! is_wp_error( $current_navigation_menus ) ) {
            foreach ( $current_navigation_menus as $menu ) {
                foreach ( $menu_location_details as $location => $menu_slug ) {
                    if ( $menu->slug === $menu_slug ) {
                        $navigation_settings[ $location ] = $menu->term_id;
                    }
                }
            }
        }

        set_theme_mod( 'nav_menu_locations', $navigation_settings );
    }
}
add_action( 'pt-ocdi/after_import', 'morenews_ocdi_after_import' );