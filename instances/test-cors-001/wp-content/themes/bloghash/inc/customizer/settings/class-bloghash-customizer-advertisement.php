<?php
/**
 * Bloghash Advertisement Section Settings in Customizer.
 *
 * @package     BlogHash
 * @author      Peregrine Themes
 * @since       1.0.0
 */

/**
 * Do not allow direct script access.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Bloghash_Customizer_Advertisement' ) ) :
	/**
	 * Bloghash Page Title Settings section in Customizer.
	 */
	class Bloghash_Customizer_Advertisement {

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

			// Advertisement Section.
			$options['section']['bloghash_section_advertisement'] = array(
				'title'    => esc_html__( 'Advertisements', 'bloghash' ),
				'priority' => 4,
			);

			// Advertisement widgets.
			$options['setting']['bloghash_ad_widgets'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_widget',
				'control'           => array(
					'type'       => 'bloghash-widget',
					'label'      => esc_html__( 'Advertisement Widgets', 'bloghash' ),
					'section'    => 'bloghash_section_advertisement',
					'widgets'    => apply_filters(
						'bloghash_main_ad_widgets',
						array(
							'advertisements' => array(
								'max_uses'      => 2,
								'display_areas' => array(
									'before_header'        => esc_html__( 'Before Header', 'bloghash' ),
									'after_header'         => esc_html__( 'After Header', 'bloghash' ),
									'before_post_archive'  => esc_html__( 'Before post archive', 'bloghash' ),
									'random_post_archives' => esc_html__( 'Random post archives', 'bloghash' ),
									'before_post_content'  => esc_html__( 'Before post content', 'bloghash' ),
									'after_post_content'   => esc_html__( 'After post content', 'bloghash' ),
									'before_footer'        => esc_html__( 'Before footer', 'bloghash' ),
									'after_footer'         => esc_html__( 'After footer', 'bloghash' ),
								),
							),
						)
					),
					'visibility' => array(
						'all'                => esc_html__( 'Show on All Devices', 'bloghash' ),
						'hide-mobile'        => esc_html__( 'Hide on Mobile', 'bloghash' ),
						'hide-tablet'        => esc_html__( 'Hide on Tablet', 'bloghash' ),
						'hide-mobile-tablet' => esc_html__( 'Hide on Mobile and Tablet', 'bloghash' ),
					),
				),
			);
			return $options;
		}
	}
endif;
new Bloghash_Customizer_Advertisement();
