<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/*
** Register sidebars
*/
function newsx_register_sidebars() {
    // Header Off-Canvas Widgets
    register_sidebar([
        'name'          => esc_html__( 'Header Off-Canvas', 'news-magazine-x' ),
        'id'            => 'header-offcanvas',
        'description'   => '',
        'before_widget' => '<section id="%1$s" class="widget %2$s">',
        'after_widget'  => '</section>',
        'before_title'  => '<h2 class="widget-title">',
        'after_title'   => '</h2>',
    ]);

    // Header Widgets
    register_sidebar([
        'name'          => esc_html__( 'Header Widgets 1', 'news-magazine-x' ),
        'id'            => 'header-widgets-1',
        'description'   => '',
        'before_widget' => '<section id="%1$s" class="widget %2$s">',
        'after_widget'  => '</section>',
        'before_title'  => '<h2 class="widget-title">',
        'after_title'   => '</h2>',
    ]);

    register_sidebar([
        'name'          => esc_html__( 'Header Widgets 2', 'news-magazine-x' ),
        'id'            => 'header-widgets-2',
        'description'   => '',
        'before_widget' => '<section id="%1$s" class="widget %2$s">',
        'after_widget'  => '</section>',
        'before_title'  => '<h2 class="widget-title">',
        'after_title'   => '</h2>',
    ]);

    // Front Page Sections
    $front_page_sections = defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ? 10 : 3;
    for ($i = 1; $i <= $front_page_sections; $i++) {
        register_sidebar([
            'name'          => sprintf(esc_html__('Front Page Widgets %d Content', 'news-magazine-x'), $i),
            'id'            => 'front-page-section-'. $i .'-content',
            'description'   => '',
            'before_widget' => '<section id="%1$s" class="widget %2$s">',
            'after_widget'  => '</section>',
            'before_title'  => '<h2 class="widget-title">',
            'after_title'   => '</h2>',
        ]);

        if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
            register_sidebar([
                'name'          => sprintf(esc_html__('Front Page Widgets %d Left Sidebar', 'news-magazine-x'), $i),
                'id'            => 'front-page-section-'. $i .'-left-sidebar',
                'description'   => '',
                'before_widget' => '<section id="%1$s" class="widget %2$s">',
                'after_widget'  => '</section>',
                'before_title'  => '<h2 class="widget-title">',
                'after_title'   => '</h2>',
            ]);
        }

        register_sidebar([
            'name'          => sprintf(esc_html__('Front Page Widgets %d Right Sidebar', 'news-magazine-x'), $i),
            'id'            => 'front-page-section-'. $i .'-right-sidebar',
            'description'   => '',
            'before_widget' => '<section id="%1$s" class="widget %2$s">',
            'after_widget'  => '</section>',
            'before_title'  => '<h2 class="widget-title">',
            'after_title'   => '</h2>',
        ]);
    }

    // General Sidebars
    register_sidebar([
        'name'          => esc_html__( 'General Left Sidebar', 'news-magazine-x' ),
        'id'            => 'general-left-sidebar',
        'description'   => '',
        'before_widget' => '<section id="%1$s" class="widget %2$s">',
        'after_widget'  => '</section>',
        'before_title'  => '<h2 class="widget-title">',
        'after_title'   => '</h2>',
    ]);

    register_sidebar([
        'name'          => esc_html__( 'General Right Sidebar', 'news-magazine-x' ),
        'id'            => 'general-right-sidebar',
        'description'   => '',
        'before_widget' => '<section id="%1$s" class="widget %2$s">',
        'after_widget'  => '</section>',
        'before_title'  => '<h2 class="widget-title">',
        'after_title'   => '</h2>',
    ]);

    // Footer Widgets
    $footer_widget_areas = defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ? 6 : 4;
    for ($i = 1; $i <= $footer_widget_areas; $i++) {
        register_sidebar([
            'name'          => sprintf(esc_html__('Footer Widgets %d', 'news-magazine-x'), $i),
            'id'            => 'footer-widgets-'. $i,
            'description'   => '',
            'before_widget' => '<section id="%1$s" class="widget %2$s">',
            'after_widget'  => '</section>',
            'before_title'  => '<h2 class="widget-title">',
            'after_title'   => '</h2>',
        ]);
    }

}

add_action( 'widgets_init', 'newsx_register_sidebars' );

/*
** Include Files
*/
// Extend the WP_Widget class
require_once NEWSX_INCLUDES_DIR .'/widgets/extend/abstract-newsx-widget.php';

// Enqueue Scripts
require_once NEWSX_INCLUDES_DIR .'/widgets/extend/enqueue-scripts.php';

// Heading Widget
require_once NEWSX_INCLUDES_DIR .'/widgets/newsx-heading-widget.php';

// Magazine Layout Widget
require_once NEWSX_INCLUDES_DIR .'/widgets/newsx-magazine-widget.php';

// List Layout Widget
require_once NEWSX_INCLUDES_DIR .'/widgets/newsx-list-widget.php';

// Grid Layout Widget
require_once NEWSX_INCLUDES_DIR .'/widgets/newsx-grid-widget.php';

// Featured Tabs Widget
require_once NEWSX_INCLUDES_DIR .'/widgets/newsx-featured-tabs-widget.php';

// Featured Posts Widget
require_once NEWSX_INCLUDES_DIR .'/widgets/newsx-featured-posts-widget.php';

// Social Icons Widget
require_once NEWSX_INCLUDES_DIR .'/widgets/newsx-social-icons-widget.php';

// Category List Widget
require_once NEWSX_INCLUDES_DIR .'/widgets/newsx-category-list-widget.php';

// Newsletter Widget
require_once NEWSX_INCLUDES_DIR .'/widgets/newsx-newsletter-widget.php';

// Premium Widgets
if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
    // Slider Widget
    require_once NEWSX_CORE_PRO_WIDGETS .'/newsx-slider-widget-pro.php';

    // News Ticker Widget
    require_once NEWSX_CORE_PRO_WIDGETS .'/newsx-news-ticker-widget-pro.php';

    // Video Playlist
    require_once NEWSX_CORE_PRO_WIDGETS .'/newsx-video-playlist-widget-pro.php';
    
    // Weather Widget
    require_once NEWSX_CORE_PRO_WIDGETS .'/newsx-weather-widget-pro.php';
    
}
