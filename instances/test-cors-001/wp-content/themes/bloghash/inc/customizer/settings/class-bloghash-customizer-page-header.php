<?php
/**
 * Bloghash Page Title Settings section in Customizer.
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

if ( ! class_exists( 'Bloghash_Customizer_Page_Header' ) ) :
	/**
	 * Bloghash Page Title Settings section in Customizer.
	 */
	class Bloghash_Customizer_Page_Header {

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

			// Page Title Section.
			$options['section']['bloghash_section_page_header'] = array(
				'title'    => esc_html__( 'Page Header', 'bloghash' ),
				'panel'    => 'bloghash_panel_header',
				'priority' => 60,
			);

			// Page Header enable.
			$options['setting']['bloghash_page_header_enable'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_toggle',
				'control'           => array(
					'type'    => 'bloghash-toggle',
					'label'   => esc_html__( 'Enable Page Header', 'bloghash' ),
					'section' => 'bloghash_section_page_header',
				),
			);

			// Spacing.
			$options['setting']['bloghash_page_header_spacing'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_responsive',
				'control'           => array(
					'type'        => 'bloghash-spacing',
					'label'       => esc_html__( 'Page Title Spacing', 'bloghash' ),
					'description' => esc_html__( 'Specify Page Title top and bottom padding.', 'bloghash' ),
					'section'     => 'bloghash_section_page_header',
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
							'control'  => 'bloghash_page_header_enable',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
			);

			// Page Header design options heading.
			$options['setting']['bloghash_page_header_heading_design'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_toggle',
				'control'           => array(
					'type'     => 'bloghash-heading',
					'label'    => esc_html__( 'Design Options', 'bloghash' ),
					'section'  => 'bloghash_section_page_header',
					'required' => array(
						array(
							'control'  => 'bloghash_page_header_enable',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
			);

			// Page Header background design.
			$options['setting']['bloghash_page_header_background'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_design_options',
				'control'           => array(
					'type'     => 'bloghash-design-options',
					'label'    => esc_html__( 'Background', 'bloghash' ),
					'section'  => 'bloghash_section_page_header',
					'display'  => array(
						'background' => array(
							'color'    => esc_html__( 'Solid Color', 'bloghash' ),
							'gradient' => esc_html__( 'Gradient', 'bloghash' ),
						),
					),
					'required' => array(
						array(
							'control'  => 'bloghash_page_header_enable',
							'value'    => true,
							'operator' => '==',
						),
						array(
							'control'  => 'bloghash_page_header_heading_design',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
			);

			// Page Header Text Color.
			$options['setting']['bloghash_page_header_text_color'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_design_options',
				'control'           => array(
					'type'     => 'bloghash-design-options',
					'label'    => esc_html__( 'Font Color', 'bloghash' ),
					'section'  => 'bloghash_section_page_header',
					'display'  => array(
						'color' => array(
							'text-color'       => esc_html__( 'Text Color', 'bloghash' ),
							'link-color'       => esc_html__( 'Link Color', 'bloghash' ),
							'link-hover-color' => esc_html__( 'Link Hover Color', 'bloghash' ),
						),
					),
					'required' => array(
						array(
							'control'  => 'bloghash_page_header_enable',
							'value'    => true,
							'operator' => '==',
						),
						array(
							'control'  => 'bloghash_page_header_heading_design',
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
new Bloghash_Customizer_Page_Header();
