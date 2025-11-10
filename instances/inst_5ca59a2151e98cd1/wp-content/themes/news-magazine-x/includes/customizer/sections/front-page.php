<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Pro Version
if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) :
    newsx_add_pro_controls_group( 'front-page' );

else : // Free Version

Kirki::add_field( 'newsx_theme_config', [
    'type'     => 'repeater',
    'settings' => 'newsx_options[front_page_sections]',
    'label'    => esc_html__( 'Front Page Sections', 'news-magazine-x' ),
    'section'  => 'newsx_section_front_page',
	'tab' => 'general',
    'default'  => [
        [
            'widget_area' => '1',
            'layout' => 'content-nosidebar',
            'edit_widgets' => 'widgets'
        ],
    ],
    'fields'   => [
        'widget_area' => [
            'type' => 'select',
            'label' => esc_html__( 'Select Widget Area', 'news-magazine-x' ),
            'default' => '0',
            'choices' => [
                '0' => esc_html__( '- Select -', 'news-magazine-x' ),
                '1' => esc_html__( 'Widget Area 1', 'news-magazine-x' ),
                '2' => esc_html__( 'Widget Area 2', 'news-magazine-x' ),
                '3' => esc_html__( 'Widget Area 3', 'news-magazine-x' ),
            ]
        ],
        'layout' => [
            'type' => 'radio-image',
            'label' => esc_html__( 'Select Layout', 'news-magazine-x' ),
            'default' => 'content-nosidebar',
            'choices' => [
                'content-nosidebar' => 'content-nosidebar',
                'content-rsidebar' => 'content-rsidebar',
            ],
        ],
        'edit_widgets' => [
            'type' => 'custom',
            'default' => 'widgets',
        ],
    ],
    'row_label' => [
        'type' => 'field',
        'value' => esc_html__( 'Section ', 'news-magazine-x' ),
        'field' => '',
    ],
    'button_label' => esc_html__( 'Add Section', 'news-magazine-x' ),
    // 'choices' => [
    //     'limit' => 3
    // ],
    'priority' => 1,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'newsx-headline',
    'settings' => 'newsx_options[front_page_general_headline]',
    'section' => 'newsx_section_front_page',
	'tab' => 'general',
    'label' => esc_html__( 'General', 'news-magazine-x' ),
    'priority' => 9,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'switch',
    'settings' => 'newsx_options[front_page_hide_blog_feed]',
    'section' => 'newsx_section_front_page',
	'tab' => 'general',
    'label' => esc_html__( 'Hide Blog Feed / Static Page', 'news-magazine-x' ),
    'description' => esc_html__( 'Disable default Blog Feed and the Static page content on the front page. This option allows you to customize the front page more effectively by adding different types of post widgets using the sections above.', 'news-magazine-x' ),
    'default' => false,
    'priority' => 10,
] );

// Upgrade to Pro List
Kirki::add_field( 'newsx_theme_config', [
    'type' => 'newsx-upgrade-pro-list',
    'settings' => 'newsx_options[front_page_upgrade_pro_list]',
    'section' => 'newsx_section_front_page',
    'tab' => 'general',
    'label' => esc_html__( 'Need more Front Page Options?', 'news-magazine-x' ),
    'choices' => [
        'sections' => esc_html__( 'Add up to 10 Sections', 'news-magazine-x' ),
        'widget-areas' => esc_html__( 'Choose from 10 Widget Areas', 'news-magazine-x' ),
        'layout' => esc_html__( '4 Different Layout Options', 'news-magazine-x' ),
        'stretch' => esc_html__( 'Stretch sections to Full Width', 'news-magazine-x' ),
    ],
    'description' => 'https://wp-royal-themes.com/themes/item-news-magazine-x-pro/?ref=newsx-free-customizer-sec-front-page-upgrade-pro#features',
    'divider' => 'newsx-group-divider-top',
    'priority' => 999,
] );

endif; // Free Version
