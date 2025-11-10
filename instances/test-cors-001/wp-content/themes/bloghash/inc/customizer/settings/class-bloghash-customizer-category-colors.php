<?php
/**
 * Bloghash Category Colors section in Customizer.
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

if ( ! class_exists( 'Bloghash_Customizer_Category_Colors' ) ) :
	/**
	 * Bloghash Colors section in Customizer.
	 */
	class Bloghash_Customizer_Category_Colors {

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
			$options['section']['bloghash_section_category_colors'] = array(
				'title'    => esc_html__( 'Post Category Colors', 'bloghash' ),
				'panel'    => 'bloghash_panel_general',
				'priority' => 21,
			);

			// Category color.
			$categories = get_categories( array( 'hide_empty' => 1 ) );
			foreach ( $categories as $category ) {
				$options['setting'][ 'bloghash_category_color_' . esc_attr( $category->term_id ) ] = array(
					'transport'         => 'refresh',
					'sanitize_callback' => 'bloghash_sanitize_color',
					'control'           => array(
						'type'     => 'bloghash-color',
						'label'    => sprintf( esc_html__( '%1$s Color', 'bloghash' ), esc_html( $category->name ) ),
						'section'  => 'bloghash_section_category_colors',
						'priority' => 10,
						'opacity'  => false,
					),
				);
			}

			return $options;
		}

	}
endif;
new Bloghash_Customizer_Category_Colors();
