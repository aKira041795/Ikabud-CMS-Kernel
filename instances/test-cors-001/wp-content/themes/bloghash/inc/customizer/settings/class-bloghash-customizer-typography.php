<?php
/**
 * Bloghash Base Typography section in Customizer.
 *
 * @package BlogHash
 * @author Peregrine Themes
 * @since   1.0.0
 */

/**
 * Do not allow direct script access.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Bloghash_Customizer_Typography' ) ) :
	/**
	 * Bloghash Typography section in Customizer.
	 */
	class Bloghash_Customizer_Typography {

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
			$options['section']['bloghash_section_typography'] = array(
				'title'    => esc_html__( 'Base Typography', 'bloghash' ),
				'panel'    => 'bloghash_panel_general',
				'priority' => 30,
			);

			// Body Font.
			$options['setting']['bloghash_body_font'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_typography',
				'control'           => array(
					'type'    => 'bloghash-typography',
					'label'   => esc_html__( 'Body Typography', 'bloghash' ),
					'section' => 'bloghash_section_typography',
					'display' => array(
						'font-family'     => array(),
						'font-subsets'    => array(),
						'font-weight'     => array(),
						'font-style'      => array(),
						'text-transform'  => array(),
						'text-decoration' => array(),
						'letter-spacing'  => array(),
						'font-size'       => array(),
						'line-height'     => array(),
					),
				),
			);

			return $options;
		}

	}
endif;
new Bloghash_Customizer_Typography();
