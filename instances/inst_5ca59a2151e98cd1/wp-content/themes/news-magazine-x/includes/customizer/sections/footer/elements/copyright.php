<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Pro Version
if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) :
    newsx_add_pro_controls_group( 'footer-copyright' );

else : // Free Version

Kirki::add_field( 'newsx_theme_config', [
    'type'     => 'textarea',
    'label'    => esc_html__( 'Copyright Text', 'news-magazine-x' ),
    'settings' => 'newsx_options[copyright_editor]',
    'section'  => 'newsx_section_ft_copyright',
    'tab' => 'general',
    'default'  => 'Copyright [copyright] [current_year] [site_title]',
    'description' => 'Tip: Copyright [copyright] [current_year] [site_title]',
    'priority' => 1,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'newsx-headline',
    'settings' => 'newsx_options[copyright_spacing_headline]',
    'section' => 'newsx_section_ft_copyright',
	'tab' => 'design',
    'label' => esc_html__( 'Spacing', 'news-magazine-x' ),
    'priority' => 299,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'kirki-margin',
    'settings' => 'newsx_options[copyright_margin]',
    'section' => 'newsx_section_ft_copyright',
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
    'settings' => 'newsx_options[copyright_upgrade_pro_list]',
    'section' => 'newsx_section_ft_copyright',
    'tab' => 'general',
    'label' => esc_html__( 'Need more Copyright Options?', 'news-magazine-x' ),
    'choices' => [
        'poweredby' => esc_html__( 'Remove Powered by link', 'news-magazine-x' ),
        'move' => esc_html__( 'Move anywhere across the Footer', 'news-magazine-x' ),
        'visibility' => esc_html__( 'Responsive Visibility Option', 'news-magazine-x' ),
        'align' => esc_html__( 'Responsive Alignment Option', 'news-magazine-x' ),
        'color' => esc_html__( 'Custom Text Color', 'news-magazine-x' ),
        'link-color' => esc_html__( 'Custom Link Color', 'news-magazine-x' ),
        'font-size' => esc_html__( 'Custom Font Size', 'news-magazine-x' ),
    ],
    'description' => 'https://wp-royal-themes.com/themes/item-news-magazine-x-pro/?ref=newsx-free-customizer-footer-sec-copyright-upgrade-pro#features',
    'divider' => 'newsx-group-divider-top',
    'priority' => 999,
] );

endif; // Free Version
