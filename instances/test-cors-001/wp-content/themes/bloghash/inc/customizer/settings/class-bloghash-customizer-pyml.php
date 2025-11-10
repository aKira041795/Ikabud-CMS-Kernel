<?php
/**
 * Bloghash PYML section in Customizer.
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

if ( ! class_exists( 'Bloghash_Customizer_PYML' ) ) :
	/**
	 * Bloghash PYML section in Customizer.
	 */
	class Bloghash_Customizer_PYML {

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
			// Posts You Might Like Section.
			$options['section']['bloghash_section_pyml'] = array(
				'title'    => esc_html__( 'Posts You Might Like', 'bloghash' ),
				'priority' => 5,
			);

			// Posts You Might Like enable.
			$options['setting']['bloghash_enable_pyml'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_toggle',
				'control'           => array(
					'type'    => 'bloghash-toggle',
					'section' => 'bloghash_section_pyml',
					'label'   => esc_html__( 'Enable Posts You Might Like Section', 'bloghash' ),
				),
			);

			// Title.
			$options['setting']['bloghash_pyml_title'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'sanitize_text_field',
				'control'           => array(
					'type'     => 'bloghash-text',
					'section'  => 'bloghash_section_pyml',
					'label'    => esc_html__( 'Title', 'bloghash' ),
					'required' => array(
						array(
							'control'  => 'bloghash_enable_pyml',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
			);

			// Posts You Might Like display on.
			$options['setting']['bloghash_pyml_enable_on'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_no_sanitize',
				'control'           => array(
					'type'        => 'bloghash-checkbox-group',
					'label'       => esc_html__( 'Enable On: ', 'bloghash' ),
					'description' => esc_html__( 'Choose on which pages you want to enable Posts You Might Like. ', 'bloghash' ),
					'section'     => 'bloghash_section_pyml',
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
							'control'  => 'bloghash_enable_pyml',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
			);

			// PYML heading.
			$options['setting']['bloghash_pyml_style'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_toggle',
				'control'           => array(
					'type'     => 'bloghash-heading',
					'section'  => 'bloghash_section_pyml',
					'label'    => esc_html__( 'Style', 'bloghash' ),
					'required' => array(
						array(
							'control'  => 'bloghash_enable_pyml',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
			);

			// PYML Elements.
			$options['setting']['bloghash_pyml_elements'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_sortable',
				'control'           => array(
					'type'        => 'bloghash-sortable',
					'section'     => 'bloghash_section_pyml',
					'label'       => esc_html__( 'Post Elements', 'bloghash' ),
					'description' => esc_html__( 'Set order and visibility for post elements.', 'bloghash' ),
					'sortable'    => false,
					'choices'     => array(
						'category' => esc_html__( 'Categories', 'bloghash' ),
						'meta'     => esc_html__( 'Post Details', 'bloghash' ),
					),
					'required'    => array(
						array(
							'control'  => 'bloghash_enable_pyml',
							'value'    => true,
							'operator' => '==',
						),
						array(
							'control'  => 'bloghash_pyml_style',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
				'partial'           => array(
					'selector'            => '#pyml',
					'render_callback'     => 'bloghash_blog_pyml',
					'container_inclusive' => true,
					'fallback_refresh'    => true,
				),
			);

			// Post Settings heading.
			$options['setting']['bloghash_pyml_posts'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_toggle',
				'control'           => array(
					'type'     => 'bloghash-heading',
					'section'  => 'bloghash_section_pyml',
					'label'    => esc_html__( 'Post Settings', 'bloghash' ),
					'required' => array(
						array(
							'control'  => 'bloghash_enable_pyml',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
			);

			// Post count.
			$options['setting']['bloghash_pyml_post_number'] = array(
				'transport'         => 'postMessage',
				'sanitize_callback' => 'bloghash_sanitize_range',
				'control'           => array(
					'type'        => 'bloghash-range',
					'section'     => 'bloghash_section_pyml',
					'label'       => esc_html__( 'Post Number', 'bloghash' ),
					'description' => esc_html__( 'Set the number of visible posts.', 'bloghash' ),
					'min'         => 1,
					'max'         => 4,
					'step'        => 1,
					'unit'        => '',
					'required'    => array(
						array(
							'control'  => 'bloghash_enable_pyml',
							'value'    => true,
							'operator' => '==',
						),
						array(
							'control'  => 'bloghash_pyml_posts',
							'value'    => true,
							'operator' => '==',
						),
					),
				),
				'partial'           => array(
					'selector'            => '#pyml',
					'render_callback'     => 'bloghash_blog_pyml',
					'container_inclusive' => true,
					'fallback_refresh'    => true,
				),
			);

			// Post category.
			$options['setting']['bloghash_pyml_category'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_select',
				'control'           => array(
					'type'        => 'bloghash-select',
					'section'     => 'bloghash_section_pyml',
					'label'       => esc_html__( 'Category', 'bloghash' ),
					'description' => esc_html__( 'Display posts from selected category only. Leave empty to include all.', 'bloghash' ),
					'is_select2'  => true,
					'data_source' => 'category',
					'multiple'    => true,
					'required'    => array(
						array(
							'control'  => 'bloghash_enable_pyml',
							'value'    => true,
							'operator' => '==',
						),
						array(
							'control'  => 'bloghash_pyml_posts',
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
new Bloghash_Customizer_PYML();
