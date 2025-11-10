<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Newsx_Featured_Tabs extends Newsx_Widget {

    public function __construct() {
		$this->widget_cssclass = 'newsx-featured-tabs-widget';
		$this->widget_description = esc_html__( 'Smaple description, remove if needed.', 'news-magazine-x' );
		$this->widget_name = esc_html__( 'NewsX: Featured Tabs', 'news-magazine-x' );

        // Register Pro Fields
        if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) :
            
            $this->sections = newsx_featured_tabs_widget_options();
        
        // Register Free Fields
        else:

		$this->sections = [
            'tab_1_section' => [
                'section_title' => [
                    'type'  => 'title',
                    'label' => esc_html__( 'Tab 1', 'news-magazine-x' ),
                ],
                't1_title' => [
                    'type' => 'text',
                    'default' => 'Latest',
                    'label' => esc_html__( 'Title', 'news-magazine-x' ),
                ],
                't1_orderby' => [
                    'type' => 'select',
                    'default' => 'date',
                    'choices' => newsx_get_orderby_query_choices(),
                    'label' => esc_html__( 'Order by', 'news-magazine-x' ),
                ],
                't1_categories' => [
                    'type' => 'select2',
                    'class' => 'multiselect', // Note: Remove this to select single option only
                    'default' => '',
                    'choices' => newsx_get_taxonomy_term_choices( 'category' ),
                    'label' => esc_html__( 'Categories', 'news-magazine-x' ),
                ],
                't1_tags' => [
                    'type' => 'select2',
                    'class' => 'multiselect',
                    'default' => '',
                    'choices' => newsx_get_taxonomy_term_choices( 'post_tag' ),
                    'label' => esc_html__( 'Tags', 'news-magazine-x' ),
                ],
            ],
            'tab_2_section' => [
                'section_title' => [
                    'type'  => 'title',
                    'label' => esc_html__( 'Tab 2', 'news-magazine-x' ),
                ],
                't2_title' => [
                    'type' => 'text',
                    'default' =>  'Popular',
                    'label' => esc_html__( 'Title', 'news-magazine-x' ),
                ],
                't2_orderby' => [
                    'type' => 'select',
                    'default' => 'popular',
                    'choices' => newsx_get_orderby_query_choices(),
                    'label' => esc_html__( 'Order by', 'news-magazine-x' ),
                ],
                't2_categories' => [
                    'type' => 'select2',
                    'class' => 'multiselect', // Note: Remove this to select single option only
                    'default' => '',
                    'choices' => newsx_get_taxonomy_term_choices( 'category' ),
                    'label' => esc_html__( 'Categories', 'news-magazine-x' ),
                ],
                't2_tags' => [
                    'type' => 'select2',
                    'class' => 'multiselect',
                    'default' => '',
                    'choices' => newsx_get_taxonomy_term_choices( 'post_tag' ),
                    'label' => esc_html__( 'Tags', 'news-magazine-x' ),
                ],
            ],
            'pro_options_section' => [
                'section_title' => [
                    'type'  => 'title',
                    'label' => esc_html__( 'Premium Options', 'news-magazine-x' ),
                ],
                'pro_features' => [
                    'type' => 'pro-features',
                    'description' => 'https://wp-royal-themes.com/themes/item-news-magazine-x-pro/?ref=newsx-free-widget-featured-tabs-upgrade-pro#features',
                    'choices' => [
                        '3rd-tab' => esc_html__( 'Display additional 3rd Tab', 'news-magazine-x' ),
                        'orderby' => esc_html__( ' Order: Modified, Popular, Random...', 'news-magazine-x' ),
                        'ppp' => esc_html__( 'Show X number of posts', 'news-magazine-x' ),
                        'accent_color' => esc_html__( 'Custom Accent Color', 'news-magazine-x' ),
                        'title_tag' => esc_html__( 'Change Title HTML Tag', 'news-magazine-x' ),
                        'more_link' => esc_html__( 'and much more...', 'news-magazine-x' ),
                    ],
                ],
            ],
		];

        endif; // End Free Fields
        
		parent::__construct();
    }

    public function render_tab_content( $instance, $tab_index ) {
        $posts = [];
        $date_query = [];
        $tax_query = [];
        $orderby = isset( $instance[$tab_index .'_orderby'] ) ? $instance[$tab_index .'_orderby'] : 'date';
        $posts_per_page = isset( $instance[$tab_index .'_posts_per_page'] ) ? $instance[$tab_index .'_posts_per_page'] : 3;
        $offset = isset( $instance[$tab_index .'_offset'] ) ? $instance[$tab_index .'_offset'] : 0;
        $title_tag = isset( $instance['title_tag'] ) ? $instance['title_tag'] : 'h6';
        $custom_categories = isset( $instance[$tab_index .'_categories'] ) ? array_values($instance[$tab_index .'_categories']) : [];
        $custom_tags = isset( $instance[$tab_index .'_tags'] ) ? array_values($instance[$tab_index .'_tags']) : [];

        if ( !empty($custom_categories) || !empty($custom_tags) ) {
            $tax_query = [
                'relation' => 'OR',
                !empty($custom_categories) ? [
                    'taxonomy' => 'category',
                    'field' => 'id',
                    'terms' => $custom_categories
                ] : [],
                !empty($custom_tags) ? [
                    'taxonomy' => 'post_tag', 
                    'field' => 'id',
                    'terms' => $custom_tags
                ] : []
            ];
        }
        
        // Post Views
        if ( str_contains($orderby, 'popular') && function_exists('pvc_get_most_viewed_posts') ) {

            if ( 'popular-custom' === $orderby ) {
                $date_query = [
                    [
                        'after' => $instance[$tab_index .'_published_days'] .' days ago',
                    ],
                ];
            }

            $posts = pvc_get_most_viewed_posts([
                'post_type' => 'post',
                'tax_query' => $tax_query,
                'posts_per_page' => $posts_per_page,
                'offset' => $offset,
                'date_query' => $date_query,
                'meta_query' => array(array('key' => '_thumbnail_id')),
            ]);
        
        // Other Queries
        } else {
            if ( 'random-custom' === $orderby ) {
                $orderby = 'rand';

                $date_query = [
                    [
                        'after' => $instance[$tab_index .'_published_days'] .' days ago',
                    ],
                ];
            }
            
            $args = [
                'post_type' => 'post',
                'orderby' => $orderby,
                'tax_query' => $tax_query,
                'date_query' => $date_query,
                'posts_per_page' => $posts_per_page,
                'offset' => $offset,
                'meta_query' => array(array('key' => '_thumbnail_id')),
            ];

            $custom_wp_query = new WP_Query( $args );
            $posts = $custom_wp_query->posts;
        }

        if ( ! empty($posts) ) {
            foreach ( $posts as $post ) {

                echo '<article class="newsx-post newsx-flex-nowrap">';
                    echo '<div class="newsx-post-image">';
                        echo '<a href="'. esc_url(get_permalink($post->ID)) .'">';
                            echo get_the_post_thumbnail($post->ID, 'newsx-150x100');
                        echo '</a>';
                        newsx_post_format_icon_markup($post->ID);
                    echo '</div>';

                    echo '<div class="newsx-post-meta newsx-flex">';
                        echo '<'. esc_html($title_tag) .'>';
                            echo '<a href="'. esc_url(get_permalink($post->ID)) .'" class="newsx-underline-hover">'. esc_html(get_the_title($post->ID)) .'</a>';
                        echo '</'. esc_html($title_tag) .'>';

                        echo '<span>'. esc_html(get_the_date('M d, Y', $post->ID)) .'</span>';
                        
                        // Post Views
                        newsx_post_views_markup($post->ID);
                    echo '</div>';
                echo '</article>';

            }
        } else {
            echo '<div class="newsx-no-posts">';
                echo '<p>'. esc_html__( 'No posts found', 'news-magazine-x' ) .'</p>';
            echo '</div>';
        }
        
        wp_reset_postdata();
    }

    // Output the content of the widget
    public function widget( $args, $instance ) {
		$this->widget_start( $args );

        echo '<div class="newsx-featured-tabs-widget">';

            echo '<div class="newsx-tabs-wrapper">';

                echo '<ul class="newsx-tabs newsx-flex-nowrap">';
                    if ( ! empty( $instance['t1_title'] ) ) {
                        echo '<li class="active" data-tab-id="1">';
                            echo '<span>'. esc_html($instance['t1_title']) .'</span>';
                        echo '</li>';
                    }

                    if ( ! empty( $instance['t2_title'] ) ) {
                        echo '<li data-tab-id="2">';
                            echo '<span>'. esc_html($instance['t2_title']) .'</span>';
                        echo '</li>';
                    }
                    
                    if ( ! empty( $instance['t3_title'] ) ) {
                        echo '<li data-tab-id="3">';
                            echo '<span>'. esc_html($instance['t3_title']) .'</span>';
                        echo '</li>';
                    }
                echo '</ul>';

                echo '<div class="newsx-tab-content newsx-tab-1 active">';
                    $this->render_tab_content( $instance, 't1' );
                echo '</div>';

                echo '<div class="newsx-tab-content newsx-tab-2">';
                    $this->render_tab_content( $instance, 't2' );
                echo '</div>';

                echo '<div class="newsx-tab-content newsx-tab-3">';
                    $this->render_tab_content( $instance, 't3' );
                echo '</div>';

            echo '</div>';
        
        echo '</div>';


		$this->widget_end( $args );
    }

}

// Register the widget
function register_featured_tabs_widget() {
    register_widget( 'Newsx_Featured_Tabs' );
}
add_action( 'widgets_init', 'register_featured_tabs_widget' );
