<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Include Presets
require_once NEWSX_PARENT_DIR .'/template-parts/blog-page/presets/class-newsx-blog-posts-presets.php';

// Get Layout Class
$posts_layout = defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ? newsx_get_option('bp_feed_layout') : 'list-7';

if ( str_contains($posts_layout, 'list') ) {
    $layout_class = 'newsx-list-layout newsx-list-layout-'. $posts_layout;
} else {
    $layout_class = 'newsx-grid-layout newsx-grid-layout-'. $posts_layout;
}

echo '<div class="newsx-posts-feed '. esc_attr($layout_class) .'">';

// Edit Button
echo newsx_customizer_edit_button_markup('bp_posts_feed');

if ( have_posts() ) :

    // Loop Start
    while ( have_posts() ) :

        the_post();

        // Get Post Template
        $posts_presets = new Newsx_Blog_Posts_Presets();
        $posts_presets->display();

    endwhile; // Loop End

    // Pagination
    get_template_part( 'template-parts/blog-page/pagination' );
    
endif; // have_posts()

echo '</div>'; // .newsx-posts-feed