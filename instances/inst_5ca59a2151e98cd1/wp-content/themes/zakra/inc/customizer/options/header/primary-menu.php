<?php

$options = apply_filters(
	'zakra-primary_menu_options',
	array(
		'zakra_primary_menu_heading' => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Primary Menu', 'zakra' ),
			'section'      => 'zakra_menu',
			'sub_controls' => apply_filters(
				'zakra_primary_menu_sub_controls',
				array(
					'zakra_enable_primary_menu_general_heading' => array(
						'type'    => 'customind-title',
						'title'   => esc_html__( 'General', 'zakra' ),
						'section' => 'zakra_menu',
					),
					'zakra_enable_primary_menu' => array(
						'title'   => esc_html__( 'Enable', 'zakra' ),
						'default' => true,
						'type'    => 'customind-toggle',
						'section' => 'zakra_menu',
					),
					'zakra_primary_menu_extra'  => array(
						'title'     => esc_html__( 'Priority plus navigation', 'zakra' ),
						'default'   => false,
						'type'      => 'customind-toggle',
						'section'   => 'zakra_menu',
						'condition' => array(
							'zakra_enable_primary_menu' => true,
						),
					),
					'zakra_primary_menu_border_bottom_width_divider' => array(
						'type'      => 'customind-divider',
						'variant'   => 'dashed',
						'section'   => 'zakra_menu',
						'priority'  => 35,
						'condition' => array(
							'zakra_enable_primary_menu' => true,
						),
					),
					'zakra_primary_menu_border_bottom_width_heading' => array(
						'type'      => 'customind-title',
						'title'     => esc_html__( 'Border Bottom', 'zakra' ),
						'section'   => 'zakra_blog',
						'priority'  => 35,
						'condition' => array(
							'zakra_enable_primary_menu' => true,
						),
					),
					'zakra_primary_menu_border_bottom_width' => array(
						'default'     => array(
							'size'  => '',
							'units' => 'px',
						),
						'type'        => 'customind-slider',
						'title'       => esc_html__( 'Width', 'zakra' ),
						'section'     => 'zakra_menu',
						'transport'   => 'postMessage',
						'units'       => array( 'px' ),
						'defaultUnit' => 'px',
						'priority'    => 35,
						'input_attrs' => array(
							'min'  => 0,
							'max'  => 20,
							'step' => 1,
						),
						'condition'   => array(
							'zakra_enable_primary_menu' => true,
						),
					),
					'zakra_primary_menu_border_bottom_color' => array(
						'title'     => esc_html__( 'Color', 'zakra' ),
						'default'   => '#e9ecef',
						'type'      => 'customind-color',
						'section'   => 'zakra_menu',
						'priority'  => 40,
						'condition' => array(
							'zakra_enable_primary_menu' => true,
						),
					),
				)
			),
			'collapsible'  => apply_filters( 'zakra_primary_menu_accordion_collapsible', false ),
		),
		'zakra_main_menu_heading'    => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Main Menu', 'zakra' ),
			'section'      => 'zakra_menu',
			'sub_controls' => apply_filters(
				'zakra_main_menu_sub_controls',
				array(
					'zakra_main_menu_layout_1_style' => array(
						'default'         => 'style-1',
						'type'            => 'customind-radio-image',
						'title'           => esc_html__( 'Active Style', 'zakra' ),
						'section'         => 'zakra_menu',
						'transport'       => 'postMessage',
						'choices'         => apply_filters(
							'zakra_main_menu_layout_1_style_choices',
							array(
								'style-1' => array(
									'label' => 'None',
									'url'   => ZAKRA_PARENT_INC_ICON_URI . '/menu-active-none.svg',
								),
								'style-2' => array(
									'label' => 'Underline Border',
									'url'   => ZAKRA_PARENT_INC_ICON_URI . '/menu-active-underline.svg',
								),
								'style-3' => array(
									'label' => 'Left Border',
									'url'   => ZAKRA_PARENT_INC_ICON_URI . '/menu-active-left.svg',
								),
								'style-4' => array(
									'label' => 'Right Border',
									'url'   => ZAKRA_PARENT_INC_ICON_URI . '/menu-active-right.svg',
								),
							)
						),
						'input_attrs'     => array(
							'columns' => 2,
						),
						'condition'       => array(
							'zakra_main_menu_layout!' => 'layout-2',
						),
						'active_callback' => function () {
							if ( 'default' === get_theme_mod( 'zakra_primary_menu_item_style', 'default' ) && get_theme_mod( 'zakra_enable_primary_menu', true ) && 'layout-2' !== get_theme_mod( 'zakra_main_menu_layout', 'layout-1' ) ) {
								return true;
							}

							return false;
						},
					),
					'zakra_main_menu_color_divider'  => array(
						'type'      => 'customind-divider',
						'variant'   => 'dashed',
						'section'   => 'zakra_menu',
						'condition' => array(
							'zakra_enable_primary_menu' => true,
						),
					),
					'zakra_main_menu_color_heading'  => array(
						'type'      => 'customind-title',
						'title'     => esc_html__( 'Style', 'zakra' ),
						'section'   => 'zakra_blog',
						'condition' => array(
							'zakra_enable_primary_menu' => true,
						),
					),
					'zakra_main_menu_color_group'    => array(
						'type'            => 'customind-color-group',
						'title'           => 'Color',
						'section'         => 'zakra_menu',
						'sub_controls'    => array(
							'zakra_main_menu_color'        => array(
								'default'   => '',
								'type'      => 'customind-color',
								'title'     => esc_html__( 'Normal', 'zakra' ),
								'transport' => 'postMessage',
								'section'   => 'zakra_menu',
							),
							'zakra_main_menu_hover_color'  => array(
								'default'   => '',
								'type'      => 'customind-color',
								'title'     => esc_html__( 'Hover', 'zakra' ),
								'transport' => 'postMessage',
								'section'   => 'zakra_menu',
							),
							'zakra_main_menu_active_color' => array(
								'default'   => '',
								'type'      => 'customind-color',
								'title'     => esc_html__( 'Active', 'zakra' ),
								'transport' => 'postMessage',
								'section'   => 'zakra_menu',
							),
						),
						'active_callback' => function () {
							if ( 'default' === get_theme_mod( 'zakra_primary_menu_item_style', 'default' ) && get_theme_mod( 'zakra_enable_primary_menu', true ) ) {
								return true;
							}

							return false;
						},
					),
					'zakra_main_menu_typography'     => array(
						'default'   => array(
							'font-family'    => 'Default',
							'font-weight'    => 'regular',
							'font-size'      => array(
								'desktop' => array(
									'size' => '1.6',
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
						'title'     => esc_html__( 'Typography', 'zakra' ),
						'section'   => 'zakra_menu',
						'transport' => 'postMessage',
					),
				)
			),
			'collapsible'  => apply_filters( 'zakra_main_menu_accordion_collapsible', false ),
			'condition'    => array(
				'zakra_enable_primary_menu' => true,
			),
		),
		'zakra_sub_menu_heading'     => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Sub Menu', 'zakra' ),
			'section'      => 'zakra_menu',
			'sub_controls' => apply_filters(
				'zakra_sub_menu_sub_controls',
				array(
					'zakra_sub_menu_typography' => array(
						'default'   => array(
							'font-family'    => 'Default',
							'font-weight'    => '400',
							'font-size'      => array(
								'desktop' => array(
									'size' => '1.6',
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
						'title'     => esc_html__( 'Typography', 'zakra' ),
						'transport' => 'postMessage',
						'section'   => 'zakra_menu',
						'priority'  => 15,
						'condition' => array(
							'zakra_enable_primary_menu' => true,
						),
					),
				)
			),
			'collapsible'  => apply_filters( 'zakra_sub_menu_accordion_collapsible', false ),
			'condition'    => array(
				'zakra_enable_primary_menu' => true,
			),
		),
		'zakra_mobile_menu_heading'  => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Mobile Menu', 'zakra' ),
			'section'      => 'zakra_menu',
			'sub_controls' => apply_filters(
				'zakra_mobile_menu_sub_controls',
				array(
					'zakra_mobile_menu_typography' => array(
						'default'   => array(
							'font-family'    => 'Default',
							'font-weight'    => '400',
							'font-size'      => array(
								'desktop' => array(
									'size' => '1.6',
									'unit' => 'rem',
								),
								'tablet'  => array(
									'size' => '',
									'unit' => '',
								),
								'mobile'  => array(
									'size' => '1.6',
									'unit' => 'rem',
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
									'size' => '1.8',
									'unit' => '-',
								),
							),
							'font-style'     => 'normal',
							'text-transform' => 'none',
						),
						'type'      => 'customind-typography',
						'priority'  => 15,
						'title'     => esc_html__( 'Typography', 'zakra' ),
						'transport' => 'postMessage',
						'section'   => 'zakra_menu',
						'condition' => array(
							'zakra_enable_primary_menu' => true,
						),
					),
				)
			),
			'collapsible'  => apply_filters( 'zakra_mobile_menu_accordion_collapsible', false ),
			'condition'    => array(
				'zakra_enable_primary_menu' => true,
			),
		),
	)
);

if ( ! zakra_is_zakra_pro_active() ) {
	$options['zakra_primary_menu_upgrade'] = array(
		'type'        => 'customind-upsell',
		'description' => esc_html__( 'Unlock more features available in Pro version.', 'zakra' ),
		'title'       => esc_html__( 'Learn more', 'zakra' ),
		'url'         => esc_url( 'https://zakratheme.com/pricing/?utm_medium=dash-customizer-learn-more&utm_source=zakra-theme&utm_campaign=customizer-upgrade-button&utm_content=learn-more' ),
		'section'     => 'zakra_menu',
		'priority'    => 100,
	);
}

zakra_customind()->add_controls( $options );
