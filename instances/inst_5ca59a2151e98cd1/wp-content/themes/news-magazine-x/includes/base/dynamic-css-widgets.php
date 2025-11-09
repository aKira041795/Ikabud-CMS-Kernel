<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

function newsx_dynamic_sidebar_dynamic_css() {
    $parse_css = '';

    // Get all active sidebar widgets
    $sidebars_widgets = wp_get_sidebars_widgets();

    // Exclude the 'wp_inactive_widgets' area
    unset( $sidebars_widgets['wp_inactive_widgets'] );

    // Loop through Sidebars
    foreach ( $sidebars_widgets as $sidebar => $widgets ) {
        if ( is_array( $widgets ) ) {
            foreach ( $widgets as $widget_id ) {
                if ( strpos( $widget_id, 'newsx_' ) === 0 ) {
                    $id_base = _get_widget_id_base( $widget_id );
                    $widget_number = _get_widget_number( $widget_id );
                    $widget_instances = get_option( 'widget_' . $id_base );

                    // Get the specific widget instance
                    $widget_instance = $widget_instances[ $widget_number ];

					// Widget Title CSS
                    if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
                        $parse_css .= newsx_widget_title_css( $widget_instance, $widget_id );
                    }
                    
                    // Widget Specific CSS
                    if ( 'newsx_list_layout' === $id_base ) {
                        $parse_css .= newsx_list_layout_css( $widget_instance, $widget_id );
                    }
                    if ( 'newsx_magazine_layout' === $id_base ) {
                        $parse_css .= newsx_magazine_layout_css( $widget_instance, $widget_id );
                    }
                    if ( 'newsx_grid_layout' === $id_base ) {
                        $parse_css .= newsx_grid_layout_css( $widget_instance, $widget_id );
                    }
                    if ( 'newsx_category_list' === $id_base ) {
                        $parse_css .= newsx_category_list_css( $widget_instance, $widget_id );
                    }
                    if ( 'newsx_featured_posts' === $id_base ) {
                        $parse_css .= newsx_featured_posts_css( $widget_instance, $widget_id );
                    }
                    if ( 'newsx_featured_tabs' === $id_base ) {
                        $parse_css .= newsx_featured_tabs_css( $widget_instance, $widget_id );
                    }
                    if ( 'newsx_social_icons' === $id_base ) {
                        $parse_css .= newsx_social_icons_css( $widget_instance, $widget_id );
                    }
                    if ( 'newsx_video_playlist' === $id_base ) {
                        $parse_css .= newsx_video_playlist_css( $widget_instance, $widget_id );
                    }
                    if ( 'newsx_news_ticker' === $id_base ) {
                        $parse_css .= newsx_news_ticker_css( $widget_instance, $widget_id );
                    }
                    if ( 'newsx_slider_carousel' === $id_base ) {
                        $parse_css .= newsx_slider_carousel_css( $widget_instance, $widget_id );
                    }
                    if ( 'newsx_newsletter' === $id_base ) {
                        $parse_css .= newsx_newsletter_css( $widget_instance, $widget_id );
                    }
                }
            }
        }
    }

    return $parse_css;
}

function newsx_widget_title_css( $instance, $id ) {
    $parse_css = '';
    $css_id = '#'. $id;

    $widget_accent_color = isset($instance['widget_accent_color']) ? $instance['widget_accent_color'] : '';

	$parse_css .= newsx_parse_css([
		$css_id .' :where(.newsx-grid-filter:hover, .newsx-grid-filter.active)' => [
			'color' => $widget_accent_color,
		],
		$css_id .' :where(.newsx-s0.newsx-widget-title-wrap .newsx-widget-title-text)' => [
			'background-color' => $widget_accent_color,
            'color' => '#fff',
		],
		$css_id .' :where(.newsx-widget-title-wrap, .newsx-widget-title-text, .newsx-widget-title-text:after),'. $css_id .' .newsx-widget-title-text:after' => [
			'border-color' => $widget_accent_color,
		],
		$css_id .' .newsx-s3 .newsx-widget-title-text:hover' => [
			'color' => $widget_accent_color,
		],
		$css_id .' :where(.newsx-s3.newsx-widget-title-wrap)' => [
			'border-bottom-color' => newsx_hex_to_rgba($widget_accent_color, 0.2),
		],
	]);

    if ( '' !== $widget_accent_color ) {
        $parse_css .= newsx_parse_css([
            $css_id .' .newsx-grid-filters-dropdown-more:hover' => [
                'background-color' => $widget_accent_color,
                'color' => '#fff',
            ],
        ]);
    }

    // Dark Mode
    $parse_css .= newsx_parse_css([
        '.newsx-dark-mode '. $css_id .' :where(.newsx-s3, .newsx-s2-ft).newsx-widget-title-wrap' => [
            'border-top-color' => '#383838',
            'border-bottom-color' => '#4a4a4a',
        ],
        '.newsx-dark-mode '. $css_id .' .newsx-widget-title-text:hover, .newsx-dark-mode '. $css_id .' .newsx-grid-filter:hover' => [
            'color' => '#ffffff',
        ],
    ]);

    return $parse_css;
}

