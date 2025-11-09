<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Newsx_Category_List extends Newsx_Widget {

    public function __construct() {
		$this->widget_cssclass = 'newsx-category-list-widget';
		$this->widget_description = esc_html__( 'Smaple description, remove if needed.', 'news-magazine-x' );
		$this->widget_name = esc_html__( 'NewsX: Category List', 'news-magazine-x' );

        // Register Pro Fields
        if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) :
            
            $this->sections = newsx_category_list_widget_options();
        
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
                    'default' => 'Category List',
                    'label' => esc_html__( 'Title', 'news-magazine-x' ),
                ],
            ],
            'query_section' => [
                'section_title' => [
                    'type'  => 'title',
                    'label' => esc_html__( 'Query', 'news-magazine-x' ),
                ],
                'categories' => [
                    'type' => 'select2',
                    'class' => 'multiselect', // Note: Remove this to select single option only
                    'default' => '',
                    'choices' => newsx_get_taxonomy_term_choices( 'category' ),
                    'label' => esc_html__( 'Categories', 'news-magazine-x' ),
                ],
            ],
            'layout_section' => [
                'section_title' => [
                    'type' => 'title',
                    'label' => esc_html__( 'Layout', 'news-magazine-x' ),
                ],
                'elements_preset' => [
                    'type' => 'select',
                    'default' => 's0',
                    'choices' => [
                        's0' => esc_html__( 'Default', 'news-magazine-x' ),
                        's2' => esc_html__( 'Style 2', 'news-magazine-x' ),
                    ],
                    'label' => esc_html__( 'Layout Preset', 'news-magazine-x' ),
                ],
            ],
            'pro_options_section' => [
                'section_title' => [
                    'type'  => 'title',
                    'label' => esc_html__( 'Premium Options', 'news-magazine-x' ),
                ],
                'pro_features' => [
                    'type' => 'pro-features',
                    'description' => 'https://wp-royal-themes.com/themes/item-news-magazine-x-pro/?ref=newsx-free-widget-category-list-upgrade-pro#features',
                    'choices' => [
                        'layouts' => esc_html__( '3 Category List Layouts', 'news-magazine-x' ),
                        'accent_color' => esc_html__( 'Custom Accent Color', 'news-magazine-x' ),
                        'title_tag' => esc_html__( 'Change Title HTML Tag', 'news-magazine-x' ),
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

        $elements_preset = isset($instance['elements_preset']) ? $instance['elements_preset'] : 's0';
        $layout_class = 'newsx-'. $elements_preset;

        echo '<ul class="newsx-category-list '. esc_attr($layout_class) .'">';

		// Get Categories
        $number = (( !defined('NEWSX_CORE_PRO_VERSION') || !newsx_core_pro_fs()->can_use_premium_code() ) && 's2' === $elements_preset) ? 4 : '';

        $categories = get_categories([
            'orderby'    => 'name',
            'order'      => 'ASC', 
            'include'    => isset($instance['categories']) ? $instance['categories'] : [],
            'hide_empty' => true,
            'number'     => $number, // Limit to 4 categories for free version
        ]);
        
        foreach( $categories as $category ) {

            // Get Posts
            $posts_layout = new Newsx_Category_List_Widget_Presets( $instance, $category, $elements_preset );
            $posts_layout->display();

        }
        
        echo '</ul>';
        // query exclude posts w/o thumbnails

		$this->widget_end( $args );
    }

}

// Register the widget
function register_category_list_widget() {
    // Include Presets
    require_once NEWSX_INCLUDES_DIR .'/widgets/presets/class-newsx-category-list-widget-presets.php';

    register_widget( 'Newsx_Category_List' );
}
add_action( 'widgets_init', 'register_category_list_widget' );
