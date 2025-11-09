<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$widget_area = $args['widget_area'];

if ( 0 != $widget_area ) {
    echo '<section class="newsx-main-content">';
        newsx_dynamic_sidebar('front-page-section-'. $args['widget_area'] .'-content');
    echo '</section>';
} else {
    if ( is_customize_preview() ) {
        esc_html_e( 'Please Select Widgets Area.', 'news-magazine-x' );
    }
}
