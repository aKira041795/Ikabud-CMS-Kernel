<?php

$options = array(
	'zakra_header_builder_cart_heading' => array(
		'type'         => 'customind-accordion',
		'title'        => esc_html__( 'Cart', 'zakra' ),
		'section'      => 'zakra_header_builder_cart',
		'sub_controls' => apply_filters(
			'zakra_header_builder_cart_sub_controls',
			array(
				'zakra_cart_color' => array(
					'default' => '',
					'type'    => 'customind-color',
					'title'   => esc_html__( 'Color', 'zakra' ),
					'section' => 'zakra_header_builder_cart',
				),
			)
		),
		'collapsible'  => apply_filters( 'zakra_header_builder_cart_accordion_collapsible', false ),
	),
);

if ( ! zakra_is_zakra_pro_active() ) {
	$options['zakra_header_builder_cart_upgrade'] = array(
		'type'        => 'customind-upsell',
		'description' => esc_html__( 'Unlock more features available in Pro version.', 'zakra' ),
		'title'       => esc_html__( 'Learn more', 'zakra' ),
		'url'         => esc_url( 'https://zakratheme.com/pricing/?utm_medium=dash-customizer-learn-more&utm_source=zakra-theme&utm_campaign=customizer-upgrade-button&utm_content=learn-more' ),
		'section'     => 'zakra_header_builder_cart',
		'priority'    => 100,
	);
}

zakra_customind()->add_controls( $options );
