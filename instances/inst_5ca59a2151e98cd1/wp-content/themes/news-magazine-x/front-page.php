<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

get_header();

?>

<div id="content" class="site-content">
    <main id="main" class="site-main">

    <?php

    // Edit Button
    echo newsx_customizer_edit_button_markup('front_page');

    // Get Front Page sections
    $front_page_sections = newsx_get_option( 'front_page_sections' );

    // Loop through Front Page sections
    $index = 1;

    if ( ! empty( $front_page_sections ) ) {
        foreach ( $front_page_sections as $section ) {
            if ( (!defined('NEWSX_CORE_PRO_VERSION') || !newsx_core_pro_fs()->can_use_premium_code()) && $index > 3 ) {
                continue;
            }

            $stretch = false;
            if (defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() && isset($section['stretch'])) {
                $stretch = $section['stretch'];
            }
            
            $args = [
                'index' => $index,
                'widget_area' => $section['widget_area'],
                'layout' => $section['layout'],
                'stretch' => $stretch,
            ];

            // Render Front Page sections
            newsx_front_page_row_markup( $args );

            $index++;
        }
    }

    // Blog Feed / Static Page
    if ( !newsx_get_option('front_page_hide_blog_feed') ) {

        // Get Options
        $wrapper_class = 'boxed' === newsx_get_option('global_content_width') ? ' newsx-container' : '';
        
        // Default Posts Feed
        if ( 'posts' === get_option('show_on_front') ) {
            // Include Presets
            require_once NEWSX_INCLUDES_DIR .'/widgets/presets/class-newsx-magazine-widget-presets.php';
            require_once NEWSX_PARENT_DIR .'/template-parts/blog-page/presets/class-newsx-blog-posts-presets.php';

            // News Magazine Layout ------------------------------
            echo '<div class="newsx-row newsx-fp-row-extra">';
                echo '<div class="newsx-row-inner newsx-flex-nowrap newsx-content-nosidebar'. esc_attr($wrapper_class) .'">';

                    echo '<div class="newsx-magazine-layout newsx-posts-widget newsx-magazine-layout-1-3 newsx-s0">';

                    // Get Posts
                    $instance = [
                        'layout' => '1-3',
                        'elements_preset' => 's0', 
                        'image_link' => true,
                        'excerpt_letter_count' => 250
                    ];

                    // Get Posts
                    $magazine_posts = new WP_Query( [
                        'post_type' => 'post',
                        'posts_per_page' => 4,
                        'ignore_sticky_posts' => true
                    ] );
        
                    // Post Index
                    $post_index = 1;

                    // Loop: Start
                    if ( $magazine_posts->have_posts() ) :
                        while ( $magazine_posts->have_posts() ) : $magazine_posts->the_post();

                        // Get Posts
                        $posts_layout = new Newsx_Magazine_Widget_Presets( $instance, $post_index, $magazine_posts->post_count );
                        $posts_layout->display();

                        $post_index++;

                        endwhile;
                    endif;
                    wp_reset_postdata();
                    
                    echo '</div>';

                echo '</div>';
            echo '</div>';

            // Get Options
            $sidebar_display = newsx_get_option( 'global_blog_sidebar_display' );

            if ( 'left' === $sidebar_display ) {
                $wrapper_class .= ' newsx-content-lsidebar';
            } elseif ( 'right' === $sidebar_display ) {
                $wrapper_class .= ' newsx-content-rsidebar';
            } elseif ( 'both' === $sidebar_display ) {
                $wrapper_class .= ' newsx-ls-content-rs';
            } else {
                $wrapper_class .= ' newsx-content-nosidebar';
            }

            echo '<div class="newsx-row newsx-fp-row-extra">';

                echo '<div class="newsx-row-inner newsx-flex-nowrap'. esc_attr($wrapper_class) .'">';
        
                    // Get Left Sidebar Template
                    newsx_general_sidebar_markup( 'global_blog_sidebar_display', 'left' );

                    echo '<div class="newsx-posts-feed newsx-list-layout newsx-list-layout-list-7">';

                    if ( have_posts() ) :

                        // Loop Start
                        while ( have_posts() ) :

                            the_post();

                            // Get Post Template
                            $posts_layout = new \Newsx_Blog_Posts_Presets();
                            $posts_layout->display();

                        endwhile; // Loop End

                        // Pagination
                        get_template_part( 'template-parts/blog-page/pagination' );

                        // Reset Query
                        wp_reset_postdata();
                        
                    endif; // have_posts()

                    echo '</div>'; // .newsx-posts-feed
        
                    // Get Right Sidebar Template
                    newsx_general_sidebar_markup( 'global_blog_sidebar_display', 'right' );

                echo '</div>'; // .newsx-row-inner

            echo '</div>'; // .newsx-row
        }

        // Static Page
        if ( 'page' === get_option('show_on_front') ) {
            if ( have_posts() ) :

                echo '<div class="newsx-default-page-wrap'. esc_attr($wrapper_class) .'">';

                    while ( have_posts() ) : the_post();

                    echo '<h2>'. get_the_title() .'</h2>';
                    the_content();

                    endwhile;
                    
                echo '</div>';

            endif;
            wp_reset_postdata();
        }
    }

    ?>

    </main>
</div>

<?php

get_footer();
