<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Widget Instance
$instance = $args['instance'];

$display_taxonomy = isset( $instance['display_taxonomy'] ) ? $instance['display_taxonomy'] : 'view-all';
$widget_url = isset( $instance['widget_url'] ) ? $instance['widget_url'] : '';
$view_all_text = isset( $instance['view_all_text'] ) ? $instance['view_all_text'] : 'h3';

if ( !defined('NEWSX_CORE_PRO_VERSION') || !newsx_core_pro_fs()->can_use_premium_code() ) {
    $display_taxonomy = 'view-all';
}

// All Filters
if ( 'view-all' === $display_taxonomy ) {
    echo '<div class="newsx-grid-view-all">';
        echo '<a href="'. esc_url( $widget_url ) .'">';
            echo esc_html( $view_all_text );
        echo '</a>';
    echo '</div>';

// Taxonomy Filters
} elseif ( 'ajax-filters' === $display_taxonomy ) {
    $selected_terms = isset($instance['filter_tax_category']) ? $instance['filter_tax_category'] : [];
    
    $filter_terms = get_terms([
        'taxonomy' => 'category',
        'include' => $selected_terms,
        'hide_empty' => true
    ]);

    echo '<div class="newsx-grid-ajax-filters newsx-flex newsx-not-visible" data-taxonomy="category">';
        echo '<ul class="newsx-flex">';
            if ( ! empty($instance['filter_all_text']) ) {
                echo '<li class="newsx-grid-filter" data-filter="all">'. esc_html($instance['filter_all_text']) .'</li>';
            }
            
            foreach ( $filter_terms as $filter_term ) {
                echo '<li class="newsx-grid-filter" data-filter="'. esc_attr($filter_term->slug) .'">' . esc_html( $filter_term->name ) . '</li>';
            }
        echo '</ul>';

        echo '<div class="newsx-grid-filters-dropdown-wrap">';
            echo '<div class="newsx-grid-filters-dropdown-more newsx-inline-flex">';
                echo '<span>'. esc_html('More', 'news-magazine-x') .'</span>';
                echo newsx_get_svg_icon('chevron-down');
            echo '</div>';

            echo '<ul class="newsx-grid-filters-dropdown"></ul>';

        echo '</div>';
    echo '</div>';
}