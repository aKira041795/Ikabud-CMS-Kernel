<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

get_header();

?>

<div id="content" class="site-content">
    <main id="main" class="site-main">
    
    <div class="newsx-archive-page-wrap<?php echo esc_attr(newsx_get_boxed_content_class()); ?>">

    <?php

    // Page Header
    newsx_archive_page_header_markup( 'term', true );

    // Page Content
    echo '<div class="newsx-archive-page-inner">';
    
    // Get Left Sidebar Template
    newsx_general_sidebar_markup( 'global_term_archive_sidebar_display', 'left' );

    // Primary Content
    echo '<div id="primary" class="content-area primary">';

        // Blog Feed
        get_template_part( 'template-parts/blog-page/feed' );

    echo '</div>'; // #primary
    
    // Get Right Sidebar Template
    newsx_general_sidebar_markup( 'global_term_archive_sidebar_display', 'right' );

    echo '</div>'; // .newsx-archive-page-inner

    ?>

    </div> <!-- .newsx-archive-page-wrap -->

</main>
</div>

<?php get_footer(); ?>