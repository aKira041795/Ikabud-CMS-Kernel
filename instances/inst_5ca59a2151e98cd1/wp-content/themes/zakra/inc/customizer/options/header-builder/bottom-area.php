<?php
$options = array(
	'zakra_header_bottom_area_heading' => array(
		'type'         => 'customind-accordion',
		'title'        => esc_html__( 'Bottom Area', 'zakra' ),
		'section'      => 'zakra_header_builder_bottom_area',
		'sub_controls' => apply_filters(
			'zakra_header_bottom_area_sub_controls',
			array(
				'zakra_header_bottom_area_container'    => array(
					'default'     => array(
						'size' => '',
						'unit' => 'px',
					),
					'type'        => 'customind-slider',
					'title'       => esc_html__( 'Container', 'zakra' ),
					'section'     => 'zakra_header_builder_bottom_area',
					'transport'   => 'postMessage',
					'units'       => array( 'px' ),
					'defaultUnit' => 'px',
					'input_attrs' => array(
						'min'  => 400,
						'max'  => 1920,
						'step' => 1,
					),
				),
				'zakra_header_bottom_area_height'       => array(
					'default'     => array(
						'size' => '',
						'unit' => 'px',
					),
					'type'        => 'customind-slider',
					'title'       => esc_html__( 'Height', 'zakra' ),
					'section'     => 'zakra_header_builder_bottom_area',
					'transport'   => 'postMessage',
					'units'       => array( 'px' ),
					'defaultUnit' => 'px',
					'input_attrs' => array(
						'min'  => 0,
						'max'  => 400,
						'step' => 1,
					),
				),
				'zakra_header_bottom_area_color'        => array(
					'title'     => esc_html__( 'Color', 'zakra' ),
					'default'   => '',
					'type'      => 'customind-color',
					'section'   => 'zakra_header_builder_bottom_area',
					'transport' => 'postMessage',
				),
				'zakra_header_bottom_area_background'   => array(
					'default'   => array(
						'background-color'      => '',
						'background-image'      => '',
						'background-repeat'     => 'repeat',
						'background-position'   => 'center center',
						'background-size'       => 'contain',
						'background-attachment' => 'scroll',
					),
					'type'      => 'customind-background',
					'title'     => esc_html__( 'Background', 'zakra' ),
					'transport' => 'postMessage',
					'section'   => 'zakra_header_builder_bottom_area',
				),

				'zakra_header_bottom_area_padding'      => array(
					'default'     => array(
						'top'    => '',
						'right'  => '',
						'bottom' => '',
						'left'   => '',
						'unit'   => 'px',
					),
					'type'        => 'customind-dimensions',
					'title'       => esc_html__( 'Padding', 'zakra' ),
					'section'     => 'zakra_header_builder_bottom_area',
					'transport'   => 'postMessage',
					'units'       => array( 'px', 'em' ),
					'defaultUnit' => 'px',
				),

				'zakra_header_bottom_area_margin'       => array(
					'default'     => array(
						'top'    => '',
						'right'  => '',
						'bottom' => '',
						'left'   => '',
						'unit'   => 'px',
					),
					'type'        => 'customind-dimensions',
					'title'       => esc_html__( 'Margin', 'zakra' ),
					'section'     => 'zakra_header_builder_bottom_area',
					'transport'   => 'postMessage',
					'units'       => array( 'px', 'em' ),
					'defaultUnit' => 'px',
				),

				'zakra_header_bottom_area_border_width' => array(
					'default'     => array(
						'top'    => '0',
						'right'  => '0',
						'bottom' => '0',
						'left'   => '0',
						'unit'   => 'px',
					),
					'type'        => 'customind-dimensions',
					'title'       => esc_html__( 'Border Width', 'zakra' ),
					'section'     => 'zakra_header_builder_bottom_area',
					'transport'   => 'postMessage',
					'units'       => array( 'px', 'em' ),
					'defaultUnit' => 'px',
				),

				'zakra_header_bottom_area_border_color' => array(
					'title'     => esc_html__( 'Border Color', 'zakra' ),
					'default'   => '',
					'type'      => 'customind-color',
					'section'   => 'zakra_header_builder_bottom_area',
					'transport' => 'postMessage',
				),
			)
		),
		'collapsible'  => apply_filters( 'zakra_header_bottom_area_background_accordion_collapsible', false ),
	),
);

if ( ! zakra_is_zakra_pro_active() ) {
	$options['zakra_bottom_area_upgrade'] = array(
		'type'        => 'customind-upsell',
		'description' => esc_html__( 'Unlock more features available in Pro version.', 'zakra' ),
		'title'       => esc_html__( 'Learn more', 'zakra' ),
		'url'         => esc_url( 'https://zakratheme.com/pricing/?utm_medium=dash-customizer-learn-more&utm_source=zakra-theme&utm_campaign=customizer-upgrade-button&utm_content=learn-more' ),
		'section'     => 'zakra_header_builder_bottom_area',
		'priority'    => 100,
	);
}

zakra_customind()->add_controls( $options );
