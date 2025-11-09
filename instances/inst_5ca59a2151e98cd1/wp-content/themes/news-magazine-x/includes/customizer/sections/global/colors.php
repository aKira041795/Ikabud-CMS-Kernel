<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'color',
    'settings' => 'newsx_options[global_color_accent]',
    'section' => 'newsx_section_global_colors',
    'label' => esc_html__( 'Accent', 'news-magazine-x' ),
    'default' => '#f84643',
    // 'transport' => 'postMessage',
    'priority' => 10,
] );

newsx_add_pro_field( 'global_color_links', 15 );

newsx_add_pro_field( 'global_color_headings', 20 );

newsx_add_pro_field( 'global_color_body_text', 25 );

newsx_add_pro_field( 'global_color_meta', 30 );

newsx_add_pro_field( 'global_color_borders', 35 );

Kirki::add_field( 'newsx_theme_config', [
  'type' => 'background',
  'settings' => 'newsx_options[global_color_site_background]',
  'section' => 'newsx_section_global_colors',
  'label' => esc_html__( 'Site Background', 'news-magazine-x' ),
  'default' => [
      'background-tabs'       => 'color',
      'background-color'      => 'rgba(255,255,255,1)',
      'gradient-color-1'      => 'rgba(20,20,20,.8)',
      'gradient-color-2'      => 'rgba(120,120,120,.8)',
      'gradient-pos-1'        => 0,
      'gradient-pos-2'        => 100,
      'gradient-angle'        => 135,
      'background-image'      => '',
      'background-repeat'     => 'repeat',
      'background-position'   => 'center center',
      'background-size'       => 'cover',
      'background-attachment' => 'scroll',
  ],
//   'transport' => 'postMessage',
  'priority' => 60,
] );

newsx_add_pro_field( 'global_color_content_background', 65 );

newsx_add_pro_field( 'global_color_footer_enable', 100 );

newsx_add_pro_field( 'global_color_footer_headings', 105 );

newsx_add_pro_field( 'global_color_footer_text', 110 );

newsx_add_pro_field( 'global_color_footer_meta', 115 );

newsx_add_pro_field( 'global_color_footer_borders', 120 );

// Upgrade to Pro List
if ( !defined('NEWSX_CORE_PRO_VERSION') || !newsx_core_pro_fs()->can_use_premium_code() ) {

  Kirki::add_field( 'newsx_theme_config', [
    'type' => 'newsx-upgrade-pro-list',
    'settings' => 'newsx_options[section_global_colors_upgrade_pro_list]',
    'section' => 'newsx_section_global_colors',
    'tab' => 'general',
    'label' => esc_html__( 'Need more Global Colors Options?', 'news-magazine-x' ),
    'choices' => [
      'link' => esc_html__( 'Link and Link Hover', 'news-magazine-x' ),
      'heading' => esc_html__( 'Heading (H1-H6) Color', 'news-magazine-x' ),
      'body' => esc_html__( 'Body Text Color', 'news-magazine-x' ),
      'meta' => esc_html__( 'Meta Color', 'news-magazine-x' ),
      'border' => esc_html__( 'Border Color', 'news-magazine-x' ),
      'footer' => esc_html__( 'Custom Footer Colors', 'news-magazine-x' ),
    ],
    'description' => 'https://wp-royal-themes.com/themes/item-news-magazine-x-pro/?ref=newsx-free-customizer-global-sec-colors-upgrade-pro#features',
    'divider' => 'newsx-group-divider-top',
    'priority' => 999,
  ] );

}
