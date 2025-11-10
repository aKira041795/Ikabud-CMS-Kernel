<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}


// Kirki Framework Configuration
Kirki::add_config( 'newsx_theme_config', [
    'capability'    => 'edit_theme_options',
    'option_type'   => 'option',
] );
 
add_filter( 'kirki_telemetry', '__return_false' );


/*
** About Section
*/
Kirki::add_section( 'newsx_section_about', [
    'title'       => esc_html__( 'About News Magazine X', 'news-magazine-x' ),
    'priority'    => 1,
] );


/*
** Global Sections
*/
Kirki::add_panel( 'newsx_panel_global', [
    'title'       => esc_html__( 'Global', 'news-magazine-x' ),
    'priority'    => 2,
] );

Kirki::add_section( 'newsx_section_global_colors', [
    'title'       => esc_html__( 'Colors', 'news-magazine-x' ),
    'panel'       => 'newsx_panel_global',
    'priority'    => 10,
] );

Kirki::add_section( 'newsx_section_global_typography', [
  'title'       => esc_html__( 'Typography', 'news-magazine-x' ),
  'panel'       => 'newsx_panel_global',
  'priority'    => 15,
] );

Kirki::add_section( 'newsx_section_global_layout', [
    'title'       => esc_html__( 'Layout', 'news-magazine-x' ),
    'panel'       => 'newsx_panel_global',
    'priority'    => 30,
] );

Kirki::add_section( 'newsx_section_global_sidebar', [
    'title'       => esc_html__( 'Sidebar', 'news-magazine-x' ),
    'panel'       => 'newsx_panel_global',
    'priority'    => 35,
] );

Kirki::add_section( 'newsx_section_global_widgets', [
    'title'       => esc_html__( 'Widgets', 'news-magazine-x' ),
    'panel'       => 'newsx_panel_global',
    'priority'    => 40,
] );

Kirki::add_section( 'newsx_section_global_categories', [
    'title'       => esc_html__( 'Categories', 'news-magazine-x' ),
    'panel'       => 'newsx_panel_global',
    'priority'    => 50,
] );

/*
** Header Sections
*/
Kirki::add_panel( 'newsx_panel_header', [
    'title'       => esc_html__( 'Header', 'news-magazine-x' ),
    'priority'    => 5,
] );

Kirki::add_section( 'newsx_section_headline_header_sections', [
    'title'       => esc_html__( 'Header Sections', 'news-magazine-x' ),
    'panel'       => 'newsx_panel_header',
    'priority'    => 9,
] );

Kirki::add_section( 'newsx_section_hd_top', [
    'title'       => esc_html__( 'Top Section', 'news-magazine-x' ),
    'panel'       => 'newsx_panel_header',
    'tabs'        => newsx_get_section_tabs(),
    'priority'    => 10,
] );

Kirki::add_section( 'newsx_section_hd_middle', [
    'title'       => esc_html__( 'Middle Section', 'news-magazine-x' ),
    'panel'       => 'newsx_panel_header',
    'tabs'        => newsx_get_section_tabs(),
    'priority'    => 20,
] );

Kirki::add_section( 'newsx_section_hd_bottom', [
    'title'       => esc_html__( 'Bottom Section', 'news-magazine-x' ),
    'panel'       => 'newsx_panel_header',
    'tabs'        => newsx_get_section_tabs(),
    'priority'    => 30,
] );

// Header Elements
Kirki::add_section( 'newsx_section_headline_header_elements', [
    'title'       => esc_html__( 'Header Elements', 'news-magazine-x' ),
    'panel'       => 'newsx_panel_header',
    'priority'    => 39,
] );

Kirki::add_section( 'title_tagline', [
    'title'       => esc_html__( 'Logo / Site Title', 'news-magazine-x' ),
    'panel'       => 'newsx_panel_header',
    'tabs'        => newsx_get_section_tabs(),
    'priority'    => 40,
] );

Kirki::add_section( 'newsx_section_hd_primary_menu', [
    'title'       => esc_html__( 'Primary Menu', 'news-magazine-x' ),
    'panel'       => 'newsx_panel_header',
    'tabs'        => newsx_get_section_tabs(),
    'priority'    => 50,
] );

