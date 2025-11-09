<?php

$options = array(
	'zakra_header_builder_widget_1_heading' => array(
		'type'         => 'customind-accordion',
		'title'        => esc_html__( 'Widget 1', 'zakra' ),
		'section'      => 'zakra_header_builder_widget_1',
		'sub_controls' => apply_filters(
			'zakra_header_builder_widget_1_sub_controls',
			array(
				'zakra_widget_1_title_color'           => array(
					'default'   => '',
					'type'      => 'customind-color',
					'title'     => esc_html__( 'Title Color', 'zakra' ),
					'transport' => 'postMessage',
					'section'   => 'zakra_header_builder_widget_1',
				),
				'zakra_widget_1_title_typography'      => array(
					'default'   => array(
						'font-family'    => 'default',
						'font-weight'    => '400',
						'font-size'      => array(
							'desktop' => array(
								'size' => '2',
								'unit' => 'rem',
							),
							'tablet'  => array(
								'size' => '',
								'unit' => '',
							),
							'mobile'  => array(
								'size' => '',
								'unit' => '',
							),
						),
						'line-height'    => array(
							'desktop' => array(
								'size' => '1.3',
								'unit' => '-',
							),
							'tablet'  => array(
								'size' => '',
								'unit' => '',
							),
							'mobile'  => array(
								'size' => '',
								'unit' => '',
							),
						),
						'font-style'     => 'normal',
						'text-transform' => 'none',
					),
					'type'      => 'customind-typography',
					'transport' => 'postMessage',
					'title'     => esc_html__( 'Title Typography', 'zakra' ),
					'section'   => 'zakra_header_builder_widget_1',
				),
				'zakra_widget_1_link_divider'          => array(
					'type'    => 'customind-divider',
					'variant' => 'dashed',
					'section' => 'zakra_blog',
				),
				'zakra_widget_1_link_color'            => array(
					'default'   => '',
					'type'      => 'customind-color',
					'title'     => esc_html__( 'Link', 'zakra' ),
					'transport' => 'postMessage',
					'section'   => 'zakra_header_builder_widget_1',
				),
				'zakra_widget_1_content_color_divider' => array(
					'type'    => 'customind-divider',
					'variant' => 'dashed',
					'section' => 'zakra_blog',
				),
				'zakra_widget_1_content_color'         => array(
					'default'   => '',
					'type'      => 'customind-color',
					'title'     => esc_html__( 'Content Color', 'zakra' ),
					'transport' => 'postMessage',
					'section'   => 'zakra_header_builder_widget_1',
				),
				'zakra_widget_1_content_typography'    => array(
					'default'   => array(
						'font-family'    => 'default',
						'font-weight'    => '400',
						'font-size'      => array(
							'desktop' => array(
								'size' => '1.4',
								'unit' => 'rem',
							),
							'tablet'  => array(
								'size' => '',
								'unit' => '',
							),
							'mobile'  => array(
								'size' => '',
								'unit' => '',
							),
						),
						'line-height'    => array(
							'desktop' => array(
								'size' => '1.8',
								'unit' => '-',
							),
							'tablet'  => array(
								'size' => '',
								'unit' => '',
							),
							'mobile'  => array(
								'size' => '',
								'unit' => '',
							),
						),
						'font-style'     => 'normal',
						'text-transform' => 'none',
					),
					'type'      => 'customind-typography',
					'transport' => 'postMessage',
					'title'     => esc_html__( 'Content Typography', 'zakra' ),
					'section'   => 'zakra_header_builder_widget_1',
				),
			)
		),
		'collapsible'  => apply_filters( 'zakra_header_builder_widget_1_accordion_collapsible', false ),
	),
);

if ( ! zakra_is_zakra_pro_active() ) {
	$options['zakra_header_builder_widget_1_upgrade'] = array(
		'type'        => 'customind-upsell',
		'description' => esc_html__( 'Unlock more features available in Pro version.', 'zakra' ),
		'title'       => esc_html__( 'Learn more', 'zakra' ),
		'url'         => esc_url( 'https://zakratheme.com/pricing/?utm_medium=dash-customizer-learn-more&utm_source=zakra-theme&utm_campaign=customizer-upgrade-button&utm_content=learn-more' ),
		'section'     => 'zakra_header_builder_widget_1',
		'priority'    => 100,
	);
}

zakra_customind()->add_controls( $options );
