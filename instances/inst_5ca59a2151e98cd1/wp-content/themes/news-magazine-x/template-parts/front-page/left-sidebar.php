<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$widget_area = $args['widget_area'];

if ( 0 != $widget_area ) {
    echo '<aside class="newsx-left-sidebar newsx-sidebar">';
        newsx_dynamic_sidebar('front-page-section-'. $widget_area .'-left-sidebar');
    echo '</aside>';
} else {
    if ( is_customize_preview() ) {
        esc_html_e( 'Please Select Widgets Area.', 'news-magazine-x' );
    }
}
