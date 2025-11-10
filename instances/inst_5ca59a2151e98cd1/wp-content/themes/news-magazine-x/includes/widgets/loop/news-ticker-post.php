<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}


// Post Elements
require_once NEWSX_INCLUDES_DIR .'/widgets/loop/post-elements.php';

// Widget Instance
$instance = $args['instance'];


// Ticker Title
if ( !function_exists('newsx_news_ticker_title') ) {
    function newsx_news_ticker_title( $instance, $class = '' ) {
        $class = '' !== $class ? ' '. $class : '';
        $count = (isset($instance['title_letter_count']) && '' !== $instance['title_letter_count']) ? intval($instance['title_letter_count']) : 999;
        $title = strlen(html_entity_decode(get_the_title())) > $count ? substr(html_entity_decode(get_the_title()), 0, $count) . '...' : get_the_title();
        $link_target = $instance['open_in_new_tab'] ? 'target="_blank"' : '';
    
        echo '<a class="newsx-news-ticker-title'. esc_attr($class) .'" href="'. esc_url( get_permalink() ) .'" '. $link_target .'>';
            echo '<p>'. wp_kses_post( $title ) .'</p>';
        echo '</a>';
    }
}

// Ticker Slider Thumbnail
if ( !function_exists('newsx_news_ticker_thumbnail') ) {
    function newsx_news_ticker_thumbnail( $instance ) {
        if ( has_post_thumbnail() ) {
            $link_target = $instance['open_in_new_tab'] ? 'target="_blank"' : '';

            echo '<a href="'. esc_url( get_permalink() ) .'" '. $link_target .' class="newsx-slider-image" title="'. esc_attr( get_the_title() ) .'">';
                the_post_thumbnail('newsx-150x100');
            echo '</a>';
        }
    }
}

// Ticker Item
echo '<article class="newsx-flex-center-vr">';

    // Thumbnail
    if ( '1' == $instance['show_image'] ) {
        newsx_news_ticker_thumbnail( $instance );
    }

    echo '<div>';
        newsx_news_ticker_title( $instance );
    echo '</div>';

echo '</article>';
