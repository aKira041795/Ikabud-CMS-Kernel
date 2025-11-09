<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Pro Version
if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) :
    newsx_add_pro_controls_group( 'header-random-post' );

else : // Free Version

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'newsx-icon-select',
    'settings' => 'newsx_options[random_post_icon]',
    'section' => 'newsx_section_hd_random_post',
	'tab' => 'general',
    'label' => esc_html__( 'Select Icon', 'news-magazine-x' ),
    'default' => 'random',
	'divider' => 'newsx-divider-bottom',
    'priority' => 5,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'switch',
    'settings' => 'newsx_options[random_post_show_tooltip]',
    'section' => 'newsx_section_hd_random_post',
	'tab' => 'general',
    'label' => esc_html__( 'Show Tooltip', 'news-magazine-x' ),
    'default' => false,
    'priority' => 10,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'text',
    'settings' => 'newsx_options[random_post_tooltip]',
    'section' => 'newsx_section_hd_random_post',
	'tab' => 'general',
    'label' => esc_html__( 'Tooltip Text', 'news-magazine-x' ),
    'default' => 'Random Post',
	'active_callback' => [
		[
			'setting'  => 'newsx_options[random_post_show_tooltip]',
			'operator' => '!=',
			'value'    => false,
		]
	],
    'priority' => 11,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'multicheck',
    'settings' => 'newsx_options[random_post_visibility]',
    'section' => 'newsx_section_hd_random_post',
	'tab' => 'general',
    'label' => esc_html__( 'Visibility', 'news-magazine-x' ),
	'default'  => [ 'desktop', 'tablet', 'mobile' ],
	'choices'  => [
		'desktop' => esc_html__( 'Desktop', 'news-magazine-x' ),
		'tablet' => esc_html__( 'Tablet', 'news-magazine-x' ),
		'mobile' => esc_html__( 'Mobile', 'news-magazine-x' ),
	],
	'custom_class' => 'newsx-visibility',
	'divider' => 'newsx-group-divider-top',
    'priority' => 99,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'newsx-headline',
    'settings' => 'newsx_options[random_post_spacing_headline]',
    'section' => 'newsx_section_hd_random_post',
	'tab' => 'design',
    'label' => esc_html__( 'Spacing', 'news-magazine-x' ),
    'priority' => 299,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'kirki-margin',
    'settings' => 'newsx_options[random_post_margin]',
    'section' => 'newsx_section_hd_random_post',
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
    'priority' => 300,
] );

// Upgrade to Pro List
Kirki::add_field( 'newsx_theme_config', [
    'type' => 'newsx-upgrade-pro-list',
    'settings' => 'newsx_options[header_random_post_upgrade_pro_list]',
    'section' => 'newsx_section_hd_random_post',
    'tab' => 'general',
    'label' => esc_html__( 'Need more Random Post Options?', 'news-magazine-x' ),
    'choices' => [
        'icon-size' => esc_html__( 'Custom Icon Size', 'news-magazine-x' ),
        'icon-color' => esc_html__( 'Custom Icon Color', 'news-magazine-x' ),
    ],
    'description' => 'https://wp-royal-themes.com/themes/item-news-magazine-x-pro/?ref=newsx-free-customizer-header-sec-random-upgrade-pro#features',
    'divider' => 'newsx-group-divider-top',
    'priority' => 999,
] );

endif; // Free Version
