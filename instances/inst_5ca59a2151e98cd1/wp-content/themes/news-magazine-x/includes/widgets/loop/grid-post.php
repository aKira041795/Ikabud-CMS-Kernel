<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Post Elements
require_once NEWSX_INCLUDES_DIR .'/widgets/loop/post-elements.php';

// Widget Instance
$instance = $args['instance'];
$index = isset( $instance['_post_index'] ) ? $instance['_post_index'] : 0;
$layout = isset( $instance['layout'] ) ? $instance['layout'] : '';
$widget = str_contains( $layout, 'list' ) ? 'list' : 'grid';

// Post Class
$post_class = implode( ' ', get_post_class( 'newsx-grid-item', get_the_ID() ) );

// Add big class to List Layout posts
switch ( $layout ) {
    case 'list-1':
    case 'list-2':
    case 'list-3':
    case 'list-4':
    case 'list-5':
        if ( 1 === $index ) {
            $post_class .= ' newsx-big-post';
        }
        break;
        
    case 'list-6':
        if ( 2 >= $index ) {
            $post_class .= ' newsx-big-post';
        }
        break;
}

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

            newsx_media_hover_link( $instance );
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
