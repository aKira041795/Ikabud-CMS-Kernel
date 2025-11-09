<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Add Pro Fields
newsx_add_pro_field( 'retina_logo_sw', 10 );
newsx_add_pro_field( 'retina_logo', 20 );
newsx_add_pro_field( 'dark_logo', 25 );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'inputslider',
    'settings' => 'newsx_options[logo_width]',
    'label' => esc_html__( 'Logo Width', 'news-magazine-x' ),
    'section' => 'title_tagline',
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
    'type' => 'radio-buttonset',
    'settings' => 'newsx_options[inline_logo_title]',
    'label' => esc_html__( 'Logo & Site Title Display', 'news-magazine-x' ),
    'section' => 'title_tagline',
	'tab' => 'general',
    'default' => 'row',
    'choices' => [
        'column' => esc_html__( 'Block', 'news-magazine-x' ),
        'row' => esc_html__( 'Inline', 'news-magazine-x' ),
    ],
	'divider' => 'newsx-group-divider-top',
    'priority' => 40,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'text',
    'settings' => 'blogname',
    'label' => esc_html__( 'Site Title', 'news-magazine-x' ),
    'default' => get_bloginfo( 'name' ),
    'section' => 'title_tagline',
	'tab' => 'general',
	'divider' => ' newsx-group-divider-top',
    'priority' => 50,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'select',
    'settings' => 'newsx_options[site_title_tag]',
    'section' => 'title_tagline',
    'tab' => 'general',
    'label' => esc_html__( 'Site Title Tag', 'news-magazine-x' ),
    'description' => esc_html__( 'Only applied to Front Page and Blog Page.', 'news-magazine-x' ),
    'default' => 'default',
    'choices' => [
        'default' => esc_html__( 'Default', 'news-magazine-x' ),
        'h1' => esc_html__( 'H1 (Adaptive)', 'news-magazine-x' ),
    ],
	'divider' => 'newsx-divider-bottom',
    'priority' => 51,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'multicheck',
    'settings' => 'newsx_options[site_title_visibility]',
    'label' => esc_html__( 'Site Title Visibility', 'news-magazine-x' ),
    'section' => 'title_tagline',
	'tab' => 'general',
	'custom_class' => 'newsx-visibility',
	'default'  => [ 'desktop', 'tablet', 'mobile' ],
	'choices'  => [
		'desktop' => esc_html__( 'Desktop', 'news-magazine-x' ),
		'tablet' => esc_html__( 'Tablet', 'news-magazine-x' ),
		'mobile' => esc_html__( 'Mobile', 'news-magazine-x' ),
	],
    'priority' => 60,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'text',
    'settings' => 'blogdescription',
    'label' => esc_html__( 'Tagline', 'news-magazine-x' ),
    'default' => get_bloginfo( 'description' ),
    'section' => 'title_tagline',
	'tab' => 'general',
	'divider' => 'newsx-divider-bottom newsx-group-divider-top',
    'priority'=> 70,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'multicheck',
    'settings' => 'newsx_options[site_tagline_visibility]',
    'label' => esc_html__( 'Tagline Visibility', 'news-magazine-x' ),
    'section' => 'title_tagline',
	'tab' => 'general',
	'custom_class' => 'newsx-visibility',
	'default'  => [ 'desktop', 'tablet', 'mobile' ],
	'choices'  => [
		'desktop' => esc_html__( 'Desktop', 'news-magazine-x' ),
		'tablet' => esc_html__( 'Tablet', 'news-magazine-x' ),
		'mobile' => esc_html__( 'Mobile', 'news-magazine-x' ),
	],
    'priority' => 80,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'multicheck',
    'settings' => 'newsx_options[logo_title_visibility]',
    'label' => esc_html__( 'General Visibility', 'news-magazine-x' ),
    'section' => 'title_tagline',
	'tab' => 'general',
	'custom_class' => 'newsx-visibility',
	'default'  => [ 'desktop', 'tablet', 'mobile' ],
	'choices'  => [
		'desktop' => esc_html__( 'Desktop', 'news-magazine-x' ),
		'tablet' => esc_html__( 'Tablet', 'news-magazine-x' ),
		'mobile' => esc_html__( 'Mobile', 'news-magazine-x' ),
	],
	'divider' => 'newsx-group-divider-top',
    'priority' => 99,
] );

