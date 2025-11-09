<?php
/**
 * Bloghash Misc section in Customizer.
 *
 * @package     Bloghash
 * @author      Peregrine Themes
 * @since       1.0.0
 */

/**
 * Do not allow direct script access.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Bloghash_Customizer_Misc' ) ) :
	/**
	 * Bloghash Misc section in Customizer.
	 */
	class Bloghash_Customizer_Misc {

		/**
		 * Primary class constructor.
		 *
		 * @since 1.0.0
		 */
		public function __construct() {

			/**
			 * Registers our custom options in Customizer.
			 */
			add_filter( 'bloghash_customizer_options', array( $this, 'register_options' ) );
		}

		/**
		 * Registers our custom options in Customizer.
		 *
		 * @since 1.0.0
		 * @param array $options Array of customizer options.
		 */
		public function register_options( $options ) {

			// Section.
			$options['section']['bloghash_section_misc'] = array(
				'title'    => esc_html__( 'Misc Settings', 'bloghash' ),
				'panel'    => 'bloghash_panel_general',
				'priority' => 60,
			);

			// Schema toggle.
			$options['setting']['bloghash_enable_schema'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_toggle',
				'control'           => array(
					'type'        => 'bloghash-toggle',
					'label'       => esc_html__( 'Schema Markup', 'bloghash' ),
					'description' => esc_html__( 'Add structured data to your content.', 'bloghash' ),
					'section'     => 'bloghash_section_misc',
				),
			);

			// Custom form styles.
			$options['setting']['bloghash_custom_input_style'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_toggle',
				'control'           => array(
					'type'        => 'bloghash-toggle',
					'label'       => esc_html__( 'Custom Form Styles', 'bloghash' ),
					'description' => esc_html__( 'Custom design for checkboxes and radio buttons.', 'bloghash' ),
					'section'     => 'bloghash_section_misc',
				),
			);

			// Enable/Disable Page Preloader.
			$options['setting']['bloghash_preloader'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_toggle',
				'control'           => array(
					'type'        => 'bloghash-toggle',
					'label'       => esc_html__( 'Enable Page Preloader', 'bloghash' ),
					'description' => esc_html__( 'Show animation until page is fully loaded.', 'bloghash' ),
					'section'     => 'bloghash_section_misc',
				),
			);

			// Enable/Disable Scroll Top.
			$options['setting']['bloghash_scroll_top'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_toggle',
				'control'           => array(
					'type'        => 'bloghash-toggle',
					'label'       => esc_html__( 'Enable Scroll Top Button', 'bloghash' ),
					'description' => esc_html__( 'A sticky button that allows users to easily return to the top of a page.', 'bloghash' ),
					'section'     => 'bloghash_section_misc',
				),
			);

			// Enable/Disable Cursor Dot.
			$options['setting']['bloghash_enable_cursor_dot'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_toggle',
				'control'           => array(
					'type'        => 'bloghash-toggle',
					'label'       => esc_html__( 'Enable Cursor Dot', 'bloghash' ),
					'description' => esc_html__( 'A cursor dot effect show on desktop size mode only with work on mouse.', 'bloghash' ),
					'section'     => 'bloghash_section_misc',
				),
			);

			return $options;
		}
	}
endif;
new Bloghash_Customizer_Misc();
