<?php
/**
 * Override default customizer options.
 *
 * @package zakra
 */

/**
 * Override controls.
 */

// Settings.
$wp_customize->get_setting( 'blogname' )->transport        = 'postMessage';
$wp_customize->get_setting( 'blogdescription' )->transport = 'postMessage';

if ( get_theme_mod( 'zakra_enable_builder', false ) || zakra_maybe_enable_builder() ) {
	$wp_customize->get_control( 'blogname' )->section         = 'zakra_header_builder_logo';
	$wp_customize->get_control( 'blogname' )->priority        = 4;
	$wp_customize->get_control( 'blogdescription' )->section  = 'zakra_header_builder_logo';
	$wp_customize->get_control( 'blogdescription' )->priority = 5;
	$wp_customize->get_control( 'site_icon' )->section        = 'zakra_header_builder_logo';
	$wp_customize->get_control( 'site_icon' )->priority       = 6;
} else {
	$wp_customize->get_control( 'site_icon' )->priority       = 5;
	$wp_customize->get_control( 'blogname' )->priority        = 6;
	$wp_customize->get_control( 'blogdescription' )->priority = 7;
}


$wp_customize->remove_control( 'display_header_text' );
$wp_customize->remove_control( 'display_header_text' );
$wp_customize->remove_control( 'header_textcolor' );
$wp_customize->remove_control( 'background_attachment' );
$wp_customize->remove_control( 'background_repeat' );
$wp_customize->remove_control( 'background_size' );
$wp_customize->remove_control( 'background_position' );
$wp_customize->remove_control( 'background_preset' );
$wp_customize->remove_control( 'background_image' );
$wp_customize->remove_control( 'background_color' );

if ( isset( $wp_customize->selective_refresh ) ) {
	$wp_customize->selective_refresh->add_partial(
		'blogname',
		array(
			'selector'        => '.site-title a',
			'render_callback' => 'Zakra_Customizer_Partials::customize_partial_blogname',
		)
	);

	$wp_customize->selective_refresh->add_partial(
		'blogdescription',
		array(
			'selector'        => '.site-description',
			'render_callback' => 'Zakra_Customizer_Partials::customize_partial_blogdescription',
		)
	);

	$wp_customize->selective_refresh->add_partial(
		'document_title',
		array(
			'selector'        => 'head > title',
			'settings'        => array( 'blogname', 'blogdescription' ),
			'render_callback' => 'wp_get_document_title',
		)
	);
}

// Header Media.
$wp_customize->get_control( 'header_image' )->priority       = 6;
$wp_customize->get_control( 'header_video' )->priority       = 7;
$wp_customize->get_control( 'external_header_video' )->label = esc_html__( 'Header Video URL', 'zakra' );

// Modify WooCommerce default section priorities.
if ( class_exists( 'WooCommerce' ) ) {
	$wp_customize->get_panel( 'woocommerce' )->priority = 36;
}
