<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}


// Post Elements
require_once NEWSX_INCLUDES_DIR .'/widgets/loop/post-elements.php';

// Widget Instance
$instance = $args['instance'];

// Post Class
$post_class = implode( ' ', get_post_class( 'newsx-grid-item', get_the_ID() ) );

// Grid Item
echo '<article class="'. esc_attr( $post_class ) .'">';

    // Grid Media
    echo '<div class="newsx-grid-media newsx-full-stretch">';

        // Thumbnail
        newsx_post_thumbnail( $instance, 'magazine' );

        // Over Media
        echo '<div class="newsx-grid-over-media newsx-full-stretch">';
            newsx_media_hover_link( $instance );
            newsx_get_post_elements_by_location( $instance, 'over' );
        echo '</div>';

        // Post Format Icon
        newsx_post_format_icon_markup();

    echo '</div>';

echo '</article>';