function newsx_list_layout_css( $instance, $id ) {
    $parse_css = '';
    $css_id = '#'. $id;
    $title_tag = isset($instance['title_tag']) ? $instance['title_tag'] : 'h3';
    $gutter = isset($instance['gutter']) ? $instance['gutter'] : ['desktop' => '25', 'tablet' => '25', 'mobile' => '25'];
    $global_heading_font = in_array($title_tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6']) ? newsx_get_option('global_font_'. $title_tag)['font-size'] : newsx_get_option('global_font_body')['font-size'];

    $widget_accent_color = isset($instance['widget_accent_color']) ? $instance['widget_accent_color'] : '';

	$parse_css .= newsx_parse_css([
		$css_id .' :where(.newsx-ring-loader div)' => [
			'border-color' => $widget_accent_color,
		],
	]);

	$parse_css .= newsx_get_resp_slider_control_css($gutter, $css_id .' .newsx-list-layout', 'grid-gap');

    $title_selector_30 = '
        .newsx-list-layout-list-1 article:not(.newsx-big-post) '. $title_tag .',
        .newsx-list-layout-list-2 article:not(.newsx-big-post) '. $title_tag .',
        .newsx-list-layout-list-3 article:not(.newsx-big-post) '. $title_tag .',
        .newsx-list-layout-list-4 article:not(.newsx-big-post) '. $title_tag .',
        .newsx-list-layout-list-5 article:not(.newsx-big-post) '. $title_tag .',
        .newsx-list-layout-list-6 article:not(.newsx-big-post) '. $title_tag .'
    ';

	$parse_css .= newsx_get_resp_slider_reduce_control_css($global_heading_font, $title_selector_30, 'font-size', 'px', 0.3);

    return $parse_css;
}

function newsx_magazine_layout_css( $instance, $id ) {
    $parse_css = '';
    $css_id = '#'. $id;
    $layout = isset($instance['layout']) ? $instance['layout'] : '1-4';
    $title_tag = isset($instance['title_tag']) ? $instance['title_tag'] : 'h2';
    $container_height = isset($instance['container_height']) ? $instance['container_height'] : ['desktop' => '650', 'tablet' => '550', 'mobile' => '650'];
    $gutter = isset($instance['gutter']) ? $instance['gutter'] : ['desktop' => '15', 'tablet' => '15', 'mobile' => '10'];
    $global_heading_font = in_array($title_tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6']) ? newsx_get_option('global_font_'. $title_tag)['font-size'] : newsx_get_option('global_font_body')['font-size'];

	// Static CSS
    $parse_css .= newsx_minify_static_css('');
 
	// Dynamic CSS
	$parse_css .= newsx_get_resp_slider_control_css($container_height, $css_id .' .newsx-magazine-layout', 'height');
	$parse_css .= newsx_get_resp_slider_control_css($gutter, $css_id .' .newsx-magazine-layout', 'grid-gap');

    $title_selector_20 = '
        .newsx-magazine-layout-1-2 article:where(:nth-child(2), :nth-child(3)) '. $title_tag .',
        .newsx-magazine-layout-1-3 article:where(:nth-child(3), :nth-child(4)) '. $title_tag .',
        .newsx-magazine-layout-1-4 article:where(:nth-child(2), :nth-child(3), :nth-child(4), :nth-child(5)) '. $title_tag .',
        .newsx-magazine-layout-1-1-2 article:where(:nth-child(2), :nth-child(3), :nth-child(4)) '. $title_tag .',
        .newsx-magazine-layout-2-1-2 article:where(:nth-child(2), :nth-child(3), :nth-child(4), :nth-child(5)) '. $title_tag .',
        .newsx-magazine-layout-1-1-1 article:where(:nth-child(2), :nth-child(3)) '. $title_tag .',
        .newsx-magazine-layout-1vh-3h article:where(:nth-child(2), :nth-child(3), :nth-child(4)) '. $title_tag .',
        .newsx-magazine-layout-1-1-3 article:where(:nth-child(2), :nth-child(3), :nth-child(4), :nth-child(5)) '. $title_tag .',
        .newsx-magazine-layout-2-3 article:where(:nth-child(3), :nth-child(4), :nth-child(5)) '. $title_tag .'
    ';

	$parse_css .= newsx_get_resp_slider_reduce_control_css($global_heading_font, $title_selector_20, 'font-size', 'px', 0.2);

    return $parse_css;
}

function newsx_grid_layout_css( $instance, $id ) {
    $parse_css = '';
    $css_id = '#'. $id;
    $gutter = isset($instance['gutter']) ? $instance['gutter'] : ['desktop' => '20', 'tablet' => '20', 'mobile' => '20'];
 
	// Dynamic CSS
	$parse_css .= newsx_get_resp_slider_control_css($gutter, $css_id .' .newsx-grid-layout', 'grid-gap');

    return $parse_css;
}

function newsx_category_list_css( $instance, $id ) {
    $parse_css = '';
    $css_id = '#'. $id;
    $preset = $instance['elements_preset'];
 
    $widget_accent_color = isset($instance['widget_accent_color']) ? $instance['widget_accent_color'] : '';

	$parse_css .= newsx_parse_css([
		$css_id .' :where(.newsx-s1 .category-count)' => [
			'background-color' => $widget_accent_color,
		],
	]);

    return $parse_css;
}

function newsx_featured_posts_css( $instance, $id ) {
    $parse_css = '';
    $css_id = '#'. $id;
 
    $widget_accent_color = isset($instance['widget_accent_color']) ? $instance['widget_accent_color'] : '';

	$parse_css .= newsx_parse_css([
		$css_id .' :where(.newsx-post-index)' => [
			'background-color' => $widget_accent_color,
		],
	]);

    return $parse_css;
}

function newsx_featured_tabs_css( $instance, $id ) {
    $parse_css = '';
    $css_id = '#'. $id;
 
    $widget_accent_color = isset($instance['widget_accent_color']) ? $instance['widget_accent_color'] : '';

	$parse_css .= newsx_parse_css([
		$css_id .' :where(.newsx-post-index)' => [
			'background-color' => $widget_accent_color,
		],
	]);

    return $parse_css;
}

function newsx_social_icons_css( $instance, $id ) {
    $parse_css = '';
    $css_id = '#'. $id;
 
    $widget_accent_color = isset($instance['widget_accent_color']) ? $instance['widget_accent_color'] : '';
    $use_original_colors = isset($instance['use_original_colors']) && '1' == $instance['use_original_colors'] ? true : false;
    $icon_size = isset($instance['icon_size']) ? $instance['icon_size'] : '20';

    $parse_css .= newsx_parse_css([
        $css_id .' .newsx-social-icon svg' => [
            'width' => $icon_size .'px',
            'height' => $icon_size .'px',
        ],
    ]);

    if ( $use_original_colors ) {
        $facebook_color = '#3b5998';
        $twitter_color = '#1da1f2';
        $instagram_color = '#b011dc';
        $pinterest_color = '#bd081c';
        $youtube_color = '#cd201f';
        $tiktok_color = '#333333';
        $telegram_color = '#0088cc';
        $soundcloud_color = '#ff8800';
        $vimeo_color = '#1ab7ea';
        $dribbble_color = '#ea4c89';

        $social_style = isset($instance['social_style']) ? $instance['social_style'] : 's2';

        if ( 's0' === $social_style ) {
            $parse_css .= newsx_minify_static_css('
                '. $css_id .' .newsx-social-icon .soc-meta span {
                    color: #fff;
                }
                '. $css_id .' .newsx-social-icon svg {
                    fill: #fff;
                }
                '. $css_id .' .newsx-facebook-si {
                    background-color: '. $facebook_color .';
                }
                '. $css_id .' .newsx-facebook-si:hover {
                    box-shadow: 0 0 10px '. newsx_hex_to_rgba($facebook_color, 0.5) .';
                }
                '. $css_id .' .newsx-x-twitter-si {
                    background-color: '. $twitter_color .';
                }
                '. $css_id .' .newsx-x-twitter-si:hover {
                    box-shadow: 0 0 10px '. newsx_hex_to_rgba($twitter_color, 0.5) .';
                }
                '. $css_id .' .newsx-instagram-si {
                    background-color: '. $instagram_color .';
                }
                '. $css_id .' .newsx-instagram-si:hover {
                    box-shadow: 0 0 10px '. newsx_hex_to_rgba($instagram_color, 0.5) .';
                }
                '. $css_id .' .newsx-pinterest-si {
                    background-color: '. $pinterest_color .';
                }
                '. $css_id .' .newsx-pinterest-si:hover {
                    box-shadow: 0 0 10px '. newsx_hex_to_rgba($pinterest_color, 0.5) .';
                }
                '. $css_id .' .newsx-youtube-si {
                    background-color: '. $youtube_color .';
                }
                '. $css_id .' .newsx-youtube-si:hover {
                    box-shadow: 0 0 10px '. newsx_hex_to_rgba($youtube_color, 0.5) .';
                }
                '. $css_id .' .newsx-tiktok-si {
                    background-color: '. $tiktok_color .';
                }
                '. $css_id .' .newsx-tiktok-si:hover {
                    box-shadow: 0 0 10px '. newsx_hex_to_rgba($tiktok_color, 0.5) .';
                }
                '. $css_id .' .newsx-telegram-si {
                    background-color: '. $telegram_color .';
                }
                '. $css_id .' .newsx-telegram-si:hover {
                    box-shadow: 0 0 10px '. newsx_hex_to_rgba($telegram_color, 0.5) .';
                }
                '. $css_id .' .newsx-soundcloud-si {
                    background-color: '. $soundcloud_color .';
                }
                '. $css_id .' .newsx-soundcloud-si:hover {
                    box-shadow: 0 0 10px '. newsx_hex_to_rgba($soundcloud_color, 0.5) .';
                }
                '. $css_id .' .newsx-vimeo-si {
                    background-color: '. $vimeo_color .';
                }
                '. $css_id .' .newsx-vimeo-si:hover {
                    box-shadow: 0 0 10px '. newsx_hex_to_rgba($vimeo_color, 0.5) .';
                }
                '. $css_id .' .newsx-dribbble-si {
                    background-color: '. $dribbble_color .';
                }
                '. $css_id .' .newsx-dribbble-si:hover {
                    box-shadow: 0 0 10px '. newsx_hex_to_rgba($dribbble_color, 0.5) .';
                }
            ');
        } else if ( 's1' === $social_style ) {
            $parse_css .= newsx_minify_static_css('
                '. $css_id .' .newsx-social-icon {
                    border-width: 1px;
                    border-style: solid;
                }
                '. $css_id .' .newsx-facebook-si svg {
                    fill: '. $facebook_color .';
                }
                '. $css_id .' .newsx-facebook-si:hover {
                    color: '. $facebook_color .';
                    border-color: '. newsx_hex_to_rgba($facebook_color, 0.5) .';
                    box-shadow: 0 0 5px '. newsx_hex_to_rgba($facebook_color, 0.5) .';
                }
                '. $css_id .' .newsx-x-twitter-si svg {
                    fill: '. $twitter_color .';
                }
                '. $css_id .' .newsx-x-twitter-si:hover {
                    color: '. $twitter_color .';
                    border-color: '. newsx_hex_to_rgba($twitter_color, 0.5) .';
                    box-shadow: 0 0 5px '. newsx_hex_to_rgba($twitter_color, 0.5) .';
                }
                '. $css_id .' .newsx-instagram-si svg {
                    fill: '. $instagram_color .';
                }
                '. $css_id .' .newsx-instagram-si:hover {
                    color: '. $instagram_color .';
                    border-color: '. newsx_hex_to_rgba($instagram_color, 0.5) .';
                    box-shadow: 0 0 5px '. newsx_hex_to_rgba($instagram_color, 0.5) .';
                }
                '. $css_id .' .newsx-pinterest-si svg {
                    fill: '. $pinterest_color .';
                }
                '. $css_id .' .newsx-pinterest-si:hover {
                    color: '. $pinterest_color .';
                    border-color: '. newsx_hex_to_rgba($pinterest_color, 0.5) .';
                    box-shadow: 0 0 5px '. newsx_hex_to_rgba($pinterest_color, 0.5) .';
                }
                '. $css_id .' .newsx-youtube-si svg {
                    fill: '. $youtube_color .';
                }
                '. $css_id .' .newsx-youtube-si:hover {
                    color: '. $youtube_color .';
                    border-color: '. newsx_hex_to_rgba($youtube_color, 0.5) .';
                    box-shadow: 0 0 5px '. newsx_hex_to_rgba($youtube_color, 0.5) .';
                }
                '. $css_id .' .newsx-tiktok-si svg {
                    fill: '. $tiktok_color .';
                }
                '. $css_id .' .newsx-tiktok-si:hover {
                    color: '. $tiktok_color .';
                    border-color: '. newsx_hex_to_rgba($tiktok_color, 0.3) .';
                    box-shadow: 0 0 5px '. newsx_hex_to_rgba($tiktok_color, 0.3) .';
                }
                '. $css_id .' .newsx-telegram-si svg {
                    fill: '. $telegram_color .';
                }
                '. $css_id .' .newsx-telegram-si:hover {
                    color: '. $telegram_color .';
                    border-color: '. newsx_hex_to_rgba($telegram_color, 0.5) .';
                    box-shadow: 0 0 5px '. newsx_hex_to_rgba($telegram_color, 0.5) .';
                }
                '. $css_id .' .newsx-soundcloud-si svg {
                    fill: '. $soundcloud_color .';
                }
                '. $css_id .' .newsx-soundcloud-si:hover {
                    color: '. $soundcloud_color .';
                    border-color: '. newsx_hex_to_rgba($soundcloud_color, 0.5) .';
                    box-shadow: 0 0 5px '. newsx_hex_to_rgba($soundcloud_color, 0.5) .';
                }
                '. $css_id .' .newsx-vimeo-si svg {
                    fill: '. $vimeo_color .';
                }
                '. $css_id .' .newsx-vimeo-si:hover {
                    color: '. $vimeo_color .';
                    border-color: '. newsx_hex_to_rgba($vimeo_color, 0.5) .';
                    box-shadow: 0 0 5px '. newsx_hex_to_rgba($vimeo_color, 0.5) .';
                }
                '. $css_id .' .newsx-dribbble-si svg {
                    fill: '. $dribbble_color .';
                }
                '. $css_id .' .newsx-dribbble-si:hover {
                    color: '. $dribbble_color .';
                    border-color: '. newsx_hex_to_rgba($dribbble_color, 0.5) .';
                    box-shadow: 0 0 5px '. newsx_hex_to_rgba($dribbble_color, 0.5) .';
                }
            ');
        } else if ( 's2' === $social_style ) {
            $parse_css .= newsx_minify_static_css('
                '. $css_id .' .newsx-social-icon {
                    border-width: 1px;
                    border-style: solid;
                }
                '. $css_id .' .newsx-social-icon:hover .soc-meta span {
                    color: #fff;
                }
                '. $css_id .' .newsx-social-icon:hover svg {
                    fill: #fff;
                }
                '. $css_id .' .newsx-facebook-si {
                    border-color: '. newsx_hex_to_rgba($facebook_color, 0.5) .';
                }
                '. $css_id .' .newsx-facebook-si:hover {
                    background-color: '. $facebook_color .';
                    box-shadow: 0 0 10px '. newsx_hex_to_rgba($facebook_color, 0.5) .';
                }
                '. $css_id .' .newsx-facebook-si svg {
                    fill: '. $facebook_color .';
                }
                '. $css_id .' .newsx-x-twitter-si {
                    border-color: '. newsx_hex_to_rgba($twitter_color, 0.5) .';
                }
                '. $css_id .' .newsx-x-twitter-si:hover {
                    background-color: '. $twitter_color .';
                    box-shadow: 0 0 10px '. newsx_hex_to_rgba($twitter_color, 0.5) .';
                }
                '. $css_id .' .newsx-x-twitter-si svg {
                    fill: '. $twitter_color .';
                }
                '. $css_id .' .newsx-instagram-si {
                    border-color: '. newsx_hex_to_rgba($instagram_color, 0.5) .';
                }
                '. $css_id .' .newsx-instagram-si:hover {
                    background-color: '. $instagram_color .';
                    box-shadow: 0 0 10px '. newsx_hex_to_rgba($instagram_color, 0.5) .';
                }
                '. $css_id .' .newsx-instagram-si svg {
                    fill: '. $instagram_color .';
                }
                '. $css_id .' .newsx-pinterest-si {
                    border-color: '. newsx_hex_to_rgba($pinterest_color, 0.5) .';
                }
                '. $css_id .' .newsx-pinterest-si:hover {
                    background-color: '. $pinterest_color .';
                    box-shadow: 0 0 10px '. newsx_hex_to_rgba($pinterest_color, 0.5) .';
                }
                '. $css_id .' .newsx-youtube-si {
                    border-color: '. newsx_hex_to_rgba($youtube_color, 0.5) .';
                }
                '. $css_id .' .newsx-youtube-si:hover {
                    background-color: '. $youtube_color .';
                    box-shadow: 0 0 10px '. newsx_hex_to_rgba($youtube_color, 0.5) .';
                }
                '. $css_id .' .newsx-youtube-si svg {
                    fill: '. $youtube_color .';
                }
                '. $css_id .' .newsx-tiktok-si {
                    border-color: '. newsx_hex_to_rgba($tiktok_color, 0.5) .';
                }
                '. $css_id .' .newsx-tiktok-si:hover {
                    background-color: '. $tiktok_color .';
                    box-shadow: 0 0 10px '. newsx_hex_to_rgba($tiktok_color, 0.5) .';
                }
                '. $css_id .' .newsx-tiktok-si svg {
                    fill: '. $tiktok_color .';
                }
                '. $css_id .' .newsx-telegram-si {
                    border-color: '. newsx_hex_to_rgba($telegram_color, 0.5) .';
                }
                '. $css_id .' .newsx-telegram-si:hover {
                    background-color: '. $telegram_color .';
                    box-shadow: 0 0 10px '. newsx_hex_to_rgba($telegram_color, 0.5) .';
                }
                '. $css_id .' .newsx-telegram-si svg {
                    fill: '. $telegram_color .';
                }
                '. $css_id .' .newsx-soundcloud-si {
                    border-color: '. newsx_hex_to_rgba($soundcloud_color, 0.5) .';
                }
                '. $css_id .' .newsx-soundcloud-si:hover {
                    background-color: '. $soundcloud_color .';
                    box-shadow: 0 0 10px '. newsx_hex_to_rgba($soundcloud_color, 0.5) .';
                }
                '. $css_id .' .newsx-soundcloud-si svg {
                    fill: '. $soundcloud_color .';
                }
                '. $css_id .' .newsx-vimeo-si {
                    border-color: '. newsx_hex_to_rgba($vimeo_color, 0.5) .';
                }
                '. $css_id .' .newsx-vimeo-si:hover {
                    background-color: '. $vimeo_color .';
                    box-shadow: 0 0 10px '. newsx_hex_to_rgba($vimeo_color, 0.5) .';
                }
                '. $css_id .' .newsx-vimeo-si svg {
                    fill: '. $vimeo_color .';
                }
                '. $css_id .' .newsx-dribbble-si {
                    border-color: '. newsx_hex_to_rgba($dribbble_color, 0.5) .';
                }
                '. $css_id .' .newsx-dribbble-si:hover {
                    background-color: '. $dribbble_color .';
                    box-shadow: 0 0 10px '. newsx_hex_to_rgba($dribbble_color, 0.5) .';
                }
                '. $css_id .' .newsx-dribbble-si svg {
                    fill: '. $dribbble_color .';
                }
            ');
        }

        // Dark Mode
        $parse_css .= newsx_parse_css([
            '.newsx-dark-mode '. $css_id .' .newsx-tiktok-si' => [
                'border-color' => '#4a4a4a',
            ],
            '.newsx-dark-mode '. $css_id .' .newsx-tiktok-si:hover' => [
                'background-color' => '#4a4a4a',
            ],
            '.newsx-dark-mode '. $css_id .' .newsx-tiktok-si svg ' => [
                'fill' => '#ffffff',
            ],
        ]);
    } else {
        $parse_css .= newsx_minify_static_css('
            '. $css_id .' .newsx-social-icon:hover .soc-meta span {
                color: #fff;
            }
            '. $css_id .' .newsx-social-icon:hover svg {
                fill: #fff;
            }
        ');
    }

    return $parse_css;
}

function newsx_video_playlist_css( $instance, $id ) {
    $parse_css = '';
    $css_id = '#'. $id;

    $parse_css .= newsx_minify_static_css('
        @media only screen and (max-width: '. newsx_get_tablet_breakpoint() .'px) {
            .newsx-vplaylist-heading {
                padding: 7px 10px;
            }
            .newsx-vplaylist-heading span,
            .newsx-vplaylist-current-title {
                margin-bottom: 0;
            }
            .newsx-vplaylist-controller svg {
                width: 20px;
                height: 20px;
            }
            .newsx-vplaylist-thumbs li {
                padding: 10px;
            }
            .newsx-vplaylist-thumbs ul img {
                width: 90px;
                margin-right: 10px;
            }
            .newsx-vplaylist-info-title {
                margin-bottom: 2px;
                font-size: 12px;
                line-height: 1.3;
            }
            .newsx-vplaylist-info span {
                font-size: 12px;
            }
        }

        @media only screen and (max-width: '. newsx_get_mobile_breakpoint() .'px) {
            .newsx-vplaylist-wrap {
                flex-direction: column;
            }
            .newsx-vplaylist-wrap .video-player-wrap {
                flex: auto;
                width: 100%;
            }
            .newsx-vplaylist-thumbs-wrap {
                flex: auto;
                width: 100%;
                height: 300px;
            }
            .newsx-vplaylist-heading {
                padding: 15px;
            }
            .newsx-vplaylist-controller {
                min-width: 15%;
            }
            .newsx-vplaylist-controller svg {
                width: 22px;
                height: 22px;
            }
            .newsx-vplaylist-thumbs li {
                padding: 15px;
            }
            .newsx-vplaylist-thumbs ul img {
                width: 120px;
                margin-right: 10px;
            }
        }
    ');

    return $parse_css;
}

function newsx_news_ticker_css( $instance, $id ) {
    $parse_css = '';
    $css_id = '#'. $id;
 
    $heading_text_color = isset($instance['heading_text_color']) ? $instance['heading_text_color'] : '';
    $heading_icon_color = isset($instance['heading_icon_color']) ? $instance['heading_icon_color'] : '';
    $heading_bg_color = isset($instance['heading_bg_color']) ? $instance['heading_bg_color'] : '';
    $title_text_color = isset($instance['title_text_color']) ? $instance['title_text_color'] : '';
    $title_text_color_hover = isset($instance['title_text_color_hover']) ? $instance['title_text_color_hover'] : '';
    $content_bd_color = isset($instance['content_bd_color']) ? $instance['content_bd_color'] : '';
    $content_bg_color = isset($instance['content_bg_color']) ? $instance['content_bg_color'] : '';
    $nav_color = isset($instance['nav_color']) ? $instance['nav_color'] : '';
    $nav_color_hover = isset($instance['nav_color_hover']) ? $instance['nav_color_hover'] : '';
    $nav_bg_color = isset($instance['nav_bg_color']) ? $instance['nav_bg_color'] : '';
    $nav_bg_color_hover = isset($instance['nav_bg_color_hover']) ? $instance['nav_bg_color_hover'] : '';
    $heading_font_size = isset($instance['heading_font_size']) ? $instance['heading_font_size'] : [
        'desktop' => 16,
        'tablet' => 16,
        'mobile' => 16,
    ];
    $title_font_size = isset($instance['title_font_size']) ? $instance['title_font_size'] : [
        'desktop' => 16,
        'tablet' => 16,
        'mobile' => 16,
    ];

	$parse_css .= newsx_parse_css([
        $css_id .' :where(.news-ticker-heading-text)' => [
            'color' => $heading_text_color,
        ],
        $css_id .' :where(.newsx-ticker-icon-circle)' => [
			'color' => $heading_icon_color,
			'background-color' => $heading_icon_color
        ],
        $css_id .' :where(.news-ticker-heading-icon svg)' => [
			'fill' => $heading_icon_color,
        ],
        $css_id .' :where(.news-ticker-heading)' => [
			'background-color' => $heading_bg_color,
        ],
        $css_id .' .news-ticker-heading.newsx-s1:before' => [
            'border-right-color' => $heading_bg_color,
        ],
        $css_id .' .news-ticker-heading.newsx-s2:before' => [
			'background-color' => $heading_bg_color,
        ],
        $css_id .' .newsx-news-ticker-title' => [
            'color' => $title_text_color,
        ],
        $css_id .' .newsx-news-ticker-title:hover' => [
            'color' => $title_text_color_hover,
        ],
        $css_id .' :where(.news-ticker-wrapper)' => [
            'background-color' => $content_bg_color,
            'border' => '' !== $content_bd_color ? '1px solid '. $content_bd_color : 'none',
        ],

        // Navigation
        $css_id .' .newsx-slider-prev::after,'. $css_id .' .newsx-slider-next::after' => [
            'color' => $nav_color,
        ],
        $css_id .' .newsx-slider-prev:hover::after,'. $css_id .' .newsx-slider-next:hover::after' => [
            'color' => $nav_color_hover,
        ],
        $css_id .' .newsx-slider-prev,'. $css_id .' .newsx-slider-next' => [
            'background-color' => $nav_bg_color,
        ],
        $css_id .' .newsx-slider-prev:hover,'. $css_id .' .newsx-slider-next:hover' => [    
            'background-color' => $nav_bg_color_hover,
        ]
	]);
    
    $parse_css .= newsx_get_resp_slider_control_css($heading_font_size, $css_id .'.newsx-widget .news-ticker-heading-text', 'font-size',);
    $parse_css .= newsx_get_resp_slider_control_css($title_font_size, $css_id .'.newsx-widget .newsx-news-ticker-title>p', 'font-size',);

    if ( 'slider' === $instance['type_select'] && $instance['show_navigation'] ) {
		$parse_css .= newsx_parse_css([
			$css_id .' .news-ticker-wrapper' => [
				'align-self' => 'center',
			],
		]);
	}

    // Static CSS
    $parse_css .= newsx_minify_static_css('
        '. $css_id .' .news-ticker-heading {
            padding: 8px 10px;
        }
        '. $css_id .' .news-ticker-heading.newsx-s1:before {
            border-width: 17px;
        }
        '. $css_id .' .news-ticker-heading.newsx-s2:before {
            right: -7px;
        }
        '. $css_id .' .news-ticker-heading:where(.newsx-s1, .newsx-s2) + .news-ticker-wrapper {
            padding-left: 25px;
        }
    ');

    if ( '' !== $nav_bg_color ) {
        $parse_css .= newsx_parse_css([
            $css_id .' .newsx-slider-prev, '. $css_id .' .newsx-slider-next' => [
                'padding' => '8px 15px',
            ]
        ]);
    } else {
        $parse_css .= newsx_parse_css([
            $css_id .' .newsx-slider-prev, '. $css_id .' .newsx-slider-next' => [
                'margin-left' => '6px',
            ]
        ]);
    }

    // Dark Mode
    $parse_css .= newsx_parse_css([
        '.newsx-dark-mode '. $css_id .' .newsx-news-ticker-title' => [
            'color' => '#ffffff',
        ],
        '.newsx-dark-mode '. $css_id .' :where(.news-ticker-wrapper)' => [
            'border-color' => '#383838 !important',
        ],
    ]);

    return $parse_css;
}

function newsx_slider_carousel_css( $instance, $id ) {
    $parse_css = '';
    $css_id = '#'. $id;
 

    return $parse_css;
}

function newsx_newsletter_css( $instance, $id ) {
    $parse_css = '';
    $css_id = '#'. $id;

	// Static CSS
    $parse_css .= newsx_minify_static_css('
        '. $css_id .' .newsx-newsletter-wrap {
            padding: 23px;
            margin: 0;
            border: none;
            background: transparent;
            box-shadow: 0 0 5px rgba(0, 0, 0, 0.1);
        }

        '. $css_id .' .newsx-newsletter-title {
            gap: 7px;
            align-items: center;
            margin-bottom: 4px;
        }

        '. $css_id .' .newsx-newsletter-title .newsx-svg-icon {
            margin: 0;
        }

        '. $css_id .' .newsx-newsletter-title svg {
            width: 20px;
            height: 20px;
        }

        '. $css_id .' .mc4wp-form-fields > div {
            display: block;
        }

        '. $css_id .' .newsx-newsletter-form input[type="submit"] {
            margin-top: 12px;
        }

        '. $css_id .' .newsx-newsletter-form .mc4wp-form-fields > div {
            margin-bottom: 20px;
        }
    ');

    if ( newsx_get_option('global_island_style') ) {
        if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
            $parse_css .= newsx_minify_static_css('
                '. $css_id .' .newsx-newsletter-wrap {
                    background-color: '. newsx_get_option('global_color_content_background') .';
                }
            ');
        } else {
            $parse_css .= newsx_minify_static_css('
                '. $css_id .' .newsx-newsletter-wrap {
                    background-color: #ffffff;
                }
            ');
        }
    }

    return $parse_css;
}

/*
** Get Widget Number
*/
function _get_widget_number( $widget_id ) {
    if ( preg_match( '/-([0-9]+)$/', $widget_id, $matches ) ) {
        return absint( $matches[1] );
    }
    return false;
}
