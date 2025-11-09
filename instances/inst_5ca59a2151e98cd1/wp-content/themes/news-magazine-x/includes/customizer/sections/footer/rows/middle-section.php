<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'switch',
    'settings' => 'newsx_options[section_ft_middle_enable]',
    'label' => esc_html__( 'Enable this Section', 'news-magazine-x' ),
    'section' => 'newsx_section_ft_middle',
	'tab' => 'general',
    'default' => false,
	'divider' => 'newsx-divider-bottom',
    'priority' => 10,
] );

/*
** Footer: Middle Section Elements
*/
Kirki::add_field( 'newsx_theme_config', [
    'type'     => 'repeater',
    'settings' => 'newsx_options[section_ft_middle_elements]',
    'label'    => esc_html__( 'Middle Section Elements', 'news-magazine-x' ),
    'section'  => 'newsx_section_ft_middle',
	'tab' => 'general',
    'default'  => [
        [
            'ft_middle_select_element' => 'footer-widgets-1',
            'ft_middle_element_position' => '1',
            'edit_element_options' => 'options'
        ],
        [
            'ft_middle_select_element' => 'footer-widgets-2',
            'ft_middle_element_position' => '2',
            'edit_element_options' => 'options'
        ],
        [
            'ft_middle_select_element' => 'footer-widgets-3',
            'ft_middle_element_position' => '3',
            'edit_element_options' => 'options'
        ],
        [
            'ft_middle_select_element' => 'footer-widgets-4',
            'ft_middle_element_position' => '4',
            'edit_element_options' => 'options'
        ],
    ],
    'fields'   => [
        'ft_middle_select_element' => [
            'type' => 'select',
            'label' => esc_html__('Select Element', 'news-magazine-x'),
            'default' => 'social-icons',
            'choices' => newsx_get_available_elements_array( 'footer' ),
        ],
        'ft_middle_element_position' => [
            'type' => 'radio-buttonset',
            'label' => esc_html__( 'Position', 'news-magazine-x' ),
            'default' => '1',
            'choices' => [
                '1' => esc_html__( '1', 'news-magazine-x' ),
                '2' => esc_html__( '2', 'news-magazine-x' ),
                '3' => esc_html__( '3', 'news-magazine-x' ),
                '4' => esc_html__( '4', 'news-magazine-x' ),
                '5' => esc_html__( '5', 'news-magazine-x' ),
                '6' => esc_html__( '6', 'news-magazine-x' ),
            ],
        ],
        'edit_element_options' => [
            'type' => 'custom',
            'default' => 'options',
        ],
    ],
    'row_label' => [
        'type' => 'field',
        'value' => esc_html__( 'Element ', 'news-magazine-x' ),
        'field' => 'ft_middle_select_element',
    ],
    'button_label' => esc_html__( 'Add Element', 'news-magazine-x' ),
    'priority' => 20,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'radio-buttonset',
    'settings' => 'newsx_options[section_ft_middle_columns]',
    'label' => esc_html__( 'Columns', 'news-magazine-x' ),
    'section' => 'newsx_section_ft_middle',
	'tab' => 'general',
    'default' => '4',
    'choices' => [
        '1' => esc_html__( '1', 'news-magazine-x' ),
        '2' => esc_html__( '2', 'news-magazine-x' ),
        '3' => esc_html__( '3', 'news-magazine-x' ),
        '4' => esc_html__( '4', 'news-magazine-x' ),
        '5' => esc_html__( '5', 'news-magazine-x' ),
        '6' => esc_html__( '6', 'news-magazine-x' ),
    ],
	'divider' => 'newsx-group-divider-top',
    'priority' => 30,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'radio-buttonset',
    'settings' => 'newsx_options[section_ft_middle_vertical_align]',
    'label' => esc_html__( 'Vertical Alignment', 'news-magazine-x' ),
    'section' => 'newsx_section_ft_middle',
	'tab' => 'general',
    'default' => 'flex-start',
    'choices' => newsx_get_align_control_options('flex'),
	'divider' => 'newsx-group-divider-top',
    'priority' => 31,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'inputslider',
    'settings' => 'newsx_options[section_ft_middle_columns_gap]',
    'label' => esc_html__( 'Columns Gap', 'news-magazine-x' ),
    'section' => 'newsx_section_ft_middle',
	'tab' => 'general',
    'responsive' => true,
	'default' => [
		'desktop' => 50,
		'tablet'  => '',
		'mobile'  => '',
	],
    'choices' => [
        'min'  => 0,
        'max'  => 50,
        'step' => 1,
    ],
    'custom_class' => 'newsx-slider-unit-px',
	'divider' => 'newsx-group-divider-top',
    'priority' => 32,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'inputslider',
    'settings' => 'newsx_options[section_ft_middle_height]',
    'label' => esc_html__( 'Section Min Height', 'news-magazine-x' ),
    'section' => 'newsx_section_ft_middle',
	'tab' => 'general',
    'responsive' => true,
	'default' => [
		'desktop' => 80,
		'tablet'  => '',
		'mobile'  => '',
	],
    'choices'  => [
        'min'  => 30,
        'max'  => 500,
        'step' => 10,
    ],
    'custom_class' => 'newsx-slider-unit-px',
	'divider' => 'newsx-group-divider-top',
    'priority' => 50,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'multicheck',
    'settings' => 'newsx_options[section_ft_middle_visibility]',
    'label' => esc_html__( 'Visibility', 'news-magazine-x' ),
    'section' => 'newsx_section_ft_middle',
	'tab' => 'general',
	'custom_class' => 'newsx-visibility',
	'divider' => 'newsx-group-divider-top',
	'default'  => [ 'desktop', 'tablet', 'mobile' ],
	'choices'  => [
		'desktop' => esc_html__( 'Desktop', 'news-magazine-x' ),
		'tablet' => esc_html__( 'Tablet', 'news-magazine-x' ),
		'mobile' => esc_html__( 'Mobile', 'news-magazine-x' ),
	],
    'priority' => 100,
] );

