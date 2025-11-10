<?php

$options = array(
	'zakra_header_builder_offset_area_heading' => array(
		'type'         => 'customind-accordion',
		'title'        => esc_html__( 'Offset Area', 'zakra' ),
		'section'      => 'zakra_header_builder_offset_area',
		'sub_controls' => apply_filters(
			'zakra_header_builder_offset_area_sub_controls',
			array(
				'zakra_header_mobile_menu_background' => array(
					'default'   => '',
					'type'      => 'customind-color',
					'title'     => esc_html__( 'Background', 'zakra' ),
					'transport' => 'postMessage',
					'section'   => 'zakra_header_builder_offset_area',
				),
			)
		),
		'collapsible'  => apply_filters( 'zakra_header_builder_offset_area_accordion_collapsible', false ),
	),
);

if ( ! zakra_is_zakra_pro_active() ) {
	$options['zakra_header_builder_offset_area_upgrade'] = array(
		'type'        => 'customind-upsell',
		'description' => esc_html__( 'Unlock more features available in Pro version.', 'zakra' ),
		'title'       => esc_html__( 'Learn more', 'zakra' ),
		'url'         => esc_url( 'https://zakratheme.com/pricing/?utm_medium=dash-customizer-learn-more&utm_source=zakra-theme&utm_campaign=customizer-upgrade-button&utm_content=learn-more' ),
		'section'     => 'zakra_header_builder_offset_area',
		'priority'    => 100,
	);
}

zakra_customind()->add_controls( $options );
