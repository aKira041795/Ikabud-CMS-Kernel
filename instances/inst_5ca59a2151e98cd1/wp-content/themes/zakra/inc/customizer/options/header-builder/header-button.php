<?php

$options = array(
	'zakra_header_button_heading' => array(
		'type'         => 'customind-accordion',
		'title'        => esc_html__( 'Button 1', 'zakra' ),
		'section'      => 'zakra_header_builder_button_1',
		'sub_controls' => apply_filters(
			'zakra_header_button_sub_controls',
			array(
				'zakra_header_button_text'          => array(
					'default' => '',
					'title'   => esc_html__( 'Text', 'zakra' ),
					'type'    => 'customind-text',
					'section' => 'zakra_header_builder_button_1',
				),
				'zakra_header_button_link'          => array(
					'default'     => '',
					'title'       => esc_html__( 'Link', 'zakra' ),
					'type'        => 'customind-text',
					'section'     => 'zakra_header_builder_button_1',
					'input_attrs' => array(
						'placeholder' => esc_attr__( 'https://example.com', 'zakra' ),
					),
				),
				'zakra_header_button_target'        => array(
					'default' => false,
					'title'   => esc_html__( 'Open link in a new tab', 'zakra' ),
					'type'    => 'customind-toggle',
					'section' => 'zakra_header_builder_button_1',
				),
				'zakra_header_button_color_group'   => array(
					'type'         => 'customind-color-group',
					'title'        => esc_html__( 'Color', 'zakra' ),
					'section'      => 'zakra_header_builder_button_1',
					'sub_controls' => array(
						'zakra_header_button_color'       => array(
							'default'   => '#ffffff',
							'type'      => 'customind-color',
							'title'     => esc_html__( 'Normal', 'zakra' ),
							'transport' => 'postMessage',
							'section'   => 'zakra_header_builder_button_1',
						),
						'zakra_header_button_hover_color' => array(
							'default'   => '#ffffff',
							'type'      => 'customind-color',
							'title'     => esc_html__( 'Hover', 'zakra' ),
							'transport' => 'postMessage',
							'section'   => 'zakra_header_builder_button_1',
						),
					),
				),
				'zakra_header_button_background_color_group' => array(
					'type'         => 'customind-color-group',
					'title'        => esc_html__( 'Background', 'zakra' ),
					'section'      => 'zakra_header_builder_button_1',
					'sub_controls' => array(
						'zakra_header_button_background_color'       => array(
							'default'   => '#027abb',
							'type'      => 'customind-color',
							'title'     => esc_html__( 'Normal', 'zakra' ),
							'transport' => 'postMessage',
							'section'   => 'zakra_header_builder_button_1',
						),
						'zakra_header_button_background_hover_color' => array(
							'default'   => '#ffffff',
							'type'      => 'customind-color',
							'title'     => esc_html__( 'Hover', 'zakra' ),
							'transport' => 'postMessage',
							'section'   => 'zakra_header_builder_button_1',
						),
					),
				),
				'zakra_header_button_typography'    => array(
					'default'   => array(
						'font-family'    => 'Default',
						'font-weight'    => '400',
						'font-size'      => array(
							'desktop' => array(
								'size' => '',
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
					'transport' => 'postMessage',
					'title'     => esc_html__( 'Typography', 'zakra' ),
					'section'   => 'zakra_header_builder_button_1',
				),
				'zakra_header_button_padding'       => array(
					'default'     => array(
						'top'    => '5',
						'right'  => '10',
						'bottom' => '5',
						'left'   => '10',
						'unit'   => 'px',
					),
					'type'        => 'customind-dimensions',
					'title'       => esc_html__( 'Padding', 'zakra' ),
					'section'     => 'zakra_header_builder_button_1',
					'transport'   => 'postMessage',
					'units'       => array( 'px', 'em', '%' ),
					'defaultUnit' => 'px',
				),
				'zakra_header_button_border_width'  => array(
					'default'     => array(
						'top'    => '',
						'right'  => '',
						'bottom' => '',
						'left'   => '',
						'unit'   => 'px',
					),
					'type'        => 'customind-dimensions',
					'title'       => esc_html__( 'Border Width', 'zakra' ),
					'section'     => 'zakra_header_builder_button_1',
					'transport'   => 'postMessage',
					'units'       => array( 'px', 'em' ),
					'defaultUnit' => 'px',
				),
				'zakra_header_button_border_color'  => array(
					'default'   => '',
					'type'      => 'customind-color',
					'title'     => esc_html__( 'Border Color', 'zakra' ),
					'transport' => 'postMessage',
					'section'   => 'zakra_header_builder_button_1',
				),
				'zakra_header_button_border_radius' => array(
					'default'     => array(
						'size'  => '',
						'units' => 'px',
					),
					'type'        => 'customind-slider',
					'title'       => esc_html__( 'Border Radius', 'zakra' ),
					'section'     => 'zakra_header_builder_button_1',
					'transport'   => 'postMessage',
					'units'       => array( 'px' ),
					'defaultUnit' => 'px',
					'input_attrs' => array(
						'min'  => 0,
						'max'  => 20,
						'step' => 1,
					),
				),
			)
		),
		'collapsible'  => apply_filters( 'zakra_header_button_accordion_collapsible', false ),
	),
);

if ( ! zakra_is_zakra_pro_active() ) {
	$options['zakra_header_button_upgrade'] = array(
		'type'        => 'customind-upsell',
		'description' => esc_html__( 'Unlock more features available in Pro version.', 'zakra' ),
		'title'       => esc_html__( 'Learn more', 'zakra' ),
		'url'         => esc_url( 'https://zakratheme.com/pricing/?utm_medium=dash-customizer-learn-more&utm_source=zakra-theme&utm_campaign=customizer-upgrade-button&utm_content=learn-more' ),
		'section'     => 'zakra_header_builder_button_1',
		'priority'    => 100,
	);
}

zakra_customind()->add_controls( $options );
