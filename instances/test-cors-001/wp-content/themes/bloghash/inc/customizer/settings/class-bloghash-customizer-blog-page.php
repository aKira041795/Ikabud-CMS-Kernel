<?php
/**
 * Bloghash Blog » Blog Page / Archive section in Customizer.
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

if ( ! class_exists( 'Bloghash_Customizer_Blog_Page' ) ) :
	/**
	 * Bloghash Blog » Blog Page / Archive section in Customizer.
	 */
	class Bloghash_Customizer_Blog_Page {

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
			$options['section']['bloghash_section_blog_page'] = array(
				'title' => esc_html__( 'Blog Page / Archive', 'bloghash' ),
				'panel' => 'bloghash_panel_blog',
			);

			// Layout.
			$options['setting']['bloghash_blog_layout'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_select',
				'control'           => array(
					'type'        => 'bloghash-select',
					'label'       => esc_html__( 'Layout', 'bloghash' ),
					'description' => esc_html__( 'Choose blog layout.', 'bloghash' ),
					'section'     => 'bloghash_section_blog_page',
					'choices'     => array(
						'blog-horizontal' => esc_html__( 'Horizontal', 'bloghash' ),
					),
				),
			);

			$_image_sizes = bloghash_get_image_sizes();
			$size_choices = array();

			if ( ! empty( $_image_sizes ) ) {
				foreach ( $_image_sizes as $key => $value ) {
					$name = ucwords( str_replace( array( '-', '_' ), ' ', $key ) );

					$size_choices[ $key ] = $name;

					if ( $value['width'] || $value['height'] ) {
						$size_choices[ $key ] .= ' (' . $value['width'] . 'x' . $value['height'] . ')';
					}
				}
			}

			// Featured Image Size.
			$options['setting']['bloghash_blog_image_size'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_select',
				'control'           => array(
					'type'    => 'bloghash-select',
					'label'   => esc_html__( 'Featured Image Size', 'bloghash' ),
					'section' => 'bloghash_section_blog_page',
					'choices' => $size_choices,
				),
			);

			// Read more.
			$options['setting']['bloghash_blog_read_more'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'sanitize_text_field',
				'control'           => array(
					'type'        => 'bloghash-text',
					'section'     => 'bloghash_section_blog_page',
					'label'       => esc_html__( 'Read More', 'bloghash' ),
					'description' => esc_html__( 'Change Read More Text.', 'bloghash' ),
				),
			);

			// Meta/Post Details Layout.
			$options['setting']['bloghash_blog_entry_meta_elements'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_sortable',
				'control'           => array(
					'type'        => 'bloghash-sortable',
					'section'     => 'bloghash_section_blog_page',
					'label'       => esc_html__( 'Post Meta', 'bloghash' ),
					'description' => esc_html__( 'Set order and visibility for post meta details.', 'bloghash' ),
					'choices'     => array(
						'author'   => esc_html__( 'Author', 'bloghash' ),
						'date'     => esc_html__( 'Publish Date', 'bloghash' ),
						'comments' => esc_html__( 'Comments', 'bloghash' ),
						'tag'      => esc_html__( 'Tags', 'bloghash' ),
					),
				),
			);

			// Post Categories.
			$options['setting']['bloghash_blog_horizontal_post_categories'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_toggle',
				'control'           => array(
					'type'        => 'bloghash-toggle',
					'label'       => esc_html__( 'Show Post Categories', 'bloghash' ),
					'description' => esc_html__( 'A list of categories the post belongs to. Displayed above post title.', 'bloghash' ),
					'section'     => 'bloghash_section_blog_page',
					'required'    => array(
						array(
							'control'  => 'bloghash_blog_layout',
							'value'    => 'blog-horizontal',
							'operator' => '==',
						),
					),
				),
			);

			// Read More Button.
			$options['setting']['bloghash_blog_horizontal_read_more'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_toggle',
				'control'           => array(
					'type'     => 'bloghash-toggle',
					'label'    => esc_html__( 'Show Read More Button', 'bloghash' ),
					'section'  => 'bloghash_section_blog_page',
					'required' => array(
						array(
							'control'  => 'bloghash_blog_layout',
							'value'    => 'blog-horizontal',
							'operator' => '==',
						),
					),
				),
			);

			// Meta Author image.
			$options['setting']['bloghash_entry_meta_icons'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_toggle',
				'control'           => array(
					'type'    => 'bloghash-toggle',
					'section' => 'bloghash_section_blog_page',
					'label'   => esc_html__( 'Show avatar and icons in post meta', 'bloghash' ),
				),
			);

			// Excerpt Length.
			$options['setting']['bloghash_excerpt_length'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_range',
				'control'           => array(
					'type'        => 'bloghash-range',
					'section'     => 'bloghash_section_blog_page',
					'label'       => esc_html__( 'Excerpt Length', 'bloghash' ),
					'description' => esc_html__( 'Number of words displayed in the excerpt.', 'bloghash' ),
					'min'         => 0,
					'max'         => 100,
					'step'        => 1,
					'unit'        => '',
					'responsive'  => false,
				),
			);

			// Excerpt more.
			$options['setting']['bloghash_excerpt_more'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'sanitize_text_field',
				'control'           => array(
					'type'        => 'bloghash-text',
					'section'     => 'bloghash_section_blog_page',
					'label'       => esc_html__( 'Excerpt More', 'bloghash' ),
					'description' => esc_html__( 'What to append to excerpt if the text is cut.', 'bloghash' ),
				),
			);

			return $options;
		}
	}
endif;

new Bloghash_Customizer_Blog_Page();
