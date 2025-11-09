<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Post Elements
require_once NEWSX_INCLUDES_DIR .'/widgets/loop/post-elements.php';

// Widget Instance
$instance = $args['instance'];

// Post Class
$post_class = implode( ' ', get_post_class( 'newsx-slider-item', get_the_ID() ) );
$flex_class = isset( $instance['center_content'] ) && $instance['center_content'] ? 'newsx-flex-center' : 'newsx-flex';

// Slider Item
echo '<div class="swiper-slide">';

echo '<article class="'. esc_attr( $post_class ) .'">';

    // Slider Media
    echo '<div class="newsx-slider-media">';

        // Thumbnail
        newsx_slider_thumbnail( $instance );

        // Over Media
        if ( isset( $instance['_el_locations']['over'] ) ) :
        echo '<div class="newsx-slider-over-media newsx-full-stretch '. esc_attr($flex_class) .'">';

            newsx_get_post_elements_by_location( $instance, 'over' );

        echo '</div>';
        endif;

    echo '</div>';

echo '</article>';

echo '</div>';
