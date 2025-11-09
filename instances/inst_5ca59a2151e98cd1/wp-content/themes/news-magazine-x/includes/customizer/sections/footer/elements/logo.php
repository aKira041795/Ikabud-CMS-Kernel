<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'image',
    'settings' => 'newsx_options[footer_logo]',
    'label' => esc_html__( 'Logo', 'news-magazine-x' ),
    'section' => 'newsx_section_ft_logo',
	'tab' => 'general',
    'priority' => 10,
] );

// Add Pro Fields
newsx_add_pro_field( 'ft_retina_logo_sw', 20 );
newsx_add_pro_field( 'ft_retina_logo', 25 );
newsx_add_pro_field( 'ft_dark_logo', 30 );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'radio-buttonset',
    'settings' => 'newsx_options[footer_logo_align]',
    'label' => esc_html__( 'Alignment', 'news-magazine-x' ),
    'section' => 'newsx_section_ft_logo',
	'tab' => 'general',
	'custom_class' => 'newsx-alignment',
	'responsive' => true,
	'default' => [
		'desktop' => 'flex-start',
		'tablet'  => 'flex-start',
		'mobile'  => 'flex-start',
	],
    'choices' => newsx_get_align_control_options('flex'),
	'divider' => 'newsx-group-divider-top',
    'priority' => 40,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'inputslider',
    'settings' => 'newsx_options[footer_logo_width]',
    'label' => esc_html__( 'Logo Width', 'news-magazine-x' ),
    'section' => 'newsx_section_ft_logo',
	'tab' => 'general',
	'responsive' => true,
	'default'    => [
		'desktop' => 50,
		'tablet'  => '',
		'mobile'  => '',
	],
    'choices'  => [
        'min'  => 0,
        'max'  => 500,
        'step' => 10,
    ],
    'custom_class' => 'newsx-slider-unit-px',
	'divider' => 'newsx-group-divider-top',
    'priority' => 30,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'newsx-headline',
    'settings' => 'newsx_options[footer_logo_spacing_headline]',
    'section' => 'newsx_section_ft_logo',
	'tab' => 'design',
    'label' => esc_html__( 'Spacing', 'news-magazine-x' ),
    'priority' => 299,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'kirki-margin',
    'settings' => 'newsx_options[footer_logo_margin]',
    'section' => 'newsx_section_ft_logo',
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
        'settings' => 'newsx_options[section_ft_logo_upgrade_pro_list]',
        'section' => 'newsx_section_ft_logo',
        'tab' => 'general',
        'label' => esc_html__( 'Need more Logo Options?', 'news-magazine-x' ),
        'choices' => [
            'retina-sw' => esc_html__( 'Retina Logo Switcher', 'news-magazine-x' ),
            'retina' => esc_html__( 'Retina Logo Upload', 'news-magazine-x' ),
        ],
        'description' => 'https://wp-royal-themes.com/themes/item-news-magazine-x-pro/?ref=newsx-free-customizer-footer-sec-logo-upgrade-pro#features',
        'divider' => 'newsx-group-divider-top',
        'priority' => 999,
    ] );

}
