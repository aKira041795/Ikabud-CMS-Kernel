<?php
/**
 * Bloghash Ticker section in Customizer.
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

if ( ! class_exists( 'Bloghash_Customizer_Ticker' ) ) :
	/**
	 * Bloghash Ticker section in Customizer.
	 */
	class Bloghash_Customizer_Ticker {

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
			// Ticker News Section.
			$options['section']['bloghash_section_ticker'] = array(
				'title'    => esc_html__( 'Ticker News', 'bloghash' ),
				'priority' => 4,
			);

			// Ticker News enable.
			$options['setting']['bloghash_enable_ticker'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_toggle',
				'control'           => array(
					'type'    => 'bloghash-toggle',
					'section' => 'bloghash_section_ticker',
					'label'   => esc_html__( 'Enable Ticker News Section', 'bloghash' ),
				),
			);

			// Title.
			$options['setting']['bloghash_ticker_title'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'sanitize_text_field',
				'control'           => array(
					'type'     => 'bloghash-text',
					'section'  => 'bloghash_section_ticker',
					'label'    => esc_html__( 'Title', 'bloghash' ),
					'required' => array(
						array(
							'control'  => 'bloghash_enable_ticker',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
			);

			// Ticker News display on.
			$options['setting']['bloghash_ticker_enable_on'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_no_sanitize',
				'control'           => array(
					'type'        => 'bloghash-checkbox-group',
					'label'       => esc_html__( 'Enable On: ', 'bloghash' ),
					'description' => esc_html__( 'Choose on which pages you want to enable Ticker News. ', 'bloghash' ),
					'section'     => 'bloghash_section_ticker',
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
							'control'  => 'bloghash_enable_ticker',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
			);

			// Post Settings heading.
			$options['setting']['bloghash_ticker_posts'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_toggle',
				'control'           => array(
					'type'     => 'bloghash-heading',
					'section'  => 'bloghash_section_ticker',
					'label'    => esc_html__( 'Post Settings', 'bloghash' ),
					'required' => array(
						array(
							'control'  => 'bloghash_enable_ticker',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
			);

			// Post count.
			$options['setting']['bloghash_ticker_post_number'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_range',
				'control'           => array(
					'type'        => 'bloghash-range',
					'section'     => 'bloghash_section_ticker',
					'label'       => esc_html__( 'Post Number', 'bloghash' ),
					'description' => esc_html__( 'Set the number of visible posts.', 'bloghash' ),
					'min'         => 1,
					'max'         => 500,
					'step'        => 1,
					'unit'        => '',
					'required'    => array(
						array(
							'control'  => 'bloghash_enable_ticker',
							'value'    => true,
							'operator' => '==',
						),
						array(
							'control'  => 'bloghash_ticker_posts',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
			);

			// Post category.
			$options['setting']['bloghash_ticker_category'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_select',
				'control'           => array(
					'type'        => 'bloghash-select',
					'section'     => 'bloghash_section_ticker',
					'label'       => esc_html__( 'Category', 'bloghash' ),
					'description' => esc_html__( 'Display posts from selected category only. Leave empty to include all.', 'bloghash' ),
					'is_select2'  => true,
					'data_source' => 'category',
					'multiple'    => true,
					'required'    => array(
						array(
							'control'  => 'bloghash_enable_ticker',
							'value'    => true,
							'operator' => '==',
						),
						array(
							'control'  => 'bloghash_ticker_posts',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
			);

			// Ticker Slider Elements.
			$options['setting']['bloghash_ticker_elements'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_sortable',
				'control'           => array(
					'type'        => 'bloghash-sortable',
					'section'     => 'bloghash_section_ticker',
					'label'       => esc_html__( 'Post Elements', 'bloghash' ),
					'description' => esc_html__( 'Set order and visibility for post elements.', 'bloghash' ),
					'sortable'    => false,
					'choices'     => array(
						// 'thumbnail' => esc_html__( 'Thumbnail', 'bloghash' ),
						'meta' => esc_html__( 'Post Details', 'bloghash' ),
					),
					'required'    => array(
						array(
							'control'  => 'bloghash_enable_ticker',
							'value'    => true,
							'operator' => '==',
						),
						array(
							'control'  => 'bloghash_ticker_posts',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
				'partial'           => array(
					'selector'            => '#ticker',
					'render_callback'     => 'bloghash_blog_ticker',
					'container_inclusive' => true,
					'fallback_refresh'    => true,
				),
			);

			return $options;
		}
	}
endif;
new Bloghash_Customizer_Ticker();
