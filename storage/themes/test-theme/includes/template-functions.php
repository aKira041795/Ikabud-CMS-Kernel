<?php
/**
 * Template Functions
 * 
 * Helper functions for DiSyL templates
 * 
 * @package test-theme
 */

/**
 * Get theme option with default fallback
 */
function test-theme_get_option($key, $default = '') {
    return get_theme_mod('test-theme_' . $key, $default);
}

/**
 * Get post data formatted for DiSyL
 */
function test-theme_get_post_data($post = null) {
    if (!$post) {
        $post = get_post();
    }
    
    if (!$post) {
        return null;
    }
    
    return array(
        'id' => $post->ID,
        'title' => get_the_title($post),
        'content' => apply_filters('the_content', $post->post_content),
        'excerpt' => get_the_excerpt($post),
        'url' => get_permalink($post),
        'date' => get_the_date('', $post),
        'modified' => get_the_modified_date('', $post),
        'author' => get_the_author_meta('display_name', $post->post_author),
        'author_url' => get_author_posts_url($post->post_author),
        'thumbnail' => get_the_post_thumbnail_url($post, 'large'),
        'categories' => wp_get_post_categories($post->ID, array('fields' => 'names')),
        'tags' => wp_get_post_tags($post->ID, array('fields' => 'names')),
    );
}

/**
 * Get posts formatted for DiSyL query component
 */
function test-theme_get_posts($args = array()) {
    $defaults = array(
        'post_type' => 'post',
        'posts_per_page' => 10,
        'post_status' => 'publish',
    );
    
    $args = wp_parse_args($args, $defaults);
    $query = new WP_Query($args);
    $posts = array();
    
    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $posts[] = test-theme_get_post_data();
        }
        wp_reset_postdata();
    }
    
    return $posts;
}

/**
 * Get pagination data for DiSyL
 */
function test-theme_get_pagination() {
    global $wp_query;
    
    return array(
        'current' => max(1, get_query_var('paged')),
        'total' => $wp_query->max_num_pages,
        'prev_url' => get_previous_posts_link() ? get_previous_posts_page_link() : null,
        'next_url' => get_next_posts_link() ? get_next_posts_page_link() : null,
    );
}
