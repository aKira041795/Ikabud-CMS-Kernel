<?php

$options = apply_filters(
	'zakra_header_search_options',
	array(
		'zakra_search_heading' => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Search', 'zakra' ),
			'section'      => 'zakra_header_search',
			'sub_controls' => apply_filters(
				'zakra_search_sub_controls',
				array(
					'zakra_enable_header_search'         => array(
						'title'   => esc_html__( 'Enable', 'zakra' ),
						'default' => true,
						'type'    => 'customind-toggle',
						'section' => 'zakra_header_search',
					),
					'zakra_enable_product_search_search' => array(
						'title'   => esc_html__( 'Product Search', 'zakra' ),
						'default' => false,
						'type'    => 'customind-toggle',
						'section' => 'zakra_header_search',
					),
				)
			),
			'collapsible'  => apply_filters( 'zakra_search_accordion_collapsible', false ),
		),
	)
);

if ( ! zakra_is_zakra_pro_active() ) {
	$options['zakra_header_search_upgrade'] = array(
		'type'        => 'customind-upsell',
		'description' => esc_html__( 'Unlock more features available in Pro version.', 'zakra' ),
		'title'       => esc_html__( 'Learn more', 'zakra' ),
		'url'         => esc_url( 'https://zakratheme.com/pricing/?utm_medium=dash-customizer-learn-more&utm_source=zakra-theme&utm_campaign=customizer-upgrade-button&utm_content=learn-more' ),
		'section'     => 'zakra_header_search',
		'priority'    => 100,
	);
}

zakra_customind()->add_controls( $options );
