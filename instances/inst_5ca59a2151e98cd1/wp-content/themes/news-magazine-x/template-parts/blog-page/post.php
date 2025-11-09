<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Post Elements
require_once NEWSX_INCLUDES_DIR .'/widgets/loop/post-elements.php';

$instance = isset( $args['instance'] ) ? $args['instance'] : [];
$layout = isset( $instance['layout'] ) ? $instance['layout'] : '';
$widget = str_contains( $layout, 'list' ) ? 'list' : 'grid';

// Post Class
$post_class = implode( ' ', get_post_class( 'newsx-grid-item', get_the_ID() ) );

// Grid Item
echo '<article class="'. esc_attr( $post_class ) .'">';

    // Above Media
    if ( isset( $instance['_el_locations']['above'] ) ) :
    echo '<div class="newsx-grid-above-media">';

        newsx_get_post_elements_by_location( $instance, 'above' );

    echo '</div>';
    endif;

    // Grid Media
    echo '<div class="newsx-grid-media">';

        // Thumbnail
        newsx_post_thumbnail( $instance, $widget );

        // Over Media
        if ( isset( $instance['_el_locations']['over'] ) ) :
        echo '<div class="newsx-grid-over-media newsx-flex newsx-full-stretch">';

            newsx_get_post_elements_by_location( $instance, 'over' );

        echo '</div>';
        endif;

        // Post Format Icon
        newsx_post_format_icon_markup();

    echo '</div>';

    // Below Media
    if ( isset( $instance['_el_locations']['below'] ) ) :
    echo '<div class="newsx-grid-below-media">';

        newsx_get_post_elements_by_location( $instance, 'below' );

    echo '</div>';
    endif;

echo '</article>';
