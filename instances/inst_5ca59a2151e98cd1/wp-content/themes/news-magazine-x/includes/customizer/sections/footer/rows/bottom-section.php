<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Pro Version
if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) :
    newsx_add_pro_controls_group( 'footer-bottom-section' );

else : // Free Version

// Upgrade to Pro List
Kirki::add_field( 'newsx_theme_config', [
    'type' => 'newsx-upgrade-pro-list',
    'settings' => 'newsx_options[section_ft_bottom_upgrade_pro_list]',
    'section' => 'newsx_section_ft_bottom',
    'tab' => 'general',
    'label' => esc_html__( 'This Section is available in the Pro Version', 'news-magazine-x' ),
    'choices' => [
        'enable-section' => esc_html__( 'Enable/Disable this Section', 'news-magazine-x' ),
        'elements' => esc_html__( 'Add Footer Builder Elements', 'news-magazine-x' ),
        'resp-visibility' => esc_html__( 'Responsive Visibility Option', 'news-magazine-x' ),
        'columns' => esc_html__( '1 to 6 Columns Option', 'news-magazine-x' ),
        'footer-widgets-5' => esc_html__( 'Footer Widgets Area 5', 'news-magazine-x' ),
        'footer-widgets-6' => esc_html__( 'Footer Widgets Area 6', 'news-magazine-x' ),
        'background' => esc_html__( 'Section Background Color', 'news-magazine-x' ),
        'much-more' => esc_html__( 'And much more....', 'news-magazine-x' ),
    ],
    'description' => 'https://wp-royal-themes.com/themes/item-news-magazine-x-pro/?ref=newsx-free-customizer-footer-sec-bottom-upgrade-pro#features',
    'priority' => 999,
] );

endif; // Free Version
