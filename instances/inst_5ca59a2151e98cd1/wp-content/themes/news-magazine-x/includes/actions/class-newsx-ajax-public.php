<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Newsx_AJAX_Public {
    public function __construct() {
        add_action('wp_ajax_newsx_ajax_search', [$this, 'ajax_search_callback']);
        add_action('wp_ajax_nopriv_newsx_ajax_search', [$this, 'ajax_search_callback']);
        add_action('wp_ajax_load_posts_by_ajax', [$this, 'load_posts_by_ajax_callback']);
        add_action('wp_ajax_nopriv_load_posts_by_ajax', [$this, 'load_posts_by_ajax_callback']);
        add_action('wp_ajax_load_single_posts_by_ajax', [$this, 'load_single_posts_by_ajax_callback']);
        add_action('wp_ajax_nopriv_load_single_posts_by_ajax', [$this, 'load_single_posts_by_ajax_callback']);
        add_action('wp_ajax_get_post_count', [$this, 'get_post_count_callback']);
        add_action('wp_ajax_nopriv_get_post_count', [$this, 'get_post_count_callback']);
    }

    public function ajax_search_callback() {
		if ( !isset( $_POST['nonce'] ) || !wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'newsx-main' ) ) {
            return;
        }
		
        $query = sanitize_text_field(newsx_get_option('header_search_query'));

        $the_query = new \WP_Query( 
            [
                'posts_per_page' => 5, 
                's' => sanitize_text_field( $_POST['newsx_keyword'] ),
                'post_type' => 'all' === $query ? ['post', 'page'] : newsx_get_option('header_search_query'),
                'post_status' => 'publish'
            ]
        );
        
        if ( $the_query->have_posts() ) {
            while( $the_query->have_posts() ) : $the_query->the_post();
                echo '<li>';
                    echo '<a href="'. get_the_permalink() .'" class="search-results-image">'. get_the_post_thumbnail(get_the_ID(), 'newsx-330x220') .'</a>';
                    echo '<div class="search-results-content">';
                        echo '<a href="'. get_the_permalink() .'" class="newsx-underline-hover">'. get_the_title() .'</a>';
                        echo '<span>'. get_the_date() .'</span>';
                    echo '</div>';
                echo '</li>';
            endwhile;

            if ( $the_query->found_posts > 5 ) {
                echo '<li class="newsx-search-results-view-all"><a href="'. get_search_link( sanitize_text_field($_POST['newsx_keyword']) ) .'">'. esc_html__('View all results', 'news-magazine-x') .'</a></li>';
            }

            wp_reset_postdata();
            
        } else {
            echo '<li class="newsx-no-results">'. esc_html__('Nothing Found.', 'news-magazine-x') .'</li>';
        }
        
        wp_die();
    }
        
    public function get_post_count_callback() {
		if ( !isset( $_POST['nonce'] ) || !wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'newsx-main' ) ) {
            return;
        }

        // Ensure nonce is valid for security
        // check_ajax_referer('get_post_count_nonce', 'nonce');
    
        // Define your query parameters based on the AJAX request
        $args = json_decode(stripslashes($_POST['_main_query_args']), true);
    
        $query = new WP_Query($args);
        $post_count = $query->found_posts;
    
        wp_send_json_success([
            'post_count' => $post_count,
            'args' => $args
        ]);
    
        wp_die();
    }
    
    public function load_posts_by_ajax_callback() {
		if ( !isset( $_POST['nonce'] ) || !wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'newsx-main' ) ) {
            return;
        }
    
        $instance = json_decode(stripslashes($_POST['instance']), true);

        $args = $instance['_main_query_args'];
        
        $query = new WP_Query($args);
    
        // Post Index
        $post_index = isset($instance['_loading_more']) && $instance['_loading_more'] ? 9 : 1; // 9 - Load More "hack"
    
        if ( $query->have_posts() ) {
            while ($query->have_posts()) :
                $query->the_post();

                // Grid Layout
                if ( 'grid' === $instance['_layout_type'] ) {
                    $posts_layout = new Newsx_Grid_Widget_Presets( $instance, $post_index, $query->post_count );
                    $posts_layout->display();
                
                // List Layout
                } elseif ( 'list' === $instance['_layout_type'] ) {
                    $posts_layout = new Newsx_List_Widget_Presets( $instance, $post_index, $query->post_count );
                    $posts_layout->display();
                }
    
                $post_index++;
            endwhile;
        }

        wp_reset_postdata();
        wp_die();
    }
    
    public function load_single_posts_by_ajax_callback() {
        // Verify nonce
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'newsx-main' ) ) {
            wp_send_json_error( 'Invalid nonce.' );
            wp_die();
        }
    
        // Check for the passed current post ID
        $current_post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : false;
    
        // Set up the global $post variable
        global $post;
        $post = get_post( $current_post_id );
    
        if ( ! $post ) {
            wp_send_json_error( 'Invalid post ID.' );
        }
    
        setup_postdata( $post );
    
        // Get the next post
        $load_from_same_cat = newsx_get_option('bs_advanced_load_posts_same_cat');
        $next_post = get_adjacent_post( $load_from_same_cat ? true : false, '', true );
    
        if ( ! empty( $next_post ) ) {
            $next_post_id = $next_post->ID;
    
            // Output the post's content
            ob_start();
            ?>
            
            <?php get_template_part( 'template-parts/blog-single/single-wrap', '', ['post-id' => $next_post_id] ); ?>

            <?php
            $output = ob_get_clean(); // Get buffered content
    
            wp_send_json_success( $output );
        } else {
            wp_send_json_error( 'No adjacent post found.' );
        }
    
        // Reset post data to avoid conflicts
        wp_reset_postdata();
    
        wp_die(); // Terminate AJAX request
    }
}

new Newsx_AJAX_Public();
