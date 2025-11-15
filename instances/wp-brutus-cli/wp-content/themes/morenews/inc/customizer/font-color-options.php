<?php

/**
 * Font and Color Option Panel
 *
 * @package MoreNews
 */

$morenews_default = morenews_get_default_theme_options();


// Setting - global content alignment of news.
$wp_customize->add_setting('global_site_mode_setting',
    array(
        'default' => $morenews_default['global_site_mode_setting'],
        'capability' => 'edit_theme_options',
        'sanitize_callback' => 'morenews_sanitize_select',
    )
);

$wp_customize->add_control('global_site_mode_setting',
    array(
        'label' => esc_html__('Site Color Mode', 'morenews'),
        'section' => 'colors',
        'type' => 'select',
        'choices' => array(
            'aft-default-mode' => esc_html__('Light', 'morenews'),
            'aft-dark-mode' => esc_html__('Dark', 'morenews'),
        ),
        'priority' => 5,
    ));

//section title
$wp_customize->add_setting('site_background_color_section_title',
    array(
        'sanitize_callback' => 'sanitize_text_field',
    )
);

$wp_customize->add_control(
    new MoreNews_Section_Title(
        $wp_customize,
        'site_background_color_section_title',
        array(
            'label' => esc_html__('Primary Color Section ', 'morenews'),
            'section' => 'colors',
            'priority' => 5,
            //'active_callback' => 'morenews_global_site_mode_status'
        )
    )
);


//section title
$wp_customize->add_setting('global_color_section_notice',
    array(
        'sanitize_callback' => 'sanitize_text_field',
    )
);

$wp_customize->add_control(
    new MoreNews_Simple_Notice_Custom_Control(
        $wp_customize,
        'global_color_section_notice',
        array(
            'description' => esc_html__('Body Background Color (Dark Mode) will be applied for this mode.', 'morenews'),
            'section' => 'colors',
            'priority' => 10,
            'active_callback' => 'morenews_global_site_mode_dark_status'
        )
    )
);



// Setting - slider_caption_bg_color.
$wp_customize->add_setting('dark_background_color',
    array(
        'default' => $morenews_default['dark_background_color'],
        'capability' => 'edit_theme_options',
        'sanitize_callback' => 'sanitize_hex_color',
    )
);
$wp_customize->add_control(
    new WP_Customize_Color_Control(
        $wp_customize,
        'dark_background_color',
        array(
            'label' => esc_html__('Body Background Color (Dark Mode)', 'morenews'),
            'section' => 'colors',
            'type' => 'color',
            'priority' => 10,
            'active_callback' => 'morenews_global_site_mode_dark_status'

        )
    )
);


//section title
$wp_customize->add_setting('secondary_color_section_title',
    array(
        'sanitize_callback' => 'sanitize_text_field',
    )
);

$wp_customize->add_control(
    new MoreNews_Section_Title(
        $wp_customize,
        'secondary_color_section_title',
        array(
            'label' => esc_html__('Secondary Color Section ', 'morenews'),
            'section' => 'colors',
            'priority' => 10,
            //'active_callback' => 'morenews_global_site_mode_status'
        )
    )
);


// Setting - secondary_color.
$wp_customize->add_setting('secondary_color',
    array(
        'default' => $morenews_default['secondary_color'],
        'capability' => 'edit_theme_options',
        'sanitize_callback' => 'sanitize_hex_color',
    )
);

$wp_customize->add_control(

    new WP_Customize_Color_Control(
        $wp_customize,
        'secondary_color',
        array(
            'label' => esc_html__('Secondary Color', 'morenews'),
            'section' => 'colors',
            'type' => 'color',
            'priority' => 10,
            //'active_callback' => 'morenews_solid_secondary_color_status'
        )
    )
);



//============= Font Options ===================
// font Section.
$wp_customize->add_section('font_typo_section',
    array(
        'title' => esc_html__('Fonts & Typography', 'morenews'),
        'priority' => 5,
        'capability' => 'edit_theme_options',
        'panel' => 'theme_option_panel',
    )
);

global $morenews_google_fonts;


// Trending Section.
$wp_customize->add_setting('site_title_font_section_title',
    array(
        'sanitize_callback' => 'sanitize_text_field',
    )
);

$wp_customize->add_control(
    new MoreNews_Section_Title(
        $wp_customize,
        'site_title_font_section_title',
        array(
            'label' => esc_html__("Font Family Section", 'morenews'),
            'section' => 'font_typo_section',
            'priority' => 100,

        )
    )
);



// Setting - global content alignment of news.
$wp_customize->add_setting(
    'global_font_family_type',
    array(
        'default' => $morenews_default['global_font_family_type'],
        'capability' => 'edit_theme_options',
        'sanitize_callback' => 'morenews_sanitize_select',
    )
);

$wp_customize->add_control(
    'global_font_family_type',
    array(
        'label' => esc_html__('Global Fonts Family', 'morenews'),
        'section' => 'font_typo_section',
        'type' => 'select',
        'choices' => array(
            'google' => esc_html__('Google Fonts', 'morenews'),
            'system' => esc_html__('System Fonts', 'morenews')
        ),
        'priority' => 100,
    )
);



// Setting - secondary_font.
$wp_customize->add_setting('site_title_font',
    array(
        'default' => $morenews_default['site_title_font'],
        'capability' => 'edit_theme_options',
        'sanitize_callback' => 'morenews_sanitize_select',
    )
);
$wp_customize->add_control('site_title_font',
    array(
        'label' => esc_html__('Site Title Font', 'morenews'),

        'section' => 'font_typo_section',
        'type' => 'select',
        'choices' => $morenews_google_fonts,
        'priority' => 100,
        'active_callback' => 'global_font_family_type_status'
    )
);

// Setting - primary_font.
$wp_customize->add_setting('primary_font',
    array(
        'default' => $morenews_default['primary_font'],
        'capability' => 'edit_theme_options',
        'sanitize_callback' => 'morenews_sanitize_select',
    )
);
$wp_customize->add_control('primary_font',
    array(
        'label' => esc_html__('Primary Font', 'morenews'),

        'section' => 'font_typo_section',
        'type' => 'select',
        'choices' => $morenews_google_fonts,
        'priority' => 100,
        'active_callback' => 'global_font_family_type_status'
    )
);

// Setting - secondary_font.
$wp_customize->add_setting('secondary_font',
    array(
        'default' => $morenews_default['secondary_font'],
        'capability' => 'edit_theme_options',
        'sanitize_callback' => 'morenews_sanitize_select',
    )
);
$wp_customize->add_control('secondary_font',
    array(
        'label' => esc_html__('Secondary Font', 'morenews'),

        'section' => 'font_typo_section',
        'type' => 'select',
        'choices' => $morenews_google_fonts,
        'priority' => 110,
        'active_callback' => 'global_font_family_type_status'
    )
);