<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Get Instance
$instance = isset($args['instance']) ? $args['instance'] : null;
$layout = isset($instance['layout']) ? $instance['layout'] : null;
$pagination_type = isset($instance['pagination_type']) ? $instance['pagination_type'] : null;

if ( 'list-4' === $layout || 'list-5'=== $layout  ) {
    return;
}

// Get Pages
global $paged;

$post_count = isset($instance['_post_count']) ? $instance['_post_count'] : intval($args['post_count']->publish);
$post_per_page = isset($instance['_main_query_args']['posts_per_page']) ? $instance['_main_query_args']['posts_per_page'] : get_option( 'posts_per_page' );
$pages = ceil($post_count / $post_per_page);
$range = $pages == $paged ? 2 : 1;

if ( empty( $paged ) ) {
	$paged = 1;
}

echo '<div class="newsx-grid-pagination newsx-flex newsx-pagination-'. esc_attr($pagination_type) .'">';

// AJAX Next Previous
if ( 'ajax-next-prev' === $pagination_type ) {

    echo '<div class="newsx-grid-next-prev newsx-flex">';

        echo '<div class="newsx-ajax-prev newsx-prev newsx-inline-flex newsx-disabled" data-page="0">';
            echo newsx_get_svg_icon('angle-left');
        echo '</div>';

        echo '<div class="newsx-ajax-next newsx-next newsx-inline-flex" data-page="0">';
            echo newsx_get_svg_icon('angle-right');
        echo '</div>';

    echo '</div>';

// AJAX Load More
} elseif ( 'ajax-load-more' === $pagination_type ) {

    echo '<div class="newsx-load-more newsx-inline-flex" data-page="0">';
        echo '<span>'. esc_html($instance['load_more_text']) .'</span>';
        echo '<div class="newsx-ring-loader"><div></div><div></div><div></div><div></div></div>';
    echo '</div>';

}

echo '</div>';