Kirki::add_section( 'newsx_section_hd_secondary_menu', [
    'title'       => esc_html__( 'Secondary Menu', 'news-magazine-x' ),
    'panel'       => 'newsx_panel_header',
    'tabs'        => newsx_get_section_tabs(),
    'priority'    => 60,
] );

Kirki::add_section( 'newsx_section_hd_date_and_time', [
    'title'       => esc_html__( 'Date and Time', 'news-magazine-x' ),
    'panel'       => 'newsx_panel_header',
    'tabs'        => newsx_get_section_tabs(),
    'priority'    => 80,
] );

Kirki::add_section( 'newsx_section_hd_news_ticker', [
    'title'       => esc_html__( 'News Ticker', 'news-magazine-x' ),
    'panel'       => 'newsx_panel_header',
    'tabs'        => newsx_get_section_tabs(),
    'priority'    => 90,
] );

Kirki::add_section( 'newsx_section_hd_social_icons', [
    'title'       => esc_html__( 'Social Icons', 'news-magazine-x' ),
    'panel'       => 'newsx_panel_header',
    'tabs'        => newsx_get_section_tabs(),
    'priority'    => 100,
] );

Kirki::add_section( 'newsx_section_hd_search', [
    'title'       => esc_html__( 'Search', 'news-magazine-x' ),
    'panel'       => 'newsx_panel_header',
    'tabs'        => newsx_get_section_tabs(),
    'priority'    => 110,
] );

Kirki::add_section( 'newsx_section_hd_offcanvas', [
    'title'       => esc_html__( 'Off-Canvas', 'news-magazine-x' ),
    'panel'       => 'newsx_panel_header',
    'tabs'        => newsx_get_section_tabs(),
    'priority'    => 115,
] );

if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
    Kirki::add_section( 'newsx_section_hd_cta_button', [
        'title'       => esc_html__( 'CTA Button', 'news-magazine-x' ),
        'panel'       => 'newsx_panel_header',
        'tabs'        => newsx_get_section_tabs(),
        'priority'    => 120,
    ] );

    Kirki::add_section( 'newsx_section_hd_weather', [
        'title'       => esc_html__( 'Weather', 'news-magazine-x' ),
        'panel'       => 'newsx_panel_header',
        'tabs'        => newsx_get_section_tabs(),
        'priority'    => 125,
    ] );
}

Kirki::add_section( 'newsx_section_hd_random_post', [
    'title'       => esc_html__( 'Random Post', 'news-magazine-x' ),
    'panel'       => 'newsx_panel_header',
    'tabs'        => newsx_get_section_tabs(),
    'priority'    => 130,
] );

Kirki::add_section( 'newsx_section_hd_dark_switcher', [
    'title'       => esc_html__( 'Dark Mode Switcher', 'news-magazine-x' ),
    'panel'       => 'newsx_panel_header',
    'tabs'        => newsx_get_section_tabs(),
    'priority'    => 140,
] );

if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
    Kirki::add_section( 'newsx_section_hd_custom_html_1', [
        'title'       => esc_html__( 'Custom HTML 1', 'news-magazine-x' ),
        'panel'       => 'newsx_panel_header',
        'tabs'        => newsx_get_section_tabs(),
        'priority'    => 150,
    ] );

    Kirki::add_section( 'newsx_section_hd_custom_html_2', [
        'title'       => esc_html__( 'Custom HTML 2', 'news-magazine-x' ),
        'panel'       => 'newsx_panel_header',
        'tabs'        => newsx_get_section_tabs(),
        'priority'    => 160,
    ] );

    Kirki::add_section( 'newsx_section_hd_header_widgets_1', [
        'title'       => esc_html__( 'Widgets Area 1', 'news-magazine-x' ),
        'panel'       => 'newsx_panel_header',
        // 'tabs'        => newsx_get_section_tabs(),
        'priority'    => 170,
    ] );

    Kirki::add_section( 'newsx_section_hd_header_widgets_2', [
        'title'       => esc_html__( 'Widgets Area 2', 'news-magazine-x' ),
        'panel'       => 'newsx_panel_header',
        // 'tabs'        => newsx_get_section_tabs(),
        'priority'    => 180,
    ] );
}

/*
** Front Page Section
*/
Kirki::add_section( 'newsx_section_front_page', [
    'title'       => esc_html__( 'Front Page', 'news-magazine-x' ),
    'priority'    => 10,
] );

