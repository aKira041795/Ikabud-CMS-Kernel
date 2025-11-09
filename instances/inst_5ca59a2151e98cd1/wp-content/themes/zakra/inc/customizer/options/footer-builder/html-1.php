<?php

$options = array(
	'zakra_footer_html_1_heading' => array(
		'type'         => 'customind-accordion',
		'title'        => esc_html__( 'HTML 1', 'zakra' ),
		'section'      => 'zakra_footer_builder_html_1',
		'sub_controls' => apply_filters(
			'zakra_footer_html_1_sub_controls',
			array(
				'zakra_footer_html_1'                  => array(
					'default'  => '',
					'type'     => 'customind-editor',
					'title'    => esc_html__( 'Text/HTML for HTML 1', 'zakra' ),
					'section'  => 'zakra_footer_builder_html_1',
					'priority' => 30,
				),
				'zakra_footer_html_1_text_color'       => array(
					'title'     => esc_html__( 'Color', 'zakra' ),
					'default'   => '',
					'type'      => 'customind-color',
					'section'   => 'zakra_footer_builder_html_1',
					'transport' => 'postMessage',
				),
				'zakra_footer_html_1_link_color_group' => array(
					'type'         => 'customind-color-group',
					'title'        => esc_html__( 'Links', 'zakra' ),
					'section'      => 'zakra_footer_builder_html_1',
					'sub_controls' => apply_filters(
						'zakra_footer_html_1_link_color_sub_controls',
						array(
							'zakra_footer_html_1_link_color'       => array(
								'default'   => '',
								'type'      => 'customind-color',
								'title'     => esc_html__( 'Normal', 'zakra' ),
								'transport' => 'postMessage',
								'section'   => 'zakra_footer_builder_html_1',
							),
							'zakra_footer_html_1_link_hover_color' => array(
								'default'   => '',
								'type'      => 'customind-color',
								'title'     => esc_html__( 'Hover', 'zakra' ),
								'transport' => 'postMessage',
								'section'   => 'zakra_footer_builder_html_1',
							),
						)
					),
				),
				'zakra_footer_html_1_font_size'        => array(
					'title'       => esc_html__( 'Font Size', 'zakra' ),
					'default'     => array(
						'size' => '',
						'unit' => 'px',
					),
					'type'        => 'customind-slider',
					'section'     => 'zakra_footer_builder_html_1',
					'transport'   => 'postMessage',
					'units'       => array( 'px', 'em', 'rem' ),
					'defaultUnit' => 'px',
					'input_attrs' => array(
						'min'  => 0,
						'max'  => 100,
						'step' => 1,
					),
				),
				'zakra_footer_html_1_margin'           => array(
					'default'     => array(
						'top'    => '',
						'right'  => '',
						'bottom' => '',
						'left'   => '',
						'unit'   => 'px',
					),
					'type'        => 'customind-dimensions',
					'title'       => 'Margin',
					'section'     => 'zakra_footer_builder_html_1',
					'transport'   => 'postMessage',
					'units'       => array( 'px', 'em', '%', 'rem' ),
					'defaultUnit' => 'px',
				),
				'zakra_html_1_alignment_divider'       => array(
					'type'    => 'customind-divider',
					'variant' => 'dashed',
					'section' => 'zakra_footer_builder_html_1',
				),
				'zakra_html_1_alignment'               => array(
					'default'   => '',
					'type'      => 'customind-toggle-button',
					'title'     => esc_html__( 'Alignment', 'zakra' ),
					'section'   => 'zakra_footer_builder_html_1',
					'transport' => 'postMessage',
					'choices'   => array(
						'left'   => esc_html__( 'Left', 'zakra' ),
						'center' => esc_html__( 'Center', 'zakra' ),
						'right'  => esc_html__( 'Right', 'zakra' ),
					),
				),
			)
		),
		'collapsible'  => apply_filters( 'zakra_footer_html_1_accordion_collapsible', false ),
	),
);

if ( ! zakra_is_zakra_pro_active() ) {
	$options['zakra_html_1_upgrade'] = array(
		'type'        => 'customind-upsell',
		'description' => esc_html__( 'Unlock more features available in Pro version.', 'zakra' ),
		'title'       => esc_html__( 'Learn more', 'zakra' ),
		'url'         => esc_url( 'https://zakratheme.com/pricing/?utm_medium=dash-customizer-learn-more&utm_source=zakra-theme&utm_campaign=customizer-upgrade-button&utm_content=learn-more' ),
		'section'     => 'zakra_footer_builder_html_1',
		'priority'    => 100,
	);
}

zakra_customind()->add_controls( $options );
