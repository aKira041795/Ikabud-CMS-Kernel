<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Widget Instance
$instance = $args['instance'];

if ( empty($instance['widget_title']) ) {
    return;
}

// Widget Args
$widget_args = $args['widget_args'];
$widget_id = isset($widget_args['id']) ? $widget_args['id'] : '';

// Attributes
$title_width = isset($instance['widget_title_width']) ? $instance['widget_title_width'] : '';
$title_class =  str_contains($widget_id, 'footer') ? 'newsx-'. newsx_get_option('global_widget_footer_title_style') : 'newsx-'. newsx_get_option('global_widget_title_style');


if ( 'container' == $title_width ) {
    echo '<div class="newsx-container">';
}

echo '<div class="newsx-widget-title-wrap newsx-flex '. esc_attr($title_class) .'">';

    // Widget Title
    echo '<h3 class="newsx-widget-title">';
        if ( ! empty( $instance['widget_url']) ) {
            echo '<a href="'. esc_url( $instance['widget_url'] ) .'" class="newsx-widget-title-text">';
                echo esc_html( $instance['widget_title'] );
            echo '</a>';
        } else {
            echo '<span class="newsx-widget-title-text">'. esc_html( $instance['widget_title'] ) .'</span>';
        }
    echo '</h3>';

    // Grid Filters
    if ( isset( $instance['display_taxonomy'] ) ) {
        get_template_part( 'includes/widgets/extras/grid-filters', '', [ 'instance' => $instance ] );
    }

echo '</div>';

if ( 'container' == $title_width ) {
    echo '</div>';
}
