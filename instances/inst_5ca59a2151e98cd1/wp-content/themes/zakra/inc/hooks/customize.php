<?php
/**
 * Hooks from Zakra theme.
 *
 * @package    ThemeGrill
 * @subpackage Zakra
 * @since      Zakra 1.5.7
 */

add_action(
	'customize_register',
	function () {
		if ( ! zakra_plugin_version_compare( 'zakra-pro/zakra-pro.php', '3.0.0', '<' ) ) {
			return;
		}

		if ( zakra_is_zakra_pro_active() ) {
			zakra_remove_filters_with_method_name( 'customize_register', 'zakra_pro_customize_register', 10 );
			zakra_remove_filters_with_method_name( 'customize_register', 'zakra_pro_customize_options_file_include', 2 );
			zakra_remove_filters_with_method_name( 'customize_controls_enqueue_scripts', 'enqueue_customize_scripts', 11 );
			remove_action( 'wp_enqueue_scripts', 'zakra_pro_add_metabox_styles', 12 );
			remove_filter( 'zakra_page_header_style_filter', 'zakra_pro_page_header_style_filter', 10, 2 );
			remove_filter( 'zakra_header_style_meta_save', 'zakra_pro_header_style_meta_save', 20 );
			remove_filter( 'zakra_page_setting', 'zakra_pro_page_setting', 15 );
			remove_action( 'zakra_page_settings', 'zakra_pro_page_settings' );
			remove_action( 'zakra_general_page_setting', 'zakra_pro_general_page_setting' );
			remove_action( 'zakra_header_page_setting', 'zakra_pro_header_page_setting' );
			remove_action( 'zakra_primary_menu_page_settings_before', 'zakra_pro_primary_menu_page_settings_before' );
			remove_action( 'zakra_page_header_page_setting', 'zakra_pro_page_header_style' );
			remove_action( 'zakra_page_settings_save', 'zakra_pro_page_settings_save' );
		}
	},
	1
);
