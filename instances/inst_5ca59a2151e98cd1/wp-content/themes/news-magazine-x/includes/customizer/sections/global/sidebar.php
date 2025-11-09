<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Pro Version
if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) :
    newsx_add_pro_controls_group( 'global-sidebar' );

else : // Free Version

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'radio-image',
    'settings' => 'newsx_options[global_blog_sidebar_display]',
    'section' => 'newsx_section_global_sidebar',
    'label' => esc_html__( 'Blog Page Sidebar', 'news-magazine-x' ),
    'default' => 'right',
    'choices' => [
        'none' => 'no-sidebar',
        'right' => 'right-sidebar',
    ],
	'divider' => 'newsx-group-divider-bottom',
    'priority' => 25,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'radio-image',
    'settings' => 'newsx_options[global_blog_single_sidebar_display]',
    'section' => 'newsx_section_global_sidebar',
    'label' => esc_html__( 'Blog Single Sidebar', 'news-magazine-x' ),
    'default' => 'right',
    'choices' => [
        'none' => 'no-sidebar',
        'right' => 'right-sidebar',
    ],
	'divider' => 'newsx-group-divider-bottom',
    'priority' => 30,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'radio-image',
    'settings' => 'newsx_options[global_single_page_sidebar_display]',
    'section' => 'newsx_section_global_sidebar',
    'label' => esc_html__( 'Single Page Sidebar', 'news-magazine-x' ),
    'default' => 'right',
    'choices' => [
        'none' => 'no-sidebar',
        'right' => 'right-sidebar',
    ],
	'divider' => 'newsx-group-divider-bottom',
    'priority' => 35,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'radio-image',
    'settings' => 'newsx_options[global_term_archive_sidebar_display]',
    'section' => 'newsx_section_global_sidebar',
    'label' => esc_html__( 'Category/Tag Sidebar', 'news-magazine-x' ),
    'default' => 'right',
    'choices' => [
        'none' => 'no-sidebar',
        'right' => 'right-sidebar',
    ],
    'priority' => 40,
] );

// Upgrade to Pro List
Kirki::add_field( 'newsx_theme_config', [
    'type' => 'newsx-upgrade-pro-list',
    'settings' => 'newsx_options[global_sidebar_upgrade_pro_list]',
    'section' => 'newsx_section_global_sidebar',
    'tab' => 'general',
    'label' => esc_html__( 'Need more Sidebar Options?', 'news-magazine-x' ),
    'choices' => [
        'width' => esc_html__( 'Custom Sidebar Width', 'news-magazine-x' ),
        'sticky' => esc_html__( 'Enable Sticky Sidebar', 'news-magazine-x' ),
        'display' => esc_html__( 'Display Left/Right or Both Sidebars', 'news-magazine-x' ),
        'more' => esc_html__( 'And much more...', 'news-magazine-x' ),
    ],
    'description' => 'https://wp-royal-themes.com/themes/item-news-magazine-x-pro/?ref=newsx-free-customizer-global-sec-sidebar-upgrade-pro#features',
    'divider' => 'newsx-group-divider-top',
    'priority' => 999,
] );

endif; // Free Version