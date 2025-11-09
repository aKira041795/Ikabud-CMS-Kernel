<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Pro Version
if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) :
    newsx_add_pro_controls_group( 'blog-single-navigation' );

// Free Version
else:

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'switch',
    'settings' => 'newsx_options[bs_nav_enable]',
    'label' => esc_html__( 'Enable Navigation', 'news-magazine-x' ),
    'default' => true,
    'section' => 'newsx_section_bs_navigation',
	'tab' => 'general',
	'divider' => 'newsx-group-divider-bottom',
    'priority' => 1,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'select',
    'settings' => 'newsx_options[bs_nav_style]',
    'label' => esc_html__( 'Navigation Style', 'news-magazine-x' ),
    'section' => 'newsx_section_bs_navigation',
	'tab' => 'general',
    'default' => 's0-nr',
    'choices' => [
        's0-nr' => esc_html__( 'Default', 'news-magazine-x' ),
        's5' => esc_html__( 'Style 2', 'news-magazine-x' ),
    ],
    'priority' => 5,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'switch',
    'settings' => 'newsx_options[bs_nav_dividers]',
    'label' => esc_html__( 'Show Dividers', 'news-magazine-x' ),
    'default' => true,
    'section' => 'newsx_section_bs_navigation',
	'tab' => 'general',
    'priority' => 10,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'newsx-headline',
    'settings' => 'newsx_options[bs_nav_spacing_headline]',
    'section' => 'newsx_section_bs_navigation',
	'tab' => 'design',
    'label' => esc_html__( 'Spacing', 'news-magazine-x' ),
    'priority' => 299,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'kirki-margin',
    'settings' => 'newsx_options[bs_nav_padding]',
    'section' => 'newsx_section_bs_navigation',
	'tab' => 'design',
    'label' => esc_html__( 'Padding', 'news-magazine-x' ),
    'responsive' => true,
    'default'    => [
        'desktop' => [
            'top'      => '40',
            'right'    => '',
            'bottom'   => '50',
            'left'     => '',
            'isLinked' => false,
        ],
        'tablet'  => [
            'top'      => '',
            'right'    => '',
            'bottom'   => '',
            'left'     => '',
            'isLinked' => false,
        ],
        'mobile'  => [
            'top'      => '',
            'right'    => '',
            'bottom'   => '',
            'left'     => '',
            'isLinked' => false,
        ],
    ],
    'choices'  => [
        'min'  => 0,
        'max'  => 100,
        'step' => 1,
    ],
    'priority' => 300,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'kirki-margin',
    'settings' => 'newsx_options[bs_nav_margin]',
    'section' => 'newsx_section_bs_navigation',
	'tab' => 'design',
    'label' => esc_html__( 'Margin', 'news-magazine-x' ),
    'responsive' => true,
    'default'    => [
        'desktop' => [
            'top'      => '50',
            'right'    => '',
            'bottom'   => '50',
            'left'     => '',
            'isLinked' => false,
        ],
        'tablet'  => [
            'top'      => '',
            'right'    => '',
            'bottom'   => '',
            'left'     => '',
            'isLinked' => false,
        ],
        'mobile'  => [
            'top'      => '',
            'right'    => '',
            'bottom'   => '',
            'left'     => '',
            'isLinked' => false,
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
    'settings' => 'newsx_options[bs_nav_upgrade_pro_list]',
    'section' => 'newsx_section_bs_navigation',
    'tab' => 'general',
    'label' => esc_html__( 'Need more Post Navigation Options?', 'news-magazine-x' ),
    'choices' => [
        'styles' => esc_html__( '8 Different Navigation Styles', 'news-magazine-x' ),
        'custom-icons' => esc_html__( 'Custom Navigation Icons', 'news-magazine-x' ),
        'custom-labels' => esc_html__( 'Custom Navigation Labels', 'news-magazine-x' ),
    ],
    'description' => 'https://wp-royal-themes.com/themes/item-news-magazine-x-pro/?ref=newsx-free-customizer-blog-single-sec-navigation-upgrade-pro#features',
    'divider' => 'newsx-group-divider-top',
    'priority' => 999,
] );

endif; // Free Version