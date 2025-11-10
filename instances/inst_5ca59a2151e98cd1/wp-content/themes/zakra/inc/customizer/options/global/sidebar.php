<?php

$sidebar_layout_choices = apply_filters(
	'zakra_site_layout_choices',
	array(
		'default'   => array(
			'label' => 'Default',
			'url'   => ZAKRA_PARENT_INC_ICON_URI . '/sidebar-default.svg',
		),
		'left'      => array(
			'label' => 'Left Sidebar',
			'url'   => ZAKRA_PARENT_INC_ICON_URI . '/left-sidebar.svg',
		),
		'right'     => array(
			'label' => 'Right Sidebar',
			'url'   => ZAKRA_PARENT_INC_ICON_URI . '/right-sidebar.svg',
		),
		'centered'  => array(
			'label' => 'Centered',
			'url'   => ZAKRA_PARENT_INC_ICON_URI . '/sidebar-centered.svg',
		),
		'contained' => array(
			'label' => 'Contained',
			'url'   => ZAKRA_PARENT_INC_ICON_URI . '/sidebar-contained.svg',
		),
		'stretched' => array(
			'label' => 'Stretched',
			'url'   => ZAKRA_PARENT_INC_ICON_URI . '/sidebar-stretched.svg',
		),
	)
);

$options = apply_filters(
	'zakra_sidebar_options',
	array(
		'zakra_sidebar'        => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Sidebar', 'zakra' ),
			'section'      => 'zakra_sidebar_layout',
			'sub_controls' => apply_filters(
				'zakra_sidebar_sub_controls',
				array(
					'zakra_sidebar_width' => array(
						'title'       => esc_html__( 'Width', 'zakra' ),
						'default'     => array(
							'size' => 30,
							'unit' => '%',
						),
						'type'        => 'customind-slider',
						'section'     => 'zakra_sidebar_layout',
						'transport'   => 'postMessage',
						'priority'    => 10,
						'units'       => array( '%', 'em', 'rem' ),
						'defaultUnit' => '%',
						'input_attrs' => array(
							'min'  => 15,
							'max'  => 100,
							'step' => 1,
						),
					),
				)
			),
			'collapsible'  => apply_filters( 'zakra_sidebar_accordion_collapsible', false ),
		),
		'zakra_sidebar_layout' => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Layout', 'zakra' ),
			'section'      => 'zakra_sidebar_layout',
			'sub_controls' => apply_filters(
				'zakra_sidebar_layout_sub_controls',
				array(
					'zakra_default_sidebar_layout' => array(
						'default'     => 'right',
						'type'        => 'customind-radio-image',
						'title'       => esc_html__( 'Default', 'zakra' ),
						'section'     => 'zakra_sidebar_layout',
						'choices'     => array_slice( $sidebar_layout_choices, 1, count( $sidebar_layout_choices ) ),
						'priority'    => 5,
						'input_attrs' => array(
							'columns' => 2,
						),
					),
					'zakra_archive_sidebar_layout' => array(
						'default'     => 'right',
						'type'        => 'customind-radio-image',
						'title'       => esc_html__( 'Archive', 'zakra' ),
						'section'     => 'zakra_sidebar_layout',
						'choices'     => $sidebar_layout_choices,
						'priority'    => 10,
						'input_attrs' => array(
							'columns' => 2,
						),
					),
					'zakra_post_sidebar_layout'    => array(
						'default'     => 'right',
						'type'        => 'customind-radio-image',
						'title'       => esc_html__( 'Single Post', 'zakra' ),
						'section'     => 'zakra_sidebar_layout',
						'choices'     => $sidebar_layout_choices,
						'priority'    => 25,
						'input_attrs' => array(
							'columns' => 2,
						),
					),
					'zakra_page_sidebar_layout'    => array(
						'default'     => 'right',
						'type'        => 'customind-radio-image',
						'title'       => esc_html__( 'Page', 'zakra' ),
						'section'     => 'zakra_sidebar_layout',
						'choices'     => $sidebar_layout_choices,
						'priority'    => 30,
						'input_attrs' => array(
							'columns' => 2,
						),
					),
					'zakra_others_sidebar_layout'  => array(
						'default'     => 'right',
						'type'        => 'customind-radio-image',
						'title'       => esc_html__( 'Others', 'zakra' ),
						'section'     => 'zakra_sidebar_layout',
						'choices'     => $sidebar_layout_choices,
						'priority'    => 50,
						'input_attrs' => array(
							'columns' => 2,
						),
					),
				)
			),
			'collapsible'  => apply_filters( 'zakra_sidebar_layout_accordion_collapsible', false ),
		),
	)
);

if ( ! zakra_is_zakra_pro_active() ) {
	$options['zakra_layout_structure_upgrade'] = array(
		'type'        => 'customind-upsell',
		'description' => esc_html__( 'Unlock more features available in Pro version.', 'zakra' ),
		'title'       => esc_html__( 'Learn more', 'zakra' ),
		'url'         => esc_url( 'https://zakratheme.com/pricing/?utm_medium=dash-customizer-learn-more&utm_source=zakra-theme&utm_campaign=customizer-upgrade-button&utm_content=learn-more' ),
		'section'     => 'zakra_sidebar_layout',
		'priority'    => 100,
	);
}

zakra_customind()->add_controls( $options );
