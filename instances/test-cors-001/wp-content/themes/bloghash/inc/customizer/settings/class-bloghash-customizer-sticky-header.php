<?php
/**
 * Bloghash Sticky Header Settings section in Customizer.
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

if ( ! class_exists( 'Bloghash_Customizer_Sticky_Header' ) ) :
	/**
	 * Bloghash Sticky Header section in Customizer.
	 */
	class Bloghash_Customizer_Sticky_Header {

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

			// Sticky Header Section.
			$options['section']['bloghash_section_sticky_header'] = array(
				'title'    => esc_html__( 'Sticky Header', 'bloghash' ),
				'panel'    => 'bloghash_panel_header',
				'priority' => 80,
			);

			// Enable Transparent Header.
			$options['setting']['bloghash_sticky_header'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_toggle',
				'control'           => array(
					'type'    => 'bloghash-toggle',
					'label'   => esc_html__( 'Enable Sticky Header', 'bloghash' ),
					'section' => 'bloghash_section_sticky_header',
				),
			);

			return $options;
		}
	}
endif;
new Bloghash_Customizer_Sticky_Header();
