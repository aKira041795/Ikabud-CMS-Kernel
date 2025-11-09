<?php

$options = apply_filters(
	'zakra_content_area_options',
	array(
		'zakra_content_area_heading' => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Content Area', 'zakra' ),
			'section'      => 'zakra_content_area',
			'priority'     => 5,
			'sub_controls' => apply_filters(
				'zakra_content_area_sub_controls',
				array(
					'zakra_content_area_layout_general_heading' => array(
						'type'    => 'customind-title',
						'title'   => esc_html__( 'General', 'zakra' ),
						'section' => 'zakra_content_area',
					),
					'zakra_content_area_layout'  => array(
						'default'     => 'bordered',
						'type'        => 'customind-radio-image',
						'title'       => esc_html__( 'Layout', 'zakra' ),
						'section'     => 'zakra_content_area',
						'transport'   => 'postMessage',
						'choices'     => array(
							'bordered' => array(
								'label' => esc_html__( 'Wide', 'zakra' ),
								'url'   => ZAKRA_PARENT_INC_ICON_URI . '/content-bordered.svg',
							),
							'boxed'    => array(
								'label' => esc_html__( 'Boxed', 'zakra' ),
								'url'   => ZAKRA_PARENT_INC_ICON_URI . '/content-boxed.svg',
							),
						),
						'input_attrs' => array(
							'columns' => 2,
						),
						'priority'    => 10,
					),
					'zakra_content_area_padding_style_divider' => array(
						'variant' => 'dashed',
						'type'    => 'customind-divider',
						'section' => 'zakra_content_area',
					),
					'zakra_content_area_padding_style_heading' => array(
						'type'    => 'customind-title',
						'title'   => esc_html__( 'Style', 'zakra' ),
						'section' => 'zakra_content_area',
					),
					'zakra_content_area_padding' => array(
						'default'     => array(
							'size'  => '',
							'units' => 'px',
						),
						'type'        => 'customind-slider',
						'title'       => esc_html__( 'Padding', 'zakra' ),
						'section'     => 'zakra_content_area',
						'transport'   => 'postMessage',
						'units'       => array( 'px' ),
						'defaultUnit' => 'px',
						'input_attrs' => array(
							'min'  => 0,
							'max'  => 500,
							'step' => 1,
						),
					),
				)
			),
			'collapsible'  => apply_filters( 'zakra_content_area_accordion_collapsible', false ),
		),
	)
);

if ( ! zakra_is_zakra_pro_active() ) {
	$options['zakra_content_area_upgrade'] = array(
		'type'        => 'customind-upsell',
		'description' => esc_html__( 'Unlock more features available in Pro version.', 'zakra' ),
		'title'       => esc_html__( 'Learn more', 'zakra' ),
		'url'         => esc_url( 'https://zakratheme.com/pricing/?utm_medium=dash-customizer-learn-more&utm_source=zakra-theme&utm_campaign=customizer-upgrade-button&utm_content=learn-more' ),
		'section'     => 'zakra_content_area',
		'priority'    => 100,
	);
}

zakra_customind()->add_controls( $options );
