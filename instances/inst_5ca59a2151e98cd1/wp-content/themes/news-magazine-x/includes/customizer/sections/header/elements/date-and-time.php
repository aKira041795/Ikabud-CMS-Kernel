<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'select',
    'settings' => 'newsx_options[date_format]',
    'label' => esc_html__( 'Date Format', 'news-magazine-x' ),
    'section' => 'newsx_section_hd_date_and_time',
	'tab' => 'general',
    'default'  => 'default',
	'choices'  => [
		'default' => esc_html__( 'Theme Default', 'news-magazine-x' ),
		'wordpress' => esc_html__( 'WordPress Date Settings', 'news-magazine-x' ),
	],
    'priority' => 5,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'switch',
    'settings' => 'newsx_options[date_show_time]',
    'section' => 'newsx_section_hd_date_and_time',
	'tab' => 'general',
    'label' => esc_html__( 'Show Live Time', 'news-magazine-x' ),
	'default' => false,
    'divider' => 'newsx-group-divider-top',
    'priority' => 10,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'switch',
    'settings' => 'newsx_options[date_show_icons]',
    'section' => 'newsx_section_hd_date_and_time',
	'tab' => 'general',
    'label' => esc_html__( 'Show Icons', 'news-magazine-x' ),
	'default' => false,
    'divider' => 'newsx-group-divider-top',
    'priority' => 15,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'multicheck',
    'settings' => 'newsx_options[date_visibility]',
    'label' => esc_html__( 'Visibility', 'news-magazine-x' ),
    'section' => 'newsx_section_hd_date_and_time',
	'tab' => 'general',
	'custom_class' => 'newsx-visibility newsx-group-divider-top',
	'default'  => [ 'desktop', 'tablet', '' ],
	'choices'  => [
		'desktop' => esc_html__( 'Desktop', 'news-magazine-x' ),
		'tablet' => esc_html__( 'Tablet', 'news-magazine-x' ),
		'mobile' => esc_html__( 'Mobile', 'news-magazine-x' ),
	],
    'priority' => 99,
] );

// Add Pro Fields
newsx_add_pro_field( 'date_duplicate_visibility', 100 );
newsx_add_pro_field( 'header_date_color', 110 );
newsx_add_pro_field( 'header_date_font_size', 115 );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'newsx-headline',
    'settings' => 'newsx_options[header_date_spacing_headline]',
    'section' => 'newsx_section_hd_date_and_time',
	'tab' => 'design',
    'label' => esc_html__( 'Spacing', 'news-magazine-x' ),
    'priority' => 299,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'kirki-margin',
    'settings' => 'newsx_options[header_date_margin]',
    'section' => 'newsx_section_hd_date_and_time',
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
        'settings' => 'newsx_options[date_and_time_upgrade_pro_list]',
        'section' => 'newsx_section_hd_date_and_time',
        'tab' => 'general',
        'label' => esc_html__( 'Need more Date & Time Options?', 'news-magazine-x' ),
        'choices' => [
            'color' => esc_html__( 'Custom Color', 'news-magazine-x' ),
            'font-size' => esc_html__( 'Custom Font Size', 'news-magazine-x' ),
        ],
        'description' => 'https://wp-royal-themes.com/themes/item-news-magazine-x-pro/?ref=newsx-free-customizer-header-sec-date-upgrade-pro#features',
        'divider' => 'newsx-group-divider-top',
        'priority' => 999,
    ] );

}
