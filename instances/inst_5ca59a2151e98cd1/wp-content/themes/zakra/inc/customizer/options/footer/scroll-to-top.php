<?php

$options = array(
	'zakra_scroll_to_top_heading'      => array(
		'type'         => 'customind-accordion',
		'title'        => esc_html__( 'Scroll To Top', 'zakra' ),
		'section'      => 'zakra_footer_scroll_to_top',
		'sub_controls' => apply_filters(
			'zakra_scroll_to_top_sub_controls',
			array(
				'zakra_enable_scroll_to_top'           => array(
					'title'    => esc_html__( 'Enable', 'zakra' ),
					'default'  => true,
					'type'     => 'customind-toggle',
					'priority' => 5,
					'section'  => 'zakra_footer_scroll_to_top',
				),
				'zakra_scroll_to_top_background_group' => array(
					'type'         => 'customind-color-group',
					'title'        => esc_html__( 'Background', 'zakra' ),
					'section'      => 'zakra_footer_scroll_to_top',
					'priority'     => 20,
					'sub_controls' => array(
						'zakra_scroll_to_top_background' => array(
							'default'   => '#16181a',
							'type'      => 'customind-color',
							'title'     => esc_html__( 'Normal', 'zakra' ),
							'transport' => 'postMessage',
							'section'   => 'zakra_footer_scroll_to_top',
						),
						'zakra_scroll_to_top_hover_background' => array(
							'default'   => '#1e7ba6',
							'type'      => 'customind-color',
							'title'     => esc_html__( 'Hover', 'zakra' ),
							'transport' => 'postMessage',
							'section'   => 'zakra_footer_scroll_to_top',
						),
					),
					'condition'    => array(
						'zakra_enable_scroll_to_top' => true,
					),
				),
			)
		),
		'collapsible'  => apply_filters( 'zakra_scroll_to_top_accordion_collapsible', false ),
	),
	'zakra_scroll_to_top_icon_heading' => array(
		'type'         => 'customind-accordion',
		'title'        => esc_html__( 'Icon', 'zakra' ),
		'section'      => 'zakra_footer_scroll_to_top',
		'sub_controls' => apply_filters(
			'zakra_scroll_to_top_icon_sub_controls',
			array(
				'zakra_scroll_to_top_icon_color_group' => array(
					'type'         => 'customind-color-group',
					'title'        => esc_html__( 'Color', 'zakra' ),
					'section'      => 'zakra_footer_scroll_to_top',
					'priority'     => 15,
					'sub_controls' => array(
						'zakra_scroll_to_top_icon_color' => array(
							'default'   => '#ffffff',
							'type'      => 'customind-color',
							'title'     => esc_html__( 'Normal', 'zakra' ),
							'transport' => 'postMessage',
							'section'   => 'zakra_footer_scroll_to_top',
						),
						'zakra_scroll_to_top_icon_hover_color' => array(
							'default'   => '#ffffff',
							'type'      => 'customind-color',
							'title'     => esc_html__( 'Hover', 'zakra' ),
							'transport' => 'postMessage',
							'section'   => 'zakra_footer_scroll_to_top',
						),
					),
					'condition'    => array(
						'zakra_enable_scroll_to_top' => true,
					),
				),
			)
		),
		'collapsible'  => apply_filters( 'zakra_scroll_to_top_icon_accordion_collapsible', false ),
		'condition'    => array(
			'zakra_enable_scroll_to_top' => true,
		),
	),
);
if ( ! zakra_is_zakra_pro_active() ) {
	$options['zakra_footer_scroll_to_top_upgrade'] = array(
		'type'        => 'customind-upsell',
		'description' => esc_html__( 'Unlock more features available in Pro version.', 'zakra' ),
		'title'       => esc_html__( 'Learn more', 'zakra' ),
		'url'         => esc_url( 'https://zakratheme.com/pricing/?utm_medium=dash-customizer-learn-more&utm_source=zakra-theme&utm_campaign=customizer-upgrade-button&utm_content=learn-more' ),
		'section'     => 'zakra_footer_scroll_to_top',
		'priority'    => 100,
	);
}

zakra_customind()->add_controls( $options );
