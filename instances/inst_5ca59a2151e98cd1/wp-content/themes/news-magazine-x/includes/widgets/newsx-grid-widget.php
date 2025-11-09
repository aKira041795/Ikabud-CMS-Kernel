<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Newsx_Grid_Layout extends Newsx_Widget {

    public function __construct() {
		$this->widget_cssclass = 'newsx-grid-widget';
		$this->widget_description = esc_html__( 'Smaple description, remove if needed.', 'news-magazine-x' );
		$this->widget_name = esc_html__( 'NewsX: Posts Grid Layout', 'news-magazine-x' );

        // Register Pro Fields
        if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) :
            
            $this->sections = newsx_grid_widget_options();
        
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
                    'default' => 'Latest Posts',
                    'label' => esc_html__( 'Title', 'news-magazine-x' ),
                ],
                'extras_group_title' => [
                    'type' => 'group-title',
                    'label' => esc_html__( 'Extras', 'news-magazine-x' ),
                ],
                'widget_url' => [
                    'type' => 'url',
                    'default' => '#',
                    'label' => esc_html__( 'Custom URL', 'news-magazine-x' ),
                ],
                'view_all_text' => [
                    'type' => 'text',
                    'default' => 'View All',
                    'label' => esc_html__( 'View All Text', 'news-magazine-x' ),
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
            'layout_section' => [
                'section_title' => [
                    'type' => 'title',
                    'label' => esc_html__( 'Layout', 'news-magazine-x' ),
                ],
                'image_size' => [
                    'type' => 'select',
                    'default' => 'full',
                    'choices' => newsx_get_image_sizes(),
                    'label' => esc_html__( 'Image Size', 'news-magazine-x' ),
                ],
            ],
            'pro_options_section' => [
                'section_title' => [
                    'type'  => 'title',
                    'label' => esc_html__( 'Premium Options', 'news-magazine-x' ),
                ],
                'pro_features' => [
                    'type' => 'pro-features',
                    'description' => 'https://wp-royal-themes.com/themes/item-news-magazine-x-pro/?ref=newsx-free-widget-grid-layout-upgrade-pro#features',
                    'choices' => [
                        'layouts' => esc_html__( '1, 2, 3, and 4 Column Grid Layouts', 'news-magazine-x' ),
                        'orderby' => esc_html__( ' Order: Modified, Popular, Random...', 'news-magazine-x' ),
                        'ajax-filters' => esc_html__( 'AJAX Category Filters', 'news-magazine-x' ),
                        'ajax-load-more' => esc_html__( 'AJAX Load More', 'news-magazine-x' ),
                        'ajax-pagination' => esc_html__( 'AJAX Pagination', 'news-magazine-x' ),
                        'elements' => esc_html__( 'Show/Hide Post Elements', 'news-magazine-x' ),
                        'ppp' => esc_html__( 'Show X number of posts', 'news-magazine-x' ),
                        'accent_color' => esc_html__( 'Custom Accent Color', 'news-magazine-x' ),
                        'excerpt_length' => esc_html__( 'Custom Excerpt Length', 'news-magazine-x' ),
                        'title_tag' => esc_html__( 'Change Title HTML Tag', 'news-magazine-x' ),
                        'date_format' => esc_html__( 'Date Format: Last Updated', 'news-magazine-x' ),
                        'more_link' => esc_html__( 'and much more...', 'news-magazine-x' ),
                    ],
                ],
            ],
		];

        endif; // End Free Fields
        
		parent::__construct();
    }

    // Output the content of the widget
    public function widget( $args, $instance ) {
		$this->widget_start( $args );

        $instance['display_taxonomy'] = isset($instance['display_taxonomy']) ? $instance['display_taxonomy'] : 'view-all';

        // Widget Title
        get_template_part( 'includes/widgets/extras/widget-title', '', [ 'instance' => $instance, 'widget_args' => $args ] );

        // Add Query Args to Instance for AJAX
        $main_query_args = newsx_get_main_query_args( $instance, 'grid' );
        $instance['_main_query_args'] = $main_query_args;
        $instance['_layout_type'] = 'grid';

        if ( !defined('NEWSX_CORE_PRO_VERSION') || !newsx_core_pro_fs()->can_use_premium_code() ) {
            $main_query_args['posts_per_page'] = 6;
        }

		// Get Posts
		$posts = new WP_Query( $main_query_args );

        // Get Post Count
        $instance['_post_count'] = $posts->found_posts;

        // Get Layout Class
        $layout_class  = isset($instance['layout']) ? ' newsx-grid-layout-'. $instance['layout'] : ' newsx-grid-layout-2-column';
        $layout_class .= isset($instance['elements_preset']) ? ' newsx-'. $instance['elements_preset'] : ' newsx-s0';

        echo '<div class="newsx-grid-layout newsx-posts-widget'. esc_attr( $layout_class ) .'" data-id="'. esc_attr($this->id) .'"  data-newsx-settings="'. esc_attr( wp_json_encode( $instance ) ) .'">';

        // Post Index
        $post_index = 1;

        // Loop: Start
		if ( $posts->have_posts() ) :
            while ( $posts->have_posts() ) : $posts->the_post();

                // Get Posts
                $posts_layout = new Newsx_Grid_Widget_Presets( $instance, $post_index, $posts->post_count );
                $posts_layout->display();


                $post_index++;

            endwhile;

        else:
            echo '<div class="newsx-no-posts">';
                echo '<p>'. esc_html__( 'No posts found', 'news-magazine-x' ) .'</p>';
            echo '</div>';
        endif;
        
        wp_reset_postdata();
        
        echo '</div>';
        
        // Pagination
        get_template_part( 'includes/widgets/extras/grid-pagination', '', [ 'instance' => $instance ] );

		$this->widget_end( $args );
    }

}

// Register the widget
function register_grid_layout_widget() {
    // Include Presets
    require_once NEWSX_INCLUDES_DIR .'/widgets/presets/class-newsx-grid-widget-presets.php';

    register_widget( 'Newsx_Grid_Layout' );
}
add_action( 'widgets_init', 'register_grid_layout_widget' );
