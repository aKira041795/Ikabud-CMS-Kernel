<?php

$options = apply_filters(
	'zakra_blog_options',
	array(
		'zakra_blog_page_title_heading'     => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Page Title', 'zakra' ),
			'section'      => 'zakra_blog',
			'priority'     => 15,
			'sub_controls' => apply_filters(
				'zakra_blog_page_title_sub_controls',
				array(
					'zakra_navigate_page_title_position' => array(
						'title'    => esc_html__( 'Page Title Position Navigation', 'zakra' ),
						'type'     => 'customind-navigation',
						'section'  => 'zakra_blog',
						'to'       => 'zakra_page_header',
						'nav_type' => 'section',
						'priority' => 55,
					),
				)
			),
			'collapsible'  => apply_filters( 'zakra_blog_page_title_accordion_collapsible', false ),
		),
		'zakra_blog_post_date_type_heading' => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Post Date Type', 'zakra' ),
			'section'      => 'zakra_blog',
			'priority'     => 15,
			'sub_controls' => apply_filters(
				'zakra_blog_post_date_type_sub_controls',
				array(
					'zakra_blog_post_date_type' => array(
						'default' => 'published-date',
						'type'    => 'customind-select',
						'title'   => esc_html__( 'Post Date Type', 'zakra' ),
						'section' => 'zakra_blog',
						'choices' => apply_filters(
							'zakra_blog_post_date_type_choices',
							array(
								'published-date' => esc_html__( 'Published Date', 'zakra' ),
								'modified-date'  => esc_html__( 'Modified Date', 'zakra' ),
							)
						),
					),
				)
			),
			'collapsible'  => apply_filters( 'zakra_blog_post_date_type_accordion_collapsible', false ),
		),
		'zakra_blog_post_elements_heading'  => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Elements', 'zakra' ),
			'section'      => 'zakra_blog',
			'priority'     => 15,
			'sub_controls' => apply_filters(
				'zakra_blog_post_elements_sub_controls',
				array(
					'zakra_blog_post_elements' => array(
						'type'        => 'customind-sortable',
						'title'       => esc_html__( 'Post Elements', 'zakra' ),
						'section'     => 'zakra_blog',
						'description' => esc_html__( 'Drag & Drop items to re-arrange the order', 'zakra' ),
						'choices'     => array(
							'featured_image' => esc_attr__( 'Featured Image', 'zakra' ),
							'title'          => esc_attr__( 'Title', 'zakra' ),
							'meta'           => esc_attr__( 'Meta Tags', 'zakra' ),
							'content'        => esc_attr__( 'Content', 'zakra' ),
						),
						'default'     => array(
							'featured_image',
							'title',
							'meta',
							'content',
						),
						'condition'   => apply_filters( 'zakra_structure_archive_blog_order', false ),
					),
					'zakra_blog_meta_elements' => array(
						'type'        => 'customind-sortable',
						'title'       => esc_html__( 'Meta Elements', 'zakra' ),
						'section'     => 'zakra_blog',
						'description' => esc_html__( 'Drag & Drop items to re-arrange the order', 'zakra' ),
						'choices'     => array(
							'comments'   => esc_attr__( 'Comments', 'zakra' ),
							'categories' => esc_attr__( 'Categories', 'zakra' ),
							'author'     => esc_attr__( 'Author', 'zakra' ),
							'date'       => esc_attr__( 'Date', 'zakra' ),
							'tags'       => esc_attr__( 'Tags', 'zakra' ),
						),
						'default'     => array(
							'author',
							'date',
							'categories',
							'tags',
							'comments',
						),
						'condition'   => apply_filters( 'zakra_structure_archive_blog_order', false ),
					),
				)
			),
			'collapsible'  => apply_filters( 'zakra_blog_post_elements_accordion_collapsible', false ),
		),
		'zakra_blog_post_title_heading'     => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Post title', 'zakra' ),
			'section'      => 'zakra_blog',
			'priority'     => 25,
			'sub_controls' => apply_filters(
				'zakra_blog_post_title_sub_controls',
				array(
					'zakra_blog_post_title_color_group' => array(
						'type'         => 'customind-color-group',
						'title'        => esc_html__( 'Color', 'zakra' ),
						'section'      => 'zakra_blog',
						'sub_controls' => apply_filters(
							'zakra_blog_post_title_color_sub_controls',
							array(
								'zakra_blog_post_title_color' => array(
									'default'   => '',
									'type'      => 'customind-color',
									'title'     => esc_html__( 'Normal', 'zakra' ),
									'transport' => 'postMessage',
									'section'   => 'zakra_blog',
								),
								'zakra_blog_post_title_hover_color' => array(
									'default'   => '',
									'type'      => 'customind-color',
									'title'     => esc_html__( 'Hover', 'zakra' ),
									'transport' => 'postMessage',
									'section'   => 'zakra_blog',
								),
							)
						),
					),
					'zakra_blog_post_title_typography'  => array(
						'default'   => array(
							'font-family'    => 'Default',
							'font-weight'    => '500',
							'subsets'        => array( 'latin' ),
							'font-size'      => array(
								'desktop' => array(
									'size' => '2.25',
									'unit' => 'rem',
								),
								'tablet'  => array(
									'size' => '',
									'unit' => '',
								),
								'mobile'  => array(
									'size' => '',
									'unit' => '',
								),
							),
							'line-height'    => array(
								'desktop' => array(
									'size' => '1.3',
									'unit' => '-',
								),
								'tablet'  => array(
									'size' => '',
									'unit' => '',
								),
								'mobile'  => array(
									'size' => '',
									'unit' => '',
								),
							),
							'font-style'     => 'normal',
							'text-transform' => 'none',
						),
						'type'      => 'customind-typography',
						'title'     => esc_html__( 'Typography', 'zakra' ),
						'transport' => 'postMessage',
						'section'   => 'zakra_blog',
						'priority'  => 15,
					),
				)
			),
			'collapsible'  => apply_filters( 'zakra_blog_post_title_accordion_collapsible', false ),
		),
		'zakra_blog_excerpt_heading'        => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Excerpt', 'zakra' ),
			'section'      => 'zakra_blog',
			'priority'     => 35,
			'sub_controls' => apply_filters(
				'zakra_blog_excerpt_sub_controls',
				array(
					'zakra_blog_excerpt_type' => array(
						'default' => 'excerpt',
						'type'    => 'customind-radio',
						'title'   => esc_html__( 'Type', 'zakra' ),
						'section' => 'zakra_blog',
						'choices' => array(
							'excerpt' => esc_html__( 'Excerpt', 'zakra' ),
							'content' => esc_html__( 'Full Content', 'zakra' ),
						),
					),
				)
			),
			'collapsible'  => apply_filters( 'zakra_blog_excerpt_accordion_collapsible', false ),
		),
		'zakra_blog_button_heading'         => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Button', 'zakra' ),
			'section'      => 'zakra_blog',
			'priority'     => 40,
			'sub_controls' => apply_filters(
				'zakra_blog_button_sub_controls',
				array(
					'zakra_enable_blog_button'    => array(
						'title'     => esc_html__( 'Enable', 'zakra' ),
						'default'   => true,
						'type'      => 'customind-toggle',
						'section'   => 'zakra_blog',
						'condition' => array(
							'zakra_blog_excerpt_type' => 'excerpt',
						),
					),
					'zakra_blog_button_alignment' => array(
						'default'   => 'style-1',
						'type'      => 'customind-radio-image',
						'title'     => esc_html__( 'Alignment', 'zakra' ),
						'section'   => 'zakra_blog',
						'choices'   => apply_filters(
							'zakra_blog_button_alignment',
							array(
								'style-1' => array(
									'label' => '',
									'url'   => ZAKRA_PARENT_INC_ICON_URI . '/read-more-left.svg',
								),
								'style-2' => array(
									'label' => '',
									'url'   => ZAKRA_PARENT_INC_ICON_URI . '/read-more-right.svg',
								),
							)
						),
						'columns'   => 2,
						'priority'  => 31,
						'condition' => apply_filters(
							'zakra_blog_button_alignment_dependency',
							array(
								'zakra_blog_excerpt_type'  => 'excerpt',
								'zakra_enable_blog_button' => true,
							)
						),
					),
				)
			),
			'collapsible'  => apply_filters( 'zakra_blog_button_accordion_collapsible', false ),
			'condition'    => array(
				'zakra_blog_excerpt_type' => 'excerpt',
			),
		),
	)
);

if ( ! zakra_is_zakra_pro_active() ) {
	$options['zakra_blog_upgrade_to_pro'] = array(
		'type'        => 'customind-upsell',
		'description' => esc_html__( 'Unlock more features available in Pro version.', 'zakra' ),
		'title'       => esc_html__( 'Learn more', 'zakra' ),
		'section'     => 'zakra_blog',
		'url'         => esc_url( 'https://zakratheme.com/pricing/?utm_medium=dash-customizer-learn-more&utm_source=zakra-theme&utm_campaign=customizer-upgrade-button&utm_content=learn-more' ),
		'priority'    => 100,
	);
}

zakra_customind()->add_controls( $options );
