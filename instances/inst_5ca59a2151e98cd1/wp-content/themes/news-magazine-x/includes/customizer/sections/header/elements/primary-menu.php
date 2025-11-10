<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
    newsx_add_pro_field( 'header_pm_hover_effect', 10 );
} else {
    Kirki::add_field( 'newsx_theme_config', [
        'type' => 'select',
        'settings' => 'newsx_options[header_pm_hover_effect]',
        'section' => 'newsx_section_hd_primary_menu',
        'tab' => 'general',
        'label' => esc_html__( 'Menu Item Hover Effect', 'news-magazine-x' ),
        'default' => 'fade',
        'choices' => [
            'none' => esc_html__( 'None', 'news-magazine-x' ),
            'fade' => esc_html__( 'Fade', 'news-magazine-x' ),
        ],
        'divider' => 'newsx-divider-bottom',
        'priority' => 10,
    ] );
}

if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
    newsx_add_pro_field( 'header_pm_submenu_animation', 20 );
} else {
    Kirki::add_field( 'newsx_theme_config', [
        'type' => 'select',
        'settings' => 'newsx_options[header_pm_submenu_animation]',
        'section' => 'newsx_section_hd_primary_menu',
        'tab' => 'general',
        'label' => esc_html__( 'Submenu Animation', 'news-magazine-x' ),
        'default' => 'fade',
        'choices' => [
            'none' => esc_html__( 'None', 'news-magazine-x' ),
            'fade' => esc_html__( 'Fade', 'news-magazine-x' ),
        ],
        'divider' => 'newsx-group-divider-bottom',
        'priority' => 20,
    ] );
}

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'switch',
    'settings' => 'newsx_options[header_pm_show_home_icon]',
    'section' => 'newsx_section_hd_primary_menu',
	'tab' => 'general',
    'label' => esc_html__( 'Show Home Icon', 'news-magazine-x' ),
    'default' => true,
    'priority' => 50,
] );

if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
    newsx_add_pro_field( 'header_pm_toggle_icon', 60 );
} else {
    Kirki::add_field( 'newsx_theme_config', [
        'type' => 'select',
        'settings' => 'newsx_options[header_pm_toggle_icon]',
        'section' => 'newsx_section_hd_primary_menu',
        'tab' => 'general',
        'label' => esc_html__( 'Mobile Toggle Icon', 'news-magazine-x' ),
        'default' => 'chevron-down',
        'choices' => [
            'none' => esc_html__( 'None', 'news-magazine-x' ),
            'chevron-down' => esc_html__( 'Chevron Down', 'news-magazine-x' ),
        ],
        'divider' => 'newsx-group-divider-top',
        'priority' => 60,
    ] );
}

if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
    newsx_add_pro_field( 'header_pm_toggle_style', 65 );
}

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'text',
    'settings' => 'newsx_options[header_pm_toggle_label]',
    'section' => 'newsx_section_hd_primary_menu',
	'tab' => 'general',
    'label' => esc_html__( 'Mobile Toggle Label', 'news-magazine-x' ),
    'default' => 'Menu',
    'priority' => 70,
] );

// Add Pro Fields
newsx_add_pro_field( 'header_pm_color', 110 );
newsx_add_pro_field( 'header_pm_bg_color', 120 );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'typography',
    'settings' => 'newsx_options[header_pm_font]',
    'section' => 'newsx_section_hd_primary_menu',
    'tab' => 'design',
    'label' => esc_html__( 'Menu Font', 'news-magazine-x' ),
    'default' => [
        'font-family'     => 'Encode Sans Condensed',
        'variant'         => '700',
        'font-weight'     => '700',
        'font-style'      => '700',
        'line-height'     => '1.5',
        'letter-spacing'  => '0',
        'text-transform'  => 'none',
        'text-decoration' => 'none',
        'text-align'      => 'left',
    ],
	'divider' => defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ? 'newsx-divider-top' : '',
    'priority' => 130,
	]
);

// Add Pro Fields
newsx_add_pro_field( 'header_pm_item_font_size', 135 );
newsx_add_pro_field( 'header_pm_subitem_font_size', 140 );
newsx_add_pro_field( 'header_pm_submenu_headline', 149 );
newsx_add_pro_field( 'header_pm_submenu_width', 150 );
newsx_add_pro_field( 'header_pm_submenu_offset', 155 );
newsx_add_pro_field( 'header_pm_submenu_bd_color', 160 );
newsx_add_pro_field( 'header_pm_submenu_bd_width', 165 );
newsx_add_pro_field( 'header_pm_submenu_bd_radius', 170 );
newsx_add_pro_field( 'header_pm_submenu_divider', 175 );
newsx_add_pro_field( 'header_pm_submenu_div_color', 180 );
newsx_add_pro_field( 'header_pm_toggle_headline', 189 );
newsx_add_pro_field( 'header_pm_toggle_color', 190 );
newsx_add_pro_field( 'header_pm_toggle_bg_color', 195 );
newsx_add_pro_field( 'header_pm_toggle_icon_size', 200 );
newsx_add_pro_field( 'header_pm_toggle_label_size', 205 );
newsx_add_pro_field( 'header_pm_toggle_bd_radius', 210 );
newsx_add_pro_field( 'header_pm_mobile_drop_headline', 215 );
newsx_add_pro_field( 'header_pm_mobile_drop_divider', 220 );
newsx_add_pro_field( 'header_pm_mobile_drop_div_color', 225 );


