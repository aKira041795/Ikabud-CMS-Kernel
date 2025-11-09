<?php

$options = array(
	'zakra_button_heading' => array(
		'type'         => 'customind-accordion',
		'title'        => esc_html__( 'Button', 'zakra' ),
		'section'      => 'zakra_button',
		'sub_controls' => apply_filters(
			'zakra_button_sub_controls',
			array(
				'zakra_button_general_heading'        => array(
					'type'     => 'customind-title',
					'title'    => esc_html__( 'General', 'zakra' ),
					'priority' => 5,
					'section'  => 'zakra_button',
				),
				'zakra_button_color_group'            => array(
					'type'         => 'customind-color-group',
					'title'        => esc_html__( 'Color', 'zakra' ),
					'section'      => 'zakra_button',
					'priority'     => 5,
					'sub_controls' => array(
						'zakra_button_color'       => array(
							'default'   => '',
							'type'      => 'customind-color',
							'title'     => esc_html__( 'Normal', 'zakra' ),
							'transport' => 'postMessage',
							'section'   => 'zakra_button',
						),
						'zakra_button_hover_color' => array(
							'default'   => '',
							'type'      => 'customind-color',
							'title'     => esc_html__( 'Hover', 'zakra' ),
							'transport' => 'postMessage',
							'section'   => 'zakra_button',
						),
					),
				),
				'zakra_button_background_color_group' => array(
					'type'         => 'customind-color-group',
					'title'        => esc_html__( 'Background', 'zakra' ),
					'section'      => 'zakra_button',
					'priority'     => 10,
					'sub_controls' => array(
						'zakra_button_background_color' => array(
							'default'   => '#027abb',
							'type'      => 'customind-color',
							'title'     => esc_html__( 'Normal', 'zakra' ),
							'transport' => 'postMessage',
							'section'   => 'zakra_button',
						),
						'zakra_button_background_hover_color' => array(
							'default'   => '',
							'type'      => 'customind-color',
							'title'     => esc_html__( 'Hover', 'zakra' ),
							'transport' => 'postMessage',
							'section'   => 'zakra_button',
						),
					),
				),
				'zakra_button_typography'             => array(
					'default'   => array(
						'font-family'    => 'default',
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
					'transport' => 'postMessage',
					'title'     => esc_html__( 'Typography', 'zakra' ),
					'section'   => 'zakra_button',
					'priority'  => 15,
				),
				'zakra_button_padding'                => array(
					'default'     => array(
						'top'    => '10',
						'right'  => '20',
						'bottom' => '10',
						'left'   => '20',
						'unit'   => 'px',
					),
					'priority'    => 20,
					'type'        => 'customind-dimensions',
					'title'       => esc_html__( 'Padding', 'zakra' ),
					'section'     => 'zakra_button',
					'transport'   => 'postMessage',
					'units'       => array( 'px', 'em', '%', 'rem' ),
					'defaultUnit' => 'px',
				),
				'zakra_button_border_radius_divider'  => array(
					'type'     => 'customind-divider',
					'variant'  => 'dashed',
					'priority' => 40,
					'section'  => 'zakra_button',
				),
				'zakra_button_border_radius_heading'  => array(
					'type'     => 'customind-title',
					'title'    => esc_html__( 'Border', 'zakra' ),
					'priority' => 40,
					'section'  => 'zakra_button',
				),
				'zakra_button_border_radius'          => array(
					'title'       => esc_html__( 'Radius', 'zakra' ),
					'default'     => array(
						'size' => '',
						'unit' => 'px',
					),
					'priority'    => 55,
					'type'        => 'customind-slider',
					'section'     => 'zakra_button',
					'transport'   => 'postMessage',
					'units'       => array( 'px' ),
					'defaultUnit' => 'px',
					'input_attrs' => array(
						'min'  => 0,
						'max'  => 50,
						'step' => 1,
					),
				),
			)
		),
		'collapsible'  => apply_filters( 'zakra_button_accordion_collapsible', false ),
	),
);

if ( ! zakra_is_zakra_pro_active() ) {
	$options['zakra_styling_button_upgrade'] = array(
		'type'        => 'customind-upsell',
		'description' => esc_html__( 'Unlock more features available in Pro version.', 'zakra' ),
		'title'       => esc_html__( 'Learn more', 'zakra' ),
		'url'         => esc_url( 'https://zakratheme.com/pricing/?utm_medium=dash-customizer-learn-more&utm_source=zakra-theme&utm_campaign=customizer-upgrade-button&utm_content=learn-more' ),
		'section'     => 'zakra_button',
		'priority'    => 100,
	);
}

zakra_customind()->add_controls( $options );
