<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Newsx_Featured_Posts extends Newsx_Widget {

    public function __construct() {
		$this->widget_cssclass = 'newsx-featured-posts-widget';
		$this->widget_description = esc_html__( 'Smaple description, remove if needed.', 'news-magazine-x' );
		$this->widget_name = esc_html__( 'NewsX: Featured Posts', 'news-magazine-x' );

        // Register Pro Fields
        if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) :
            
            $this->sections = newsx_featured_posts_widget_options();
        
        // Register Free Fields
        else:

		$this->sections = [
            'widget_general_section' => [
                'section_title' => [
                    'type'  => 'title',
                    'label' => esc_html__( 'General', 'news-magazine-x' ),
                ],
                'widget_title' => [
                    'type' => 'text',
                    'default' => 'Featured Posts',
                    'label' => esc_html__( 'Title', 'news-magazine-x' ),
                ],
            ],
            'query_section' => [
                'section_title' => [
                    'type'  => 'title',
                    'label' => esc_html__( 'Query', 'news-magazine-x' ),
                ],
                'orderby' => [
                    'type' => 'select',
                    'default' => 'date',
                    'choices' => newsx_get_orderby_query_choices(),
                    'label' => esc_html__( 'Order by', 'news-magazine-x' ),
                ],
                'categories' => [
                    'type' => 'select2',
                    'class' => 'multiselect', // Note: Remove this to select single option only
                    'default' => '',
                    'choices' => newsx_get_taxonomy_term_choices( 'category' ),
                    'label' => esc_html__( 'Categories', 'news-magazine-x' ),
                ],
                'tags' => [
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
                    'description' => 'https://wp-royal-themes.com/themes/item-news-magazine-x-pro/?ref=newsx-free-widget-featured-posts-upgrade-pro#features',
                    'choices' => [
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

    public function render_posts( $instance ) {
        $posts = [];
        $date_query = [];
        $orderby = isset($instance['orderby']) ? $instance['orderby'] : 'date';
        $posts_per_page = isset($instance['posts_per_page']) ? $instance['posts_per_page'] : 3;
        $offset = isset($instance['offset']) ? $instance['offset'] : 0;
        $title_tag = isset($instance['title_tag']) ? $instance['title_tag'] : 'h6';

        // Post Views
        if ( str_contains($orderby, 'popular') && function_exists('pvc_get_most_viewed_posts') ) {

            if ( 'popular-custom' === $orderby ) {
                $date_query = [
                    [
                        'after' => $instance['published_days'] .' days ago',
                    ],
                ];
            }
            
            $posts = pvc_get_most_viewed_posts([
                'post_type' => 'post',
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
                        'after' => $instance['published_days'] .' days ago',
                    ],
                ];
            }
            
            $args = [
                'post_type' => 'post',
                'orderby' => $orderby,
                'date_query' => $date_query,
                'tax_query' => newsx_get_tax_query_args( $instance ),
                'posts_per_page' => $posts_per_page,
                'offset' => $offset,
                'meta_query' => array(array('key' => '_thumbnail_id')),
            ];

            $custom_wp_query = new WP_Query( $args );
            $posts = $custom_wp_query->posts;
        }

        $post_index = 1;

        if ( ! empty($posts) ) {
            foreach ( $posts as $post ) {

                echo '<article class="newsx-post newsx-flex-nowrap">';
                    echo '<div class="newsx-post-image">';
                    echo '<span class="newsx-post-index">'. esc_html($post_index) .'</span>';
                        echo '<a href="'. esc_url(get_permalink($post->ID)) .'">';
                            echo get_the_post_thumbnail($post->ID, 'newsx-150x100');
                        echo '</a>';
                        newsx_post_format_icon_markup($post->ID);
                    echo '</div>';

                    echo '<div class="newsx-post-meta newsx-flex">';
                        echo '<'. esc_html($title_tag) .'>';
                            echo '<a href="'. esc_url(get_permalink($post->ID)) .'" class="newsx-underline-hover">'. esc_html(get_the_title($post->ID)) .'</a>';
                        echo '</'. esc_html($title_tag) .'>';
                        echo '<span>'. esc_html(get_the_date('', $post->ID)) .'</span>';

                        // Post Views
                        newsx_post_views_markup($post->ID);
                    echo '</div>';
                echo '</article>';

                $post_index++;
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

        // Widget Title
        get_template_part( 'includes/widgets/extras/widget-title', '', [ 'instance' => $instance, 'widget_args' => $args ] );

        echo '<div class="newsx-featured-posts">';

            $this->render_posts( $instance );
        
        echo '</div>';


		$this->widget_end( $args );
    }

}

// Register the widget
function register_featured_posts_widget() {
    register_widget( 'Newsx_Featured_Posts' );
}
add_action( 'widgets_init', 'register_featured_posts_widget' );
