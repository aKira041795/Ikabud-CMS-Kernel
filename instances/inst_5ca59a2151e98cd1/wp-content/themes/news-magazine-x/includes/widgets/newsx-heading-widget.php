<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Newsx_Heading extends Newsx_Widget {

    public function __construct() {
		$this->widget_cssclass = 'newsx-heading-widget';
		$this->widget_description = esc_html__( 'Smaple description, remove if needed.', 'news-magazine-x' );
		$this->widget_name = esc_html__( 'NewsX: Heading', 'news-magazine-x' );

        // Register Pro Fields
        if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) :
            
            $this->sections = newsx_heading_widget_options();
        
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
                    'default' => 'Sample Heading',
                    'label' => esc_html__( 'Text', 'news-magazine-x' ),
                ],
                'widget_title_width' => [
                    'type' => 'select',
                    'default' => 'default',
                    'choices' => [
                        'default' => esc_html__( 'Default', 'news-magazine-x' ),
                        'container' => esc_html__( 'Container Width', 'news-magazine-x' ),
                    ],
                    'label' => esc_html__( 'Width', 'news-magazine-x' ),
                ],
            ],
            'pro_options_section' => [
                'section_title' => [
                    'type'  => 'title',
                    'label' => esc_html__( 'Premium Options', 'news-magazine-x' ),
                ],
                'pro_features' => [
                    'type' => 'pro-features',
                    'description' => 'https://wp-royal-themes.com/themes/item-news-magazine-x-pro/?ref=newsx-free-widget-heading-upgrade-pro#features',
                    'choices' => [
                        'accent_color' => esc_html__( 'Custom Accent Color', 'news-magazine-x' ),
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

		$this->widget_end( $args );
    }

}

// Register the widget
function register_heading_widget() {
    register_widget( 'Newsx_Heading' );
}
add_action( 'widgets_init', 'register_heading_widget' );
