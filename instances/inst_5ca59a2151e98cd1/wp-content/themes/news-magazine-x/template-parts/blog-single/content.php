<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Post Content with Table of Contents
if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() && 
    newsx_get_option('bs_toc_enable') && file_exists( NEWSX_CORE_PRO_PATH .'public/template-parts/blog-single/table-of-contents.php' ) ) {
    
    load_template( NEWSX_CORE_PRO_PATH .'public/template-parts/blog-single/table-of-contents.php' );
    newsx_the_content();
    
} else {
    echo '<div class="newsx-post-content">';
        // Edit Button
        echo newsx_customizer_edit_button_markup('bs_content');
        the_content(); 
    echo '</div>';
}
        
// Page Break Navigation
wp_link_pages([
    'before' => '<p class="single-pagination">'. esc_html__( 'Pages:', 'news-magazine-x' ),
    'after' => '</p>'
]);