// Add Pro Fields
newsx_add_pro_field( 'logo_title_duplicate_visibility', 100 );
newsx_add_pro_field( 'logo_title_color', 110 );
newsx_add_pro_field( 'logo_tagline_color', 120 );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'typography',
    'settings' => 'newsx_options[logo_title_font]',
    'label' => esc_html__( 'Title & Tagline Font', 'news-magazine-x' ),
    'section' => 'title_tagline',
    'tab' => 'design',
    // 'transport'   => 'auto',
    'default' => [
        'font-family'     => 'Encode Sans Condensed',
        'variant'         => '700',
        'font-weight'     => '700',
        'font-style'      => 'normal',
        'line-height'     => '1.3',
        'letter-spacing'  => '0',
        'text-transform'  => 'none',
        'text-decoration' => 'none',
        'text-align'      => 'left',
    ],
    'priority' => 130,
	]
);

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'inputslider',
    'settings' => 'newsx_options[logo_title_font_size]',
    'label' => esc_html__( 'Title Font Size', 'news-magazine-x' ),
    'section' => 'title_tagline',
	'tab' => 'design',
	'responsive' => true,
	'default'    => [
		'desktop' => 40,
		'tablet'  => 40,
		'mobile'  => 23,
	],
    'choices' => [
        'min'  => 10,
        'max'  => 200,
        'step' => 1,
    ],
    'custom_class' => 'newsx-slider-unit-px',
	'divider' => 'newsx-divider-top',
    'priority' => 140,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'inputslider',
    'settings' => 'newsx_options[logo_tagline_font_size]',
    'label' => esc_html__( 'Tagline Font Size', 'news-magazine-x' ),
    'section' => 'title_tagline',
	'tab' => 'design',
	'responsive' => true,
	'default'    => [
		'desktop' => 12,
		'tablet'  => 12,
		'mobile'  => 12,
	],
    'choices' => [
        'min'  => 10,
        'max'  => 50,
        'step' => 1,
    ],
    'custom_class' => 'newsx-slider-unit-px',
    'priority' => 150,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'newsx-headline',
    'settings' => 'newsx_options[logo_spacing_headline]',
    'section' => 'title_tagline',
	'tab' => 'design',
    'label' => esc_html__( 'Spacing', 'news-magazine-x' ),
    'priority' => 159,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'kirki-margin',
    'settings' => 'newsx_options[logo_margin]',
    'section' => 'title_tagline',
	'tab' => 'design',
    'label' => esc_html__( 'Margin', 'news-magazine-x' ),
    'responsive' => true,
    'default'    => [
        'desktop' => [
            'top'      => '',
            'right'    => '10',
            'bottom'   => '',
            'left'     => '',
            'isLinked' => false,
        ],
        'tablet'  => [
            'top'      => '',
            'right'    => '0',
            'bottom'   => '',
            'left'     => '',
            'isLinked' => false,
        ],
        'mobile'  => [
            'top'      => '',
            'right'    => '0',
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
    'priority' => 160,
] );


// Upgrade to Pro List
if ( !defined('NEWSX_CORE_PRO_VERSION') || !newsx_core_pro_fs()->can_use_premium_code() ) {

    Kirki::add_field( 'newsx_theme_config', [
        'type' => 'newsx-upgrade-pro-list',
        'settings' => 'newsx_options[section_title_tagline_upgrade_pro_list]',
        'section' => 'title_tagline',
        'tab' => 'general',
        'label' => esc_html__( 'Need more Logo Options?', 'news-magazine-x' ),
        'choices' => [
            'retina-sw' => esc_html__( 'Retina Logo Switcher', 'news-magazine-x' ),
            'retina' => esc_html__( 'Retina Logo Upload', 'news-magazine-x' ),
            'logo-title-color' => esc_html__( 'Site Title Color', 'news-magazine-x' ),
            'logo-tagline-color' => esc_html__( 'Tagline Color', 'news-magazine-x' ),
        ],
        'description' => 'https://wp-royal-themes.com/themes/item-news-magazine-x-pro/?ref=newsx-free-customizer-header-sec-logo-upgrade-pro#features',
        'divider' => 'newsx-group-divider-top',
        'priority' => 999,
    ] );

}
