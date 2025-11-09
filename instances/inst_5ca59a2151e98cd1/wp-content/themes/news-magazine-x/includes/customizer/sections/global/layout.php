<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Pro Version
if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) :
    newsx_add_pro_controls_group( 'global-layout' );

else : // Free Version

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'radio-buttonset',
    'settings' => 'newsx_options[global_header_width]',
    'label' => esc_html__( 'Header Width', 'news-magazine-x' ),
    'section' => 'newsx_section_global_layout',
	'tab' => 'general',
    'default' => 'boxed',
    'choices' => [
        'full' => esc_html__( 'Full', 'news-magazine-x' ),
        'boxed' => esc_html__( 'Boxed', 'news-magazine-x' ),
    ],
    'priority' => 100,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'radio-buttonset',
    'settings' => 'newsx_options[global_content_width]',
    'label' => esc_html__( 'Content Width', 'news-magazine-x' ),
    'section' => 'newsx_section_global_layout',
	'tab' => 'general',
    'default' => 'boxed',
    'choices' => [
        'full' => esc_html__( 'Full', 'news-magazine-x' ),
        'boxed' => esc_html__( 'Boxed', 'news-magazine-x' ),
    ],
    'priority' => 105,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'radio-buttonset',
    'settings' => 'newsx_options[global_single_content_width]',
    'label' => esc_html__( 'Single Content Width', 'news-magazine-x' ),
    'section' => 'newsx_section_global_layout',
	'tab' => 'general',
    'default' => 'boxed',
    'choices' => [
        'full' => esc_html__( 'Full', 'news-magazine-x' ),
        'boxed' => esc_html__( 'Boxed', 'news-magazine-x' ),
    ],
    'priority' => 110,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'radio-buttonset',
    'settings' => 'newsx_options[global_footer_width]',
    'label' => esc_html__( 'Footer Width', 'news-magazine-x' ),
    'section' => 'newsx_section_global_layout',
	'tab' => 'general',
    'default' => 'boxed',
    'choices' => [
        'full' => esc_html__( 'Full', 'news-magazine-x' ),
        'boxed' => esc_html__( 'Boxed', 'news-magazine-x' ),
    ],
    'priority' => 115,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'switch',
    'settings' => 'newsx_options[global_island_style]',
    'label' => esc_html__( 'Apply Global Island Style', 'news-magazine-x' ),
    'description' => esc_html__( 'Apply Island Style to visually separate some element from the rest of the page with a boxed, padded layout. Works best when the site background is NOT pure white.', 'news-magazine-x' ),
    'section' => 'newsx_section_global_layout',
	'tab' => 'general',
    'default' => false,
    'divider' => 'newsx-group-divider-top',
    'priority' => 117,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'switch',
    'settings' => 'newsx_options[global_border_radius]',
    'label' => esc_html__( 'Apply Global Border Radius', 'news-magazine-x' ),
    'description' => esc_html__( 'Apply border radius to all Elements except Header and Footer.', 'news-magazine-x' ),
    'section' => 'newsx_section_global_layout',
	'tab' => 'general',
    'default' => true,
    'divider' => 'newsx-group-divider-top',
    'priority' => 120,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'switch',
    'settings' => 'newsx_options[global_image_hover_effects]',
    'label' => esc_html__( 'Apply Global Image Hover Effects', 'news-magazine-x' ),
    'description' => esc_html__( 'Apply different hover effects to images in grid layouts.', 'news-magazine-x' ),
    'section' => 'newsx_section_global_layout',
	'tab' => 'general',
    'default' => true,
    'divider' => 'newsx-group-divider-top',
    'priority' => 125,
] );

// Upgrade to Pro List
Kirki::add_field( 'newsx_theme_config', [
    'type' => 'newsx-upgrade-pro-list',
    'settings' => 'newsx_options[global_layout_upgrade_pro_list]',
    'section' => 'newsx_section_global_layout',
    'tab' => 'general',
    'label' => esc_html__( 'Need more Layout Options?', 'news-magazine-x' ),
    'choices' => [
        'width' => esc_html__( 'Custom Container Width', 'news-magazine-x' ),
        'padding' => esc_html__( 'Custom Container Padding', 'news-magazine-x' ),
    ],
    'description' => 'https://wp-royal-themes.com/themes/item-news-magazine-x-pro/?ref=newsx-free-customizer-global-sec-layout-upgrade-pro#features',
    'divider' => 'newsx-group-divider-top',
    'priority' => 999,
] );

endif; // Free Version
