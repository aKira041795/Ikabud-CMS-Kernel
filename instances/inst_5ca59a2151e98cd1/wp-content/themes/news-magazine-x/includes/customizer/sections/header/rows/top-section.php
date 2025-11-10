<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'switch',
    'settings' => 'newsx_options[section_hd_top_enable]',
    'label' => esc_html__( 'Enable this Section', 'news-magazine-x' ),
    'section' => 'newsx_section_hd_top',
	'tab' => 'general',
    'default' => true,
	'divider' => 'newsx-divider-bottom',
    'priority' => 10,
] );

/*
** Top Section Elements
*/
Kirki::add_field( 'newsx_theme_config', [
    'type'     => 'repeater',
    'settings' => 'newsx_options[section_hd_top_elements]',
    'label'    => esc_html__( 'Top Section Elements', 'news-magazine-x' ),
    'section'  => 'newsx_section_hd_top',
    'tab'      => 'general',
    'default'  => [
        [
            'hd_top_select_element' => 'news-ticker',
            'hd_top_element_position' => 'left',
            'hd_top_element_duplicate' => false,
        ],
    ],
    'fields'   => [
        'hd_top_select_element' => [
            'type' => 'select',
            'label' => esc_html__('Select Element', 'news-magazine-x'),
            'default' => 'date-and-time',
            'choices' => newsx_get_available_elements_array( 'header' ),
        ],
        'hd_top_element_position' => [
            'type' => 'radio-buttonset',
            'label' => esc_html__( 'Position', 'news-magazine-x' ),
            'default'  => 'left',
            'choices' => [
                'left' => esc_html__( 'Left', 'news-magazine-x' ),
                'center' => esc_html__( 'Center', 'news-magazine-x' ),
                'right' => esc_html__( 'Right', 'news-magazine-x' ),
            ],
        ],
        'hd_top_element_duplicate' => newsx_get_pro_field_args( 'header_element_duplicate' ),
        'edit_element_options' => [
            'type' => 'custom',
            'default' => 'options',
        ],
    ],
    'row_label' => [
        'type' => 'field',
        'value' => esc_html__( 'Element ', 'news-magazine-x' ),
        'field' => 'hd_top_select_element',
    ],
    'button_label' => esc_html__( 'Add Element', 'news-magazine-x' ),
    'priority' => 20,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'inputslider',
    'settings' => 'newsx_options[section_hd_top_height]',
    'label' => esc_html__( 'Section Min Height', 'news-magazine-x' ),
    'section' => 'newsx_section_hd_top',
	'tab' => 'general',
    'responsive' => true,
	'default' => [
		'desktop' => 30,
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
    'priority' => 30,
] );

// Add Pro Field
newsx_add_pro_field( 'section_hd_top_sticky', 35 );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'multicheck',
    'settings' => 'newsx_options[section_hd_top_visibility]',
    'label' => esc_html__( 'Visibility', 'news-magazine-x' ),
    'section' => 'newsx_section_hd_top',
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

// Add Pro Fields
newsx_add_pro_field( 'section_hd_top_bg', 110 );
newsx_add_pro_field( 'section_hd_top_bd_color', 120 );
newsx_add_pro_field( 'section_hd_top_bd_width', 130 );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'newsx-headline',
    'settings' => 'newsx_options[hd_top_spacing_headline]',
    'section' => 'newsx_section_hd_top',
	'tab' => 'design',
    'label' => esc_html__( 'Spacing', 'news-magazine-x' ),
    'priority' => 299,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'kirki-margin',
    'settings' => 'newsx_options[hd_top_padding]',
    'section' => 'newsx_section_hd_top',
	'tab' => 'design',
    'label' => esc_html__( 'Padding', 'news-magazine-x' ),
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
        'settings' => 'newsx_options[section_hd_top_upgrade_pro_list]',
        'section' => 'newsx_section_hd_top',
        'tab' => 'general',
        'label' => esc_html__( 'Need more Header Options?', 'news-magazine-x' ),
        'choices' => [
            'sticky' => esc_html__( 'Make this Section Sticky', 'news-magazine-x' ),
            'elements' => esc_html__( 'Add more Header Elements', 'news-magazine-x' ),
            'weather' => esc_html__( 'Weather Forecast', 'news-magazine-x' ),
            'cta' => esc_html__( 'Call to Action Button', 'news-magazine-x' ),
            'custom-html-1' => esc_html__( 'Custom HTML 1', 'news-magazine-x' ),
            'custom-html-2' => esc_html__( 'Custom HTML 2', 'news-magazine-x' ),
            'header-widgets-1' => esc_html__( 'Header Widgets Area 1', 'news-magazine-x' ),
            'header-widgets-2' => esc_html__( 'Header Widgets Area 2', 'news-magazine-x' ),
            'background' => esc_html__( 'Section Background Color', 'news-magazine-x' ),
            'much-more' => esc_html__( 'And much more....', 'news-magazine-x' ),
        ],
        'description' => 'https://wp-royal-themes.com/themes/item-news-magazine-x-pro/?ref=newsx-free-customizer-header-sec-top-upgrade-pro#features',
        'divider' => 'newsx-group-divider-top',
        'priority' => 999,
    ] );

}
