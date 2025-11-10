<?php

$options = apply_filters(
	'zakra_single_blog_post_options',
	array(
		'zakra_single_post_elements_heading' => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Post Elements', 'zakra' ),
			'section'      => 'zakra_single_blog_post',
			'sub_controls' => apply_filters(
				'zakra_single_post_elements_sub_controls',
				array(
					'zakra_single_post_elements' => array(
						'type'        => 'customind-sortable',
						'title'       => esc_html__( 'Post Elements', 'zakra' ),
						'section'     => 'zakra_single_blog_post',
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
					),
				)
			),
			'collapsible'  => apply_filters( 'zakra_single_post_elements_accordion_collapsible', false ),
		),
		'zakra_single_meta_elements_heading' => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Meta Elements', 'zakra' ),
			'section'      => 'zakra_single_blog_post',
			'sub_controls' => apply_filters(
				'zakra_single_meta_elements_sub_controls',
				array(
					'zakra_single_meta_elements' => array(
						'type'        => 'customind-sortable',
						'title'       => esc_html__( 'Meta Elements', 'zakra' ),
						'section'     => 'zakra_single_blog_post',
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
					),
				)
			),
			'collapsible'  => apply_filters( 'zakra_single_meta_elements_accordion_collapsible', false ),
		),
	)
);

if ( ! zakra_is_zakra_pro_active() ) {
	$options['zakra_single_blog_post_upgrade'] = array(
		'type'        => 'customind-upsell',
		'description' => esc_html__( 'Unlock more features available in Pro version.', 'zakra' ),
		'title'       => esc_html__( 'Learn more', 'zakra' ),
		'url'         => esc_url( 'https://zakratheme.com/pricing/?utm_medium=dash-customizer-learn-more&utm_source=zakra-theme&utm_campaign=customizer-upgrade-button&utm_content=learn-more' ),
		'section'     => 'zakra_single_blog_post',
		'priority'    => 100,
	);
}

zakra_customind()->add_controls( $options );
