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
    newsx_archive_page_header_markup( 'search', false );

    // Primary Content
    echo '<div id="primary" class="content-area primary">';

        // Blog Feed
        get_template_part( 'template-parts/blog-page/feed' );

    echo '</div>'; // #primary

    ?>

    </div> <!-- .newsx-blog-page-wrap -->

</main>
</div>

<?php get_footer(); ?>