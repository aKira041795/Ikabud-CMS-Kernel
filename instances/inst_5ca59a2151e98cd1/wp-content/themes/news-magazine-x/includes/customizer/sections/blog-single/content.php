<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Pro Version
if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) :
    newsx_add_pro_controls_group( 'blog-single-content' );

// Free Version
else:

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'switch',
    'settings' => 'newsx_options[bs_content_underline_links]',
    'section' => 'newsx_section_bs_content',
	'tab' => 'design',
    'label' => esc_html__( 'Enable Underline Links', 'news-magazine-x' ),
    'default' => true,
    'priority' => 30,
] );

// Upgrade to Pro List
Kirki::add_field( 'newsx_theme_config', [
    'type' => 'newsx-upgrade-pro-list',
    'settings' => 'newsx_options[bs_content_upgrade_pro_list]',
    'section' => 'newsx_section_bs_content',
    'tab' => 'design',
    'label' => esc_html__( 'Need more Single Content Options?', 'news-magazine-x' ),
    'choices' => [
        'table-of-contents' => esc_html__( 'Table of Contents (Auto-Generate)', 'news-magazine-x' ),
        'newsletter-form' => esc_html__( 'Newsletter (Subscription) Form', 'news-magazine-x' ),
        'color' => esc_html__( 'Custom Text Color', 'news-magazine-x' ),
        'font-size' => esc_html__( 'Custom Font Size', 'news-magazine-x' ),
    ],
    'description' => 'https://wp-royal-themes.com/themes/item-news-magazine-x-pro/?ref=newsx-free-customizer-blog-single-sec-content-upgrade-pro#features',
    'divider' => 'newsx-group-divider-top',
    'priority' => 999,
] );

endif; // Free Version