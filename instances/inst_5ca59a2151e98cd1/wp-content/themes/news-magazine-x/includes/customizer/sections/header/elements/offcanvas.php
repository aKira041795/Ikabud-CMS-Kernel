<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Pro Version
if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) :
    newsx_add_pro_controls_group( 'header-off-canvas' );

else : // Free Version

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'newsx-icon-select',
    'settings' => 'newsx_options[header_ofc_icon]',
    'section' => 'newsx_section_hd_offcanvas',
	'tab' => 'general',
    'label' => esc_html__( 'Select Icon', 'news-magazine-x' ),
    'default' => 'hamburger-thin',
    'priority' => 10,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'multicheck',
    'settings' => 'newsx_options[header_ofc_visibility]',
    'label' => esc_html__( 'Visibility', 'news-magazine-x' ),
    'section' => 'newsx_section_hd_offcanvas',
	'tab' => 'general',
	'custom_class' => 'newsx-visibility',
	'divider' => 'newsx-group-divider-top',
	'default'  => [ 'desktop', 'tablet', 'mobile' ],
	'choices'  => [
		'desktop' => esc_html__( 'Desktop', 'news-magazine-x' ),
		'tablet' => esc_html__( 'Tablet', 'news-magazine-x' ),
		'mobile' => esc_html__( 'Mobile', 'news-magazine-x' ),
	],
    'priority' => 99,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'newsx-headline',
    'settings' => 'newsx_options[header_ofc_spacing_headline]',
    'section' => 'newsx_section_hd_offcanvas',
	'tab' => 'design',
    'label' => esc_html__( 'Spacing', 'news-magazine-x' ),
    'priority' => 299,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'kirki-margin',
    'settings' => 'newsx_options[header_ofc_icon_margin]',
    'section' => 'newsx_section_hd_offcanvas',
	'tab' => 'design',
    'label' => esc_html__( 'Icon Margin', 'news-magazine-x' ),
    'responsive' => true,
    'default'    => [
        'desktop' => [
            'top'      => '',
            'right'    => '5',
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
    'priority' => 300,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'kirki-margin',
    'settings' => 'newsx_options[header_ofc_padding]',
    'section' => 'newsx_section_hd_offcanvas',
	'tab' => 'design',
    'label' => esc_html__( 'Popup Padding', 'news-magazine-x' ),
    'responsive' => true,
    'default'    => [
        'desktop' => [
            'top'      => '40',
            'right'    => '20',
            'bottom'   => '40',
            'left'     => '20',
            'isLinked' => false,
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
    'priority' => 305,
] );

// Upgrade to Pro List
Kirki::add_field( 'newsx_theme_config', [
    'type' => 'newsx-upgrade-pro-list',
    'settings' => 'newsx_options[header_ofc_upgrade_pro_list]',
    'section' => 'newsx_section_hd_offcanvas',
    'tab' => 'general',
    'label' => esc_html__( 'Need more Off-Canvas Options?', 'news-magazine-x' ),
    'choices' => [
        'width' => esc_html__( 'Custom Popup Width', 'news-magazine-x' ),
        'icon-size' => esc_html__( 'Custom Icon Size', 'news-magazine-x' ),
        'icon-color' => esc_html__( 'Custom Icon Color', 'news-magazine-x' ),
        'much-more' => esc_html__( 'And much more....', 'news-magazine-x' ),
    ],
    'description' => 'https://wp-royal-themes.com/themes/item-news-magazine-x-pro/?ref=newsx-free-customizer-header-sec-offcanvas-upgrade-pro#features',
    'divider' => 'newsx-group-divider-top',
    'priority' => 999,
] );

endif; // Free Version
