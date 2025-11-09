<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Pro Version
if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) :
    newsx_add_pro_controls_group( 'blog-single-sharing' );

// Free Version
else:

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'switch',
    'settings' => 'newsx_options[bs_sharing_show_content]',
    'label' => esc_html__( 'Show After Content', 'news-magazine-x' ),
    'default' => true,
    'section' => 'newsx_section_bs_sharing',
	'tab' => 'general',
    'divider' => 'newsx-group-divider-bottom',
    'priority' => 2,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'text',
    'settings' => 'newsx_options[bs_sharing_text]',
    'section' => 'newsx_section_bs_sharing',
	'tab' => 'general',
    'label' => esc_html__( 'Sharing Text', 'news-magazine-x' ),
    'default' => 'Share Article',
    'priority' => 3,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'select',
    'settings' => 'newsx_options[bs_sharing_style]',
    'label' => esc_html__( 'Icon Style', 'news-magazine-x' ),
    'section' => 'newsx_section_bs_sharing',
	'tab' => 'general',
    'default' => 's0',
    'choices' => [
        's0' => esc_html__( 'Default', 'news-magazine-x' ),
        's2' => esc_html__( 'Style 2', 'news-magazine-x' ),
    ],
    'divider' => 'newsx-group-divider-bottom',
    'priority' => 4,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'switch',
    'settings' => 'newsx_options[bs_sharing_facebook]',
    'label' => esc_html__( 'Facebook', 'news-magazine-x' ),
    'default' => true,
    'section' => 'newsx_section_bs_sharing',
	'tab' => 'general',
    'priority' => 20,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'switch',
    'settings' => 'newsx_options[bs_sharing_x_twitter]',
    'label' => esc_html__( 'X (twitter)', 'news-magazine-x' ),
    'default' => true,
    'section' => 'newsx_section_bs_sharing',
	'tab' => 'general',
    'priority' => 21,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'switch',
    'settings' => 'newsx_options[bs_sharing_flipboard]',
    'label' => esc_html__( 'Flipboard', 'news-magazine-x' ),
    'default' => false,
    'section' => 'newsx_section_bs_sharing',
	'tab' => 'general',
    'priority' => 22,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'switch',
    'settings' => 'newsx_options[bs_sharing_pinterest]',
    'label' => esc_html__( 'Pinterest', 'news-magazine-x' ),
    'default' => false,
    'section' => 'newsx_section_bs_sharing',
	'tab' => 'general',
    'priority' => 23,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'switch',
    'settings' => 'newsx_options[bs_sharing_whatsapp]',
    'label' => esc_html__( 'Whatsapp', 'news-magazine-x' ),
    'default' => false,
    'section' => 'newsx_section_bs_sharing',
	'tab' => 'general',
    'priority' => 24,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'switch',
    'settings' => 'newsx_options[bs_sharing_linkedin]',
    'label' => esc_html__( 'Linkedin', 'news-magazine-x' ),
    'default' => false,
    'section' => 'newsx_section_bs_sharing',
	'tab' => 'general',
    'priority' => 25,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'switch',
    'settings' => 'newsx_options[bs_sharing_tumblr]',
    'label' => esc_html__( 'Tumblr', 'news-magazine-x' ),
    'default' => false,
    'section' => 'newsx_section_bs_sharing',
	'tab' => 'general',
    'priority' => 26,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'switch',
    'settings' => 'newsx_options[bs_sharing_reddit]',
    'label' => esc_html__( 'Reddit', 'news-magazine-x' ),
    'default' => false,
    'section' => 'newsx_section_bs_sharing',
	'tab' => 'general',
    'priority' => 27,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'switch',
    'settings' => 'newsx_options[bs_sharing_vk]',
    'label' => esc_html__( 'VKontakte', 'news-magazine-x' ),
    'default' => false,
    'section' => 'newsx_section_bs_sharing',
	'tab' => 'general',
    'priority' => 28,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'switch',
    'settings' => 'newsx_options[bs_sharing_telegram]',
    'label' => esc_html__( 'Telegram', 'news-magazine-x' ),
    'default' => false,
    'section' => 'newsx_section_bs_sharing',
	'tab' => 'general',
    'priority' => 29,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'switch',
    'settings' => 'newsx_options[bs_sharing_email]',
    'label' => esc_html__( 'Email', 'news-magazine-x' ),
    'default' => false,
    'section' => 'newsx_section_bs_sharing',
	'tab' => 'general',
    'priority' => 30,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'switch',
    'settings' => 'newsx_options[bs_sharing_copy]',
    'label' => esc_html__( 'Copy Link', 'news-magazine-x' ),
    'default' => true,
    'section' => 'newsx_section_bs_sharing',
	'tab' => 'general',
    'priority' => 31,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'switch',
    'settings' => 'newsx_options[bs_sharing_print]',
    'label' => esc_html__( 'Print Article', 'news-magazine-x' ),
    'default' => true,
    'section' => 'newsx_section_bs_sharing',
	'tab' => 'general',
    'priority' => 32,
] );

// Upgrade to Pro List
Kirki::add_field( 'newsx_theme_config', [
    'type' => 'newsx-upgrade-pro-list',
    'settings' => 'newsx_options[bs_sharing_upgrade_pro_list]',
    'section' => 'newsx_section_bs_sharing',
    'tab' => 'general',
    'label' => esc_html__( 'Need more Post Sharing Options?', 'news-magazine-x' ),
    'choices' => [
        'header' => esc_html__( 'Show in Post Header', 'news-magazine-x' ),
        'floated' => esc_html__( 'Display as side Floated Sharing', 'news-magazine-x' ),
        'original-colors' => esc_html__( 'Display with Brand Colors', 'news-magazine-x' ),
        'brand-labels' => esc_html__( 'Show Brand Labels', 'news-magazine-x' ),
        'tooltips' => esc_html__( 'Show Tooltips', 'news-magazine-x' ),
        'custom-colors' => esc_html__( 'Custom Colors: Normal/Hover', 'news-magazine-x' ),
        'custom-icons' => esc_html__( 'Custom Icon Sizes', 'news-magazine-x' ),
        'much-more' => esc_html__( 'And much more....', 'news-magazine-x' ),
    ],
    'description' => 'https://wp-royal-themes.com/themes/item-news-magazine-x-pro/?ref=newsx-free-customizer-blog-single-sec-sharing-upgrade-pro#features',
    'divider' => 'newsx-group-divider-top',
    'priority' => 999,
] );

endif; // Free Version