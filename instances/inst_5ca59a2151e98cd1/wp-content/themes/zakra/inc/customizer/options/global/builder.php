<?php

$options = array(
	'zakra_builder_heading' => array(
		'type'         => 'customind-accordion',
		'title'        => esc_html__( 'Builder', 'zakra' ),
		'section'      => 'zakra_builder',
		'sub_controls' => apply_filters(
			'zakra_builder_sub_controls',
			array(
				'zakra_enable_builder' => array(
					'default' => '',
					'title'   => esc_html__( 'Enable', 'zakra' ),
					'type'    => 'customind-builder-migration',
					'section' => 'zakra_builder',
				),
			)
		),
		'collapsible'  => apply_filters( 'zakra_builder_accordion_collapsible', false ),
	),
);
zakra_customind()->add_controls( $options );
