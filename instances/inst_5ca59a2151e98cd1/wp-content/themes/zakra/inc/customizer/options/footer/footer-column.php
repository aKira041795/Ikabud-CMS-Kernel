<?php

$options = apply_filters(
	'zakra_footer_column_options',
	array(
		'zakra_footer_column_heading'               => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Footer Column', 'zakra' ),
			'section'      => 'zakra_footer_column',
			'sub_controls' => apply_filters(
				'zakra_footer_column_sub_controls',
				array(
					'zakra_enable_footer_column'           => array(
						'title'    => esc_html__( 'Enable', 'zakra' ),
						'default'  => false,
						'type'     => 'customind-toggle',
						'priority' => 5,
						'section'  => 'zakra_footer_column',
					),
					'zakra_footer_column_layout_1_style'   => array(
						'default'     => 'style-4',
						'type'        => 'customind-radio-image',
						'title'       => esc_html__( 'Advanced Style', 'zakra' ),
						'section'     => 'zakra_footer_column',
						'priority'    => 8,
						'choices'     => apply_filters(
							'zakra_footer_widgets_style_choices',
							array(
								'style-1' => array(
									'label' => '',
									'url'   => ZAKRA_PARENT_INC_ICON_URI . '/footer-column-one.svg',
								),
								'style-2' => array(
									'label' => '',
									'url'   => ZAKRA_PARENT_INC_ICON_URI . '/footer-column-two.svg',
								),
								'style-3' => array(
									'label' => '',
									'url'   => ZAKRA_PARENT_INC_ICON_URI . '/footer-column-three.svg',
								),
								'style-4' => array(
									'label' => '',
									'url'   => ZAKRA_PARENT_INC_ICON_URI . '/footer-column-four.svg',
								),
							)
						),
						'input_attrs' => array(
							'columns' => 2,
						),
						'condition'   => array(
							'zakra_enable_footer_column'  => true,
							'zakra_footer_column_layout!' => 'layout-2',
						),
					),
					'zakra_footer_column_divider'          => array(
						'type'      => 'customind-divider',
						'variant'   => 'dashed',
						'section'   => 'zakra_footer_column',
						'priority'  => 11,
						'condition' => array(
							'zakra_enable_footer_column' => true,
						),
					),
					'zakra_footer_column_style_heading'    => array(
						'type'      => 'customind-title',
						'title'     => esc_html__( 'Style', 'zakra' ),
						'section'   => 'zakra_footer_column',
						'priority'  => 11,
						'condition' => array(
							'zakra_enable_footer_column' => true,
						),
					),
					'zakra_footer_column_background'       => array(
						'default'   => array(
							'background-color'      => '#18181B',
							'background-image'      => '',
							'background-repeat'     => 'repeat',
							'background-position'   => 'center center',
							'background-size'       => 'contain',
							'background-attachment' => 'scroll',
						),
						'type'      => 'customind-background',
						'title'     => esc_html__( 'Background', 'zakra' ),
						'transport' => 'postMessage',
						'section'   => 'zakra_footer_column',
						'priority'  => 11,
						'condition' => array(
							'zakra_enable_footer_column' => true,
						),
					),
					'zakra_footer_column_border_divider'   => array(
						'type'      => 'customind-divider',
						'variant'   => 'dashed',
						'section'   => 'zakra_footer_column',
						'priority'  => 15,
						'condition' => array(
							'zakra_enable_footer_column' => true,
						),
					),
					'zakra_footer_column_border_heading'   => array(
						'title'     => esc_html__( 'Border Top', 'zakra' ),
						'type'      => 'customind-title',
						'section'   => 'zakra_footer_column',
						'priority'  => 15,
						'condition' => array(
							'zakra_enable_footer_column' => true,
						),
					),
					'zakra_footer_column_border_top_width' => array(
						'default'     => array(
							'size'  => '',
							'units' => 'px',
						),
						'type'        => 'customind-slider',
						'title'       => esc_html__( 'Size', 'zakra' ),
						'section'     => 'zakra_footer_column',
						'transport'   => 'postMessage',
						'units'       => array( 'px' ),
						'defaultUnit' => 'px',
						'priority'    => 20,
						'input_attrs' => array(
							'min'  => 0,
							'max'  => 20,
							'step' => 1,
						),
						'condition'   => array(
							'zakra_enable_footer_column' => true,
						),
					),
					'zakra_footer_column_border_top_color' => array(
						'title'     => esc_html__( 'Color', 'zakra' ),
						'default'   => '#e9ecef',
						'type'      => 'customind-color',
						'section'   => 'zakra_footer_column',
						'transport' => 'postMessage',
						'priority'  => 25,
						'condition' => array(
							'zakra_enable_footer_column' => true,
						),
					),
				)
			),
			'collapsible'  => apply_filters( 'zakra_footer_column_accordion_collapsible', false ),
		),
		'zakra_footer_column_text_heading'          => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Text', 'zakra' ),
			'section'      => 'zakra_footer_column',
			'sub_controls' => apply_filters(
				'zakra_footer_column_text_sub_controls',
				array(
					'zakra_footer_column_widget_text_color' => array(
						'title'     => esc_html__( 'Color', 'zakra' ),
						'default'   => '#D4D4D8',
						'type'      => 'customind-color',
						'section'   => 'zakra_footer_column',
						'transport' => 'postMessage',
						'condition' => array(
							'zakra_enable_footer_column' => true,
						),
					),
				)
			),
			'collapsible'  => apply_filters( 'zakra_footer_column_text_accordion_collapsible', false ),
			'condition'    => array(
				'zakra_enable_footer_column' => true,
			),
		),
		'zakra_footer_column_widget_link_heading'   => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Link', 'zakra' ),
			'section'      => 'zakra_footer_column',
			'sub_controls' => apply_filters(
				'zakra_footer_column_widget_link_sub_controls',
				array(
					'zakra_footer_column_widget_link_color_group'   => array(
						'type'         => 'customind-color-group',
						'title'        => esc_html__( 'Color', 'zakra' ),
						'section'      => 'zakra_footer_column',
						'sub_controls' => array(
							'zakra_footer_column_widget_link_color'       => array(
								'default'   => '',
								'type'      => 'customind-color',
								'title'     => esc_html__( 'Normal', 'zakra' ),
								'transport' => 'postMessage',
								'section'   => 'zakra_footer_column',
							),
							'zakra_footer_column_widget_link_hover_color' => array(
								'default' => '',
								'type'    => 'customind-color',
								'title'   => esc_html__( 'Hover', 'zakra' ),
								'section' => 'zakra_footer_column',
							),
						),
						'condition'    => array(
							'zakra_enable_footer_column' => true,
						),
					),
				)
			),
			'collapsible'  => apply_filters( 'zakra_footer_column_widget_link_accordion_collapsible', false ),
			'condition'    => array(
				'zakra_enable_footer_column' => true,
			),
		),
		'zakra_footer_column_widgets_title_heading' => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Widget', 'zakra' ),
			'section'      => 'zakra_footer_column',
			'sub_controls' => apply_filters(
				'zakra_footer_column_widgets_title_sub_controls',
				array(
					'zakra_footer_column_widgets_heading' => array(
						'priority'  => 5,
						'type'      => 'customind-title',
						'title'     => esc_html__( 'Title', 'zakra' ),
						'section'   => 'zakra_footer_column',
						'condition' => array(
							'zakra_enable_footer_column' => true,
						),
					),
					'zakra_enable_footer_widgets_title'   => array(
						'title'     => esc_html__( 'Enable', 'zakra' ),
						'default'   => true,
						'type'      => 'customind-toggle',
						'section'   => 'zakra_footer_column',
						'priority'  => 5,
						'condition' => array(
							'zakra_enable_footer_column' => true,
						),
					),
					'zakra_footer_widgets_title_color'    => array(
						'title'     => esc_html__( 'Color', 'zakra' ),
						'default'   => '',
						'type'      => 'customind-color',
						'transport' => 'postMessage',
						'priority'  => 5,
						'section'   => 'zakra_footer_column',
						'condition' => array(
							'zakra_enable_footer_column' => true,
							'zakra_enable_footer_widgets_title' => true,
						),
					),
				)
			),
			'priority'     => 20,
			'collapsible'  => apply_filters( 'zakra_footer_column_widgets_title_accordion_collapsible', false ),
			'condition'    => array(
				'zakra_enable_footer_column' => true,
			),
		),
		'zakra_footer_column_list_item_heading'     => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Widget List Item', 'zakra' ),
			'section'      => 'zakra_footer_column',
			'priority'     => 25,
			'sub_controls' => apply_filters(
				'zakra_footer_column_list_item_sub_controls',
				array(
					'zakra_footer_widgets_item_border_bottom_width' => array(
						'default'     => array(
							'size'  => '',
							'units' => 'px',
						),
						'type'        => 'customind-slider',
						'title'       => esc_html__( 'Border Bottom Width', 'zakra' ),
						'section'     => 'zakra_footer_column',
						'transport'   => 'postMessage',
						'units'       => array( 'px' ),
						'defaultUnit' => 'px',
						'priority'    => 15,
						'input_attrs' => array(
							'min'  => 0,
							'max'  => 20,
							'step' => 1,
						),
						'condition'   => array(
							'zakra_enable_footer_column' => true,
						),
					),
					'zakra_footer_widgets_item_border_bottom_color' => array(
						'title'     => esc_html__( 'Border Bottom Color', 'zakra' ),
						'default'   => '#e9ecef',
						'type'      => 'customind-color',
						'section'   => 'zakra_footer_column',
						'priority'  => 15,
						'transport' => 'postMessage',
						'condition' => array(
							'zakra_enable_footer_column' => true,
						),
					),
				)
			),
			'collapsible'  => apply_filters( 'zakra_footer_column_list_item_accordion_collapsible', false ),
			'condition'    => array(
				'zakra_enable_footer_column' => true,
			),
		),
	)
);
if ( ! zakra_is_zakra_pro_active() ) {
	$options['zakra_footer_widgets_upgrade'] = array(
		'type'        => 'customind-upsell',
		'description' => esc_html__( 'Unlock more features available in Pro version.', 'zakra' ),
		'title'       => esc_html__( 'Learn more', 'zakra' ),
		'url'         => esc_url( 'https://zakratheme.com/pricing/?utm_medium=dash-customizer-learn-more&utm_source=zakra-theme&utm_campaign=customizer-upgrade-button&utm_content=learn-more' ),
		'section'     => 'zakra_footer_column',
		'priority'    => 100,
	);
}

zakra_customind()->add_controls( $options );
