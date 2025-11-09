<?php
/**
 * Bloghash Breadcrumbs Settings section in Customizer.
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

if ( ! class_exists( 'Bloghash_Customizer_Breadcrumbs' ) ) :
	/**
	 * Bloghash Breadcrumbs Settings section in Customizer.
	 */
	class Bloghash_Customizer_Breadcrumbs {

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

			// Main Navigation Section.
			$options['section']['bloghash_section_breadcrumbs'] = array(
				'title'    => esc_html__( 'Breadcrumbs', 'bloghash' ),
				'panel'    => 'bloghash_panel_header',
				'priority' => 70,
			);

			// Breadcrumbs.
			$options['setting']['bloghash_breadcrumbs_enable'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_toggle',
				'control'           => array(
					'type'    => 'bloghash-toggle',
					'label'   => esc_html__( 'Enable Breadcrumbs', 'bloghash' ),
					'section' => 'bloghash_section_breadcrumbs',
				),
			);

			// Hide breadcrumbs on.
			$options['setting']['bloghash_breadcrumbs_hide_on'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_no_sanitize',
				'control'           => array(
					'type'        => 'bloghash-checkbox-group',
					'label'       => esc_html__( 'Disable On: ', 'bloghash' ),
					'description' => esc_html__( 'Choose on which pages you want to disable breadcrumbs. ', 'bloghash' ),
					'section'     => 'bloghash_section_breadcrumbs',
					'choices'     => bloghash_get_display_choices(),
					'required'    => array(
						array(
							'control'  => 'bloghash_breadcrumbs_enable',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
			);

			// Spacing.
			$options['setting']['bloghash_breadcrumbs_spacing'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_responsive',
				'control'           => array(
					'type'        => 'bloghash-spacing',
					'label'       => esc_html__( 'Spacing', 'bloghash' ),
					'description' => esc_html__( 'Specify top and bottom padding.', 'bloghash' ),
					'section'     => 'bloghash_section_breadcrumbs',
					'choices'     => array(
						'top'    => esc_html__( 'Top', 'bloghash' ),
						'bottom' => esc_html__( 'Bottom', 'bloghash' ),
					),
					'responsive'  => true,
					'unit'        => array(
						'px',
					),
					'required'    => array(
						array(
							'control'  => 'bloghash_breadcrumbs_enable',
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
new Bloghash_Customizer_Breadcrumbs();
