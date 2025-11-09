<?php
/**
 * Bloghash Featured Links Section Settings section in Customizer.
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

if ( ! class_exists( 'Bloghash_Customizer_Featured_Links' ) ) :
	/**
	 * Bloghash Page Title Settings section in Customizer.
	 */
	class Bloghash_Customizer_Featured_Links {

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

			// Featured links Section.
			$options['section']['bloghash_section_featured_links'] = array(
				'title'    => esc_html__( 'Featured Items', 'bloghash' ),
				'priority' => 4,
			);

			// Featured links enable.
			$options['setting']['bloghash_enable_featured_links'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_toggle',
				'control'           => array(
					'type'    => 'bloghash-toggle',
					'section' => 'bloghash_section_featured_links',
					'label'   => esc_html__( 'Enable featured items section', 'bloghash' ),
				),
			);

			// Title.
			$options['setting']['bloghash_featured_links_title'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'sanitize_text_field',
				'control'           => array(
					'type'     => 'bloghash-text',
					'section'  => 'bloghash_section_featured_links',
					'label'    => esc_html__( 'Title', 'bloghash' ),
					'required' => array(
						array(
							'control'  => 'bloghash_enable_featured_links',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
			);

			$options['setting']['bloghash_featured_links'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_repeater_sanitize',
				'control'           => array(
					'type'         => 'bloghash-repeater',
					'label'        => esc_html__( 'Featured Items', 'bloghash' ),
					'section'      => 'bloghash_section_featured_links',
					'item_name'    => esc_html__( 'Featured Link', 'bloghash' ),
					'title_format' => esc_html__( '[live_title]', 'bloghash' ), // [live_title]
					'add_text'     => esc_html__( 'Add new Feature', 'bloghash' ),
					'max_item'     => 3, // 3 Maximum item can add,
					'limited_msg'  => wp_kses_post( __( 'Upgrade to <a target="_blank" href="https://peregrine-themes.com/bloghash/">BlogHash Pro</a> to be able to add more items and unlock other premium features!', 'bloghash' ) ),
					'fields'       => array(
						'link'  => array(
							'title' => esc_html__( 'Select feature link', 'bloghash' ),
							'type'  => 'link',
						),

						'image' => array(
							'title' => esc_html__( 'Image', 'bloghash' ),
							'type'  => 'media',
						),
					),
					'required'     => array(
						array(
							'control'  => 'bloghash_enable_featured_links',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
				'partial'           => array(
					'selector'            => '#featured_links',
					'render_callback'     => 'bloghash_blog_featured_links',
					'container_inclusive' => true,
					'fallback_refresh'    => true,
				),
			);

			// Featured links display on.
			$options['setting']['bloghash_featured_links_enable_on'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_no_sanitize',
				'control'           => array(
					'type'        => 'bloghash-checkbox-group',
					'label'       => esc_html__( 'Enable On: ', 'bloghash' ),
					'description' => esc_html__( 'Choose on which pages you want to enable Featured links. ', 'bloghash' ),
					'section'     => 'bloghash_section_featured_links',
					'choices'     => array(
						'home'       => array(
							'title' => esc_html__( 'Home Page', 'bloghash' ),
						),
						'posts_page' => array(
							'title' => esc_html__( 'Blog / Posts Page', 'bloghash' ),
						),
					),
					'required'    => array(
						array(
							'control'  => 'bloghash_enable_featured_links',
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
new Bloghash_Customizer_Featured_Links();
