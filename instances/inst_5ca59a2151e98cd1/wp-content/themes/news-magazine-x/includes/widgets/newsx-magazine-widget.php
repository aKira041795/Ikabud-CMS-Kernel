<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Newsx_Magazine_Layout extends Newsx_Widget {

    public function __construct() {
		$this->widget_cssclass = 'newsx-magazine-widget';
		$this->widget_description = esc_html__( 'Smaple description, remove if needed.', 'news-magazine-x' );
		$this->widget_name = esc_html__( 'NewsX: Magazine Grid Layout', 'news-magazine-x' );

        // Register Pro Fields
        if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) :
            
            $this->sections = newsx_magazine_widget_options();
        
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
                    'label' => esc_html__( 'Widget Title', 'news-magazine-x' ),
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
                'layout' => [
                    'type' => 'radio-image',
                    'default' => '1-4',
                    'choices' => [
                        '1-4' => [
                            'image' => newsx_get_svg_icon('mgz-layout-3'),
                            'title' => esc_html__( '5 posts', 'news-magazine-x' ),
                        ],
                        '1-1-3' => [
                            'image' => newsx_get_svg_icon('mgz-layout-8'),
                            'title' => esc_html__( '5 posts', 'news-magazine-x' ),
                        ],
                        ],
                    'label' => esc_html__( 'Select Layout', 'news-magazine-x' ),
                ],
            ],
            'pro_options_section' => [
                'section_title' => [
                    'type'  => 'title',
                    'label' => esc_html__( 'Premium Options', 'news-magazine-x' ),
                ],
                'pro_features' => [
                    'type' => 'pro-features',
                    'description' => 'https://wp-royal-themes.com/themes/item-news-magazine-x-pro/?ref=newsx-free-widget-magazine-layout-upgrade-pro#features',
                    'choices' => [
                        'layouts' => esc_html__( '12 Magazine Grid Layouts', 'news-magazine-x' ),
                        'orderby' => esc_html__( ' Order: Modified, Popular, Random...', 'news-magazine-x' ),
                        'elements' => esc_html__( 'Show/Hide Post Elements', 'news-magazine-x' ),
                        'container_height' => esc_html__( 'Custom Container Height', 'news-magazine-x' ),
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

        // Widget Title
        get_template_part( 'includes/widgets/extras/widget-title', '', [ 'instance' => $instance, 'widget_args' => $args ] );

        // Get Layout Class
        $layout_class  = isset( $instance['layout'] ) ? ' newsx-magazine-layout-'. $instance['layout'] : '1-4';
        $layout_class .= isset( $instance['elements_preset'] ) ? ' newsx-'. $instance['elements_preset'] : '';

        echo '<div class="newsx-magazine-layout newsx-posts-widget'. esc_attr( $layout_class ) .'">';

		// Get Posts
		$posts = new WP_Query( newsx_get_main_query_args( $instance, 'magazine' ) );

        $isntace['_ajax_query'] = newsx_get_main_query_args( $instance, 'magazine' );

        // Post Index
        $post_index = 1;

        // Loop: Start
		if ( $posts->have_posts() ) :
            while ( $posts->have_posts() ) : $posts->the_post();

            // Get Posts
            $posts_layout = new Newsx_Magazine_Widget_Presets( $instance, $post_index, $posts->post_count );
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

		$this->widget_end( $args );
    }

}

// Register the widget
function register_magazine_layout_widget() {
    // Include Presets
    require_once NEWSX_INCLUDES_DIR .'/widgets/presets/class-newsx-magazine-widget-presets.php';

    register_widget( 'Newsx_Magazine_Layout' );
}
add_action( 'widgets_init', 'register_magazine_layout_widget' );
