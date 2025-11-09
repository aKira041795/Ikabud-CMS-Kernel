<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Pro Version
if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) :
    newsx_add_pro_controls_group( 'footer-menu' );

else : // Free Version

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'radio-buttonset',
    'settings' => 'newsx_options[footer_menu_layout]',
    'label' => esc_html__( 'Layout', 'news-magazine-x' ),
    'section' => 'newsx_section_ft_footer_menu',
	'tab' => 'general',
	'default' => 'row',
    'choices' => [
		'row' => esc_html__( 'Inline', 'news-magazine-x' ),
		'column' => esc_html__( 'Stack', 'news-magazine-x' ),
	],
    'priority' => 80,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'radio-buttonset',
    'settings' => 'newsx_options[footer_menu_align]',
    'label' => esc_html__( 'Alignment', 'news-magazine-x' ),
    'section' => 'newsx_section_ft_footer_menu',
	'tab' => 'general',
	'custom_class' => 'newsx-alignment',
	'responsive' => true,
	'default'    => [
		'desktop' => 'center',
		'tablet'  => 'center',
		'mobile'  => 'center',
	],
    'choices' => newsx_get_align_control_options('flex'),
	'divider' => 'newsx-group-divider-top',
    'priority' => 90,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'multicheck',
    'settings' => 'newsx_options[footer_menu_visibility]',
    'label' => esc_html__( 'Visibility', 'news-magazine-x' ),
    'section' => 'newsx_section_ft_footer_menu',
	'tab' => 'general',
	'custom_class' => 'newsx-visibility',
	'default'  => [ 'desktop', 'tablet', 'mobile' ],
	'choices'  => [
		'desktop' => esc_html__( 'Desktop', 'news-magazine-x' ),
		'tablet' => esc_html__( 'Tablet', 'news-magazine-x' ),
		'mobile' => esc_html__( 'Mobile', 'news-magazine-x' ),
	],
	'divider' => 'newsx-group-divider-top',
    'priority' => 100,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'newsx-headline',
    'settings' => 'newsx_options[footer_menu_spacing_headline]',
    'section' => 'newsx_section_ft_footer_menu',
	'tab' => 'design',
    'label' => esc_html__( 'Spacing', 'news-magazine-x' ),
    'priority' => 299,
]);

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'kirki-margin',
    'settings' => 'newsx_options[footer_menu_item_padding]',
    'section' => 'newsx_section_ft_footer_menu',
	'tab' => 'design',
    'label' => esc_html__( 'Item Padding', 'news-magazine-x' ),
    'responsive' => true,
    'default'    => [
        'desktop' => [
            'top'      => '',
            'right'    => '',
            'bottom'   => '',
            'left'     => '15',
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
    'priority' => 300,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'kirki-margin',
    'settings' => 'newsx_options[footer_menu_margin]',
    'section' => 'newsx_section_ft_footer_menu',
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
    'priority' => 310,
] );

// Upgrade to Pro List
Kirki::add_field( 'newsx_theme_config', [
    'type' => 'newsx-upgrade-pro-list',
    'settings' => 'newsx_options[footer_menu_upgrade_pro_list]',
    'section' => 'newsx_section_ft_footer_menu',
    'tab' => 'general',
    'label' => esc_html__( 'Need more Footer Menu Options?', 'news-magazine-x' ),
    'choices' => [
        'color' => esc_html__( 'Menu Item Colors', 'news-magazine-x' ),
        'bg-color' => esc_html__( 'Menu Item Background Colors', 'news-magazine-x' ),
        'font-size' => esc_html__( 'Menu Item Font Size', 'news-magazine-x' ),
    ],
    'description' => 'https://wp-royal-themes.com/themes/item-news-magazine-x-pro/?ref=newsx-free-customizer-footer-sec-menu-upgrade-pro#features',
    'divider' => 'newsx-group-divider-top',
    'priority' => 999,
] );

endif; // Free Version
