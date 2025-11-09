<?php
/**
 * Bloghash Layout section in Customizer.
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

if ( ! class_exists( 'Bloghash_Customizer_Layout' ) ) :
	/**
	 * Bloghash Layout section in Customizer.
	 */
	class Bloghash_Customizer_Layout {

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
			$options['section']['bloghash_layout_section'] = array(
				'title'    => esc_html__( 'Layout', 'bloghash' ),
				'panel'    => 'bloghash_panel_general',
				'priority' => 10,
			);

			// Site layout.
			$options['setting']['bloghash_site_layout'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_select',
				'control'           => array(
					'type'        => 'bloghash-select',
					'section'     => 'bloghash_layout_section',
					'label'       => esc_html__( 'Site Layout', 'bloghash' ),
					'description' => esc_html__( 'Choose your site&rsquo;s main layout.', 'bloghash' ),
					'choices'     => array(
						'fw-contained' => esc_html__( 'Full Width: Contained', 'bloghash' ),
						'fw-stretched' => esc_html__( 'Full Width: Stretched', 'bloghash' ),
					),
				),
			);

			return $options;
		}
	}
endif;
new Bloghash_Customizer_Layout();
