<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( !is_customize_preview() ) {
    return;
}

// Include Customizer Files
function newsx_include_customizer_files( $wp_customize ) {
    // Extend Customizer: Custom Controls & Functions
    require_once NEWSX_CUSTOMIZER_DIR .'/extend/custom-controls.php';
    require_once NEWSX_CUSTOMIZER_DIR .'/extend/override-defaults.php';

    // Register Panels and Sections
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/register-sections.php';
    
    // About Section
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/about.php';

    // Global Sections
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/global/colors.php';
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/global/typography.php';
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/global/layout.php';
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/global/sidebar.php';
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/global/widgets.php';
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/global/categories.php';

    // Header Sections
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/header/rows/top-section.php';
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/header/rows/middle-section.php';
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/header/rows/bottom-section.php';

    // Header Elements
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/header/elements/offcanvas.php';
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/header/elements/cta-button.php';
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/header/elements/weather.php';
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/header/elements/dark-switcher.php';
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/header/elements/date-and-time.php';
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/header/elements/site-identity.php';
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/header/elements/news-ticker.php';
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/header/elements/primary-menu.php';
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/header/elements/secondary-menu.php';
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/header/elements/random-post.php';
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/header/elements/search.php';
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/header/elements/social-icons.php';
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/header/elements/custom-html-1.php';
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/header/elements/custom-html-2.php';
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/header/elements/header-widgets-1.php';
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/header/elements/header-widgets-2.php';

    // // Front Page Section
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/front-page.php';

    // Blog Page Sections
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/blog-page/posts-slider.php';
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/blog-page/posts-feed.php';

    // Blog Single Sections
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/blog-single/header.php';
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/blog-single/featured.php';
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/blog-single/table-of-contents.php';
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/blog-single/content.php';
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/blog-single/sharing.php';
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/blog-single/navigation.php';
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/blog-single/author.php';
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/blog-single/newsletter.php';
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/blog-single/related-posts.php';
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/blog-single/advanced.php';

    // Footer Sections
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/footer/rows/top-section.php';
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/footer/rows/middle-section.php';
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/footer/rows/bottom-section.php';

    // Footer Elements
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/footer/elements/logo.php';
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/footer/elements/copyright.php';
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/footer/elements/footer-menu.php';
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/footer/elements/custom-html-1.php';
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/footer/elements/custom-html-2.php';
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/footer/elements/social-icons.php';
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/footer/elements/back-to-top.php';

    // Preloader
    require_once NEWSX_CUSTOMIZER_DIR .'/sections/preloader.php';
}

add_action( 'init', 'newsx_include_customizer_files' );

// Enqueue Customizer Scripts
require_once NEWSX_CUSTOMIZER_DIR .'/extend/enqueue-scripts.php';
