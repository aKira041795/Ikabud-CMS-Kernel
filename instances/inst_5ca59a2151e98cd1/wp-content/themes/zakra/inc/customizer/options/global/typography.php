<?php

$options = apply_filters(
	'zakra_typography_options',
	array(
		'zakra_root_font_size_heading'      => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Root Font Size', 'zakra' ),
			'section'      => 'zakra_typography',
			'priority'     => 3,
			'sub_controls' => apply_filters(
				'zakra_root_font_size_sub_controls',
				array(
					'zakra_root_font_size' => array(
						'title'       => esc_html__( 'Root Font Size', 'zakra' ),
						'default'     => array(
							'size' => 10,
							'unit' => 'px',
						),
						'type'        => 'customind-slider',
						'transport'   => 'postMessage',
						'section'     => 'zakra_typography',
						'priority'    => 3,
						'units'       => array( 'px' ),
						'defaultUnit' => 'px',
						'input_attrs' => array(
							'min'  => 10,
							'max'  => 16,
							'step' => 1,
						),
					),
				)
			),
			'collapsible'  => apply_filters( 'zakra_root_font_size_accordion_collapsible', false ),
		),
		'zakra_body_typography_heading'     => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Body', 'zakra' ),
			'section'      => 'zakra_typography',
			'sub_controls' => apply_filters(
				'zakra_body_typography_sub_controls',
				array(
					'zakra_body_typography' => array(
						'default'   => array(
							'font-family'    => 'Default',
							'font-weight'    => '400',
							'font-size'      => array(
								'desktop' => array(
									'size' => '15',
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
							'line-height'    => array(
								'desktop' => array(
									'size' => '1.8',
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
						'transport' => 'postMessage',
						'title'     => esc_html__( 'Body', 'zakra' ),
						'section'   => 'zakra_typography',
					),
				)
			),
			'collapsible'  => apply_filters( 'zakra_body_typography_accordion_collapsible', false ),
		),
		'zakra_headings_typography_heading' => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Headings', 'zakra' ),
			'section'      => 'zakra_typography',
			'sub_controls' => apply_filters(
				'zakar_headings_typography_sub_controls',
				array(
					'zakra_heading_typography' => array(
						'default'            => apply_filters(
							'zakra_heading_typography_filter',
							array(
								'font-family'    => 'Default',
								'font-weight'    => '400',
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
							)
						),
						'type'               => 'customind-typography',
						'title'              => esc_html__( 'Heading', 'zakra' ),
						'transport'          => 'postMessage',
						'section'            => 'zakra_typography',
						'allowed_properties' => array(
							'font-family',
							'font-weight',
							'line-height',
							'font-style',
							'text-transform',
						),
					),
					'zakra_h1_typography'      => array(
						'default'   => apply_filters(
							'zakra_h1_typography_filter',
							array(
								'font-family'    => 'Default',
								'font-weight'    => '500',
								'subsets'        => array( 'latin' ),
								'font-size'      => array(
									'desktop' => array(
										'size' => '2.5',
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
							)
						),
						'type'      => 'customind-typography',
						'transport' => 'postMessage',
						'title'     => esc_html__( 'H1', 'zakra' ),
						'section'   => 'zakra_typography',
					),
					'zakra_h2_typography'      => array(
						'default'   => apply_filters(
							'zakra_h2_typography_filter',
							array(
								'font-family'    => 'Default',
								'font-weight'    => '500',
								'subsets'        => array( 'latin' ),
								'font-size'      => array(
									'desktop' => array(
										'size' => '2.5',
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
							)
						),
						'type'      => 'customind-typography',
						'transport' => 'postMessage',
						'title'     => esc_html__( 'H2', 'zakra' ),
						'section'   => 'zakra_typography',
					),
					'zakra_h3_typography'      => array(
						'default'   => apply_filters(
							'zakra_h3_typography_filter',
							array(
								'font-family'    => 'Default',
								'font-weight'    => '500',
								'subsets'        => array( 'latin' ),
								'font-size'      => array(
									'desktop' => array(
										'size' => '2',
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
							)
						),
						'type'      => 'customind-typography',
						'title'     => esc_html__( 'H3', 'zakra' ),
						'transport' => 'postMessage',
						'section'   => 'zakra_typography',
					),
					'zakra_h4_typography'      => array(
						'default'   => apply_filters(
							'zakra_h4_typography_filter',
							array(
								'font-family'    => 'Default',
								'font-weight'    => '500',
								'subsets'        => array( 'latin' ),
								'font-size'      => array(
									'desktop' => array(
										'size' => '1.75',
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
							)
						),
						'type'      => 'customind-typography',
						'title'     => esc_html__( 'H4', 'zakra' ),
						'transport' => 'postMessage',
						'section'   => 'zakra_typography',
					),
					'zakra_h5_typography'      => array(
						'default'   => apply_filters(
							'zakra_h5_typography_filter',
							array(
								'font-family'    => 'Default',
								'font-weight'    => '500',
								'subsets'        => array( 'latin' ),
								'font-size'      => array(
									'desktop' => array(
										'size' => '1.313',
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
							)
						),
						'type'      => 'customind-typography',
						'title'     => esc_html__( 'H5', 'zakra' ),
						'transport' => 'postMessage',
						'section'   => 'zakra_typography',
					),
					'zakra_h6_typography'      => array(
						'default'   => apply_filters(
							'zakra_h6_typography_filter',
							array(
								'font-family'    => 'Default',
								'font-weight'    => '500',
								'subsets'        => array( 'latin' ),
								'font-size'      => array(
									'desktop' => array(
										'size' => '1.125',
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
							)
						),
						'type'      => 'customind-typography',
						'title'     => esc_html__( 'H6', 'zakra' ),
						'transport' => 'postMessage',
						'section'   => 'zakra_typography',
					),
				)
			),
			'collapsible'  => apply_filters( 'zakar_headings_typography_accordion_collapsible', false ),
		),
	),
);

if ( ! zakra_is_zakra_pro_active() ) {
	$options['zakra_typography_upgrade'] = array(
		'type'        => 'customind-upsell',
		'description' => esc_html__( 'Unlock more features available in Pro version.', 'zakra' ),
		'title'       => esc_html__( 'Learn more', 'zakra' ),
		'url'         => esc_url( 'https://zakratheme.com/pricing/?utm_medium=dash-customizer-learn-more&utm_source=zakra-theme&utm_campaign=customizer-upgrade-button&utm_content=learn-more' ),
		'section'     => 'zakra_typography',
		'priority'    => 100,
	);
}

zakra_customind()->add_controls( $options );
