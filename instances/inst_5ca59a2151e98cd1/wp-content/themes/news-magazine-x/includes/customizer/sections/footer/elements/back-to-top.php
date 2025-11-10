<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Pro Version
if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) :
    newsx_add_pro_controls_group( 'footer-back-to-top' );

else : // Free Version

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'switch',
    'settings' => 'newsx_options[ft_backtop_enable]',
    'label' => esc_html__( 'Enable Back to Top Button', 'news-magazine-x' ),
    'section' => 'newsx_section_ft_backtop',
	'tab' => 'general',
    'default' => true,
	'divider' => 'newsx-group-divider-bottom',
    'priority' => 1,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'switch',
    'settings' => 'newsx_options[ft_backtop_transparent]',
    'label' => esc_html__( 'Transparent Background', 'news-magazine-x' ),
    'section' => 'newsx_section_ft_backtop',
	'tab' => 'general',
    'default' => false,
    'priority' => 10,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'multicheck',
    'settings' => 'newsx_options[ft_backtop_visibility]',
    'label' => esc_html__( 'Visibility', 'news-magazine-x' ),
    'section' => 'newsx_section_ft_backtop',
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

// Upgrade to Pro List
Kirki::add_field( 'newsx_theme_config', [
    'type' => 'newsx-upgrade-pro-list',
    'settings' => 'newsx_options[ft_backtop_upgrade_pro_list]',
    'section' => 'newsx_section_ft_backtop',
    'tab' => 'general',
    'label' => esc_html__( 'Need more Back to Top Options?', 'news-magazine-x' ),
    'choices' => [
        'icon' => esc_html__( 'Change Icon', 'news-magazine-x' ),
        'color' => esc_html__( 'Custom Icon Color', 'news-magazine-x' ),
        'bg-color' => esc_html__( 'Custom Background Color', 'news-magazine-x' ),
    ],
    'description' => 'https://wp-royal-themes.com/themes/item-news-magazine-x-pro/?ref=newsx-free-customizer-footer-sec-back-to-top-upgrade-pro#features',
    'divider' => 'newsx-group-divider-top',
    'priority' => 999,
] );

endif; // Free Version
