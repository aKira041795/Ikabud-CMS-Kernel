<?php
/**
 * Bloghash Sidebar section in Customizer.
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

if ( ! class_exists( 'Bloghash_Customizer_Sidebar' ) ) :

	/**
	 * Bloghash Sidebar section in Customizer.
	 */
	class Bloghash_Customizer_Sidebar {

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
			$options['section']['bloghash_section_sidebar'] = array(
				'title'    => esc_html__( 'Sidebar', 'bloghash' ),
				'priority' => 3,
			);

			// Default sidebar position.
			$options['setting']['bloghash_sidebar_position'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_select',
				'control'           => array(
					'type'        => 'bloghash-select',
					'section'     => 'bloghash_section_sidebar',
					'label'       => esc_html__( 'Default Position', 'bloghash' ),
					'description' => esc_html__( 'Choose default sidebar position layout. You can change this setting per page via metabox settings.', 'bloghash' ),
					'choices'     => array(
						'no-sidebar'    => esc_html__( 'No Sidebar', 'bloghash' ),
						'left-sidebar'  => esc_html__( 'Left Sidebar', 'bloghash' ),
						'right-sidebar' => esc_html__( 'Right Sidebar', 'bloghash' ),
					),
				),
			);

			// Single post sidebar position.
			$options['setting']['bloghash_single_post_sidebar_position'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_select',
				'control'           => array(
					'type'        => 'bloghash-select',
					'label'       => esc_html__( 'Single Post', 'bloghash' ),
					'description' => esc_html__( 'Choose default sidebar position layout for single posts. You can change this setting per post via metabox settings.', 'bloghash' ),
					'section'     => 'bloghash_section_sidebar',
					'choices'     => array(
						'default'       => esc_html__( 'Default', 'bloghash' ),
						'no-sidebar'    => esc_html__( 'No Sidebar', 'bloghash' ),
						'left-sidebar'  => esc_html__( 'Left Sidebar', 'bloghash' ),
						'right-sidebar' => esc_html__( 'Right Sidebar', 'bloghash' ),
					),
				),
			);

			// Single page sidebar position.
			$options['setting']['bloghash_single_page_sidebar_position'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_select',
				'control'           => array(
					'type'        => 'bloghash-select',
					'label'       => esc_html__( 'Page', 'bloghash' ),
					'description' => esc_html__( 'Choose default sidebar position layout for pages. You can change this setting per page via metabox settings.', 'bloghash' ),
					'section'     => 'bloghash_section_sidebar',
					'choices'     => array(
						'default'       => esc_html__( 'Default', 'bloghash' ),
						'no-sidebar'    => esc_html__( 'No Sidebar', 'bloghash' ),
						'left-sidebar'  => esc_html__( 'Left Sidebar', 'bloghash' ),
						'right-sidebar' => esc_html__( 'Right Sidebar', 'bloghash' ),
					),
				),
			);

			// Archive sidebar position.
			$options['setting']['bloghash_archive_sidebar_position'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_select',
				'control'           => array(
					'type'        => 'bloghash-select',
					'label'       => esc_html__( 'Archives & Search', 'bloghash' ),
					'description' => esc_html__( 'Choose default sidebar position layout for archives and search results.', 'bloghash' ),
					'section'     => 'bloghash_section_sidebar',
					'choices'     => array(
						'default'       => esc_html__( 'Default', 'bloghash' ),
						'no-sidebar'    => esc_html__( 'No Sidebar', 'bloghash' ),
						'left-sidebar'  => esc_html__( 'Left Sidebar', 'bloghash' ),
						'right-sidebar' => esc_html__( 'Right Sidebar', 'bloghash' ),
					),
				),
			);

			// Sidebar options heading.
			$options['setting']['bloghash_sidebar_options_heading'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_toggle',
				'control'           => array(
					'type'    => 'bloghash-heading',
					'label'   => esc_html__( 'Options', 'bloghash' ),
					'section' => 'bloghash_section_sidebar',
				),
			);

			// Sidebar width.
			$options['setting']['bloghash_sidebar_width'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_range',
				'control'           => array(
					'type'        => 'bloghash-range',
					'section'     => 'bloghash_section_sidebar',
					'label'       => esc_html__( 'Sidebar Width', 'bloghash' ),
					'description' => esc_html__( 'Change your sidebar width.', 'bloghash' ),
					'min'         => 15,
					'max'         => 50,
					'step'        => 1,
					'unit'        => '%',
					'required'    => array(
						array(
							'control'  => 'bloghash_sidebar_options_heading',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
			);

			// Sticky sidebar.
			$options['setting']['bloghash_sidebar_sticky'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_select',
				'control'           => array(
					'type'        => 'bloghash-select',
					'section'     => 'bloghash_section_sidebar',
					'label'       => esc_html__( 'Sticky Sidebar', 'bloghash' ),
					'description' => esc_html__( 'Stick sidebar when scrolling.', 'bloghash' ),
					'choices'     => array(
						''        => esc_html__( 'Disable', 'bloghash' ),
						'sidebar' => esc_html__( 'Stick first widget', 'bloghash' ),
					),
					'required'    => array(
						array(
							'control'  => 'bloghash_sidebar_options_heading',
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

new Bloghash_Customizer_Sidebar();