Kirki::add_field( 'newsx_theme_config', [
    'type' => 'newsx-headline',
    'settings' => 'newsx_options[header_pm_spacing_headline]',
    'section' => 'newsx_section_hd_primary_menu',
	'tab' => 'design',
    'label' => esc_html__( 'Spacing', 'news-magazine-x' ),
    'priority' => 299,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'kirki-margin',
    'settings' => 'newsx_options[header_pm_item_padding]',
    'section' => 'newsx_section_hd_primary_menu',
	'tab' => 'design',
    'label' => esc_html__( 'Item Padding', 'news-magazine-x' ),
    'responsive' => true,
    'default'    => [
        'desktop' => [
            'top'      => '',
            'right'    => '',
            'bottom'   => '',
            'left'     => '',
            'isLinked' => true,
        ],
        'tablet'  => [
            'top'      => '5',
            'right'    => '5',
            'bottom'   => '5',
            'left'     => '5',
            'isLinked' => false,
        ],
        'mobile'  => [
            'top'      => '',
            'right'    => '',
            'bottom'   => '',
            'left'     => '',
            'isLinked' => false,
        ],
    ],
    'choices'  => [
        'min'  => 0,
        'max'  => 100,
        'step' => 1,
    ],
    'priority' => 300,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'kirki-margin',
    'settings' => 'newsx_options[header_pm_item_margin]',
    'section' => 'newsx_section_hd_primary_menu',
	'tab' => 'design',
    'label' => esc_html__( 'Item Margin', 'news-magazine-x' ),
    'responsive' => true,
    'default'    => [
        'desktop' => [
            'top'      => '',
            'right'    => '12',
            'bottom'   => '',
            'left'     => '',
            'isLinked' => false,
        ],
        'tablet'  => [
            'top'      => '',
            'right'    => '',
            'bottom'   => '',
            'left'     => '',
            'isLinked' => false,
        ],
        'mobile'  => [
            'top'      => '',
            'right'    => '',
            'bottom'   => '',
            'left'     => '',
            'isLinked' => false,
        ],
    ],
    'choices'  => [
        'min'  => 0,
        'max'  => 100,
        'step' => 1,
    ],
    'priority' => 305,
] );

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'kirki-margin',
    'settings' => 'newsx_options[header_pm_margin]',
    'section' => 'newsx_section_hd_primary_menu',
	'tab' => 'design',
    'label' => esc_html__( 'Margin', 'news-magazine-x' ),
    'responsive' => true,
    'default'    => [
        'desktop' => [
            'top'      => '',
            'right'    => '',
            'bottom'   => '',
            'left'     => '20',
            'isLinked' => false,
        ],
        'tablet'  => [
            'top'      => '',
            'right'    => '',
            'bottom'   => '',
            'left'     => '',
            'isLinked' => true,
        ],
        'mobile'  => [
            'top'      => '',
            'right'    => '',
            'bottom'   => '',
            'left'     => '',
            'isLinked' => true,
        ],
    ],
    'choices'  => [
        'min'  => 0,
        'max'  => 100,
        'step' => 1,
    ],
    'priority' => 310,
] );

// Upgrade to Pro List
if ( !defined('NEWSX_CORE_PRO_VERSION') || !newsx_core_pro_fs()->can_use_premium_code() ) {

    Kirki::add_field( 'newsx_theme_config', [
        'type' => 'newsx-upgrade-pro-list',
        'settings' => 'newsx_options[section_hd_primary_menu_upgrade_pro_list]',
        'section' => 'newsx_section_hd_primary_menu',
        'tab' => 'general',
        'label' => esc_html__( 'Need more Menu Options?', 'news-magazine-x' ),
        'choices' => [
            'font' => esc_html__( 'All 1000+ Google Fonts', 'news-magazine-x' ),
            'item-hover-effect' => esc_html__( 'Item Hover Effects', 'news-magazine-x' ),
            'submenu-animation' => esc_html__( 'Submenu Animations', 'news-magazine-x' ),
            'toggle-style' => esc_html__( 'Mobile Toggle Styles', 'news-magazine-x' ),
            'color' => esc_html__( 'Menu Item Colors', 'news-magazine-x' ),
            'item-font-size' => esc_html__( 'Menu Item Font Size', 'news-magazine-x' ),
            'subitem-font-size' => esc_html__( 'Submenu Item Font Size', 'news-magazine-x' ),
            'submenu-width' => esc_html__( 'Submenu Width', 'news-magazine-x' ),
            'submenu-offset' => esc_html__( 'Submenu Offset', 'news-magazine-x' ),
            'more' => esc_html__( 'And much more....', 'news-magazine-x' ),
        ],
        'description' => 'https://wp-royal-themes.com/themes/item-news-magazine-x-pro/?ref=newsx-free-customizer-header-sec-prm-menu-upgrade-pro#features',
        'divider' => 'newsx-group-divider-top',
        'priority' => 999,
    ] );

}
