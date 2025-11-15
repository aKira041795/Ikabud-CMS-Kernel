<?php
if (!function_exists('corpnews_theme_enqueue_styles')) {
    add_action('wp_enqueue_scripts', 'corpnews_theme_enqueue_styles');

    function corpnews_theme_enqueue_styles()
    {
        $min = defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? '' : '.min';
        $corpnews_version = wp_get_theme()->get('Version');
        $parent_style = 'morenews-style';

        // Enqueue Parent and Child Theme Styles
        wp_enqueue_style('bootstrap', get_template_directory_uri() . '/assets/bootstrap/css/bootstrap' . $min . '.css', array(), $corpnews_version);
        wp_enqueue_style($parent_style, get_template_directory_uri() . '/style' . $min . '.css', array(), $corpnews_version);
        wp_enqueue_style(
            'corpnews',
            get_stylesheet_directory_uri() . '/style.css',
            array('bootstrap', $parent_style),
            $corpnews_version
        );

        // Enqueue RTL Styles if the site is in RTL mode
        if (is_rtl()) {
            wp_enqueue_style(
                'morenews-rtl',
                get_template_directory_uri() . '/rtl.css',
                array($parent_style),
                $corpnews_version
            );
        }
    }
}


function corpnews_filter_default_theme_options($defaults)
{

    $defaults['site_title_font_size'] = 50;
    $defaults['site_title_uppercase']  = 0;
    $defaults['global_site_mode_setting']    = 'aft-dark-mode';
    $defaults['dark_background_color']     = '#1A1A1A';
    $defaults['select_header_image_mode']  = 'above';
    $defaults['disable_header_image_tint_overlay']  = 1;
    $defaults['disable_wp_emoji'] = 1;
    $defaults['show_primary_menu_desc']  = 0;    
    $defaults['select_popular_tags_mode']  = 'category';
    $defaults['aft_custom_title']           = __('Subscribe', 'corpnews');
    $defaults['flash_news_title'] = __('Breaking News', 'corpnews');
    $defaults['select_main_banner_layout_section'] = 'layout-4';
    $defaults['secondary_color'] = '#FF7903';
    $defaults['select_update_post_filterby'] = 'cat';
    $defaults['global_show_min_read'] = 'no';
    $defaults['frontpage_content_type']  = 'frontpage-widgets-and-content';
    $defaults['featured_news_section_title'] = __('Featured News', 'corpnews');
    $defaults['show_featured_post_list_section'] = 1;
    $defaults['featured_post_list_section_title_1']           = __('Popular News', 'corpnews');
    $defaults['featured_post_list_section_title_2']           = __('General News', 'corpnews');
    $defaults['featured_post_list_section_title_3']           = __('More News', 'corpnews');
    $defaults['single_related_posts_title']     = __('Related News', 'corpnews');

    return $defaults;
}
add_filter('morenews_filter_default_theme_options', 'corpnews_filter_default_theme_options', 1);
