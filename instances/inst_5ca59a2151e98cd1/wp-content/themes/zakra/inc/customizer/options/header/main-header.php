<?php

$options = apply_filters(
	'zakra_main_header_options',
	array(
		'zakra_main_header_heading'            => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Main Header', 'zakra' ),
			'section'      => 'zakra_main_header',
			'priority'     => 10,
			'sub_controls' => apply_filters(
				'zakra_main_header_sub_controls',
				array(
					'zakra_main_header_layout'           => array(
						'default'   => 'layout-1',
						'type'      => 'customind-select',
						'title'     => esc_html__( 'Layout', 'zakra' ),
						'transport' => 'postMessage',
						'section'   => 'zakra_main_header',
						'choices'   => apply_filters(
							'zakra_main_header_layout_choices',
							array(
								'layout-1' => esc_html__( 'Layout 1', 'zakra' ),
								'layout-2' => esc_html__( 'Layout 2', 'zakra' ),
							)
						),
						'partial'   => array(
							'selector'        => '#zak-masthead',
							'render_callback' => 'Zakra_Customizer_Partials::customize_partial_header_main',
						),
					),
					'zakra_main_header_layout_1_style'   => array(
						'default'     => 'style-1',
						'type'        => 'customind-radio-image',
						'title'       => esc_html__( 'Advanced Style', 'zakra' ),
						'section'     => 'zakra_main_header',
						'priority'    => 10,
						'choices'     => apply_filters(
							'zakra_main_header_layout_1_style_choices',
							array(
								'style-1' => array(
									'label' => 'Left',
									'url'   => ZAKRA_PARENT_INC_ICON_URI . '/header-left.svg',
								),
								'style-2' => array(
									'label' => 'Center',
									'url'   => ZAKRA_PARENT_INC_ICON_URI . '/header-center.svg',
								),
								'style-3' => array(
									'label' => 'Right',
									'url'   => ZAKRA_PARENT_INC_ICON_URI . '/header-right.svg',
								),
							)
						),
						'input_attrs' => array(
							'columns' => 2,
						),
						'condition'   => apply_filters(
							'zakra_main_header_style_cb',
							array(
								'zakra_main_header_layout' => 'layout-1',
							)
						),
						'partial'     => array(
							'selector'        => '#zak-masthead',
							'render_callback' => 'Zakra_Customizer_Partials::customize_partial_header_main',
						),
					),
					'zakra_main_header_layout_2_style'   => array(
						'default'     => 'style-1',
						'type'        => 'customind-radio-image',
						'title'       => esc_html__( 'Advanced Style', 'zakra' ),
						'section'     => 'zakra_main_header',
						'priority'    => 10,
						'choices'     => apply_filters(
							'zakra_main_header_layout_2_style_choices',
							array(
								'style-1' => array(
									'label' => 'Two Row Logo Left',
									'url'   => ZAKRA_PARENT_INC_ICON_URI . '/two-row-logo-left.svg',
								),
							)
						),
						'input_attrs' => array(
							'columns' => 2,
						),
						'condition'   => apply_filters(
							'zakra_main_header_style_cb',
							array(
								'zakra_main_header_layout' => 'layout-2',
							)
						),
						'partial'     => array(
							'selector'        => '#zak-masthead',
							'render_callback' => 'Zakra_Customizer_Partials::customize_partial_header_main',
						),
					),
					'zakra_main_header_background_color_divider' => array(
						'type'     => 'customind-divider',
						'variant'  => 'dashed',
						'section'  => 'zakra_main_header',
						'priority' => 20,
					),
					'zakra_main_header_background_color_heading' => array(
						'type'     => 'customind-title',
						'title'    => esc_html__( 'Style', 'zakra' ),
						'section'  => 'zakra_main_header',
						'priority' => 20,
					),
					'zakra_main_header_background_color' => array(
						'default'   => array(
							'background-color'      => '',
							'background-image'      => '',
							'background-repeat'     => 'repeat',
							'background-position'   => 'center center',
							'background-size'       => 'contain',
							'background-attachment' => 'scroll',
						),
						'priority'  => 20,
						'type'      => 'customind-background',
						'title'     => esc_html__( 'Background', 'zakra' ),
						'transport' => 'postMessage',
						'section'   => 'zakra_main_header',
					),
					'zakra_blog_post_box_border_gap_divider' => array(
						'type'     => 'customind-divider',
						'variant'  => 'dashed',
						'section'  => 'zakra_main_header',
						'priority' => 30,
					),
					'zakra_main_header_border_bottom_heading' => array(
						'title'    => esc_html__( 'Border Bottom', 'zakra' ),
						'type'     => 'customind-title',
						'section'  => 'zakra_main_header',
						'priority' => 30,
					),
					'zakra_main_header_border_bottom_width' => array(
						'default'     => array(
							'size'  => 1,
							'units' => 'px',
						),
						'type'        => 'customind-slider',
						'title'       => esc_html__( 'Width', 'zakra' ),
						'section'     => 'zakra_main_header',
						'units'       => array( 'px' ),
						'defaultUnit' => 'px',
						'input_attrs' => array(
							'min'  => 0,
							'max'  => 20,
							'step' => 1,
						),
						'priority'    => 30,
					),
					'zakra_main_header_border_bottom_color' => array(
						'title'    => esc_html__( 'Color', 'zakra' ),
						'default'  => '#E4E4E7',
						'type'     => 'customind-color',
						'section'  => 'zakra_main_header',
						'priority' => 30,
					),
				)
			),
			'collapsible'  => apply_filters( 'zakra_main_header_accordion_collapsible', false ),
		),
		'zakra_main_header_components_heading' => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Components', 'zakra' ),
			'section'      => 'zakra_main_header',
			'priority'     => 50,
			'sub_controls' => apply_filters(
				'zakra_main_header_components_sub_controls',
				array(
					'zakra_search_navigation_info' => array(
						'title'    => esc_html__( 'Header Search Navigation', 'zakra' ),
						'type'     => 'customind-navigation',
						'section'  => 'zakra_main_header',
						'to'       => 'zakra_header_search',
						'nav_type' => 'section',
					),
				)
			),
			'collapsible'  => apply_filters( 'zakra_main_header_components_accordion_collapsible', false ),
		),
	)
);

if ( ! zakra_is_zakra_pro_active() ) {
	$options['zakra_main_header_upgrade'] = array(
		'type'        => 'customind-upsell',
		'description' => esc_html__( 'Unlock more features available in Pro version.', 'zakra' ),
		'title'       => esc_html__( 'Learn more', 'zakra' ),
		'url'         => esc_url( 'https://zakratheme.com/pricing/?utm_medium=dash-customizer-learn-more&utm_source=zakra-theme&utm_campaign=customizer-upgrade-button&utm_content=learn-more' ),
		'section'     => 'zakra_main_header',
		'priority'    => 100,
	);
}

zakra_customind()->add_controls( $options );
