<?php

$options = apply_filters(
	'zakra_breadcrumbs_options',
	array(
		'zakra_breadcrumbs_heading'          => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Breadcrumbs', 'zakra' ),
			'section'      => 'zakra_breadcrumb',
			'sub_controls' => apply_filters(
				'zakra_breadcrumbs_sub_controls',
				array(
					'zakra_breadcrumbs_general_heading' => array(
						'type'    => 'customind-title',
						'title'   => esc_html__( 'General', 'zakra' ),
						'section' => 'zakra_breadcrumb',
					),
					'zakra_enable_breadcrumb'           => array(
						'title'   => esc_html__( 'Enable', 'zakra' ),
						'default' => true,
						'type'    => 'customind-toggle',
						'section' => 'zakra_breadcrumb',
					),
					'zakra_breadcrumbs_style_divider'   => array(
						'type'     => 'customind-divider',
						'variant'  => 'dashed',
						'section'  => 'zakra_breadcrumb',
						'priority' => 30,
					),
					'zakra_breadcrumbs_style_heading'   => array(
						'type'     => 'customind-title',
						'title'    => esc_html__( 'Style', 'zakra' ),
						'section'  => 'zakra_breadcrumb',
						'priority' => 30,
					),
					'zakra_breadcrumb_typography'       => array(
						'default'   => apply_filters(
							'zakra_breadcrumb_typography_filter',
							array(
								'font-family' => 'Default',
								'font-weight' => '500',
								'font-size'   => array(
									'desktop' => array(
										'size' => '16',
										'unit' => 'px',
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
							)
						),
						'type'      => 'customind-typography',
						'title'     => esc_html__( 'Typography', 'zakra' ),
						'transport' => 'postMessage',
						'section'   => 'zakra_breadcrumb',
						'priority'  => 30,
						'condition' => array(
							'zakra_enable_breadcrumb' => true,
						),
					),
				)
			),
			'collapsible'  => apply_filters( 'zakra_breadcrumbs_accordion_collapsible', false ),
		),
		'zakra_breadcrumb_text_heading'      => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Text', 'zakra' ),
			'section'      => 'zakra_breadcrumb',
			'sub_controls' => apply_filters(
				'zakra_breadcrumb_text_sub_controls',
				array(
					'zakra_breadcrumbs_text_color' => array(
						'title'     => esc_html__( 'Color', 'zakra' ),
						'default'   => '#16181a',
						'type'      => 'customind-color',
						'transport' => 'postMessage',
						'section'   => 'zakra_breadcrumb',
						'condition' => array(
							'zakra_enable_breadcrumb' => true,
						),
					),
				)
			),
			'collapsible'  => apply_filters( 'zakra_breadcrumb_text_accordion_collapsible', false ),
			'condition'    => array(
				'zakra_enable_breadcrumb' => true,
			),
		),
		'zakra_breadcrumb_separator_heading' => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Separator', 'zakra' ),
			'section'      => 'zakra_breadcrumb',
			'sub_controls' => apply_filters(
				'zakra_breadcrumb_separator_sub_controls',
				array(
					'zakra_breadcrumb_separator_color' => array(
						'title'     => esc_html__( 'Color', 'zakra' ),
						'default'   => '#16181a',
						'type'      => 'customind-color',
						'transport' => 'postMessage',
						'section'   => 'zakra_breadcrumb',
						'condition' => array(
							'zakra_enable_breadcrumb' => true,
						),
					),
				)
			),
			'collapsible'  => apply_filters( 'zakra_breadcrumb_separator_accordion_collapsible', false ),
			'condition'    => array(
				'zakra_enable_breadcrumb' => true,
			),
		),
		'zakra_breadcrumb_link_heading'      => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Link', 'zakra' ),
			'section'      => 'zakra_breadcrumb',
			'sub_controls' => apply_filters(
				'zakra_breadcrumb_link_sub_controls',
				array(
					'zakra_breadcrumbs_link_color_group' => array(
						'type'         => 'customind-color-group',
						'title'        => esc_html__( 'Color', 'zakra' ),
						'section'      => 'zakra_breadcrumb',
						'sub_controls' => array(
							'zakra_breadcrumbs_link_color' => array(
								'default'   => '#16181a',
								'type'      => 'customind-color',
								'transport' => 'postMessage',
								'title'     => esc_html__( 'Normal', 'zakra' ),
								'section'   => 'zakra_breadcrumb',
							),
							'zakra_breadcrumbs_link_hover_color' => array(
								'default'   => '#027abb',
								'type'      => 'customind-color',
								'transport' => 'postMessage',
								'title'     => esc_html__( 'Hover', 'zakra' ),
								'section'   => 'zakra_breadcrumb',
							),
						),
					),
				)
			),
			'collapsible'  => apply_filters( 'zakra_breadcrumb_link_accordion_collapsible', false ),
			'condition'    => array(
				'zakra_enable_breadcrumb' => true,
			),
		),
	)
);

if ( ! zakra_is_zakra_pro_active() ) {
	$options['zakra_page_header_upgrade'] = array(
		'type'        => 'customind-upsell',
		'description' => esc_html__( 'Unlock more features available in Pro version.', 'zakra' ),
		'title'       => esc_html__( 'Learn more', 'zakra' ),
		'url'         => esc_url( 'https://zakratheme.com/pricing/?utm_medium=dash-customizer-learn-more&utm_source=zakra-theme&utm_campaign=customizer-upgrade-button&utm_content=learn-more' ),
		'section'     => 'zakra_breadcrumb',
		'priority'    => 100,
	);
}

zakra_customind()->add_controls( $options );
