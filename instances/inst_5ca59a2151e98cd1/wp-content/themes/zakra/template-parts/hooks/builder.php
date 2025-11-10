<?php

$enable_builder = get_theme_mod( 'zakra_enable_builder', false );
if ( $enable_builder || zakra_maybe_enable_builder() ) {
	remove_action( 'zakra_header', 'zakra_header_markup' );
	remove_action( 'zakra_action_before_header', 'zakra_header_start', 15 );
	remove_action( 'zakra_action_after_header', 'zakra_header_end', 15 );
	remove_action( 'zakra_action_footer_widgets', 'zakra_footer_widgets' );
	remove_action( 'zakra_action_footer_bottom_bar', 'zakra_footer_bottom_bar' );
	remove_action( 'after_setup_theme', 'zakra_mobile_menu' );
	remove_action( 'zakra_action_before_footer', 'zakra_footer_start' );
	remove_action( 'zakra_action_after_footer', 'zakra_footer_end' );
}