// Add Pro Fields
newsx_add_pro_field( 'section_ft_middle_bg', 110 );
newsx_add_pro_field( 'section_ft_middle_bd_color', 120 );
newsx_add_pro_field( 'section_ft_middle_bd_width', 130 );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'newsx-headline',
    'settings' => 'newsx_options[section_ft_middle_spacing_headline]',
    'section' => 'newsx_section_ft_middle',
	'tab' => 'design',
    'label' => esc_html__( 'Spacing', 'news-magazine-x' ),
    'priority' => 299,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'kirki-margin',
    'settings' => 'newsx_options[section_ft_middle_padding]',
    'section' => 'newsx_section_ft_middle',
	'tab' => 'design',
    'label' => esc_html__( 'Padding', 'news-magazine-x' ),
    'responsive' => true,
    'default'    => [
        'desktop' => [
            'top'      => '20',
            'right'    => '20',
            'bottom'   => '20',
            'left'     => '20',
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
        'settings' => 'newsx_options[section_ft_middle_upgrade_pro_list]',
        'section' => 'newsx_section_ft_middle',
        'tab' => 'general',
        'label' => esc_html__( 'Need more Footer Options?', 'news-magazine-x' ),
        'choices' => [
            'footer-widgets-5' => esc_html__( 'Footer Widgets Area 5', 'news-magazine-x' ),
            'footer-widgets-6' => esc_html__( 'Footer Widgets Area 6', 'news-magazine-x' ),
            'background' => esc_html__( 'Section Background Color', 'news-magazine-x' ),
            'much-more' => esc_html__( 'And much more....', 'news-magazine-x' ),
        ],
        'description' => 'https://wp-royal-themes.com/themes/item-news-magazine-x-pro/?ref=newsx-free-customizer-footer-sec-middle-upgrade-pro#features',
        'divider' => 'newsx-group-divider-top',
        'priority' => 999,
    ] );

}