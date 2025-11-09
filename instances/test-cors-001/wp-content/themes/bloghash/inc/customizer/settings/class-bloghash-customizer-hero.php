<?php
/**
 * Bloghash Hero Section Settings section in Customizer.
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

if ( ! class_exists( 'Bloghash_Customizer_Hero' ) ) :
	/**
	 * Bloghash Page Title Settings section in Customizer.
	 */
	class Bloghash_Customizer_Hero {

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

			// Hero Section.
			$options['section']['bloghash_section_hero'] = array(
				'title'    => esc_html__( 'Hero', 'bloghash' ),
				'priority' => 4,
			);

			// Hero enable.
			$options['setting']['bloghash_enable_hero'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_toggle',
				'control'           => array(
					'type'    => 'bloghash-toggle',
					'section' => 'bloghash_section_hero',
					'label'   => esc_html__( 'Enable Hero Section', 'bloghash' ),
				),
			);

			// Hero display on.
			$options['setting']['bloghash_hero_enable_on'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_no_sanitize',
				'control'           => array(
					'type'        => 'bloghash-checkbox-group',
					'label'       => esc_html__( 'Enable On: ', 'bloghash' ),
					'description' => esc_html__( 'Choose on which pages you want to enable Hero. ', 'bloghash' ),
					'section'     => 'bloghash_section_hero',
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
							'control'  => 'bloghash_enable_hero',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
			);

			// Hero Type.
			$options['setting']['bloghash_hero_type'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_select',
				'control'           => array(
					'type'        => 'bloghash-select',
					'section'     => 'bloghash_section_hero',
					'label'       => esc_html__( 'Type', 'bloghash' ),
					'description' => esc_html__( 'Choose hero style type.', 'bloghash' ),
					'choices'     => array(
						'horizontal-slider' => esc_html__( 'Slider Horizontal', 'bloghash' ),
					),
					'required'    => array(
						array(
							'control'  => 'bloghash_enable_hero',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
			);

			// Post Settings heading.
			$options['setting']['bloghash_hero_slider_posts'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_toggle',
				'control'           => array(
					'type'     => 'bloghash-heading',
					'section'  => 'bloghash_section_hero',
					'label'    => esc_html__( 'Post Settings', 'bloghash' ),
					'required' => array(
						array(
							'control'  => 'bloghash_enable_hero',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
			);

			// Post count.
			$options['setting']['bloghash_hero_slider_post_number'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_range',
				'control'           => array(
					'type'        => 'bloghash-range',
					'section'     => 'bloghash_section_hero',
					'label'       => esc_html__( 'Post Number', 'bloghash' ),
					'description' => esc_html__( 'Set the number of visible posts.', 'bloghash' ),
					'min'         => 1,
					'max'         => 50,
					'step'        => 1,
					'unit'        => '',
					'required'    => array(
						array(
							'control'  => 'bloghash_enable_hero',
							'value'    => true,
							'operator' => '==',
						),
						array(
							'control'  => 'bloghash_hero_slider_posts',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
				'partial'           => array(
					'selector'            => '#hero',
					'render_callback'     => 'bloghash_blog_hero',
					'container_inclusive' => true,
					'fallback_refresh'    => true,
				),
			);

			// Post category.
			$options['setting']['bloghash_hero_slider_category'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_select',
				'control'           => array(
					'type'        => 'bloghash-select',
					'section'     => 'bloghash_section_hero',
					'label'       => esc_html__( 'Category', 'bloghash' ),
					'description' => esc_html__( 'Display posts from selected category only. Leave empty to include all.', 'bloghash' ),
					'is_select2'  => true,
					'data_source' => 'category',
					'multiple'    => true,
					'required'    => array(
						array(
							'control'  => 'bloghash_enable_hero',
							'value'    => true,
							'operator' => '==',
						),
						array(
							'control'  => 'bloghash_hero_slider_posts',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
			);

			// Hero Slider heading.
			$options['setting']['bloghash_hero_slider'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_toggle',
				'control'           => array(
					'type'     => 'bloghash-heading',
					'section'  => 'bloghash_section_hero',
					'label'    => esc_html__( 'Style', 'bloghash' ),
					'required' => array(
						array(
							'control'  => 'bloghash_enable_hero',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
			);

			// Hero Slider Elements.
			$options['setting']['bloghash_hero_slider_elements'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_sortable',
				'control'           => array(
					'type'        => 'bloghash-sortable',
					'section'     => 'bloghash_section_hero',
					'label'       => esc_html__( 'Post Elements', 'bloghash' ),
					'description' => esc_html__( 'Set order and visibility for post elements.', 'bloghash' ),
					'sortable'    => false,
					'choices'     => array(
						'category'  => esc_html__( 'Categories', 'bloghash' ),
						'meta'      => esc_html__( 'Post Details', 'bloghash' ),
						'read_more' => esc_html__( 'Continue Reading', 'bloghash' ),
					),
					'required'    => array(
						array(
							'control'  => 'bloghash_enable_hero',
							'value'    => true,
							'operator' => '==',
						),
						array(
							'control'  => 'bloghash_hero_slider',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
				'partial'           => array(
					'selector'            => '#hero',
					'render_callback'     => 'bloghash_blog_hero',
					'container_inclusive' => true,
					'fallback_refresh'    => true,
				),
			);

			// Hero Slider Meta/Post Details.
			$options['setting']['bloghash_hero_entry_meta_elements'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_sortable',
				'control'           => array(
					'type'        => 'bloghash-sortable',
					'section'     => 'bloghash_section_hero',
					'label'       => esc_html__( 'Post Meta', 'bloghash' ),
					'description' => esc_html__( 'Set order and visibility for post meta details.', 'bloghash' ),
					'choices'     => array(
						'author'   => esc_html__( 'Author', 'bloghash' ),
						'date'     => esc_html__( 'Publish Date', 'bloghash' ),
						'comments' => esc_html__( 'Comments', 'bloghash' ),
					),
					'required'    => array(
						array(
							'control'  => 'bloghash_enable_hero',
							'value'    => true,
							'operator' => '==',
						),
						array(
							'control'  => 'bloghash_hero_slider',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
				'partial'           => array(
					'selector'            => '#hero',
					'render_callback'     => 'bloghash_blog_hero',
					'container_inclusive' => true,
					'fallback_refresh'    => true,
				),
			);

			// Continue Reading.
			$options['setting']['bloghash_hero_slider_read_more'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'sanitize_text_field',
				'control'           => array(
					'type'        => 'bloghash-text',
					'section'     => 'bloghash_section_hero',
					'label'       => esc_html__( 'Continue Reading', 'bloghash' ),
					'description' => esc_html__( 'Change Continue Reading Text.', 'bloghash' ),
					'required'    => array(
						array(
							'control'  => 'bloghash_enable_hero',
							'value'    => true,
							'operator' => '==',
						),
						array(
							'control'  => 'bloghash_hero_slider',
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
new Bloghash_Customizer_Hero();
