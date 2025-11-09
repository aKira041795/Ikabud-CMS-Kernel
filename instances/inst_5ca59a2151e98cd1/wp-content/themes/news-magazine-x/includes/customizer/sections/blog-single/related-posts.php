<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Pro Version
if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) :
    newsx_add_pro_controls_group( 'blog-single-related-posts' );

// Free Version
else:

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'switch',
    'settings' => 'newsx_options[bs_related_enable]',
    'label' => esc_html__( 'Enable Related Posts', 'news-magazine-x' ),
    'section' => 'newsx_section_bs_related',
	'tab' => 'general',
    'default' => true,
	'divider' => 'newsx-group-divider-bottom',
    'priority' => 1,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'text',
    'settings' => 'newsx_options[bs_related_title]',
    'section' => 'newsx_section_bs_related',
	'tab' => 'general',
    'label' => esc_html__( 'Section Title', 'news-magazine-x' ),
    'default' => 'Related Posts',
    'priority' => 5,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'switch',
    'settings' => 'newsx_options[bs_related_section_divider]',
    'section' => 'newsx_section_bs_related',
	'tab' => 'general',
    'label' => esc_html__( 'Show Section Divider', 'news-magazine-x' ),
    'default' => true,
	'divider' => 'newsx-group-divider-top',
    'priority' => 20,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'newsx-headline',
    'settings' => 'newsx_options[bs_related_spacing_headline]',
    'section' => 'newsx_section_bs_related',
	'tab' => 'design',
    'label' => esc_html__( 'Spacing', 'news-magazine-x' ),
    'priority' => 299,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'kirki-margin',
    'settings' => 'newsx_options[bs_related_padding]',
    'section' => 'newsx_section_bs_related',
	'tab' => 'design',
    'label' => esc_html__( 'Padding', 'news-magazine-x' ),
    'responsive' => true,
    'default'    => [
        'desktop' => [
            'top'      => '25',
            'right'    => '',
            'bottom'   => '45',
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
    'settings' => 'newsx_options[bs_related_margin]',
    'section' => 'newsx_section_bs_related',
	'tab' => 'design',
    'label' => esc_html__( 'Margin', 'news-magazine-x' ),
    'responsive' => true,
    'default'    => [
        'desktop' => [
            'top'      => '',
            'right'    => '',
            'bottom'   => '',
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
    'settings' => 'newsx_options[bs_related_upgrade_pro_list]',
    'section' => 'newsx_section_bs_related',
    'tab' => 'general',
    'label' => esc_html__( 'Need more Related Posts Options?', 'news-magazine-x' ),
    'choices' => [
        'query' => esc_html__( 'Display Related or Random Posts', 'news-magazine-x' ),
        'posts' => esc_html__( 'Display 3, 6 or 9 Posts Grid', 'news-magazine-x' ),
    ],
    'description' => 'https://wp-royal-themes.com/themes/item-news-magazine-x-pro/?ref=newsx-free-customizer-blog-single-sec-related-upgrade-pro#features',
    'divider' => 'newsx-group-divider-top',
    'priority' => 999,
] );

endif; // Free Version
