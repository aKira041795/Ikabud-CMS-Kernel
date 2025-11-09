<?php
/**
 * Bloghash Main Header Settings section in Customizer.
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

if ( ! class_exists( 'Bloghash_Customizer_Main_Header' ) ) :
	/**
	 * Bloghash Main Header section in Customizer.
	 */
	class Bloghash_Customizer_Main_Header {

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

			// Main Header Section.
			$options['section']['bloghash_section_main_header'] = array(
				'title'    => esc_html__( 'Main Header', 'bloghash' ),
				'panel'    => 'bloghash_panel_header',
				'priority' => 20,
			);

			// Header Layout.
			$options['setting']['bloghash_header_layout'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_select',
				'control'           => array(
					'type'        => 'bloghash-radio-image',
					'label'       => esc_html__( 'Header Layout', 'bloghash' ),
					'description' => esc_html__( 'Pre-defined positions of header elements, such as logo and navigation.', 'bloghash' ),
					'section'     => 'bloghash_section_main_header',
					'priority'    => 5,
					'choices'     => array(
						'layout-1' => array(
							'image' => BLOGHASH_THEME_URI . '/inc/customizer/assets/images/header-layout-1.svg',
							'title' => esc_html__( 'Header 1', 'bloghash' ),
						),
					),
				),
			);

			// Header widgets heading.
			$options['setting']['bloghash_header_heading_widgets'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_toggle',
				'control'           => array(
					'type'        => 'bloghash-heading',
					'label'       => esc_html__( 'Header Widgets', 'bloghash' ),
					'description' => esc_html__( 'Click the "Add Widget" button to add available widgets to your Header. Click the down arrow icon to expand widget options.', 'bloghash' ),
					'section'     => 'bloghash_section_main_header',
					'space'       => true,
				),
			);

			// Header widgets.
			$options['setting']['bloghash_header_widgets'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_widget',
				'control'           => array(
					'type'       => 'bloghash-widget',
					'label'      => esc_html__( 'Header Widgets', 'bloghash' ),
					'section'    => 'bloghash_section_main_header',
					'widgets'    => apply_filters(
						'bloghash_main_header_widgets',
						array(
							'search'   => array(
								'max_uses' => 1,
							),
							'darkmode' => array(
								'max_uses' => 1,
							),
							'button'   => array(
								'max_uses' => 1,
							),
							'socials'  => array(
								'max_uses' => 1,
								'styles'   => array(
									'rounded-fill'   => esc_html__( 'Rounded Fill', 'bloghash' ),
									'rounded-border' => esc_html__( 'Rounded Border', 'bloghash' ),
								),
							),
						)
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
							'control'  => 'bloghash_header_heading_widgets',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
				'partial'           => array(
					'selector'            => '#bloghash-header',
					'render_callback'     => 'bloghash_header_content_output',
					'container_inclusive' => false,
					'fallback_refresh'    => true,
				),
			);

			return $options;
		}
	}
endif;
new Bloghash_Customizer_Main_Header();
