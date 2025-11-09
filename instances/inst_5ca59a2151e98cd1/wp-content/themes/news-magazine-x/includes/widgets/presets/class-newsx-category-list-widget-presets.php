<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Newsx_Category_List_Widget_Presets {
    private $instance;
    private $category;
    private $preset;

    public function __construct( $instance, $category, $preset ) {
        $this->instance = $instance;
        $this->category = $category;

        if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
            $this->preset = $preset;
        } else {
            $this->preset = in_array($preset, ['s0', 's2']) ? $preset : 's0';
        }
    }

    // Display
    public function display() {
        
        // Switch Presets
        switch ( $this->preset ) {
            case 's1':
                $this->elements_preset_s1();
                break;

            case 's2':
                $this->elements_preset_s2();
                break;

            default:
                $this->elements_preset_default();
                break;
        }

    }

    public function get_category_image_url( $category ) {
        $category_image_url = '';
        $category_image_attr = '';

        // Get the first post with a thumbnail
        $args = [
            'cat' => $category->term_id,
            'posts_per_page' => 1,
        ];
    
        $query = new WP_Query($args);
    
        if ( $query->have_posts() ) {
            while ( $query->have_posts() ) {
                $query->the_post();
    
                if ( has_post_thumbnail() ) {
                    $category_image_url = get_the_post_thumbnail_url( get_the_ID(), 'newsx-420x280' );
                }
            }
        }
    
        wp_reset_postdata(); // Reset the query

        return $category_image_url;
    }

    public function category_title_markup() {
        if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
            $title_tag = isset($this->instance['title_tag']) ? $this->instance['title_tag'] : 'span';
        } else {
            if ( 's2' === $this->preset ) {
                $title_tag = 'h4';
            } else {
                $title_tag = 'span';
            }
        }
        echo '<'. esc_html($title_tag) .' class="category-name">'. esc_html($this->category->name) .'</'. esc_html($title_tag) .'>';
    }

    public function elements_preset_s1() {
        $category_image_attr = '' !== $this->get_category_image_url( $this->category ) ? ' style="background-image: url('. esc_url($this->get_category_image_url( $this->category )) .')"' : '';

        // Output the Category
        echo '<li'. $category_image_attr .'>';
            echo '<a href="'. esc_url(get_category_link($this->category->term_id)) .'" class="newsx-flex">';
                $this->category_title_markup();
                echo '<span class="category-count newsx-flex">'. esc_html($this->category->count) .'</span>';
            echo '</a>';
        echo '</li>';
    }

    public function elements_preset_s2() {
        $category_image_url = $this->get_category_image_url( $this->category );

        // Output the Category
        echo '<li>';
            if ( '' !== $category_image_url ) {
                echo '<a href="'. esc_url(get_category_link($this->category->term_id)) .'" class="newsx-flex">';
                    echo '<img src="'. esc_url($category_image_url) .'" alt="'. esc_attr($this->category->name) .'">';
                echo '</a>';
            }
            echo '<div class="category-meta">';
                echo '<a href="'. esc_url(get_category_link($this->category->term_id)) .'" class="newsx-flex">';
                $this->category_title_markup();
                echo '</a>';
                echo '<span class="category-count newsx-flex">'. esc_html($this->category->count) .'&nbsp;'. esc_html__('Articles', 'news-magazine-x') .'</span>';
            echo '</div>';
        echo '</li>';
    }

    public function elements_preset_default() {
        echo '<li>';
            echo '<a href="'. esc_url(get_category_link($this->category->term_id)) .'" class="newsx-flex">';
                echo newsx_get_svg_icon('chevron-right');
                $this->category_title_markup();
                echo '<span class="category-count newsx-flex">('. esc_html($this->category->count) .')</span>';
            echo '</a>';
        echo '</li>';
    }
}