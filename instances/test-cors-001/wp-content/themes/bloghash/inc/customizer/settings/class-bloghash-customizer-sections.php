<?php
/**
 * Bloghash Customizer sections and panels.
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

if ( ! class_exists( 'Bloghash_Customizer_Sections' ) ) :
	/**
	 * Bloghash Customizer sections and panels.
	 */
	class Bloghash_Customizer_Sections {

		/**
		 * Primary class constructor.
		 *
		 * @since 1.0.0
		 */
		public function __construct() {

			/**
			 * Registers our custom panels in Customizer.
			 */
			add_filter( 'bloghash_customizer_options', array( $this, 'register_panel' ) );
		}

		/**
		 * Registers our custom options in Customizer.
		 *
		 * @since 1.0.0
		 * @param array $options Array of customizer options.
		 */
		public function register_panel( $options ) {

			// Title - General Options
			$options['section']['bloghash_section_general_group'] = array(
				'class'    => 'Bloghash_Customizer_Control_Section_Group_Title',
				'title'    => esc_html__( 'General Options', 'bloghash' ),
				'priority' => 1,
			);

			// General panel.
			$options['panel']['bloghash_panel_general'] = array(
				'title'    => esc_html__( 'General Settings', 'bloghash' ),
				'priority' => 2,
			);

			// Header panel.
			$options['panel']['bloghash_panel_header'] = array(
				'title'    => esc_html__( 'Header', 'bloghash' ),
				'priority' => 3,
			);

			// Footer panel.
			$options['panel']['bloghash_panel_footer'] = array(
				'title'    => esc_html__( 'Footer', 'bloghash' ),
				'priority' => 3,
			);

			// Blog settings.
			$options['panel']['bloghash_panel_blog'] = array(
				'title'    => esc_html__( 'Blog', 'bloghash' ),
				'priority' => 3,
			);

			// Title - Extra Options
			$options['section']['bloghash_section_extra_group'] = array(
				'class'    => 'Bloghash_Customizer_Control_Section_Group_Title',
				'title'    => esc_html__( 'Extra Options', 'bloghash' ),
				'priority' => 4,
			);

			// Title - Core
			$options['section']['bloghash_section_core_group'] = array(
				'class'    => 'Bloghash_Customizer_Control_Section_Group_Title',
				'title'    => esc_html__( 'Core', 'bloghash' ),
				'priority' => 7,
			);

			return $options;
		}
	}
endif;
new Bloghash_Customizer_Sections();
