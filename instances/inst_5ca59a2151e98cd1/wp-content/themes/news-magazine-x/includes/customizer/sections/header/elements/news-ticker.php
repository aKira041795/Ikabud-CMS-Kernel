<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Kirki::add_field( 'newsx_theme_config', [
//     'type' => 'select',
//     'settings' => 'newsx_options[header_nt_query]',
//     'section' => 'newsx_section_hd_news_ticker',
// 	'tab' => 'general',
//     'label' => esc_html__( 'Select Query', 'news-magazine-x' ),
//     'default' => 'dynamic',
//     'choices' => [
//         'dynamic' => esc_html__( 'Dynamic', 'news-magazine-x' ),
//         'manual' => esc_html__( 'Manual', 'news-magazine-x' ),
//     ],
//     'priority' => 10,
// ] );

// Pro Version
if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) :
    newsx_add_pro_controls_group( 'header-news-ticker' );

else : // Free Version

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'select',
    'settings' => 'newsx_options[header_nt_orderby]',
    'section' => 'newsx_section_hd_news_ticker',
	'tab' => 'general',
    'label' => esc_html__( 'Order by', 'news-magazine-x' ),
    'default' => 'date',
    'choices' => [
        'date' => esc_html__( 'Published Date', 'news-magazine-x' ),
        'title' => esc_html__( 'Post Title', 'news-magazine-x' ),
        'ID' => esc_html__( 'Post ID', 'news-magazine-x' ),
        'author' => esc_html__( 'Post Author', 'news-magazine-x' ),
    ],
    'priority' => 15,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'select',
    'settings' => 'newsx_options[header_nt_categories]',
    'section' => 'newsx_section_hd_news_ticker',
	'tab' => 'general',
    'label' => esc_html__( 'Categories', 'news-magazine-x' ),
    'default' => '',
    'multiple' => true,
    'choices' => newsx_get_taxonomy_term_choices( 'category' ),
    'priority' => 25,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'select',
    'settings' => 'newsx_options[header_nt_tags]',
    'section' => 'newsx_section_hd_news_ticker',
	'tab' => 'general',
    'label' => esc_html__( 'Tags', 'news-magazine-x' ),
    'default' => '',
    'multiple' => true,
    'choices' => newsx_get_taxonomy_term_choices( 'post_tag' ),
    'priority' => 30,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'newsx-headline',
    'settings' => 'newsx_options[header_nt_heading_headline]',
    'section' => 'newsx_section_hd_news_ticker',
	'tab' => 'general',
    'label' => esc_html__( 'Heading', 'news-magazine-x' ),
    'priority' => 39,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'text',
    'settings' => 'newsx_options[header_nt_heading_text]',
    'section' => 'newsx_section_hd_news_ticker',
	'tab' => 'general',
    'label' => esc_html__( 'Heading Text', 'news-magazine-x' ),
    'default' => 'Hot News',
    'priority' => 40,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'select',
    'settings' => 'newsx_options[header_nt_heading_icon_type]',
    'section' => 'newsx_section_hd_news_ticker',
	'tab' => 'general',
    'label' => esc_html__( 'Icon Type', 'news-magazine-x' ),
    'default' => 'fire',
    'choices' => [
        'none' => esc_html__( 'None', 'news-magazine-x' ),
        'fire' => esc_html__( 'Fire', 'news-magazine-x' )
    ],
    'priority' => 43,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'newsx-headline',
    'settings' => 'newsx_options[header_nt_layout_headline]',
    'section' => 'newsx_section_hd_news_ticker',
	'tab' => 'general',
    'label' => esc_html__( 'Layout', 'news-magazine-x' ),
    'priority' => 49,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'number',
    'settings' => 'newsx_options[header_nt_posts_per_page]',
    'section' => 'newsx_section_hd_news_ticker',
	'tab' => 'general',
    'label' => esc_html__( 'Number of Posts', 'news-magazine-x' ),
    'default' => 6,
    'priority' => 60,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'switch',
    'settings' => 'newsx_options[header_nt_show_images]',
    'section' => 'newsx_section_hd_news_ticker',
	'tab' => 'general',
    'label' => esc_html__( 'Show Thumbnails', 'news-magazine-x' ),
    'default' => '',
	'divider' => 'newsx-group-divider-top',
    'priority' => 70,
] );


Kirki::add_field( 'newsx_theme_config', [
    'type' => 'newsx-headline',
    'settings' => 'newsx_options[header_nt_spacing_headline]',
    'section' => 'newsx_section_hd_news_ticker',
	'tab' => 'design',
    'label' => esc_html__( 'Spacing', 'news-magazine-x' ),
    'priority' => 299,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'kirki-margin',
    'settings' => 'newsx_options[header_nt_margin]',
    'section' => 'newsx_section_hd_news_ticker',
	'tab' => 'design',
    'label' => esc_html__( 'Margin', 'news-magazine-x' ),
    'responsive' => true,
    'default'    => [
        'desktop' => [
            'top'      => '',
            'right'    => '',
            'bottom'   => '',
            'left'     => '',
            'isLinked' => true,
        ],
        'tablet'  => [
            'top'      => '',
            'right'    => '',
            'bottom'   => '',
            'left'     => '',
            'isLinked' => true,
        ],
        'mobile'  => [
            'top'      => '',
            'right'    => '',
            'bottom'   => '',
            'left'     => '',
            'isLinked' => true,
        ],
    ],
    'choices'  => [
        'min'  => 0,
        'max'  => 100,
        'step' => 1,
    ],
    'priority' => 310,
] );

// Upgrade to Pro List
Kirki::add_field( 'newsx_theme_config', [
    'type' => 'newsx-upgrade-pro-list',
    'settings' => 'newsx_options[header_nt_upgrade_pro_list]',
    'section' => 'newsx_section_hd_news_ticker',
    'tab' => 'general',
    'label' => esc_html__( 'Need more News Ticker Options?', 'news-magazine-x' ),
    'choices' => [
        'orderby' => esc_html__( 'Order: Modified, Popular, Random...', 'news-magazine-x' ),
        'ticker-type' => esc_html__( 'News Ticker Type: Slider', 'news-magazine-x' ),
        'slider-nav-arrows' => esc_html__( 'Slider Navigation Arrows', 'news-magazine-x' ),
        'slider-autoplay' => esc_html__( 'Slider Autoplay on Load', 'news-magazine-x' ),
        'heading-styles' => esc_html__( 'Different Heading Styles', 'news-magazine-x' ),
        'heading-icon-styles' => esc_html__( 'Blinking Heading Icon', 'news-magazine-x' ),
        'letter-count' => esc_html__( 'Post Title Letter Count', 'news-magazine-x' ),
        'much-more' => esc_html__( 'And much more....', 'news-magazine-x' ),
    ],
    'description' => 'https://wp-royal-themes.com/themes/item-news-magazine-x-pro/?ref=newsx-free-customizer-header-sec-ticker-upgrade-pro#features',
    'divider' => 'newsx-group-divider-top',
    'priority' => 999,
] );

endif; // Free Version
