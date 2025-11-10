<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Include Presets
require_once NEWSX_PARENT_DIR .'/includes/widgets/presets/class-newsx-slider-widget-presets.php';

// Get Instance
$instance = [
    'selection' => newsx_get_option('bp_slider_query'),
    'orderby' => newsx_get_option('bp_slider_orderby'),
    'published_days' => newsx_get_option('bp_slider_published_days'),
    'categories' => newsx_get_option('bp_slider_categories'),
    'tags' => newsx_get_option('bp_slider_tags'),
    'posts_per_page' => intval(newsx_get_option('bp_slider_posts_per_page')),
    'offset' => newsx_get_option('bp_slider_offset'),
    'layout' => newsx_get_option('bp_slider_columns'),
    'elements_preset' => newsx_get_option('bp_slider_elements_preset'),
    'image_size' => newsx_get_option('bp_slider_image_size'),
    'gutter' => newsx_get_option('bp_slider_gutter'),
    'center_content' => newsx_get_option('bp_slider_center_content'),
    'autoplay' => newsx_get_option('bp_slider_autoplay'),
    'autoplay_delay' => newsx_get_option('bp_slider_autoplay_delay'),
    'title_tag' => newsx_get_option('bp_slider_title_tag'),
    'title_letter_count' => newsx_get_option('bp_slider_title_letter_count'),
    'excerpt_letter_count' => newsx_get_option('bp_slider_excerpt_letter_count'),
    'date_format' => newsx_get_option('bp_slider_date_format'),
    'date_show_time' => newsx_get_option('bp_slider_date_show_time'),
    'author_show_avatar' => newsx_get_option('bp_slider_show_avatar'),
    'author_avatar_size' => newsx_get_option('bp_slider_avatar_size'),
    'read_more_text' => newsx_get_option('bp_slider_read_more_text'),
];

if ( !defined('NEWSX_CORE_PRO_VERSION') || !newsx_core_pro_fs()->can_use_premium_code() ) {
    $instance['posts_per_page'] = 3;
    $instance['layout'] = '1-column';
    $instance['elements_preset'] = 's0';
    $instance['image_size'] = 'newsx-1200x600';
    $instance['author_show_avatar'] = false;
    $instance['date_show_time'] = false;
}

// Get Options
$show_arrows = newsx_get_option('bp_slider_nav_arrows');
$arrows_style = newsx_get_option('bp_slider_nav_style');

// Get Query Args
$main_query_args = newsx_get_main_query_args( $instance );
$instance['_main_query_args'] = $main_query_args;

// Get Posts
$slider_posts = new WP_Query( $main_query_args );

// Get Layout Class
$layout_class  = isset($instance['layout']) ? ' newsx-slider-'. $instance['layout'] : ' newsx-slider-1-column';
$layout_class .= isset( $instance['elements_preset'] ) ? ' newsx-'. $instance['elements_preset'] : '';

echo '<div class="newsx-slider-wrap">';

    // Edit Button
    echo newsx_customizer_edit_button_markup('bp_slider');

    echo '<div class="newsx-swiper swiper">';
        echo '<div class="newsx-slider swiper-wrapper '. esc_attr( $layout_class ) .'" data-id="blog-page-main-slider"  data-newsx-settings="'. esc_attr( wp_json_encode( $instance ) ) .'">';

        // Loop: Start
        if ( $slider_posts->have_posts() ) :
            while ( $slider_posts->have_posts() ) : $slider_posts->the_post();

                if ( !has_post_thumbnail() ) {
                    continue;
                }

                // Get Posts
                $posts_layout = new Newsx_Slider_Widget_Presets( $instance );
                $posts_layout->display();

            endwhile;
        endif;
        wp_reset_postdata();
        
        echo '</div>';
    echo '</div>'; // end .newsx-swiper


    if ( $show_arrows ) {
        echo '<div class="newsx-slider-prev newsx-slider-arrow swiper-button-prev newsx-'. esc_attr( $arrows_style ) .'"></div>';
        echo '<div class="newsx-slider-next newsx-slider-arrow swiper-button-next newsx-'. esc_attr( $arrows_style ) .'"></div>';
    }
    
echo '</div>'; // end .newsx-slider-wrap
