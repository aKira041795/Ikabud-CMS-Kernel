<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

Kirki::add_field( 'newsx_theme_config', [
    'type' => 'about-section-links',
    'settings' => 'newsx_options[about_section_title]',
    'section' => 'newsx_section_about',
	'tab' => 'general',
    'label' => esc_html__( 'Section Title', 'news-magazine-x' ),
    'default' => 'About News Magazine X',
    'priority' => 11,
] );