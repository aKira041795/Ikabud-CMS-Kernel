<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Pro Version
if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) :
    newsx_add_pro_controls_group( 'header-search' );

else : // Free Version

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'text',
    'settings' => 'newsx_options[header_search_placeholder]',
    'section' => 'newsx_section_hd_search',
	'tab' => 'general',
    'label' => esc_html__( 'Placeholder', 'news-magazine-x' ),
    'default' => 'Type and hit enter...',
    'priority' => 20,
] );


Kirki::add_field( 'newsx_theme_config', [
    'type' => 'select',
    'settings' => 'newsx_options[header_search_style]',
    'section' => 'newsx_section_hd_search',
	'tab' => 'general',
    'label' => esc_html__( 'Select Style', 'news-magazine-x' ),
    'default' => 's1',
    'choices' => [
        's0' => esc_html__( 'Default', 'news-magazine-x' ),
        's1' => esc_html__( 'Style 1', 'news-magazine-x' ),
    ],
	'divider' => 'newsx-group-divider-top',
    'priority' => 40,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'multicheck',
    'settings' => 'newsx_options[header_search_visibility]',
    'label' => esc_html__( 'Visibility', 'news-magazine-x' ),
    'section' => 'newsx_section_hd_search',
	'tab' => 'general',
	'custom_class' => 'newsx-visibility newsx-group-divider-top',
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
    'settings' => 'newsx_options[header_search_spacing_headline]',
    'section' => 'newsx_section_hd_search',
	'tab' => 'design',
    'label' => esc_html__( 'Spacing', 'news-magazine-x' ),
    'priority' => 299,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'kirki-margin',
    'settings' => 'newsx_options[header_search_margin]',
    'section' => 'newsx_section_hd_search',
	'tab' => 'design',
    'label' => esc_html__( 'Margin', 'news-magazine-x' ),
    'responsive' => true,
    'default'    => [
        'desktop' => [
            'top'      => '',
            'right'    => '',
            'bottom'   => '',
            'left'     => '10',
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

// Upgrade to Pro List
Kirki::add_field( 'newsx_theme_config', [
    'type' => 'newsx-upgrade-pro-list',
    'settings' => 'newsx_options[header_search_upgrade_pro_list]',
    'section' => 'newsx_section_hd_search',
    'tab' => 'general',
    'label' => esc_html__( 'Need more Search Options?', 'news-magazine-x' ),
    'choices' => [
        'query' => esc_html__( 'Query: only Posts or Pages', 'news-magazine-x' ),
        'ajax-search' => esc_html__( 'Live(AJAX) Search', 'news-magazine-x' ),
        'popup' => esc_html__( 'Popup Search on Click', 'news-magazine-x' ),
        'color' => esc_html__( 'Custom Colors', 'news-magazine-x' ),
        'icon-size' => esc_html__( 'Custom Icon Size', 'news-magazine-x' ),
        'much-more' => esc_html__( 'And much more....', 'news-magazine-x' ),
    ],
    'description' => 'https://wp-royal-themes.com/themes/item-news-magazine-x-pro/?ref=newsx-free-customizer-header-sec-search-upgrade-pro#features',
    'divider' => 'newsx-group-divider-top',
    'priority' => 999,
] );

endif; // Free Version