/*
** Blog Page Sections
*/
Kirki::add_panel( 'newsx_panel_blog_page', [
    'title'       => esc_html__( 'Blog Page', 'news-magazine-x' ),
    'priority'    => 15,
] );

Kirki::add_section( 'newsx_section_bp_slider', [
    'title'       => esc_html__( 'Posts Slider', 'news-magazine-x' ),
    'panel'       => 'newsx_panel_blog_page',
    'priority'    => 10,
] );

Kirki::add_section( 'newsx_section_bp_posts_feed', [
    'title'       => esc_html__( 'Posts Feed', 'news-magazine-x' ),
    'panel'       => 'newsx_panel_blog_page',
    // 'tabs'        => newsx_get_section_tabs(),
    'priority'    => 15,
] );

/*
** Blog Single Sections
*/
Kirki::add_panel( 'newsx_panel_blog_single', [
    'title'       => esc_html__( 'Blog Single', 'news-magazine-x' ),
    'priority'    => 16,
] );

Kirki::add_section( 'newsx_section_bs_header', [
    'title'       => esc_html__( 'Post Header', 'news-magazine-x' ),
    'panel'       => 'newsx_panel_blog_single',
    // 'tabs'        => newsx_get_section_tabs(),
    'priority'    => 10,
] );

if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
    Kirki::add_section( 'newsx_section_bs_featured', [
        'title'       => esc_html__( 'Featured Image', 'news-magazine-x' ),
        'panel'       => 'newsx_panel_blog_single',
        // 'tabs'        => newsx_get_section_tabs(),
        'priority'    => 15,
    ] );

    Kirki::add_section( 'newsx_section_bs_toc', [
        'title'       => esc_html__( 'Table of Contents', 'news-magazine-x' ),
        'panel'       => 'newsx_panel_blog_single',
        'tabs'        => newsx_get_section_tabs(),
        'priority'    => 20,
    ] );
}

Kirki::add_section( 'newsx_section_bs_content', [
    'title'       => esc_html__( 'Post Content', 'news-magazine-x' ),
    'panel'       => 'newsx_panel_blog_single',
    // 'tabs'        => newsx_get_section_tabs(),
    'priority'    => 25,
] );

if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
    Kirki::add_section( 'newsx_section_bs_sharing', [
        'title'       => esc_html__( 'Social Sharing', 'news-magazine-x' ),
        'panel'       => 'newsx_panel_blog_single',
        'tabs'        => newsx_get_section_tabs(),
        'priority'    => 30,
    ] );
} else {
    Kirki::add_section( 'newsx_section_bs_sharing', [
        'title'       => esc_html__( 'Social Sharing', 'news-magazine-x' ),
        'panel'       => 'newsx_panel_blog_single',
        'priority'    => 30,
    ] );
}

Kirki::add_section( 'newsx_section_bs_navigation', [
    'title'       => esc_html__( 'Navigation', 'news-magazine-x' ),
    'panel'       => 'newsx_panel_blog_single',
    'tabs'        => newsx_get_section_tabs(),
    'priority'    => 40,
] );

Kirki::add_section( 'newsx_section_bs_author', [
    'title'       => esc_html__( 'Author Box', 'news-magazine-x' ),
    'panel'       => 'newsx_panel_blog_single',
    'tabs'        => newsx_get_section_tabs(),
    'priority'    => 50,
] );

if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {   
    Kirki::add_section( 'newsx_section_bs_newsletter', [
        'title'       => esc_html__( 'Newsletter (Mailchimp)', 'news-magazine-x' ),
        'panel'       => 'newsx_panel_blog_single',
        'tabs'        => newsx_get_section_tabs(),
        'priority'    => 60,
    ] );
}

Kirki::add_section( 'newsx_section_bs_related', [
    'title'       => esc_html__( 'Related Posts', 'news-magazine-x' ),
    'panel'       => 'newsx_panel_blog_single',
    'tabs'        => newsx_get_section_tabs(),
    'priority'    => 70,
] );

Kirki::add_section( 'newsx_section_bs_advanced', [
    'title'       => esc_html__( 'Advanced Options', 'news-magazine-x' ),
    'panel'       => 'newsx_panel_blog_single',
    // 'tabs'        => newsx_get_section_tabs(),
    'priority'    => 80,
] );


