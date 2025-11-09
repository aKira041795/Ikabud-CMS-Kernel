<?php
/**
 * Bloghash Logo section in Customizer.
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

if ( ! class_exists( 'Bloghash_Customizer_Logo' ) ) :
	/**
	 * Bloghash Logo section in Customizer.
	 */
	class Bloghash_Customizer_Logo {

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

			// Logo Max Height.
			$options['setting']['bloghash_logo_max_height'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_responsive',
				'control'           => array(
					'type'        => 'bloghash-range',
					'label'       => esc_html__( 'Logo Height', 'bloghash' ),
					'description' => esc_html__( 'Maximum logo image height.', 'bloghash' ),
					'section'     => 'title_tagline',
					'priority'    => 30,
					'min'         => 0,
					'max'         => 1000,
					'step'        => 10,
					'unit'        => 'px',
					'responsive'  => true,
					'required'    => array(
						array(
							'control'  => 'custom_logo',
							'value'    => false,
							'operator' => '!=',
						),
					),
				),
			);

			// Logo margin.
			$options['setting']['bloghash_logo_margin'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_responsive',
				'control'           => array(
					'type'        => 'bloghash-spacing',
					'label'       => esc_html__( 'Logo Margin', 'bloghash' ),
					'description' => esc_html__( 'Specify spacing around logo. Negative values are allowed.', 'bloghash' ),
					'section'     => 'title_tagline',
					'settings'    => 'bloghash_logo_margin',
					'priority'    => 40,
					'choices'     => array(
						'top'    => esc_html__( 'Top', 'bloghash' ),
						'right'  => esc_html__( 'Right', 'bloghash' ),
						'bottom' => esc_html__( 'Bottom', 'bloghash' ),
						'left'   => esc_html__( 'Left', 'bloghash' ),
					),
					'responsive'  => true,
					'unit'        => array(
						'px',
					),
				),
			);

			// Show tagline.
			$options['setting']['bloghash_display_tagline'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_toggle',
				'control'           => array(
					'type'     => 'bloghash-toggle',
					'label'    => esc_html__( 'Display Tagline', 'bloghash' ),
					'section'  => 'title_tagline',
					'settings' => 'bloghash_display_tagline',
					'priority' => 80,
				),
				'partial'           => array(
					'selector'            => '.bloghash-logo',
					'render_callback'     => 'bloghash_logo',
					'container_inclusive' => false,
					'fallback_refresh'    => true,
				),
			);

			// Site Identity heading.
			$options['setting']['bloghash_logo_heading_site_identity'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_toggle',
				'control'           => array(
					'type'     => 'bloghash-heading',
					'label'    => esc_html__( 'Site Identity', 'bloghash' ),
					'section'  => 'title_tagline',
					'settings' => 'bloghash_logo_heading_site_identity',
					'priority' => 50,
					'toggle'   => false,
				),
			);

			// Logo typography heading.
			$options['setting']['bloghash_typography_logo_heading'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_toggle',
				'control'           => array(
					'type'     => 'bloghash-heading',
					'label'    => esc_html__( 'Typography', 'bloghash' ),
					'section'  => 'title_tagline',
					'priority' => 100,
					'required' => array(
						array(
							'control'  => 'custom_logo',
							'value'    => false,
							'operator' => '==',
						),
					),
				),
			);

			// Site title font size.
			$options['setting']['bloghash_logo_text_font_size'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_responsive',
				'control'           => array(
					'type'       => 'bloghash-range',
					'label'      => esc_html__( 'Site Title Font Size', 'bloghash' ),
					'section'    => 'title_tagline',
					'priority'   => 100,
					'min'        => 8,
					'max'        => 30,
					'step'       => 1,
					'responsive' => true,
					'unit'       => array(
						array(
							'id'   => 'px',
							'name' => 'px',
							'min'  => 8,
							'max'  => 90,
							'step' => 1,
						),
						array(
							'id'   => 'em',
							'name' => 'em',
							'min'  => 0.5,
							'max'  => 5,
							'step' => 0.01,
						),
						array(
							'id'   => 'rem',
							'name' => 'rem',
							'min'  => 0.5,
							'max'  => 5,
							'step' => 0.01,
						),
					),
					'required'   => array(
						array(
							'control'  => 'custom_logo',
							'value'    => false,
							'operator' => '==',
						),
						array(
							'control'  => 'bloghash_typography_logo_heading',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
			);

			return $options;
		}
	}
endif;
new Bloghash_Customizer_Logo();
