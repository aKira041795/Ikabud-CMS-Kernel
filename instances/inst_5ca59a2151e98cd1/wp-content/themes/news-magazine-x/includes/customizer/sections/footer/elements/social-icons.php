<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Pro Version
if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) :
    newsx_add_pro_controls_group( 'footer-social-icons' );

else : // Free Version

Kirki::add_field( 'newsx_theme_config', [
    'type'     => 'repeater',
    'settings' => 'newsx_options[footer_social_icons]',
    'section'  => 'newsx_section_ft_social_icons',
	'tab' => 'general',
    'label'    => esc_html__( 'Social Icons', 'news-magazine-x' ),
    'default' => [
        [
            'footer_social_icons_select' => 'facebook-f',
            'footer_social_icons_url' => '#',
            'footer_social_icons_label' => '',
        ],
        [
            'footer_social_icons_select' => 'x-twitter',
            'footer_social_icons_url' => '#',
            'footer_social_icons_label' => '',
        ],
        [
            'footer_social_icons_select' => 'instagram-square',
            'footer_social_icons_url' => '#',
            'footer_social_icons_label' => '',
        ],
        [
            'footer_social_icons_select' => 'pinterest-p',
            'footer_social_icons_url' => '#',
            'footer_social_icons_label' => '',
        ],
    ],
    'fields'   => [
        'footer_social_icons_select' => [
            'type' => 'newsx-icon-select',
            'label' => esc_html__('Select Icon', 'news-magazine-x'),
            'default' => '',
        ],
        'footer_social_icons_url' => [
            'type' => 'url',
            'label' => esc_html__('URL', 'news-magazine-x'),
            'default' => '#',
        ],
    ],
    'row_label' => [
        'type' => 'field',
        'value' => esc_html__( 'Social Icon ', 'news-magazine-x' ),
    ],
    'button_label' => esc_html__( 'Add Element', 'news-magazine-x' ),
    'priority' => 1,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'radio-buttonset',
    'settings' => 'newsx_options[footer_si_align]',
    'label' => esc_html__( 'Alignment', 'news-magazine-x' ),
    'section' => 'newsx_section_ft_social_icons',
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
    'settings' => 'newsx_options[footer_si_visibility]',
    'label' => esc_html__( 'Visibility', 'news-magazine-x' ),
    'section' => 'newsx_section_ft_social_icons',
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
    'settings' => 'newsx_options[footer_si_spacing_headline]',
    'section' => 'newsx_section_ft_social_icons',
	'tab' => 'design',
    'label' => esc_html__( 'Spacing', 'news-magazine-x' ),
    'priority' => 299,
]);

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'kirki-margin',
    'settings' => 'newsx_options[footer_si_margin]',
    'section' => 'newsx_section_ft_social_icons',
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
    'settings' => 'newsx_options[footer_si_upgrade_pro_list]',
    'section' => 'newsx_section_ft_social_icons',
    'tab' => 'general',
    'label' => esc_html__( 'Need more Social Icons Options?', 'news-magazine-x' ),
    'choices' => [
        'color' => esc_html__( 'Custom Icon Colors', 'news-magazine-x' ),
        'icon-size' => esc_html__( 'Custom Icon Size', 'news-magazine-x' ),
        'label-size' => esc_html__( 'Custom Label Size', 'news-magazine-x' ),
    ],
    'description' => 'https://wp-royal-themes.com/themes/item-news-magazine-x-pro/?ref=newsx-free-customizer-footer-sec-social-upgrade-pro#features',
    'divider' => 'newsx-group-divider-top',
    'priority' => 999,
] );

endif; // Free Version
