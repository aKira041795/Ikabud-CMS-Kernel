<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Add Pro Field
newsx_add_pro_field( 'dark_switcher_default', 10 );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'switch',
    'settings' => 'newsx_options[dark_switcher_show_tooltip]',
    'section' => 'newsx_section_hd_dark_switcher',
	'tab' => 'general',
    'label' => esc_html__( 'Show Tooltip', 'news-magazine-x' ),
	'default' => true,
    'priority' => 10,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'text',
    'settings' => 'newsx_options[dark_switcher_tooltip_light]',
    'section' => 'newsx_section_hd_dark_switcher',
	'tab' => 'general',
    'label' => esc_html__( 'Tooltip: Light Text', 'news-magazine-x' ),
    'default' => 'Switch to Dark',
	'active_callback' => [
		[
			'setting'  => 'newsx_options[dark_switcher_show_tooltip]',
			'operator' => '!=',
			'value'    => false,
		]
	],
    'priority' => 11,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'text',
    'settings' => 'newsx_options[dark_switcher_tooltip_dark]',
    'section' => 'newsx_section_hd_dark_switcher',
	'tab' => 'general',
    'label' => esc_html__( 'Tooltip: Dark Text', 'news-magazine-x' ),
    'default' => 'Switch to Light',
	'active_callback' => [
		[
			'setting'  => 'newsx_options[dark_switcher_show_tooltip]',
			'operator' => '!=',
			'value'    => false,
		]
	],
    'priority' => 12,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'multicheck',
    'settings' => 'newsx_options[dark_switcher_visibility]',
    'section' => 'newsx_section_hd_dark_switcher',
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

// Add Pro Field
newsx_add_pro_field( 'dark_switcher_duplicate_visibility', 100 );

// Kirki::add_field( 'newsx_theme_config', [
//     'type' => 'multicolor',
//     'settings' => 'newsx_options[dark_switcher_color]',
//     'section' => 'newsx_section_hd_dark_switcher',
// 	'tab' => 'design',
//     'label' => esc_html__( 'Color', 'news-magazine-x' ),
//     'choices'   => [
//         'normal' => esc_html__( 'Normal', 'news-magazine-x' ),
//         'hover' => esc_html__( 'Hover', 'news-magazine-x' ),
//     ],
//     'default' => [
//         'normal' => '',
//         'hover' => '',
//     ],
//     'custom_class' => 'newsx-multicolor',
//     'priority' => 110,
// ] );

// Kirki::add_field( 'newsx_theme_config', [
//     'type' => 'inputslider',
//     'settings' => 'newsx_options[dark_switcher_icon_size]',
//     'section' => 'newsx_section_hd_dark_switcher',
// 	'tab' => 'design',
//     'label' => esc_html__( 'Icon Size', 'news-magazine-x' ),
// 	'responsive' => true,
// 	'default'    => [
// 		'desktop' => '',
// 		'tablet'  => '',
// 		'mobile'  => '',
// 	],
//     'choices'  => [
//         'min'  => 10,
//         'max'  => 25,
//         'step' => 1,
//     ],
//     'custom_class' => 'newsx-slider-unit-px',
// 	'divider' => 'newsx-group-divider-top',
//     'priority' => 120,
// ] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'newsx-headline',
    'settings' => 'newsx_options[dark_switcher_spacing_headline]',
    'section' => 'newsx_section_hd_dark_switcher',
	'tab' => 'design',
    'label' => esc_html__( 'Spacing', 'news-magazine-x' ),
    'priority' => 299,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'kirki-margin',
    'settings' => 'newsx_options[dark_switcher_margin]',
    'section' => 'newsx_section_hd_dark_switcher',
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
if ( !defined('NEWSX_CORE_PRO_VERSION') || !newsx_core_pro_fs()->can_use_premium_code() ) {

    Kirki::add_field( 'newsx_theme_config', [
        'type' => 'newsx-upgrade-pro-list',
        'settings' => 'newsx_options[dark_switcher_upgrade_pro_list]',
        'section' => 'newsx_section_hd_dark_switcher',
        'tab' => 'general',
        'label' => esc_html__( 'Need more Dark Switcher Options?', 'news-magazine-x' ),
        'choices' => [
            'color' => esc_html__( 'Set Dark Mode by Default', 'news-magazine-x' ),
        ],
        'description' => 'https://wp-royal-themes.com/themes/item-news-magazine-x-pro/?ref=newsx-free-customizer-header-sec-dark-mode-upgrade-pro#features',
        'divider' => 'newsx-group-divider-top',
        'priority' => 999,
    ] );

}
