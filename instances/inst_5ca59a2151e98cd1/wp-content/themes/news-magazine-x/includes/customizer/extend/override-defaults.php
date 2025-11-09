<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/*
** Override default customizer panels, sections, settings and controls.
*/
function newsx_override_customizer_defaults( $wp_customize ) {
    $wp_customize->get_section( 'title_tagline' )->panel = 'newsx_panel_header';
    $wp_customize->get_section( 'title_tagline' )->priority = 40;
    $wp_customize->get_section( 'title_tagline' )->title = esc_html__( 'Logo / Site Title', 'news-magazine-x' );

	$panel_arr = array(
		'priority'       => 80,
		'capability'     => 'edit_theme_options',
		'theme_supports' => '',
		'title'          => esc_html__( 'Site Identity', 'news-magazine-x' ),
		'description'    => '',
	);
	// Register panel.
	/** @psalm-suppress InvalidArgument */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
	$wp_customize->add_panel( 'newsx-site-identity', $panel_arr );
	/** @psalm-suppress InvalidArgument */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort

	$section_arr = array(
		'priority'       => 80,
		'capability'     => 'edit_theme_options',
		'theme_supports' => '',
		'title'          => esc_html__( 'Site Identity', 'news-magazine-x' ),
		'description'    => '',
	);

	// Register Section.
	$wp_customize->add_section( 'newsx-site-identity', $section_arr );

	/** @psalm-suppress PossiblyNullPropertyAssignment */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
	$wp_customize->get_control( 'site_icon' )->section = 'newsx-site-identity';
	/** @psalm-suppress PossiblyNullPropertyAssignment */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort

	/** @psalm-suppress PossiblyNullPropertyAssignment */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
	$wp_customize->get_control( 'site_icon' )->description = __( 'Site Icons are what you see in browser tabs, bookmark bars, and within the WordPress mobile apps. Upload one here! Site Icons should be square and at least 512 × 512 pixels.', 'news-magazine-x' );
	/** @psalm-suppress PossiblyNullPropertyAssignment */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
}

add_action( 'customize_register', 'newsx_override_customizer_defaults', 20 );

function disable_nav_menu_selective_refresh( $wp_customize ) {
    // Check if selective refresh is available
    if ( isset( $wp_customize->selective_refresh ) ) {
        // Loop through each menu location
        $locations = get_nav_menu_locations();
        foreach ( $locations as $location => $menu_id ) {
            // Generate the partial ID for the nav menu
            $partial_id = sprintf( 'nav_menu_instance[%s]', $location );

            // Check if the partial exists, and if so, remove it
            $partial = $wp_customize->selective_refresh->get_partial( $partial_id );
            if ( $partial ) {
                $wp_customize->selective_refresh->remove_partial( $partial_id );
            }
        }
    }
}
add_action( 'customize_register', 'disable_nav_menu_selective_refresh', 100 );
