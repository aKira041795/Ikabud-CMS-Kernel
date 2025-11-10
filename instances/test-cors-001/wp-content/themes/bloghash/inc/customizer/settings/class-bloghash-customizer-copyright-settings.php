<?php
/**
 * Bloghash Copyright Bar section in Customizer.
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

if ( ! class_exists( 'Bloghash_Customizer_Copyright_Settings' ) ) :
	/**
	 * Bloghash Copyright Bar section in Customizer.
	 */
	class Bloghash_Customizer_Copyright_Settings {

		/**
		 * Primary class constructor.
		 *
		 * @since 1.0.0
		 */
		public function __construct() {

			// Registers our custom options in Customizer.
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
			$options['section']['bloghash_section_copyright_bar'] = array(
				'title'    => esc_html__( 'Copyright Bar', 'bloghash' ),
				'priority' => 30,
				'panel'    => 'bloghash_panel_footer',
			);

			// Enable Copyright Bar.
			$options['setting']['bloghash_enable_copyright'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_toggle',
				'control'           => array(
					'type'    => 'bloghash-toggle',
					'label'   => esc_html__( 'Enable Copyright Bar', 'bloghash' ),
					'section' => 'bloghash_section_copyright_bar',
				),
			);

			// Copyright Layout.
			$options['setting']['bloghash_copyright_layout'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_select',
				'control'           => array(
					'type'        => 'bloghash-radio-image',
					'section'     => 'bloghash_section_copyright_bar',
					'label'       => esc_html__( 'Copyright Layout', 'bloghash' ),
					'description' => esc_html__( 'Choose your site&rsquo;s copyright widgets layout.', 'bloghash' ),
					'choices'     => array(
						'layout-1' => array(
							'image' => BLOGHASH_THEME_URI . '/inc/customizer/assets/images/copyright-layout-1.svg',
							'title' => esc_html__( 'Centered', 'bloghash' ),
						),
					),
					'required'    => array(
						array(
							'control'  => 'bloghash_enable_copyright',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
			);

			// Copyright widgets heading.
			$options['setting']['bloghash_copyright_heading_widgets'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_toggle',
				'control'           => array(
					'type'        => 'bloghash-heading',
					'section'     => 'bloghash_section_copyright_bar',
					'label'       => esc_html__( 'Copyright Bar Widgets', 'bloghash' ),
					'description' => esc_html__( 'Click the Add Widget button to add available widgets to your Copyright Bar.', 'bloghash' ),
					'required'    => array(
						array(
							'control'  => 'bloghash_enable_copyright',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
			);

			// Copyright widgets.
			$options['setting']['bloghash_copyright_widgets'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_widget',
				'control'           => array(
					'type'       => 'bloghash-widget',
					'section'    => 'bloghash_section_copyright_bar',
					'label'      => esc_html__( 'Copyright Bar Widgets', 'bloghash' ),
					'widgets'    => array(
						'text'    => array(
							'max_uses' => 1,
						),
						'nav'     => array(
							'menu_location' => apply_filters( 'bloghash_footer_menu_location', 'bloghash-footer' ),
							'max_uses'      => 1,
						),
						'socials' => array(
							'max_uses' => 1,
							'styles'   => array(
								'minimal' => esc_html__( 'Minimal', 'bloghash' ),
								'rounded' => esc_html__( 'Rounded', 'bloghash' ),
							),
						),
					),
					'locations'  => array(
						'start' => esc_html__( 'Start', 'bloghash' ),
						'end'   => esc_html__( 'End', 'bloghash' ),
					),
					'visibility' => array(
						'all'                => esc_html__( 'Show on All Devices', 'bloghash' ),
						'hide-mobile'        => esc_html__( 'Hide on Mobile', 'bloghash' ),
						'hide-tablet'        => esc_html__( 'Hide on Tablet', 'bloghash' ),
						'hide-mobile-tablet' => esc_html__( 'Hide on Mobile and Tablet', 'bloghash' ),
					),
					'required'   => array(
						array(
							'control'  => 'bloghash_copyright_heading_widgets',
							'value'    => true,
							'operator' => '==',
						),
						array(
							'control'  => 'bloghash_enable_copyright',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
				'partial'           => array(
					'selector'            => '#bloghash-copyright',
					'render_callback'     => 'bloghash_copyright_bar_output',
					'container_inclusive' => true,
					'fallback_refresh'    => true,
				),
			);

			return $options;
		}

	}
endif;
new Bloghash_Customizer_Copyright_Settings();
