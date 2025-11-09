<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Newsx_Newsletter extends Newsx_Widget {

    public function __construct() {
		$this->widget_cssclass = 'newsx-newsletter-widget';
		$this->widget_description = esc_html__( 'Smaple description, remove if needed.', 'news-magazine-x' );
		$this->widget_name = esc_html__( 'NewsX: Mailchimp Newsletter', 'news-magazine-x' );

        // Register Pro Fields
        if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) :
            
            $this->sections = newsx_newsletter_widget_options();
        
        // Register Free Fields
        else:

		$this->sections = [
            'widget_general_section' => [
                'section_title' => [
                    'type'  => 'title',
                    'label' => esc_html__( 'General', 'news-magazine-x' ),
                ],
                'title' => [
                    'type' => 'text',
                    'default' => 'Newsletter',
                    'label' => esc_html__( 'Section Title', 'news-magazine-x' ),
                ],
                'description' => [
                    'type' => 'textarea',
                    'default' => 'Stay updated with our weekly newsletter. Subscribe now to never miss an update!',
                    'label' => esc_html__( 'Description', 'news-magazine-x' ),
                ],
                'shortcode' => [
                    'type' => 'text',
                    'default' => '[mc4wp_form]',
                    'label' => esc_html__( 'Description', 'news-magazine-x' ),
                ],
            ],
            'pro_options_section' => [
                'section_title' => [
                    'type'  => 'title',
                    'label' => esc_html__( 'Premium Options', 'news-magazine-x' ),
                ],
                'pro_features' => [
                    'type' => 'pro-features',
                    'description' => 'https://wp-royal-themes.com/themes/item-news-magazine-x-pro/?ref=newsx-free-widget-newsletter-upgrade-pro#features',
                    'choices' => [
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

        $title_tag = isset($instance['title_tag']) ? $instance['title_tag'] : 'h3';

        echo '<div class="newsx-newsletter-wrap">';
            
            echo '<'. esc_attr($title_tag) .' class="newsx-newsletter-title newsx-flex">';
                echo newsx_get_svg_icon('email') . esc_html($instance['title']);
            echo '</'. esc_attr($title_tag) .'>';

            echo '<p>'. esc_html($instance['description']) .'</p>';

            echo '<div class="newsx-newsletter-form">';
                echo do_shortcode($instance['shortcode']);
            echo '</div>';
        echo '</div>';

		$this->widget_end( $args );
    }

}

// Register the widget
function register_newsletter_widget() {

    register_widget( 'Newsx_Newsletter' );
}
add_action( 'widgets_init', 'register_newsletter_widget' );
