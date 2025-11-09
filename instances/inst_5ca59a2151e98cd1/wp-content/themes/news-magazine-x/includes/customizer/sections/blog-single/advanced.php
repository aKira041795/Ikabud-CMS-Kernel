<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Pro Version
if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) :
    newsx_add_pro_controls_group( 'blog-single-advanced' );

// Free Version
else:

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'newsx-headline',
    'settings' => 'newsx_options[bs_advanced_rpbar_headline]',
    'section' => 'newsx_section_bs_advanced',
	'tab' => 'design',
    'label' => esc_html__( 'Reading Progress Bar', 'news-magazine-x' ),
    'priority' => 1,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'switch',
    'settings' => 'newsx_options[bs_advanced_rpbar_enable]',
    'section' => 'newsx_section_bs_advanced',
	'tab' => 'general',
    'label' => esc_html__( 'Enable Progress Bar', 'news-magazine-x' ),
    'default' => true,
    'priority' => 3,
] );

// Upgrade to Pro List
Kirki::add_field( 'newsx_theme_config', [
    'type' => 'newsx-upgrade-pro-list',
    'settings' => 'newsx_options[bs_advanced_upgrade_pro_list]',
    'section' => 'newsx_section_bs_advanced',
    'tab' => 'general',
    'label' => esc_html__( 'Need more Advanced Options?', 'news-magazine-x' ),
    'choices' => [
        'progress-bar-color' => esc_html__( 'Progress Bar Color', 'news-magazine-x' ),
        'progress-bar-height' => esc_html__( 'Progress Bar Height', 'news-magazine-x' ),
        'auto-load-posts' => esc_html__( 'Auto Load Next Posts', 'news-magazine-x' ),
        'more-options' => esc_html__( 'More is coming soon...', 'news-magazine-x' ),
    ],
    'description' => 'https://wp-royal-themes.com/themes/item-news-magazine-x-pro/?ref=newsx-free-customizer-blog-single-sec-advanced-upgrade-pro#features',
    'divider' => 'newsx-group-divider-top',
    'priority' => 999,
] );

endif; // Free Version
