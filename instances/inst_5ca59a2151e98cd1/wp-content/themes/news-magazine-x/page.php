<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

get_header();

?>


<div id="content" class="site-content">
    <main id="main" class="site-main">
    
    <div class="newsx-default-page-wrap<?php echo esc_attr(newsx_get_boxed_content_class()); ?>">

        <div class="newsx-single-inner">

        <?php newsx_general_sidebar_markup( 'global_single_page_sidebar_display', 'left' ); ?>
        
        <div id="primary" class="content-area primary">

            <?php

            if ( have_posts() ) :

                // Loop Start
                while ( have_posts() ) :

                    the_post();

                    echo '<h1 class="newsx-page-title">'. get_the_title() .'</h1>';

                    echo '<div class="newsx-post-content">';
                        the_content();
                    echo '</div>';
                    
                    // Page Break Navigation
                    wp_link_pages([
                        'before' => '<p class="single-pagination">'. esc_html__( 'Pages:', 'news-magazine-x' ),
                        'after' => '</p>'
                    ]);
                    
                    // Post Comments
                    if ( comments_open() || get_comments_number() ) {
                        echo '<div class="comments-area" id="comments">';
                            comments_template( '', true );
                        echo '</div>';
                    }

                endwhile; // Loop End

                // Reset Query
                wp_reset_postdata();
                
            endif; // have_posts()

            ?>
            
        </div><!-- #primary -->

        <?php newsx_general_sidebar_markup( 'global_single_page_sidebar_display', 'right' ); ?>

        </div><!-- .newsx-single-inner -->

    </div><!-- .newsx-default-page-wrap -->

    </main>
</div><!-- #content -->

<?php
get_footer();
