<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

get_header();

?>

<div id="content" class="site-content">
    <main id="main" class="site-main">
    
    <div class="newsx-blog-page-wrap<?php echo esc_attr(newsx_get_boxed_content_class()); ?>">

    <?php

    // Get Slider Template
    if ( newsx_get_option('section_bp_slider_enable') && !is_archive() ) {
        get_template_part( 'template-parts/blog-page/slider' );
    }

    echo '<div class="newsx-blog-page-inner">';
    
    // Get Left Sidebar Template
    newsx_general_sidebar_markup( 'global_blog_sidebar_display', 'left' );

    // Primary Content
    echo '<div id="primary" class="content-area primary">';

        // Blog Feed
        get_template_part( 'template-parts/blog-page/feed' );

    echo '</div>'; // #primary
      
    // Get Right Sidebar Template
    newsx_general_sidebar_markup( 'global_blog_sidebar_display', 'right' );

    echo '</div>'; // .newsx-blog-page-inner

    ?>

    </div> <!-- .newsx-blog-page-wrap -->

</main>
</div>

<?php get_footer(); ?>