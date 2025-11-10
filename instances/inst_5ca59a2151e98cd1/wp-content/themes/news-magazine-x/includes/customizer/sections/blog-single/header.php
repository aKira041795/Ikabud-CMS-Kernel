<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Pro Version
if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) :
    newsx_add_pro_controls_group( 'blog-single-header' );

// Free Version
else:

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'select',
    'settings' => 'newsx_options[bs_header_layout_preset]',
    'label' => esc_html__( 'Post Header Layout Preset', 'news-magazine-x' ),
    'section' => 'newsx_section_bs_header',
	'tab' => 'general',
    'default' => 's5',
    'choices' => [
        's5' => esc_html__( 'Default', 'news-magazine-x' ),
        's4' => esc_html__( 'Style 2 (No Media)', 'news-magazine-x' ),
    ],
    'priority' => 10,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'switch',
    'settings' => 'newsx_options[bs_header_show_breadcrumbs]',
    'label' => esc_html__( 'Show Breadcrumbs', 'news-magazine-x' ),
    'section' => 'newsx_section_bs_header',
	'tab' => 'general',
    'default' => true,
	'divider' => 'newsx-group-divider-top',
    'priority' => 20,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'switch',
    'settings' => 'newsx_options[bs_header_show_categories]',
    'label' => esc_html__( 'Show Categories', 'news-magazine-x' ),
    'section' => 'newsx_section_bs_header',
	'tab' => 'general',
    'default' => true,
	'divider' => 'newsx-group-divider-top',
    'priority' => 25,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'newsx-headline',
    'settings' => 'newsx_options[bs_header_meta_headline]',
    'section' => 'newsx_section_bs_header',
	'tab' => 'general',
    'label' => esc_html__( 'Post Meta', 'news-magazine-x' ),
    'priority' => 29,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'switch',
    'settings' => 'newsx_options[bs_header_show_author]',
    'label' => esc_html__( 'Show Author', 'news-magazine-x' ),
    'section' => 'newsx_section_bs_header',
	'tab' => 'general',
    'default' => false,
    'priority' => 30,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'switch',
    'settings' => 'newsx_options[bp_header_show_avatar]',
    'label' => esc_html__( 'Show Avatar', 'news-magazine-x' ),
    'section' => 'newsx_section_bs_header',
	'tab' => 'general',
    'default' => false,
	'active_callback' => [
		[
			'setting'  => 'newsx_options[bs_header_show_author]',
			'operator' => '!=',
			'value'    => false,
		]
	],
    'priority' => 31,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'number',
    'settings' => 'newsx_options[bs_header_avatar_size]',
    'section' => 'newsx_section_bs_header',
	'tab' => 'general',
    'label' => esc_html__( 'Avatar Size', 'news-magazine-x' ),
    'default' => 32,
	'active_callback' => [
		[
			'setting'  => 'newsx_options[bs_header_show_author]',
			'operator' => '!=',
			'value'    => false,
        ],
		[
			'setting'  => 'newsx_options[bp_header_show_avatar]',
			'operator' => '!=',
			'value'    => false,
		]
	],
    'priority' => 32,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'switch',
    'settings' => 'newsx_options[bs_header_show_comments]',
    'label' => esc_html__( 'Show Comments', 'news-magazine-x' ),
    'section' => 'newsx_section_bs_header',
	'tab' => 'general',
    'default' => false,
	'divider' => 'newsx-group-divider-top',
    'priority' => 40,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'switch',
    'settings' => 'newsx_options[bs_header_show_time]',
    'label' => esc_html__( 'Show Time', 'news-magazine-x' ),
    'section' => 'newsx_section_bs_header',
	'tab' => 'general',
    'default' => false,
	'divider' => 'newsx-group-divider-top',
    'priority' => 72,
] );

// Upgrade to Pro List
Kirki::add_field( 'newsx_theme_config', [
    'type' => 'newsx-upgrade-pro-list',
    'settings' => 'newsx_options[bs_header_upgrade_pro_list]',
    'section' => 'newsx_section_bs_header',
    'tab' => 'general',
    'label' => esc_html__( 'Need more Post Header Options?', 'news-magazine-x' ),
    'choices' => [
        'header-layouts' => esc_html__( '8 Different Header Layouts', 'news-magazine-x' ),
        'reading-time' => esc_html__( 'Show Reading Time', 'news-magazine-x' ),
        'post-views' => esc_html__( 'Show Post Views', 'news-magazine-x' ),
        'date-format' => esc_html__( 'Date Format: Last Updated', 'news-magazine-x' ),
        'much-more' => esc_html__( 'And much more....', 'news-magazine-x' ),
    ],
    'description' => 'https://wp-royal-themes.com/themes/item-news-magazine-x-pro/?ref=newsx-free-customizer-blog-single-sec-header-upgrade-pro#features',
    'divider' => 'newsx-group-divider-top',
    'priority' => 999,
] );

endif; // Free Version
