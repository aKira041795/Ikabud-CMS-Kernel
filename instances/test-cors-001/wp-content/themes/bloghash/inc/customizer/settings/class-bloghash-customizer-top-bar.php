<?php
/**
 * Bloghash Top Bar Settings section in Customizer.
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

if ( ! class_exists( 'Bloghash_Customizer_Top_Bar' ) ) :
	/**
	 * Bloghash Top Bar Settings section in Customizer.
	 */
	class Bloghash_Customizer_Top_Bar {

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
			$options['section']['bloghash_section_top_bar'] = array(
				'title'    => esc_html__( 'Top Bar', 'bloghash' ),
				'panel'    => 'bloghash_panel_header',
				'priority' => 10,
			);

			// Enable Top Bar.
			$options['setting']['bloghash_top_bar_enable'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_toggle',
				'control'           => array(
					'type'        => 'bloghash-toggle',
					'label'       => esc_html__( 'Enable Top Bar', 'bloghash' ),
					'description' => esc_html__( 'Top Bar is a section with widgets located above Main Header area.', 'bloghash' ),
					'section'     => 'bloghash_section_top_bar',
				),
			);

			// Top Bar widgets heading.
			$options['setting']['bloghash_top_bar_heading_widgets'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_toggle',
				'control'           => array(
					'type'        => 'bloghash-heading',
					'label'       => esc_html__( 'Top Bar Widgets', 'bloghash' ),
					'description' => esc_html__( 'Click the Add Widget button to add available widgets to your Top Bar.', 'bloghash' ),
					'section'     => 'bloghash_section_top_bar',
					'required'    => array(
						array(
							'control'  => 'bloghash_top_bar_enable',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
			);

			// Top Bar widgets.
			$options['setting']['bloghash_top_bar_widgets'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_widget',
				'control'           => array(
					'type'       => 'bloghash-widget',
					'label'      => esc_html__( 'Top Bar Widgets', 'bloghash' ),
					'section'    => 'bloghash_section_top_bar',
					'widgets'    => array(
						'text'    => array(
							'max_uses' => 2,
						),
						'nav'     => array(
							'max_uses' => 1,
						),
					),
					'locations'  => array(
						'left'  => esc_html__( 'Left', 'bloghash' ),
						'right' => esc_html__( 'Right', 'bloghash' ),
					),
					'visibility' => array(
						'all'                => esc_html__( 'Show on All Devices', 'bloghash' ),
						'hide-mobile'        => esc_html__( 'Hide on Mobile', 'bloghash' ),
						'hide-tablet'        => esc_html__( 'Hide on Tablet', 'bloghash' ),
						'hide-mobile-tablet' => esc_html__( 'Hide on Mobile and Tablet', 'bloghash' ),
					),
					'required'   => array(
						array(
							'control'  => 'bloghash_top_bar_heading_widgets',
							'value'    => true,
							'operator' => '==',
						),
						array(
							'control'  => 'bloghash_top_bar_enable',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
				'partial'           => array(
					'selector'            => '#bloghash-topbar',
					'render_callback'     => 'bloghash_topbar_output',
					'container_inclusive' => true,
					'fallback_refresh'    => true,
				),
			);

			// Top Bar design options heading.
			$options['setting']['bloghash_top_bar_heading_design_options'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_toggle',
				'control'           => array(
					'type'     => 'bloghash-heading',
					'label'    => esc_html__( 'Design Options', 'bloghash' ),
					'section'  => 'bloghash_section_top_bar',
					'required' => array(
						array(
							'control'  => 'bloghash_top_bar_enable',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
			);

			// Top Bar Background.
			$options['setting']['bloghash_top_bar_background'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_design_options',
				'control'           => array(
					'type'     => 'bloghash-design-options',
					'label'    => esc_html__( 'Background', 'bloghash' ),
					'section'  => 'bloghash_section_top_bar',
					'display'  => array(
						'background' => array(
							'color'    => esc_html__( 'Solid Color', 'bloghash' ),
							'gradient' => esc_html__( 'Gradient', 'bloghash' ),
						),
					),
					'required' => array(
						array(
							'control'  => 'bloghash_top_bar_enable',
							'value'    => true,
							'operator' => '==',
						),
						array(
							'control'  => 'bloghash_top_bar_heading_design_options',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
			);

			// Top Bar Text Color.
			$options['setting']['bloghash_top_bar_text_color'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_design_options',
				'control'           => array(
					'type'     => 'bloghash-design-options',
					'label'    => esc_html__( 'Font Color', 'bloghash' ),
					'section'  => 'bloghash_section_top_bar',
					'display'  => array(
						'color' => array(
							'text-color'       => esc_html__( 'Text Color', 'bloghash' ),
							'link-color'       => esc_html__( 'Link Color', 'bloghash' ),
							'link-hover-color' => esc_html__( 'Link Hover Color', 'bloghash' ),
						),
					),
					'required' => array(
						array(
							'control'  => 'bloghash_top_bar_enable',
							'value'    => true,
							'operator' => '==',
						),
						array(
							'control'  => 'bloghash_top_bar_heading_design_options',
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
new Bloghash_Customizer_Top_Bar();
