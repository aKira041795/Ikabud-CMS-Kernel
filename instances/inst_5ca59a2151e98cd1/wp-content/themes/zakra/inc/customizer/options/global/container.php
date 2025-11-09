<?php

$options = array(
	'zakra_container_heading'            => array(
		'type'         => 'customind-accordion',
		'title'        => esc_html__( 'Container', 'zakra' ),
		'section'      => 'zakra_container',
		'sub_controls' => apply_filters(
			'zakra_container_sub_controls',
			array(
				'zakra_container_layout_general_heading' => array(
					'type'    => 'customind-title',
					'title'   => esc_html__( 'General', 'zakra' ),
					'section' => 'zakra_container',
				),
				'zakra_container_layout'                 => array(
					'default'   => 'wide',
					'type'      => 'customind-radio-image',
					'title'     => esc_html__( 'Layout', 'zakra' ),
					'section'   => 'zakra_container',
					'transport' => 'postMessage',
					'choices'   => array(
						'wide'  => array(
							'label' => esc_html__( 'Wide', 'zakra' ),
							'url'   => ZAKRA_PARENT_INC_ICON_URI . '/container-wide.svg',
						),
						'boxed' => array(
							'label' => esc_html__( 'Boxed', 'zakra' ),
							'url'   => ZAKRA_PARENT_INC_ICON_URI . '/container-box.svg',
						),
					),
					'columns'   => 2,
					'priority'  => 10,
				),
				'zakra_container_width_style_divider'    => array(
					'variant' => 'dashed',
					'type'    => 'customind-divider',
					'section' => 'zakra_container',
				),
				'zakra_container_width_style_heading'    => array(
					'type'    => 'customind-title',
					'title'   => esc_html__( 'Style', 'zakra' ),
					'section' => 'zakra_container',
				),
				'zakra_container_width'                  => array(
					'default'     => array(
						'size' => 1170,
						'unit' => 'px',
					),
					'type'        => 'customind-slider',
					'title'       => esc_html__( 'Width', 'zakra' ),
					'section'     => 'zakra_container',
					'transport'   => 'postMessage',
					'units'       => array( 'px' ),
					'defaultUnit' => 'px',
					'input_attrs' => array(
						'min'  => 768,
						'max'  => 1920,
						'step' => 1,
					),
				),
			)
		),
		'collapsible'  => apply_filters( 'zakra_container_accordion_collapsible', false ),
	),
	'zakra_container_background_heading' => array(
		'type'         => 'customind-accordion',
		'title'        => esc_html__( 'Background', 'zakra' ),
		'section'      => 'zakra_container',
		'sub_controls' => apply_filters(
			'zakra_container_background_sub_controls',
			array(
				'zakra_inside_container_background'  => array(
					'default'   => array(
						'background-color'      => '#ffffff',
						'background-image'      => '',
						'background-position'   => 'center center',
						'background-size'       => 'auto',
						'background-attachment' => 'scroll',
						'background-repeat'     => 'repeat',
					),
					'type'      => 'customind-background',
					'title'     => esc_html__( 'Inside Background', 'zakra' ),
					'section'   => 'zakra_container',
					'transport' => 'postMessage',
					'priority'  => 20,
				),
				'zakra_outside_container_background' => array(
					'default'   => array(
						'background-color'      => '',
						'background-image'      => '',
						'background-position'   => 'center center',
						'background-size'       => 'auto',
						'background-attachment' => 'scroll',
						'background-repeat'     => 'repeat',
					),
					'type'      => 'customind-background',
					'title'     => esc_html__( 'Outside Background', 'zakra' ),
					'section'   => 'zakra_container',
					'transport' => 'postMessage',
					'priority'  => 20,
				),
			)
		),
		'collapsible'  => apply_filters( 'zakra_container_background_accordion_collapsible', false ),
	),
);

if ( ! zakra_is_zakra_pro_active() ) {
	$options['zakra_base_colors_upgrade'] = array(
		'type'        => 'customind-upsell',
		'description' => esc_html__( 'Unlock more features available in Pro version.', 'zakra' ),
		'title'       => esc_html__( 'Learn more', 'zakra' ),
		'url'         => esc_url( 'https://zakratheme.com/pricing/?utm_medium=dash-customizer-learn-more&utm_source=zakra-theme&utm_campaign=customizer-upgrade-button&utm_content=learn-more' ),
		'section'     => 'zakra_container',
		'priority'    => 100,
	);
}

zakra_customind()->add_controls( $options );
