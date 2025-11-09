<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Get Google Fonts
function newsx_get_google_fonts( $families ) {

    // Detect Protocol
    $protocol = is_ssl() ? 'https' : 'http';

    // Option fetching is simplified for clarity. Implement newsx_get_option() accordingly.
    $latin_subset       = newsx_get_option( 'global_latin_subset' );
    $cyrillic_subset    = newsx_get_option( 'global_cyrillic_subset' );
    $greek_subset       = newsx_get_option( 'global_greek_subset' );
    $vietnamese_subset  = newsx_get_option( 'global_vietnamese_subset' );

    // Subsets
    $subsets = [];
    if ( $latin_subset ) {
        $subsets[] = 'latin';
        $subsets[] = 'latin-ext';
    }

    if ( $cyrillic_subset ) {
        $subsets[] = 'cyrillic';
        $subsets[] = 'cyrillic-ext';
    }

    if ( $greek_subset ) {
        $subsets[] = 'greek';
        $subsets[] = 'greek-ext';
    }

    if ( $vietnamese_subset ) {
        $subsets[] = 'vietnamese';
    }

    $subsets = !empty($subsets) ? '&subset='. implode( ",", $subsets ) : '';

    $families = implode( "|", $families );

    if ( '' === $families ) {
        return;
    }

    // Get Font URL
    $fonts_url = $protocol .'://fonts.googleapis.com/css?family='. esc_attr( $families ) .'&display=swap'. esc_attr( $subsets );

    // Register & Enqueue
    wp_register_style( 'newsx-google-fonts-css', esc_url_raw( $fonts_url ), [], NEWSX_THEME_VERSION );
    wp_enqueue_style( 'newsx-google-fonts-css' );
}

// Enqueue Google Fonts
function newsx_enqueue_google_fonts() {
    $font_families_arr = [];
    $new_font_families_arr = [];

    $global_font_heading = newsx_get_option('global_font_heading');
    $global_font_h1 = newsx_get_option('global_font_h1');
    $global_font_h2 = newsx_get_option('global_font_h2');
    $global_font_h3 = newsx_get_option('global_font_h3');
    $global_font_h4 = newsx_get_option('global_font_h4');
    $global_font_h5 = newsx_get_option('global_font_h5');
    $global_font_h6 = newsx_get_option('global_font_h6');

    if ( '' === $global_font_h2['font-family'] ) {
        $global_font_h2['font-family'] = $global_font_heading['font-family'];
    }

    if ( '' === $global_font_h1['font-family'] ) {
        $global_font_h1['font-family'] = $global_font_heading['font-family'];
    }

    if ( '' === $global_font_h3['font-family'] ) {
        $global_font_h3['font-family'] = $global_font_heading['font-family'];
    }

    if ( '' === $global_font_h4['font-family'] ) {
        $global_font_h4['font-family'] = $global_font_heading['font-family'];
    }

    if ( '' === $global_font_h5['font-family'] ) {
        $global_font_h5['font-family'] = $global_font_heading['font-family'];
    }

    if ( '' === $global_font_h6['font-family'] ) {
        $global_font_h6['font-family'] = $global_font_heading['font-family'];
    }
    
    $customizer_font_options = [
        'body_font' => newsx_get_option('global_font_body'),
        'heading_font' => $global_font_heading,
        'h1_font' => $global_font_h1,
        'h2_font' => $global_font_h2,
        'h3_font' => $global_font_h3,
        'h4_font' => $global_font_h4,
        'h5_font' => $global_font_h5,
        'h6_font' => $global_font_h6,
        'logo_font' => newsx_get_option('logo_title_font'),
        'header_pm_font' => newsx_get_option('header_pm_font'),
        'header_sm_font' => newsx_get_option('header_sm_font'),
        'header_cta_font' => newsx_get_option('header_cta_font'),
        // TODO: Add all fonts here
    ];

    foreach ($customizer_font_options as $key => $font_option) {
        $font_family = $font_option['font-family'];

        if ( 'default' === $font_family || '' === $font_family ) {
            continue;
        }

        // FF Array
        $ff_array = [
            'Roboto',
            'Open Sans',
            'Oxygen',
            'Encode Sans Condensed'
        ];

        if ( !defined('NEWSX_CORE_PRO_VERSION') || !newsx_core_pro_fs()->can_use_premium_code() ) {
            if ( ! in_array($font_family, $ff_array) ) {
                continue;
            }
        }

        // Get font family and variant
        $font_family = str_replace( ' ', '+', $font_option['font-family'] );
        $font_variant = str_replace( 'italic', 'i', $font_option['variant'] );
        $font_variant = str_replace( 'regular', '400', $font_variant );

        // Add normal variant for logo tagline font
        if ( 'logo_font' === $key ) {
            $font_variant .= ',normal';
        }

        // Add to array
        if ( ! isset( $font_families_arr[$font_family] ) ) {
            $font_families_arr[$font_family] = $font_variant;
        } else {
            if ( ! str_contains($font_families_arr[$font_family], $font_variant ) ) {
                $font_families_arr[$font_family] .= ','. $font_variant;
            }
        }
        
    }

    foreach ($font_families_arr as $key => $value) {
        $new_font_families_arr[] = $key .':'. $value;
    }

    // Enqueue Google Fonts
    newsx_get_google_fonts($new_font_families_arr);
}

add_action('wp_enqueue_scripts', 'newsx_enqueue_google_fonts');
