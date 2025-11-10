<?php

$options = array(
	'zakra_load_google_fonts_locally_heading' => array(
		'type'         => 'customind-accordion',
		'title'        => esc_html__( 'Load Google fonts locally', 'zakra' ),
		'section'      => 'zakra_optimization',
		'sub_controls' => apply_filters(
			'zakra_load_google_fonts_locally_sub_controls',
			array(
				'zakra_load_google_fonts_locally' => array(
					'default' => 0,
					'title'   => esc_html__( 'Enable', 'zakra' ),
					'type'    => 'customind-toggle',
					'section' => 'zakra_optimization',
				),
			)
		),
		'collapsible'  => apply_filters( 'zakra_load_google_fonts_locally_accordion_collapsible', false ),
	),
	'zakra_demo_migrated_heading'             => array(
		'type'         => 'customind-accordion',
		'title'        => esc_html__( 'Demo Migrated', 'zakra' ),
		'section'      => 'zakra_optimization',
		'sub_controls' => apply_filters(
			'zakra_demo_migrated_sub_controls',
			array(
				'demo_migrated_to_builder' => array(
					'default' => 0,
					'title'   => esc_html__( 'Demo migrated', 'zakra' ),
					'type'    => 'customind-toggle',
					'section' => 'zakra_optimization',
				),
			),
		),
		'collapsible'  => apply_filters( 'zakra_demo_migrated_accordion_collapsible', false ),
	),
);

if ( ! zakra_is_zakra_pro_active() ) {
	$options['zakra_additional_upgrade'] = array(
		'type'        => 'customind-upsell',
		'description' => esc_html__( 'Unlock more features available in Pro version.', 'zakra' ),
		'title'       => esc_html__( 'Learn more', 'zakra' ),
		'url'         => esc_url( 'https://zakratheme.com/pricing/?utm_medium=dash-customizer-learn-more&utm_source=zakra-theme&utm_campaign=customizer-upgrade-button&utm_content=learn-more' ),
		'section'     => 'zakra_optimization',
		'priority'    => 100,
	);
}

zakra_customind()->add_controls( $options );
