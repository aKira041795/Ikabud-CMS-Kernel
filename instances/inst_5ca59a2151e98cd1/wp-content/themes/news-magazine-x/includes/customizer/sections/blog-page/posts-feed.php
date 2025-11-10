<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Pro Version
if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) :
    newsx_add_pro_controls_group( 'blog-page-posts-feed' );

else : // Free Version

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'select',
    'settings' => 'newsx_options[bp_feed_title_tag]',
    'section' => 'newsx_section_bp_posts_feed',
	'tab' => 'general',
    'label' => esc_html__( 'Title Tag', 'news-magazine-x' ),
    'default' => 'h3',
    'choices' => newsx_get_html_tag_options(),
    'priority' => 90,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'number',
    'settings' => 'newsx_options[bp_feed_title_letter_count]',
    'section' => 'newsx_section_bp_posts_feed',
	'tab' => 'general',
    'label' => esc_html__( 'Title Letter Count', 'news-magazine-x' ),
    'default' => '',
    'priority' => 96,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'switch',
    'settings' => 'newsx_options[bp_feed_image_link]',
    'label' => esc_html__( 'Image links to Single Page', 'news-magazine-x' ),
    'section' => 'newsx_section_bp_posts_feed',
	'tab' => 'general',
    'default' => true,
	'divider' => 'newsx-group-divider-top',
    'priority' => 116,
] );

// Upgrade to Pro List
Kirki::add_field( 'newsx_theme_config', [
    'type' => 'newsx-upgrade-pro-list',
    'settings' => 'newsx_options[bp_feed_upgrade_pro_list]',
    'section' => 'newsx_section_bp_posts_feed',
    'tab' => 'general',
    'label' => esc_html__( 'Need more Blog Feed Options?', 'news-magazine-x' ),
    'choices' => [
        'columns' => esc_html__( 'Grid Layout: 1, 2, 3 Columns', 'news-magazine-x' ),
        'date-format' => esc_html__( 'Date Format: Last Updated', 'news-magazine-x' ),
        'autor-avatar' => esc_html__( 'Show Author Avatar', 'news-magazine-x' ),
        'excerpt-letter-count' => esc_html__( 'Custom Excerpt Letter Count', 'news-magazine-x' ),
        'read-more-text' => esc_html__( 'Custom Read More Text', 'news-magazine-x' ),
        'much-more' => esc_html__( 'And much more....', 'news-magazine-x' ),
    ],
    'description' => 'https://wp-royal-themes.com/themes/item-news-magazine-x-pro/?ref=newsx-free-customizer-blog-page-sec-posts-feed-upgrade-pro#features',
    'divider' => 'newsx-group-divider-top',
    'priority' => 999,
] );

endif; // Free Version
