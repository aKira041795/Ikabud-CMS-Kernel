<?php

$options = array(
	'zakra_search_heading' => array(
		'type'         => 'customind-accordion',
		'title'        => esc_html__( 'Search', 'zakra' ),
		'section'      => 'zakra_header_builder_search',
		'sub_controls' => apply_filters(
			'zakra_search_sub_controls',
			array(
				'zakra_enable_product_search_search' => array(
					'title'    => esc_html__( 'Product Search', 'zakra' ),
					'default'  => false,
					'type'     => 'customind-toggle',
					'section'  => 'zakra_header_builder_search',
					'priority' => 8,
				),
				'zakra_header_search_icon_color'     => array(
					'default'   => '',
					'type'      => 'customind-color',
					'title'     => esc_html__( 'Color', 'zakra' ),
					'transport' => 'postMessage',
					'section'   => 'zakra_header_builder_primary_menu',
				),
				'zakra_header_search_text_color'     => array(
					'default'   => '',
					'type'      => 'customind-color',
					'title'     => esc_html__( 'Text Color', 'zakra' ),
					'transport' => 'postMessage',
					'section'   => 'zakra_header_builder_primary_menu',
				),
				'zakra_header_search_background'     => array(
					'default'   => array(
						'background-color'      => '',
						'background-image'      => '',
						'background-position'   => 'center center',
						'background-size'       => 'auto',
						'background-attachment' => 'scroll',
						'background-repeat'     => 'repeat',
					),
					'type'      => 'customind-background',
					'title'     => esc_html__( 'Background', 'zakra' ),
					'section'   => 'zakra_header_builder_search',
					'transport' => 'postMessage',
					'priority'  => 20,
				),
			)
		),
		'collapsible'  => apply_filters( 'zakra_search_accordion_collapsible', false ),
	),
);

if ( ! zakra_is_zakra_pro_active() ) {
	$options['zakra_header_search_upgrade'] = array(
		'type'        => 'customind-upsell',
		'label'       => esc_html__( 'Learn more', 'zakra' ),
		'url'         => esc_url( 'https://zakratheme.com/pricing/?utm_medium=dash-customizer-learn-more&utm_source=zakra-theme&utm_campaign=customizer-upgrade-button&utm_content=learn-more' ),
		'description' => esc_html__( 'Unlock more features available in Pro version.', 'zakra' ),
		'section'     => 'zakra_header_builder_search',
		'priority'    => 100,
	);
}

zakra_customind()->add_controls( $options );
