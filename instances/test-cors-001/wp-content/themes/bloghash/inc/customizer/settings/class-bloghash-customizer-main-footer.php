<?php
/**
 * Bloghash Main Footer section in Customizer.
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

if ( ! class_exists( 'Bloghash_Customizer_Main_Footer' ) ) :
	/**
	 * Bloghash Main Footer section in Customizer.
	 */
	class Bloghash_Customizer_Main_Footer {

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
			$options['section']['bloghash_section_main_footer'] = array(
				'title'    => esc_html__( 'Main Footer', 'bloghash' ),
				'panel'    => 'bloghash_panel_footer',
				'priority' => 20,
			);

			// Enable Footer.
			$options['setting']['bloghash_enable_footer'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_toggle',
				'control'           => array(
					'type'    => 'bloghash-toggle',
					'label'   => esc_html__( 'Enable Main Footer', 'bloghash' ),
					'section' => 'bloghash_section_main_footer',
				),
			);

			// Footer Layout.
			$options['setting']['bloghash_footer_layout'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_select',
				'control'           => array(
					'type'        => 'bloghash-radio-image',
					'label'       => esc_html__( 'Column Layout', 'bloghash' ),
					'description' => esc_html__( 'Choose your site&rsquo;s footer column layout.', 'bloghash' ),
					'section'     => 'bloghash_section_main_footer',
					'choices'     => array(
						'layout-2' => array(
							'image' => BLOGHASH_THEME_URI . '/inc/customizer/assets/images/footer-layout-2.svg',
							'title' => esc_html__( '1/3 + 1/3 + 1/3', 'bloghash' ),
						),
						'layout-8' => array(
							'image' => BLOGHASH_THEME_URI . '/inc/customizer/assets/images/footer-layout-8.svg',
							'title' => esc_html__( '1', 'bloghash' ),
						),
					),
					'required'    => array(
						array(
							'control'  => 'bloghash_enable_footer',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
				'partial'           => array(
					'selector'            => '#bloghash-footer-widgets',
					'render_callback'     => 'bloghash_footer_widgets',
					'container_inclusive' => false,
					'fallback_refresh'    => true,
				),
			);

			// Center footer widgets..
			$options['setting']['bloghash_footer_widgets_align_center'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_toggle',
				'control'           => array(
					'type'     => 'bloghash-toggle',
					'label'    => esc_html__( 'Center Widget Content', 'bloghash' ),
					'section'  => 'bloghash_section_main_footer',
					'required' => array(
						array(
							'control'  => 'bloghash_enable_footer',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
				'partial'           => array(
					'selector'            => '#bloghash-footer-widgets',
					'render_callback'     => 'bloghash_footer_widgets',
					'container_inclusive' => false,
					'fallback_refresh'    => true,
				),
			);

			// Footer Design Options heading.
			$options['setting']['bloghash_footer_heading_design_options'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_toggle',
				'control'           => array(
					'type'     => 'bloghash-heading',
					'label'    => esc_html__( 'Design Options', 'bloghash' ),
					'section'  => 'bloghash_section_main_footer',
					'required' => array(
						array(
							'control'  => 'bloghash_enable_footer',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
			);

			// Footer Background.
			$options['setting']['bloghash_footer_background'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_design_options',
				'control'           => array(
					'type'     => 'bloghash-design-options',
					'label'    => esc_html__( 'Background', 'bloghash' ),
					'section'  => 'bloghash_section_main_footer',
					'display'  => array(
						'background' => array(
							'color'    => esc_html__( 'Solid Color', 'bloghash' ),
							'gradient' => esc_html__( 'Gradient', 'bloghash' ),
						),
					),
					'required' => array(
						array(
							'control'  => 'bloghash_enable_footer',
							'value'    => true,
							'operator' => '==',
						),
						array(
							'control'  => 'bloghash_footer_heading_design_options',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
			);

			// Footer Text Color.
			$options['setting']['bloghash_footer_text_color'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_design_options',
				'control'           => array(
					'type'     => 'bloghash-design-options',
					'label'    => esc_html__( 'Font Color', 'bloghash' ),
					'section'  => 'bloghash_section_main_footer',
					'display'  => array(
						'color' => array(
							'text-color'         => esc_html__( 'Text Color', 'bloghash' ),
							'link-color'         => esc_html__( 'Link Color', 'bloghash' ),
							'link-hover-color'   => esc_html__( 'Link Hover Color', 'bloghash' ),
							'widget-title-color' => esc_html__( 'Widget Title Color', 'bloghash' ),
						),
					),
					'required' => array(
						array(
							'control'  => 'bloghash_enable_footer',
							'value'    => true,
							'operator' => '==',
						),
						array(
							'control'  => 'bloghash_footer_heading_design_options',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
			);

			// Footer Border.
			$options['setting']['bloghash_footer_border'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_design_options',
				'control'           => array(
					'type'     => 'bloghash-design-options',
					'label'    => esc_html__( 'Border', 'bloghash' ),
					'section'  => 'bloghash_section_main_footer',
					'display'  => array(
						'border' => array(
							'style'     => esc_html__( 'Style', 'bloghash' ),
							'color'     => esc_html__( 'Color', 'bloghash' ),
							'width'     => esc_html__( 'Width (px)', 'bloghash' ),
							'positions' => array(
								'top'    => esc_html__( 'Top', 'bloghash' ),
								'bottom' => esc_html__( 'Bottom', 'bloghash' ),
							),
						),
					),
					'required' => array(
						array(
							'control'  => 'bloghash_enable_footer',
							'value'    => true,
							'operator' => '==',
						),
						array(
							'control'  => 'bloghash_footer_heading_design_options',
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
new Bloghash_Customizer_Main_Footer();
