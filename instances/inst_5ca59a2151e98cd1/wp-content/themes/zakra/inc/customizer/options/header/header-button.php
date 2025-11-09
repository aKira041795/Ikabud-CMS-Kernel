<?php

$options = apply_filters(
	'zakra_header_button_options',
	array(
		'zakra_header_button_heading' => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Button 1', 'zakra' ),
			'section'      => 'zakra_header_button',
			'sub_controls' => apply_filters(
				'zakra_header_button_sub_controls',
				array(
					'zakra_header_button_general_heading' => array(
						'type'    => 'customind-title',
						'title'   => esc_html__( 'General', 'zakra' ),
						'section' => 'zakra_header_button',
					),
					'zakra_enable_header_button'          => array(
						'default' => false,
						'title'   => esc_html__( 'Enable', 'zakra' ),
						'type'    => 'customind-toggle',
						'section' => 'zakra_header_button',
					),
					'zakra_header_button_text'            => array(
						'default'   => '',
						'title'     => esc_html__( 'Text', 'zakra' ),
						'type'      => 'customind-text',
						'section'   => 'zakra_header_button',
						'condition' => array(
							'zakra_enable_header_button' => true,
						),
					),
					'zakra_header_button_link'            => array(
						'default'     => '',
						'title'       => esc_html__( 'Link', 'zakra' ),
						'type'        => 'customind-text',
						'section'     => 'zakra_header_button',
						'input_attrs' => array(
							'placeholder' => esc_attr__( 'https://example.com', 'zakra' ),
						),
						'condition'   => array(
							'zakra_enable_header_button' => true,
						),
					),
					'zakra_header_button_target'          => array(
						'default'   => false,
						'title'     => esc_html__( 'Open link in a new tab', 'zakra' ),
						'type'      => 'customind-toggle',
						'section'   => 'zakra_header_button',
						'condition' => array(
							'zakra_enable_header_button' => true,
						),
					),
					'zakra_header_button_style_divider'   => array(
						'type'      => 'customind-divider',
						'section'   => 'zakra_menu',
						'condition' => array(
							'zakra_enable_header_button' => true,
						),
					),
					'zakra_header_button_style'           => array(
						'type'      => 'customind-title',
						'title'     => esc_html__( 'Style', 'zakra' ),
						'section'   => 'zakra_menu',
						'condition' => array(
							'zakra_enable_header_button' => true,
						),
					),
					'zakra_header_button_color_group'     => array(
						'type'         => 'customind-color-group',
						'title'        => esc_html__( 'Color', 'zakra' ),
						'section'      => 'zakra_header_button',
						'sub_controls' => array(
							'zakra_header_button_color' => array(
								'default' => '#ffffff',
								'type'    => 'customind-color',
								'title'   => esc_html__( 'Normal', 'zakra' ),
								'section' => 'zakra_header_button',
							),
							'zakra_header_button_hover_color' => array(
								'default' => '#ffffff',
								'type'    => 'customind-color',
								'title'   => esc_html__( 'Hover', 'zakra' ),
								'section' => 'zakra_header_button',
							),
						),
						'condition'    => array(
							'zakra_enable_header_button' => true,
						),
					),
					'zakra_header_button_background_color_group' => array(
						'type'         => 'customind-color-group',
						'title'        => esc_html__( 'Background', 'zakra' ),
						'section'      => 'zakra_header_button',
						'sub_controls' => array(
							'zakra_header_button_background_color'       => array(
								'default' => '#027abb',
								'type'    => 'customind-color',
								'title'   => esc_html__( 'Normal', 'zakra' ),
								'section' => 'zakra_header_button',
							),
							'zakra_header_button_background_hover_color' => array(
								'default' => '#ffffff',
								'type'    => 'customind-color',
								'title'   => esc_html__( 'Hover', 'zakra' ),
								'section' => 'zakra_header_button',
							),
						),
						'condition'    => array(
							'zakra_enable_header_button' => true,
						),
					),
					'zakra_header_button_padding'         => array(
						'default'     => array(
							'top'    => '5',
							'right'  => '10',
							'bottom' => '5',
							'left'   => '10',
							'unit'   => 'px',
						),
						'type'        => 'customind-dimensions',
						'title'       => esc_html__( 'Padding', 'zakra' ),
						'section'     => 'zakra_header_button',
						'priority'    => 20,
						'units'       => array( 'px', 'em', '%' ),
						'defaultUnit' => 'px',
						'condition'   => array(
							'zakra_enable_header_button' => true,
						),
					),
					'zakra_header_button_border_divider'  => array(
						'type'      => 'customind-divider',
						'section'   => 'zakra_menu',
						'priority'  => 20,
						'condition' => array(
							'zakra_enable_header_button' => true,
						),
					),
					'zakra_header_button_border_heading'  => array(
						'type'      => 'customind-title',
						'title'     => esc_html__( 'Border', 'zakra' ),
						'section'   => 'zakra_menu',
						'priority'  => 20,
						'condition' => array(
							'zakra_enable_header_button' => true,
						),
					),
					'zakra_header_button_border_radius'   => array(
						'default'     => array(
							'size'  => '',
							'units' => 'px',
						),
						'type'        => 'customind-slider',
						'title'       => esc_html__( 'Radius', 'zakra' ),
						'section'     => 'zakra_header_button',
						'priority'    => 35,
						'units'       => array( 'px' ),
						'defaultUnit' => 'px',
						'input_attrs' => array(
							'min'  => 0,
							'max'  => 30,
							'step' => 1,
						),
						'condition'   => array(
							'zakra_enable_header_button' => true,
						),
					),
				)
			),
			'collapsible'  => apply_filters( 'zakra_header_button_accordion_collapsible', false ),
		),
	)
);

if ( ! zakra_is_zakra_pro_active() ) {
	$options['zakra_header_button_upgrade'] = array(
		'type'        => 'customind-upsell',
		'description' => esc_html__( 'Unlock more features available in Pro version.', 'zakra' ),
		'title'       => esc_html__( 'Learn more', 'zakra' ),
		'url'         => esc_url( 'https://zakratheme.com/pricing/?utm_medium=dash-customizer-learn-more&utm_source=zakra-theme&utm_campaign=customizer-upgrade-button&utm_content=learn-more' ),
		'section'     => 'zakra_header_button',
		'priority'    => 100,
	);
}

zakra_customind()->add_controls( $options );
