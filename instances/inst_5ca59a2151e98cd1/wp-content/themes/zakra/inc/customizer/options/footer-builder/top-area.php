<?php
$options = array(
	'zakra_footer_top_area_heading' => array(
		'type'         => 'customind-accordion',
		'title'        => esc_html__( 'Top Area', 'zakra' ),
		'section'      => 'zakra_footer_builder_top_area',
		'sub_controls' => apply_filters(
			'zakra_footer_top_area_sub_controls',
			array(
				'zakra_footer_top_area_cols'            => array(
					'type'        => 'customind-slider',
					'title'       => 'Top row cols',
					'default'     => 4,
					'priority'    => 7,
					'section'     => 'zakra_footer_builder_top_area',
					'input_attrs' => array(
						'min'  => 1,
						'max'  => 6,
						'step' => 1,
					),
				),
				'zakra_footer_top_area_container'       => array(
					'default'     => array(
						'size' => '',
						'unit' => 'px',
					),
					'type'        => 'customind-slider',
					'title'       => esc_html__( 'Container', 'zakra' ),
					'section'     => 'zakra_footer_builder_top_area',
					'transport'   => 'postMessage',
					'units'       => array( 'px' ),
					'defaultUnit' => 'px',
					'input_attrs' => array(
						'min'  => 400,
						'max'  => 1920,
						'step' => 1,
					),
				),
				'zakra_footer_top_area_height'          => array(
					'default'     => array(
						'size' => '',
						'unit' => 'px',
					),
					'type'        => 'customind-slider',
					'title'       => esc_html__( 'Height', 'zakra' ),
					'section'     => 'zakra_footer_builder_top_area',
					'transport'   => 'postMessage',
					'units'       => array( 'px' ),
					'defaultUnit' => 'px',
					'input_attrs' => array(
						'min'  => 0,
						'max'  => 400,
						'step' => 1,
					),
				),
				'zakra_footer_top_area_color'           => array(
					'title'     => esc_html__( 'Color', 'zakra' ),
					'default'   => '',
					'type'      => 'customind-color',
					'section'   => 'zakra_footer_builder_top_area',
					'transport' => 'postMessage',
				),
				'zakra_footer_top_area_background'      => array(
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
					'section'   => 'zakra_footer_builder_top_area',
				),

				'zakra_footer_top_area_padding'         => array(
					'default'     => array(
						'top'    => '20',
						'right'  => '',
						'bottom' => '20',
						'left'   => '',
						'unit'   => 'px',
					),
					'type'        => 'customind-dimensions',
					'title'       => esc_html__( 'Padding', 'zakra' ),
					'section'     => 'zakra_footer_builder_top_area',
					'transport'   => 'postMessage',
					'units'       => array( 'px', 'em' ),
					'defaultUnit' => 'px',
				),

				'zakra_footer_top_area_margin'          => array(
					'default'     => array(
						'top'    => '',
						'right'  => '',
						'bottom' => '',
						'left'   => '',
						'unit'   => 'px',
					),
					'type'        => 'customind-dimensions',
					'title'       => esc_html__( 'Margin', 'zakra' ),
					'section'     => 'zakra_footer_builder_top_area',
					'transport'   => 'postMessage',
					'units'       => array( 'px', 'em' ),
					'defaultUnit' => 'px',
				),

				'zakra_footer_top_area_border_width'    => array(
					'default'     => array(
						'top'    => '0',
						'right'  => '0',
						'bottom' => '0',
						'left'   => '0',
						'unit'   => 'px',
					),
					'type'        => 'customind-dimensions',
					'title'       => esc_html__( 'Border Width', 'zakra' ),
					'section'     => 'zakra_footer_builder_top_area',
					'transport'   => 'postMessage',
					'units'       => array( 'px', 'em' ),
					'defaultUnit' => 'px',
				),

				'zakra_footer_top_area_border_color'    => array(
					'title'     => esc_html__( 'Border Color', 'zakra' ),
					'default'   => '',
					'type'      => 'customind-color',
					'section'   => 'zakra_footer_builder_top_area',
					'transport' => 'postMessage',
				),
				'zakra_footer_top_inner_element_layout_divider' => array(
					'type'    => 'customind-divider',
					'variant' => 'dashed',
					'section' => 'zakra_footer_builder_top_area',
					'tab'     => 'general',
				),
				'zakra_footer_top_inner_element_layout_heading' => array(
					'type'    => 'customind-title',
					'title'   => esc_html__( 'Inner Elements', 'zakra' ),
					'section' => 'zakra_footer_builder_top_area',
				),
				'zakra_footer_top_inner_element_layout' => array(
					'type'      => 'customind-toggle-button',
					'title'     => esc_html__( ' Layout', 'zakra' ),
					'section'   => 'zakra_footer_builder_top_area',
					'transport' => 'postMessage',
					'choices'   => array(
						'column' => esc_html__( 'Stack', 'zakra' ),
						'row'    => esc_html__( 'Inline', 'zakra' ),
					),
				),

			)
		),
		'collapsible'  => apply_filters( 'zakra_footer_top_area_background_accordion_collapsible', false ),
	),
);

zakra_customind()->add_controls( $options );