/*
** Footer Sections
*/
Kirki::add_panel( 'newsx_panel_footer', [
    'title'       => esc_html__( 'Footer', 'news-magazine-x' ),
    'priority'    => 20,
] );

Kirki::add_section( 'newsx_section_headline_footer_sections', [
    'title'       => esc_html__( 'Footer Sections', 'news-magazine-x' ),
    'panel'       => 'newsx_panel_footer',
    'priority'    => 9,
] );

Kirki::add_section( 'newsx_section_ft_top', [
    'title'       => esc_html__( 'Top Section', 'news-magazine-x' ),
    'panel'       => 'newsx_panel_footer',
    'tabs'        => newsx_get_section_tabs(),
    'priority'    => 10,
] );

Kirki::add_section( 'newsx_section_ft_middle', [
    'title'       => esc_html__( 'Middle Section', 'news-magazine-x' ),
    'panel'       => 'newsx_panel_footer',
    'tabs'        => newsx_get_section_tabs(),
    'priority'    => 20,
] );

if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
    Kirki::add_section( 'newsx_section_ft_bottom', [
        'title'       => esc_html__( 'Bottom Section', 'news-magazine-x' ),
        'panel'       => 'newsx_panel_footer',
        'tabs'        => newsx_get_section_tabs(),
        'priority'    => 30,
    ] );
} else {
    Kirki::add_section( 'newsx_section_ft_bottom', [
        'title'       => esc_html__( 'Bottom Section', 'news-magazine-x' ),
        'panel'       => 'newsx_panel_footer',
        'priority'    => 30,
    ] );
}

// Footer Elements
Kirki::add_section( 'newsx_section_headline_footer_elements', [
    'title'       => esc_html__( 'Footer Elements', 'news-magazine-x' ),
    'panel'       => 'newsx_panel_footer',
    'priority'    => 39,
] );

Kirki::add_section( 'newsx_section_ft_logo', [
    'title'       => esc_html__( 'Logo', 'news-magazine-x' ),
    'panel'       => 'newsx_panel_footer',
    'tabs'        => newsx_get_section_tabs(),
    'priority'    => 40,
] );

Kirki::add_section( 'newsx_section_ft_copyright', [
    'title'       => esc_html__( 'Copyright', 'news-magazine-x' ),
    'panel'       => 'newsx_panel_footer',
    'tabs'        => newsx_get_section_tabs(),
    'priority'    => 45,
] );

Kirki::add_section( 'newsx_section_ft_footer_menu', [
    'title'       => esc_html__( 'Menu', 'news-magazine-x' ),
    'panel'       => 'newsx_panel_footer',
    'tabs'        => newsx_get_section_tabs(),
    'priority'    => 50,
] );

Kirki::add_section( 'newsx_section_ft_social_icons', [
    'title'       => esc_html__( 'Social Icons', 'news-magazine-x' ),
    'panel'       => 'newsx_panel_footer',
    'tabs'        => newsx_get_section_tabs(),
    'priority'    => 60,
] );

Kirki::add_section( 'newsx_section_ft_custom_html_1', [
    'title'       => esc_html__( 'Custom HTML 1', 'news-magazine-x' ),
    'panel'       => 'newsx_panel_footer',
    'tabs'        => newsx_get_section_tabs(),
    'priority'    => 70,
] );

Kirki::add_section( 'newsx_section_ft_custom_html_2', [
    'title'       => esc_html__( 'Custom HTML 2', 'news-magazine-x' ),
    'panel'       => 'newsx_panel_footer',
    'tabs'        => newsx_get_section_tabs(),
    'priority'    => 80,
] );

if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code()) {
    Kirki::add_section( 'newsx_section_ft_backtop', [
        'title'       => esc_html__( 'Back to Top', 'news-magazine-x' ),
        'panel'       => 'newsx_panel_footer',
        'tabs'        => newsx_get_section_tabs(),
        'priority'    => 80,
    ] );
} else {
    Kirki::add_section( 'newsx_section_ft_backtop', [
        'title'       => esc_html__( 'Back to Top', 'news-magazine-x' ),
        'panel'       => 'newsx_panel_footer',
        'priority'    => 80,
    ] );
}

if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code()) {
    Kirki::add_section( 'newsx_section_preloader', [
        'title'       => esc_html__( 'Preloader', 'news-magazine-x' ),
        'tabs'        => newsx_get_section_tabs(),
        'priority'    => 40,
    ] );
}