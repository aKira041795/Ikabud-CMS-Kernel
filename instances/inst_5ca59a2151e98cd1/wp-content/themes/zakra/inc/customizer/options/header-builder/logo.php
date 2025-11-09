<?php

$options = apply_filters(
	'zakra_builder_logo_options',
	array(
		'zakra_header_site_identity_general_heading' => array(
			'type'     => 'customind-title',
			'title'    => esc_html__( 'Site Identity', 'zakra' ),
			'section'  => 'zakra_header_builder_logo',
			'priority' => 3,
		),
		'zakra_header_site_logo_heading'             => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Site Logo', 'zakra' ),
			'section'      => 'zakra_header_builder_logo',
			'priority'     => 9,
			'sub_controls' => apply_filters(
				'zakra_header_site_logo_sub_controls',
				array(
					'custom_logo'                   => array(
						'type'    => 'customind-image',
						'title'   => esc_html__( 'Logo', 'zakra' ),
						'section' => 'zakra_header_builder_logo',
						'crop'    => array(
							'width'  => 170,
							'height' => 60,
						),
					),
					'zakra_retina_logo'             => array(
						'type'        => 'customind-image',
						'title'       => esc_html__( 'Retina Logo', 'zakra' ),
						'section'     => 'zakra_header_builder_logo',
						'description' => esc_html__( 'Upload 2X times the size of your current logo. Eg: If your current logo size is 120*60 then upload 240*120 sized logo.', 'zakra' ),

					),
					'zakra_header_site_logo_height' => array(
						'default'     => array(
							'size'  => '',
							'units' => 'px',
						),
						'type'        => 'customind-slider',
						'title'       => esc_html__( 'Height', 'zakra' ),
						'transport'   => 'postMessage',
						'section'     => 'zakra_header_builder_logo',
						'units'       => array( 'px' ),
						'defaultUnit' => 'px',
						'input_attrs' => array(
							'min'  => 0,
							'max'  => 400,
							'step' => 1,
						),
					),
				)
			),
			'collapsible'  => apply_filters( 'zakra_header_site_logo_accordion_collapsible', false ),
		),
		'zakra_header_site_identity_heading'         => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Site Title', 'zakra' ),
			'section'      => 'zakra_header_builder_logo',
			'priority'     => 11,
			'sub_controls' => apply_filters(
				'zakra_header_site_identity_sub_controls',
				array(
					'zakra_enable_site_identity'         => array(
						'title'    => esc_html__( 'Enable', 'zakra' ),
						'default'  => true,
						'type'     => 'customind-toggle',
						'section'  => 'zakra_header_builder_logo',
						'priority' => 10,
					),
					'zakra_header_site_identity_color_group' => array(
						'type'         => 'customind-color-group',
						'title'        => esc_html__( 'Color', 'zakra' ),
						'section'      => 'zakra_header_builder_logo',
						'sub_controls' => apply_filters(
							'zakra_header_site_identity_color_sub_controls',
							array(
								'zakra_header_site_identity_color' => array(
									'default'   => '',
									'type'      => 'customind-color',
									'title'     => esc_html__( 'Normal', 'zakra' ),
									'transport' => 'postMessage',
									'section'   => 'zakra_colors',
								),
							)
						),
						'condition'    => array(
							'zakra_enable_site_identity' => true,
						),
					),
					'zakra_header_site_title_typography' => array(
						'default'   => array(
							'font-family'    => 'Default',
							'font-weight'    => '400',
							'subsets'        => array( 'latin' ),
							'font-size'      => array(
								'desktop' => array(
									'size' => '4',
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
									'size' => '1.5',
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
						'section'   => 'zakra_header_builder_logo',
						'transport' => 'postMessage',
						'priority'  => 14,
						'condition' => array(
							'zakra_enable_site_identity' => true,
						),
					),
				)
			),
			'collapsible'  => apply_filters( 'zakra_header_site_identity_accordion_collapsible', false ),
		),
		'zakra_header_tagline_heading'               => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Tagline', 'zakra' ),
			'section'      => 'zakra_header_builder_logo',
			'priority'     => 11,
			'sub_controls' => apply_filters(
				'zakra_tagline_sub_controls',
				array(
					'zakra_enable_site_tagline'            => array(
						'default'  => true,
						'type'     => 'customind-toggle',
						'title'    => 'Enable',
						'section'  => 'zakra_header_builder_logo',
						'priority' => 16,
					),
					'zakra_header_site_tagline_color'      => array(
						'title'     => esc_html__( 'Color', 'zakra' ),
						'default'   => '',
						'type'      => 'customind-color',
						'section'   => 'zakra_header_builder_logo',
						'transport' => 'postMessage',
						'priority'  => 16,
						'condition' => array(
							'zakra_enable_site_tagline' => true,
						),
					),
					'zakra_header_site_tagline_typography' => array(
						'default'   => array(
							'font-family'    => 'Default',
							'font-weight'    => '400',
							'subsets'        => array( 'latin' ),
							'font-size'      => array(
								'desktop' => array(
									'size' => '1.6',
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
						'title'     => esc_html__( 'Typography', 'zakra' ),
						'section'   => 'zakra_header_builder_logo',
						'transport' => 'postMessage',
						'priority'  => 18,
						'condition' => array(
							'zakra_enable_site_tagline' => true,
						),
					),
				)
			),
			'collapsible'  => apply_filters( 'zakra_tagline_accordion_collapsible', false ),
		),
	)
);
if ( ! zakra_is_zakra_pro_active() ) {
	$options['zakra_header_tagline_upgrade'] = array(
		'type'        => 'customind-upsell',
		'description' => esc_html__( 'Unlock more features available in Pro version.', 'zakra' ),
		'title'       => esc_html__( 'Learn more', 'zakra' ),
		'url'         => esc_url( 'https://zakratheme.com/pricing/?utm_medium=dash-customizer-learn-more&utm_source=zakra-theme&utm_campaign=customizer-upgrade-button&utm_content=learn-more' ),
		'section'     => 'zakra_header_builder_logo',
		'priority'    => 100,
	);
}
zakra_customind()->add_controls( $options );
