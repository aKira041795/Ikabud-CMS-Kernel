<?php
/**
 * Bloghash Base Colors section in Customizer.
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

if ( ! class_exists( 'Bloghash_Customizer_Colors' ) ) :
	/**
	 * Bloghash Colors section in Customizer.
	 */
	class Bloghash_Customizer_Colors {

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
			$options['section']['bloghash_section_colors'] = array(
				'title'    => esc_html__( 'Base Colors', 'bloghash' ),
				'panel'    => 'bloghash_panel_general',
				'priority' => 20,
			);

			// Accent color.
			$options['setting']['bloghash_accent_color'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_color',
				'control'           => array(
					'type'        => 'bloghash-color',
					'label'       => esc_html__( 'Accent Color', 'bloghash' ),
					'description' => esc_html__( 'The accent color is used subtly throughout your site, to call attention to key elements.', 'bloghash' ),
					'section'     => 'bloghash_section_colors',
					'priority'    => 10,
					'opacity'     => false,
				),
			);

			// Dark mode
			$options['setting']['bloghash_dark_mode'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_toggle',
				'control'           => array(
					'type'        => 'bloghash-toggle',
					'label'       => esc_html__( 'Dark mode', 'bloghash' ),
					'description' => esc_html__( 'Enable dark mode.', 'bloghash' ),
					'section'     => 'bloghash_section_colors',
					'priority'    => 11,
				),
			);

			// Body Animation
			$options['setting']['bloghash_body_animation'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_select',
				'control'           => array(
					'type'        => 'bloghash-select',
					'label'       => esc_html__( 'Body Animation', 'bloghash' ),
					'description' => esc_html__( 'Choose Body Animation.', 'bloghash' ),
					'section'     => 'bloghash_section_colors',
					'priority'    => 12,
					'choices'     => array(
						'0' => esc_html__( 'None', 'bloghash' ),
						'1' => esc_html__( 'Glassmorphism', 'bloghash' ),
					),
				),
			);

			// Body background heading.
			$options['setting']['bloghash_body_background_heading'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_toggle',
				'control'           => array(
					'type'     => 'bloghash-heading',
					'priority' => 40,
					'label'    => esc_html__( 'Body Background', 'bloghash' ),
					'section'  => 'bloghash_section_colors',
					'toggle'   => false,
				),
			);

			return $options;
		}

	}
endif;
new Bloghash_Customizer_Colors();
