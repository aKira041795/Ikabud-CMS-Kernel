<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Add Pro Field
newsx_add_pro_field( 'global_category_style', 1 );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'switch',
    'settings' => 'newsx_options[global_category_enable_cc]',
    'label' => esc_html__( 'Enable Custom Category Colors', 'news-magazine-x' ),
    'section' => 'newsx_section_global_categories',
	'tab' => 'general',
    'default' => true,
    'priority' => 2,
] );

// Get all Categories
$categories = get_categories( array(
    'orderby' => 'name',
    'order'   => 'ASC'
) );

foreach ( $categories as $category ) {
    if ( $category->term_id ) {
        
        Kirki::add_field( 'newsx_theme_config', [
            'type'     => 'color',
            'settings' => 'newsx_options[global_category_' . $category->term_id . ']',
            'section'  => 'newsx_section_global_categories',
            'label'    => $category->name,
            'default'  => '#333333',
            'active_callback' => [
                [
                    'setting'  => 'newsx_options[global_category_enable_cc]',
                    'operator' => '!=',
                    'value'    => '',
                ]
            ],
            'priority' => $category->term_id + 10,
        ] );
    }
}

// Upgrade to Pro List
if ( !defined('NEWSX_CORE_PRO_VERSION') || !newsx_core_pro_fs()->can_use_premium_code() ) {

    Kirki::add_field( 'newsx_theme_config', [
      'type' => 'newsx-upgrade-pro-list',
      'settings' => 'newsx_options[section_global_categories_upgrade_pro_list]',
      'section' => 'newsx_section_global_categories',
      'tab' => 'general',
      'label' => esc_html__( 'Need more Category Options?', 'news-magazine-x' ),
      'choices' => [
        'style' => esc_html__( 'Different Category Styles', 'news-magazine-x' ),
      ],
      'description' => 'https://wp-royal-themes.com/themes/item-news-magazine-x-pro/?ref=newsx-free-customizer-global-sec-categories-upgrade-pro#features',
      'divider' => 'newsx-group-divider-top',
      'priority' => 999,
    ] );
  
  }
