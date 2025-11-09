<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/*
** Define constants.
*/
$constants = [
    'NEWSX_THEME_VERSION'         => wp_get_theme( get_template() )->get( 'Version' ),

    // Directory Location Constants
    'NEWSX_PARENT_DIR'            => get_template_directory(),
    'NEWSX_INCLUDES_DIR'          => get_template_directory() . '/includes',
    'NEWSX_ASSETS_DIR'            => get_template_directory() . '/assets',
    'NEWSX_CUSTOMIZER_DIR'        => get_template_directory() . '/includes/customizer',

    // URL Location Constants
    'NEWSX_PARENT_URL'            => get_template_directory_uri(),
    'NEWSX_ASSETS_URL'            => get_template_directory_uri() . '/assets',
    'NEWSX_ADMIN_URL'             => get_template_directory_uri() . '/includes/admin',
    'NEWSX_CUSTOMIZER_URL'        => get_template_directory_uri() . '/includes/customizer',
    'NEWSX_WIDGETS_URL'           => get_template_directory_uri() . '/includes/widgets',

    // Child Theme Constants
    'NEWSX_CHILD_URL'             => get_stylesheet_directory_uri(),
    'NEWSX_CHILD_DIR'             => get_stylesheet_directory(),
];

// Define constant safely.
foreach ( $constants as $name => $value ) {
    define_constant( $name, $value );
}

function define_constant( $name, $value ) {
    if ( ! defined( $name ) ) {
        define( $name, $value );
    }
}
