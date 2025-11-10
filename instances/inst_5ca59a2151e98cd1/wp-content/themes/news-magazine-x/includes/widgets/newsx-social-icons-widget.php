<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Newsx_Social_Icons extends Newsx_Widget {

    public function __construct() {
		$this->widget_cssclass = 'newsx-social-icons-widget';
		$this->widget_description = esc_html__( 'Smaple description, remove if needed.', 'news-magazine-x' );
		$this->widget_name = esc_html__( 'NewsX: Social Icons', 'news-magazine-x' );

        // Register Pro Fields
        if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) :
            
            $this->sections = newsx_social_icons_widget_options();
        
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
                    'default' => 'Social Icons',
                    'label' => esc_html__( 'Widget Title', 'news-magazine-x' ),
                ],
                'settings_group_title' => [
                    'type' => 'group-title',
                    'label' => esc_html__( 'Settings', 'news-magazine-x' ),
                ],
                'use_original_colors' => [
                    'type' => 'switcher',
                    'default' => false,
                    'label' => esc_html__( 'Use Original colors', 'news-magazine-x' ),
                ],
            ],
            'social_icons_section' => [
                'section_title' => [
                    'type'  => 'title',
                    'label' => esc_html__( 'Social Icons', 'news-magazine-x' ),
                ],
                'facebook_group_title' => [
                    'type' => 'group-title',
                    'label' => esc_html__( 'Facebook', 'news-magazine-x' ),
                ],
                'facebook_url' => [
                    'type' => 'url',
                    'default' => '#',
                    'label' => esc_html__( 'Social URL', 'news-magazine-x' ),
                ],
                'facebook_followers' => [
                    'type' => 'number',
                    'default' => '1000',
                    'label' => esc_html__( 'Likes / Followers Count', 'news-magazine-x' ),
                ],
                'x_twitter_group_title' => [
                    'type' => 'group-title',
                    'label' => esc_html__( 'X (Twitter)', 'news-magazine-x' ),
                ],
                'x_twitter_url' => [
                    'type' => 'url',
                    'default' => '#',
                    'label' => esc_html__( 'Social URL', 'news-magazine-x' ),
                ],
                'x_twitter_followers' => [
                    'type' => 'number',
                    'default' => '1000',
                    'label' => esc_html__( 'Likes / Followers Count', 'news-magazine-x' ),
                ],
                'instagram_group_title' => [
                    'type' => 'group-title',
                    'label' => esc_html__( 'Instagram', 'news-magazine-x' ),
                ],
                'instagram_url' => [
                    'type' => 'url',
                    'default' => '#',
                    'label' => esc_html__( 'Social URL', 'news-magazine-x' ),
                ],
                'instagram_followers' => [
                    'type' => 'number',
                    'default' => '1000',
                    'label' => esc_html__( 'Likes / Followers Count', 'news-magazine-x' ),
                ],
                'pinterest_group_title' => [
                    'type' => 'group-title',
                    'label' => esc_html__( 'Pinterest', 'news-magazine-x' ),
                ],
                'pinterest_url' => [
                    'type' => 'url',
                    'default' => '#',
                    'label' => esc_html__( 'Social URL', 'news-magazine-x' ),
                ],
                'pinterest_followers' => [
                    'type' => 'number',
                    'default' => '1000',
                    'label' => esc_html__( 'Likes / Followers Count', 'news-magazine-x' ),
                ],
                'youtube_group_title' => [
                    'type' => 'group-title',
                    'label' => esc_html__( 'Youtube', 'news-magazine-x' ),
                ],
                'youtube_url' => [
                    'type' => 'url',
                    'default' => '#',
                    'label' => esc_html__( 'Social URL', 'news-magazine-x' ),
                ],
                'youtube_followers' => [
                    'type' => 'number',
                    'default' => '1000',
                    'label' => esc_html__( 'Likes / Followers Count', 'news-magazine-x' ),
                ],
                'tiktok_group_title' => [
                    'type' => 'group-title',
                    'label' => esc_html__( 'Tiktok', 'news-magazine-x' ),
                ],
                'tiktok_url' => [
                    'type' => 'url',
                    'default' => '#',
                    'label' => esc_html__( 'Social URL', 'news-magazine-x' ),
                ],
                'tiktok_followers' => [
                    'type' => 'number',
                    'default' => '1000',
                    'label' => esc_html__( 'Likes / Followers Count', 'news-magazine-x' ),
                ],
                'telegram_group_title' => [
                    'type' => 'group-title',
                    'label' => esc_html__( 'Telegram', 'news-magazine-x' ),
                ],
                'telegram_url' => [
                    'type' => 'url',
                    'default' => '#',
                    'label' => esc_html__( 'Social URL', 'news-magazine-x' ),
                ],
                'telegram_followers' => [
                    'type' => 'number',
                    'default' => '1000',
                    'label' => esc_html__( 'Likes / Followers Count', 'news-magazine-x' ),
                ],
                'soundcloud_group_title' => [
                    'type' => 'group-title',
                    'label' => esc_html__( 'Soundcloud', 'news-magazine-x' ),
                ],
                'soundcloud_url' => [
                    'type' => 'url',
                    'default' => '#',
                    'label' => esc_html__( 'Social URL', 'news-magazine-x' ),
                ],
                'soundcloud_followers' => [
                    'type' => 'number',
                    'default' => '1000',
                    'label' => esc_html__( 'Likes / Followers Count', 'news-magazine-x' ),
                ],
                'vimeo_group_title' => [
                    'type' => 'group-title',
                    'label' => esc_html__( 'Vimeo', 'news-magazine-x' ),
                ],
                'vimeo_url' => [
                    'type' => 'url',
                    'default' => '#',
                    'label' => esc_html__( 'Social URL', 'news-magazine-x' ),
                ],
                'vimeo_followers' => [
                    'type' => 'number',
                    'default' => '1000',
                    'label' => esc_html__( 'Likes / Followers Count', 'news-magazine-x' ),
                ],
                'dribbble_group_title' => [
                    'type' => 'group-title',
                    'label' => esc_html__( 'Dribbble', 'news-magazine-x' ),
                ],
                'dribbble_url' => [
                    'type' => 'url',
                    'default' => '#',
                    'label' => esc_html__( 'Social URL', 'news-magazine-x' ),
                ],
                'dribbble_followers' => [
                    'type' => 'number',
                    'default' => '1000',
                    'label' => esc_html__( 'Likes / Followers Count', 'news-magazine-x' ),
                ],
            ],
            'pro_options_section' => [
                'section_title' => [
                    'type'  => 'title',
                    'label' => esc_html__( 'Premium Options', 'news-magazine-x' ),
                ],
                'pro_features' => [
                    'type' => 'pro-features',
                    'description' => 'https://wp-royal-themes.com/themes/item-news-magazine-x-pro/?ref=newsx-free-widget-social-icons-upgrade-pro#features',
                    'choices' => [
                        'social_layout' => esc_html__( '3 Social Icon Layout', 'news-magazine-x' ),
                        'social_style' => esc_html__( '3 Social Icon Styles', 'news-magazine-x' ),
                        'columns' => esc_html__( 'Choose 1 or 2 Columns', 'news-magazine-x' ),
                        'icon_size' => esc_html__( 'Custom Icon Size', 'news-magazine-x' ),
                        'thousand_separator' => esc_html__( 'Thousand Separator', 'news-magazine-x' ),
                        'accent_color' => esc_html__( 'Custom Accent Color', 'news-magazine-x' ),
                        'more_link' => esc_html__( 'and much more...', 'news-magazine-x' ),
                    ],
                ],
            ],
		];

        endif; // End Free Fields

		parent::__construct();
    }

    // convert number to k, m, b
    public function newsx_number_to_kmb( $number ) {
        if ( $number > 1000000000 ) {
            return round( $number / 1000000000, 1 ) . 'b';
        } elseif ( $number > 1000000 ) {
            return round( $number / 1000000, 1 ) . 'm';
        } elseif ( $number > 1000 ) {
            return round( $number / 1000, 1 ) . 'k';
        } else {
            return $number;
        }
    }

    // convert number with thousand separator
    public function newsx_number_format( $instance, $number ) {
        $thousand_separator = isset( $instance['thousand_separator'] ) && $instance['thousand_separator'];
        
        if ($thousand_separator) {
            if ( $number >= 1000000000 ) {
                return round( $number / 1000000000, 1 ) . 'B';
            } elseif ( $number >= 1000000 ) {
                return round( $number / 1000000, 1 ) . 'M';
            } elseif ( $number >= 1000 ) {
                return round( $number / 1000, 1 ) . 'K';
            }
        }
        return number_format( $number, 0, '.', ',' );
    }

    public function render_social_icon( $instance, $icon, $brand ) {
        $icon_db = str_replace( '-', '_', $icon);
        $social_url = isset( $instance[ $icon_db .'_url' ] ) ? $instance[ $icon_db .'_url' ] : '';
        $social_followers_raw = isset( $instance[ $icon_db .'_followers' ] ) ? $instance[ $icon_db .'_followers' ] : 1000;
        $social_followers = $this->newsx_number_format( $instance, $social_followers_raw );
        $label_1 = esc_html__( 'Followers', 'news-magazine-x' );
        $label_2 = esc_html__( 'Follow', 'news-magazine-x' );

        if ( 'facebook' === $icon ) {
            $label_1 = esc_html__( 'Fans', 'news-magazine-x' );
            $label_2 = esc_html__( 'Like', 'news-magazine-x' );
        }

        if ( 'pinterest' === $icon ) {
            $label_2 = esc_html__( 'Pin', 'news-magazine-x' );
        }

        if ( 'youtube' === $icon ) {
            $label_1 = esc_html__( 'Subscribers', 'news-magazine-x' );
            $label_2 = esc_html__( 'Subscribe', 'news-magazine-x' );
        }

        if ( 'telegram' === $icon ) {
            $label_1 = esc_html__( 'Members', 'news-magazine-x' );
            $label_2 = esc_html__( 'Join', 'news-magazine-x' );
        }

        if ( ! empty( $social_url ) ) {
            echo '<a href="'. esc_url( $social_url ) .'" target="_blank" class="newsx-social-icon newsx-'. esc_attr($icon) .'-si newsx-flex-nowrap">';
                echo newsx_get_svg_icon( $icon );
                echo '<span class="soc-meta newsx-flex">';
                    echo '<span class="soc-brand">'. esc_html( $brand ) .'</span>';
                    echo '<span class="soc-count">'. esc_html( $social_followers ) .'</span>';
                    echo '<span class="soc-label1">'. $label_1 .'</span>';
                    echo '<span class="soc-label2">'. $label_2 .'</span>';
                echo '</span>';
            echo '</a>';
        }
    }

    public function get_layout_class( $instance ) {
        $layout_class  = '';

        $layout_class .= isset($instance['use_original_colors']) && $instance['use_original_colors'] ? ' newsx-original-colors' : '';
        $layout_class .= isset($instance['social_layout']) ? ' newsx-lt-'. $instance['social_layout'] : ' newsx-lt-s0';
        $layout_class .= isset($instance['social_style']) ? ' newsx-st-'. $instance['social_style'] : ' newsx-st-s2';
        $layout_class .= isset($instance['columns']) ? ' newsx-columns-'. $instance['columns'] : ' newsx-columns-2';

        return $layout_class;
    }

    // Output the content of the widget
    public function widget( $args, $instance ) {
		$this->widget_start( $args );

        // Widget Title
        get_template_part( 'includes/widgets/extras/widget-title', '', [ 'instance' => $instance, 'widget_args' => $args ] );

        echo '<div class="newsx-social-icons'. esc_attr($this->get_layout_class($instance)) .'">';
            $this->render_social_icon( $instance, 'facebook', 'Facebook' );
            $this->render_social_icon( $instance, 'x-twitter', 'X (Twitter)' );
            $this->render_social_icon( $instance, 'instagram', 'Instagram' );
            $this->render_social_icon( $instance, 'pinterest', 'Pinterest' );
            $this->render_social_icon( $instance, 'youtube', 'Youtube' );
            $this->render_social_icon( $instance, 'tiktok', 'Tiktok' );
            $this->render_social_icon( $instance, 'telegram', 'Telegram' );
            $this->render_social_icon( $instance, 'soundcloud', 'Soundcloud' );
            $this->render_social_icon( $instance, 'vimeo', 'Vimeo' );
            $this->render_social_icon( $instance, 'dribbble', 'Dribbble' );
        
        echo '</div>';


		$this->widget_end( $args );
    }

}

// Register the widget
function register_social_icons_widget() {
    register_widget( 'Newsx_Social_Icons' );
}
add_action( 'widgets_init', 'register_social_icons_widget' );
