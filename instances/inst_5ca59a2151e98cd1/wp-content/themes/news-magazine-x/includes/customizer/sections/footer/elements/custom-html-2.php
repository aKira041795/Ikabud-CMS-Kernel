<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Pro Version
if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) :
    newsx_add_pro_controls_group( 'footer-custom-html-2' );

else : // Free Version

Kirki::add_field( 'newsx_theme_config', [
    'type'     => 'editor',
    'settings' => 'newsx_options[footer_html2_editor]',
    'section'  => 'newsx_section_ft_custom_html_2',
	'tab' => 'general',
    'default' => '',
    'choices' => [
        'tinymce' => [
            'wpautop' => true,
            'height' => 200,
            'toolbar1' => 'formatselect | styleselect | bold italic strikethrough | forecolor backcolor | link | alignleft aligncenter alignright alignjustify | numlist bullist outdent indent | insert | fontsizeselect',
        ]
    ],
    'priority' => 5,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'multicheck',
    'settings' => 'newsx_options[footer_html2_visibility]',
    'label' => esc_html__( 'Visibility', 'news-magazine-x' ),
    'section' => 'newsx_section_ft_custom_html_2',
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
    'settings' => 'newsx_options[footer_html2_spacing_headline]',
    'section' => 'newsx_section_ft_custom_html_2',
	'tab' => 'design',
    'label' => esc_html__( 'Spacing', 'news-magazine-x' ),
    'priority' => 299,
]);

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'kirki-margin',
    'settings' => 'newsx_options[footer_html2_margin]',
    'section' => 'newsx_section_ft_custom_html_2',
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
    'settings' => 'newsx_options[footer_html2_upgrade_pro_list]',
    'section' => 'newsx_section_ft_custom_html_2',
    'tab' => 'general',
    'label' => esc_html__( 'Need more Custom HTML Options?', 'news-magazine-x' ),
    'choices' => [
        'color' => esc_html__( 'Custom Text Color', 'news-magazine-x' ),
        'link-color' => esc_html__( 'Custom Link Color', 'news-magazine-x' ),
        'font-size' => esc_html__( 'Custom Font Size', 'news-magazine-x' ),
    ],
    'description' => 'https://wp-royal-themes.com/themes/item-news-magazine-x-pro/?ref=newsx-free-customizer-footer-sec-custom-html-2-upgrade-pro#features',
    'divider' => 'newsx-group-divider-top',
    'priority' => 999,
] );

endif; // Free Version
