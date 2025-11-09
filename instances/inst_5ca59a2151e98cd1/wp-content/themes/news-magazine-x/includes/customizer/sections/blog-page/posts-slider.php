<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Pro Version
if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) :
    newsx_add_pro_controls_group( 'blog-page-slider' );

else : // Free Version

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'switch',
    'settings' => 'newsx_options[section_bp_slider_enable]',
    'label' => esc_html__( 'Enable this Section', 'news-magazine-x' ),
    'description' => esc_html__( 'Please Note: Posts Slider will only be displayed on the blog page if Settings > Reading > "Front page displays" is set to "A static page".', 'news-magazine-x' ),
    'section' => 'newsx_section_bp_slider',
	'tab' => 'general',
    'default' => true,
    'priority' => 1,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'newsx-headline',
    'settings' => 'newsx_options[bp_slider_query_headline]',
    'section' => 'newsx_section_bp_slider',
	'tab' => 'general',
    'label' => esc_html__( 'Query', 'news-magazine-x' ),
    'priority' => 9,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'select',
    'settings' => 'newsx_options[bp_slider_orderby]',
    'section' => 'newsx_section_bp_slider',
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
    'settings' => 'newsx_options[bp_slider_categories]',
    'section' => 'newsx_section_bp_slider',
	'tab' => 'general',
    'label' => esc_html__( 'Categories', 'news-magazine-x' ),
    'default' => '',
    'multiple' => true,
    'choices' => newsx_get_taxonomy_term_choices( 'category' ),
    'priority' => 25,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'select',
    'settings' => 'newsx_options[bp_slider_tags]',
    'section' => 'newsx_section_bp_slider',
	'tab' => 'general',
    'label' => esc_html__( 'Tags', 'news-magazine-x' ),
    'default' => '',
    'multiple' => true,
    'choices' => newsx_get_taxonomy_term_choices( 'post_tag' ),
    'priority' => 30,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'newsx-headline',
    'settings' => 'newsx_options[bp_slider_nav_headline]',
    'section' => 'newsx_section_bp_slider',
	'tab' => 'general',
    'label' => esc_html__( 'Navigation', 'news-magazine-x' ),
    'priority' => 84,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'switch',
    'settings' => 'newsx_options[bp_slider_nav_arrows]',
    'label' => esc_html__( 'Show Arrows', 'news-magazine-x' ),
    'section' => 'newsx_section_bp_slider',
	'tab' => 'general',
    'default' => true,
    'priority' => 85,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'newsx-headline',
    'settings' => 'newsx_options[bp_slider_elements_headline]',
    'section' => 'newsx_section_bp_slider',
	'tab' => 'general',
    'label' => esc_html__( 'Elements', 'news-magazine-x' ),
    'priority' => 89,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'select',
    'settings' => 'newsx_options[bp_slider_title_tag]',
    'section' => 'newsx_section_bp_slider',
	'tab' => 'general',
    'label' => esc_html__( 'Title Tag', 'news-magazine-x' ),
    'default' => 'h1',
    'choices' => newsx_get_html_tag_options(),
    'priority' => 90,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'number',
    'settings' => 'newsx_options[bp_slider_title_letter_count]',
    'section' => 'newsx_section_bp_slider',
	'tab' => 'general',
    'label' => esc_html__( 'Title Letter Count', 'news-magazine-x' ),
    'default' => '',
	'divider' => 'newsx-group-divider-bottom',
    'priority' => 96,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'select',
    'settings' => 'newsx_options[bp_slider_date_format]',
    'section' => 'newsx_section_bp_slider',
	'tab' => 'general',
    'label' => esc_html__( 'Date Format', 'news-magazine-x' ),
    'default' => 'default',
    'choices' => [
        'default' => esc_html__( 'WordPress Default', 'news-magazine-x' ),
        'time-ago' => esc_html__( 'Time Ago', 'news-magazine-x' ),
    ],
    'priority' => 105,
] );

// Upgrade to Pro List
Kirki::add_field( 'newsx_theme_config', [
    'type' => 'newsx-upgrade-pro-list',
    'settings' => 'newsx_options[bp_slider_upgrade_pro_list]',
    'section' => 'newsx_section_bp_slider',
    'tab' => 'general',
    'label' => esc_html__( 'Need more Posts Slider Options?', 'news-magazine-x' ),
    'choices' => [
        'columns' => esc_html__( 'Choose 1, 2, 3, or 4 Columns', 'news-magazine-x' ),
        'orderby' => esc_html__( 'Order: Modified, Popular, Random...', 'news-magazine-x' ),
        'number-of-posts' => esc_html__( 'Unlimited Number of Posts', 'news-magazine-x' ),
        'autoplay' => esc_html__( 'Slider Autoplay on Load', 'news-magazine-x' ),
        'nav-arrows' => esc_html__( 'Custom Navigation Arrows', 'news-magazine-x' ),
        'read-more-text' => esc_html__( 'Custom Read More Text', 'news-magazine-x' ),
        'much-more' => esc_html__( 'And much more....', 'news-magazine-x' ),
    ],
    'description' => 'https://wp-royal-themes.com/themes/item-news-magazine-x-pro/?ref=newsx-free-customizer-blog-page-sec-posts-slider-upgrade-pro#features',
    'divider' => 'newsx-group-divider-top',
    'priority' => 999,
] );

endif; // Free Version
