<?php
$menus        = wp_get_nav_menus();
$menu_choices = array();

foreach ( $menus as $menu ) {
	$menu_choices[ $menu->term_id ] = $menu->name;
}
$options = array(
	'zakra_header_primary_menu_heading' => array(
		'type'         => 'customind-accordion',
		'title'        => esc_html__( 'Primary Menu', 'zakra' ),
		'section'      => 'zakra_header_builder_primary_menu',
		'priority'     => 9,
		'sub_controls' => apply_filters(
			'zakra_header_primary_menu_sub_controls',
			array(
				'zakra_header_primary_menu_navigation'  => array(
					'title'    => esc_html__( 'Select Menu Navigation', 'zakra' ),
					'type'     => 'customind-navigation',
					'section'  => 'zakra_header_builder_primary_menu',
					'to'       => 'menu_locations',
					'nav_type' => 'section',
					'priority' => 5,
				),
				'zakra_primary_menu_extra'              => array(
					'title'    => esc_html__( 'Priority plus navigation', 'zakra' ),
					'default'  => false,
					'type'     => 'customind-toggle',
					'section'  => 'zakra_header_builder_primary_menu',
					'priority' => 5,
				),
				'zakra_primary_menu_border_bottom_width_divider' => array(
					'type'    => 'customind-divider',
					'variant' => 'dashed',
					'section' => 'zakra_header_builder_primary_menu',
				),
				'zakra_primary_menu_border_bottom_width_heading' => array(
					'type'    => 'customind-title',
					'title'   => esc_html__( 'Border Bottom', 'zakra' ),
					'section' => 'zakra_header_builder_primary_menu',
				),
				'zakra_header_menu_border_bottom_width' => array(
					'default'     => array(
						'size'  => '',
						'units' => 'px',
					),
					'type'        => 'customind-slider',
					'title'       => esc_html__( 'Border Bottom Width', 'zakra' ),
					'section'     => 'zakra_header_builder_primary_menu',
					'transport'   => 'postMessage',
					'units'       => array( 'px' ),
					'defaultUnit' => 'px',
					'input_attrs' => array(
						'min'  => 0,
						'step' => 1,
						'max'  => 20,
					),
				),
				'zakra_header_menu_border_bottom_color' => array(
					'title'     => esc_html__( 'Border Bottom Color', 'zakra' ),
					'default'   => '#e9ecef',
					'type'      => 'customind-color',
					'transport' => 'postMessage',
					'section'   => 'zakra_header_builder_primary_menu',
				),
			)
		),
		'collapsible'  => apply_filters( 'zakra_primary_menu_accordion_collapsible', false ),
	),
	'zakra_header_main_menu_heading'    => array(
		'type'         => 'customind-accordion',
		'title'        => esc_html__( 'Main Menu', 'zakra' ),
		'section'      => 'zakra_header_builder_primary_menu',
		'sub_controls' => apply_filters(
			'zakra_header_main_menu_sub_controls',
			array(
				'zakra_main_menu_layout_1_style'     => array(
					'default'   => 'style-1',
					'type'      => 'customind-radio-image',
					'title'     => esc_html__( 'Advanced Style', 'zakra' ),
					'section'   => 'zakra_header_builder_primary_menu',
					'priority'  => 6,
					'choices'   => apply_filters(
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
					'columns'   => 2,
					'condition' => array(
						'zakra_main_menu_layout!' => 'layout-2',
					),
				),
				'zakra_header_main_menu_color_group' => array(
					'type'            => 'customind-color-group',
					'title'           => 'Color',
					'section'         => 'zakra_header_builder_primary_menu',
					'priority'        => 7,
					'sub_controls'    => array(
						'zakra_header_main_menu_color' => array(
							'default'   => '',
							'type'      => 'customind-color',
							'title'     => esc_html__( 'Normal', 'zakra' ),
							'transport' => 'postMessage',
							'section'   => 'zakra_header_builder_primary_menu',
						),
						'zakra_header_main_menu_hover_color' => array(
							'default'   => '',
							'type'      => 'customind-color',
							'title'     => esc_html__( 'Hover', 'zakra' ),
							'transport' => 'postMessage',
							'section'   => 'zakra_header_builder_primary_menu',
						),
						'zakra_header_main_menu_active_color' => array(
							'default'   => '',
							'type'      => 'customind-color',
							'title'     => esc_html__( 'Active', 'zakra' ),
							'transport' => 'postMessage',
							'section'   => 'zakra_header_builder_primary_menu',
						),
					),
					'active_callback' => function () {
						if ( 'default' === get_theme_mod( 'zakra_primary_menu_item_style', 'default' ) && get_theme_mod( 'zakra_enable_primary_menu', true ) ) {
							return true;
						}

						return false;
					},
				),
				'zakra_header_main_menu_typography'  => array(
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
					'transport' => 'postMessage',
					'section'   => 'zakra_header_builder_primary_menu',
					'priority'  => 9,
				),
			)
		),
		'collapsible'  => apply_filters( 'zakra_header_main_menu_accordion_collapsible', false ),
	),
	'zakra_header_sub_menu_heading'     => array(
		'type'         => 'customind-accordion',
		'title'        => esc_html__( 'Sub Menu', 'zakra' ),
		'section'      => 'zakra_header_builder_primary_menu',
		'sub_controls' => apply_filters(
			'zakra_header_sub_menu_sub_controls',
			array(
				'zakra_header_sub_menu_typography' => array(
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
					'section'   => 'zakra_header_builder_primary_menu',
					'priority'  => 11,
				),
			)
		),
		'collapsible'  => apply_filters( 'zakra_sub_menu_accordion_collapsible', false ),
	),

);

if ( ! zakra_is_zakra_pro_active() ) {
	$options['zakra_primary_menu_upgrade2'] = array(
		'type'        => 'customind-upsell',
		'description' => esc_html__( 'Unlock more features available in Pro version.', 'zakra' ),
		'title'       => esc_html__( 'Learn more', 'zakra' ),
		'url'         => esc_url( 'https://zakratheme.com/pricing/?utm_medium=dash-customizer-learn-more&utm_source=zakra-theme&utm_campaign=customizer-upgrade-button&utm_content=learn-more' ),
		'section'     => 'zakra_header_builder_primary_menu',
		'priority'    => 100,
	);

}

zakra_customind()->add_controls( $options );
