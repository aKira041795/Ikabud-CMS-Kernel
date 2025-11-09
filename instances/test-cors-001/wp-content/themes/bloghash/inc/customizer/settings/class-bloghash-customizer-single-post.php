<?php
/**
 * Bloghash Blog - Single Post section in Customizer.
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

if ( ! class_exists( 'Bloghash_Customizer_Single_Post' ) ) :
	/**
	 * Bloghash Blog - Single Post section in Customizer.
	 */
	class Bloghash_Customizer_Single_Post {

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
			$options['section']['bloghash_section_blog_single_post'] = array(
				'title'    => esc_html__( 'Single Post', 'bloghash' ),
				'panel'    => 'bloghash_panel_blog',
				'priority' => 20,
			);

			$options['setting']['bloghash_single_post_elements'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_sortable',
				'control'           => array(
					'type'        => 'bloghash-sortable',
					'section'     => 'bloghash_section_blog_single_post',
					'label'       => esc_html__( 'Post Elements', 'bloghash' ),
					'description' => esc_html__( 'Set visibility of post elements.', 'bloghash' ),
					'sortable'    => false,
					'choices'     => array(
						'thumb'          => esc_html__( 'Featured Image', 'bloghash' ),
						'category'       => esc_html__( 'Post Categories', 'bloghash' ),
						'tags'           => esc_html__( 'Post Tags', 'bloghash' ),
						'last-updated'   => esc_html__( 'Last Updated Date', 'bloghash' ),
						'about-author'   => esc_html__( 'About Author Box', 'bloghash' ),
						'prev-next-post' => esc_html__( 'Next/Prev Post Links', 'bloghash' ),
					),
				),
			);

			// Meta/Post Details Layout.
			$options['setting']['bloghash_single_post_meta_elements'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_sortable',
				'control'           => array(
					'type'        => 'bloghash-sortable',
					'label'       => esc_html__( 'Post Meta', 'bloghash' ),
					'description' => esc_html__( 'Set order and visibility for post meta details.', 'bloghash' ),
					'section'     => 'bloghash_section_blog_single_post',
					'choices'     => array(
						'author'   => esc_html__( 'Author', 'bloghash' ),
						'date'     => esc_html__( 'Publish Date', 'bloghash' ),
						'comments' => esc_html__( 'Comments', 'bloghash' ),
						'category' => esc_html__( 'Categories', 'bloghash' ),
					),
				),
			);

			// Meta icons.
			$options['setting']['bloghash_single_entry_meta_icons'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_toggle',
				'control'           => array(
					'type'    => 'bloghash-toggle',
					'section' => 'bloghash_section_blog_single_post',
					'label'   => esc_html__( 'Show avatar and icons in post meta', 'bloghash' ),
				),
			);

			// Toggle Comments.
			$options['setting']['bloghash_single_toggle_comments'] = array(
				'transport'         => 'refresh',
				'sanitize_callback' => 'bloghash_sanitize_toggle',
				'control'           => array(
					'type'        => 'bloghash-toggle',
					'label'       => esc_html__( 'Show Toggle Comments', 'bloghash' ),
					'description' => esc_html__( 'Hide comments and comment form behind a toggle button. ', 'bloghash' ),
					'section'     => 'bloghash_section_blog_single_post',
				),
			);

			return $options;
		}
	}
endif;
new Bloghash_Customizer_Single_Post();
