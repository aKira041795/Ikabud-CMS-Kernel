<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Pro Version
if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) :
    newsx_add_pro_controls_group( 'global-typography' );

else : // Free Version

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'newsx-headline',
	'settings' => 'newsx_options[global_base_fonts_headline]',
	'label' => esc_html__( 'Base Fonts', 'news-magazine-x' ),
	'section' => 'newsx_section_global_typography',
	'priority' => 1,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'typography',
	'settings' => 'newsx_options[global_font_body]',
	'label' => esc_html__( 'Body Font', 'news-magazine-x' ),
	'section' => 'newsx_section_global_typography',
	// 'transport'   => 'auto',
	'default' => [
		'font-family' => 'Oxygen',
		'variant'     => 'regular',
		'font-weight' => 'normal',
		'font-style'  => 'normal',
		'font-size' => [
			'desktop' => 14,
			'tablet'  => 14,
			'mobile'  => 14,
		],
		'line-height'     => '1.5',
		'letter-spacing'  => '0',
		'text-transform'  => 'none',
		'text-decoration' => 'none',
		'text-align'      => 'left',
		],
        'priority' => 10,
] );

Kirki::add_field( 'newsx_theme_config', [
	'type' => 'typography',
	'settings' => 'newsx_options[global_font_heading]',
	'label' => esc_html__( 'Heading Font', 'news-magazine-x' ),
	'section' => 'newsx_section_global_typography',
	// 'transport'   => 'auto',
	'default' => [
		'font-family'     => 'Encode Sans Condensed',
		'variant'         => 'regular',
		'font-weight'     => 'normal',
		'font-style'      => 'normal',
		'line-height'     => '1.6',
		'letter-spacing'  => '0',
		'text-transform'  => 'none',
		'text-decoration' => 'none',
		'text-align'      => 'left',
	],
	'priority' => 20,
] );

// Upgrade to Pro List
Kirki::add_field( 'newsx_theme_config', [
	'type' => 'newsx-upgrade-pro-list',
	'settings' => 'newsx_options[section_global_typography_upgrade_pro_list]',
	'section' => 'newsx_section_global_typography',
	'tab' => 'general',
	'label' => esc_html__( 'Need more Global Typography Options?', 'news-magazine-x' ),
	'choices' => [
		'google' => esc_html__( 'All 1000+ Google Fonts', 'news-magazine-x' ),
		'body-font-size' => esc_html__( 'Body Font Size', 'news-magazine-x' ),
		'heading' => esc_html__( 'Heading Font Options: H1 - H6', 'news-magazine-x' ),
		'subsets' => esc_html__( 'Custom Font Subsets', 'news-magazine-x' ),
	],
	'description' => 'https://wp-royal-themes.com/themes/item-news-magazine-x-pro/?ref=newsx-free-customizer-global-sec-typography-upgrade-pro#features',
	'divider' => 'newsx-group-divider-top',
	'priority' => 999,
] );

endif; // Free Version
