<?php
$options = apply_filters(
	'zakra_top_bar_options',
	array(
		'zakra_top_bar'                  => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Top Bar', 'zakra' ),
			'section'      => 'zakra_header_top',
			'priority'     => 5,
			'sub_controls' => apply_filters(
				'zakra_top_bar_sub_controls',
				array(
					'zakra_top_bar_general_heading'       => array(
						'type'     => 'customind-title',
						'title'    => esc_html__( 'General', 'zakra' ),
						'section'  => 'zakra_header_top',
						'priority' => 5,
					),
					'zakra_enable_top_bar'                => array(
						'title'    => esc_html__( 'Enable', 'zakra' ),
						'default'  => false,
						'type'     => 'customind-toggle',
						'section'  => 'zakra_header_top',
						'priority' => 5,
					),
					'zakra_top_bar_style_heading_divider' => array(
						'type'      => 'customind-divider',
						'variant'   => 'dashed',
						'section'   => 'zakra_header_top',
						'priority'  => 30,
						'condition' => array(
							'zakra_enable_top_bar' => true,
						),
					),
					'zakra_top_bar_style_heading'         => array(
						'type'      => 'customind-title',
						'title'     => esc_html__( 'Style', 'zakra' ),
						'section'   => 'zakra_header_top',
						'priority'  => 30,
						'condition' => array(
							'zakra_enable_top_bar' => true,
						),
					),
					'zakra_top_bar_color'                 => array(
						'title'     => esc_html__( 'Color', 'zakra' ),
						'default'   => '#FAFAFA',
						'type'      => 'customind-color',
						'section'   => 'zakra_header_top',
						'transport' => 'postMessage',
						'priority'  => 30,
						'condition' => array(
							'zakra_enable_top_bar' => true,
						),
					),
					'zakra_top_bar_background'            => array(
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
						'section'   => 'zakra_header_top',
						'condition' => array(
							'zakra_enable_top_bar' => true,
						),
						'priority'  => 30,
					),
				)
			),
			'collapsible'  => apply_filters( 'zakra_top_bar_accordion_collapsible', false ),
		),
		'zakra_top_bar_column_1_heading' => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Column 1', 'zakra' ),
			'section'      => 'zakra_header_top',
			'sub_controls' => apply_filters(
				'zakra_top_bar_column_1_sub_controls',
				array(
					'zakra_top_bar_column_1_content_type' => array(
						'default'   => 'text_html',
						'type'      => 'customind-select',
						'title'     => esc_html__( 'Content Type', 'zakra' ),
						'section'   => 'zakra_header_top',
						'choices'   => array(
							'none'      => esc_html__( 'None', 'zakra' ),
							'text_html' => esc_html__( 'Text/HTML', 'zakra' ),
							'menu'      => esc_html__( 'Menu', 'zakra' ),
							'widget'    => esc_html__( 'Widget', 'zakra' ),
						),
						'priority'  => 40,
						'condition' => array(
							'zakra_enable_top_bar' => true,
						),
					),
					'zakra_top_bar_column_1_html'         => array(
						'default'   => '',
						'type'      => 'customind-editor',
						'title'     => esc_html__( 'Text/HTML for Column 1', 'zakra' ),
						'section'   => 'zakra_header_top',
						'priority'  => 45,
						'condition' => array(
							'zakra_enable_top_bar' => true,
							'zakra_top_bar_column_1_content_type' => 'text_html',
						),
					),
					'zakra_top_bar_column_1_menu'         => array(
						'default'   => 'none',
						'type'      => 'customind-select',
						'title'     => esc_html__( 'Select a Menu for Column 1', 'zakra' ),
						'section'   => 'zakra_header_top',
						'choices'   => zakra_get_menu_options(),
						'priority'  => 45,
						'condition' => array(
							'zakra_enable_top_bar' => true,
							'zakra_top_bar_column_1_content_type' => 'menu',
						),
					),
					'zakra_top_bar_column_1_widget_navigation' => array(
						'title'     => esc_html__( 'Column 1 Widget Navigation', 'zakra' ),
						'type'      => 'customind-navigation',
						'section'   => 'zakra_header_top',
						'to'        => 'sidebar-widgets-top-bar-col-1-sidebar',
						'nav_type'  => 'section',
						'priority'  => 50,
						'condition' => array(
							'zakra_enable_top_bar' => true,
							'zakra_top_bar_column_1_content_type' => 'widget',
						),
					),
				)
			),
			'collapsible'  => apply_filters( 'zakra_top_bar_column_1_accordion_collapsible', false ),
			'condition'    => array(
				'zakra_enable_top_bar' => true,
			),
		),
		'zakra_top_bar_column_2_heading' => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Column 2', 'zakra' ),
			'section'      => 'zakra_header_top',
			'sub_controls' => apply_filters(
				'zakra_top_bar_column_2_sub_controls',
				array(
					'zakra_top_bar_column_2_content_type' => array(
						'default'   => 'none',
						'type'      => 'customind-select',
						'title'     => esc_html__( 'Content Type', 'zakra' ),
						'section'   => 'zakra_header_top',
						'choices'   => array(
							'none'      => esc_html__( 'None', 'zakra' ),
							'text_html' => esc_html__( 'Text/HTML', 'zakra' ),
							'menu'      => esc_html__( 'Menu', 'zakra' ),
							'widget'    => esc_html__( 'Widget', 'zakra' ),
						),
						'priority'  => 50,
						'condition' => array(
							'zakra_enable_top_bar'  => true,
							'zakra_top_bar_layout!' => 'layout-1',
						),
					),
					'zakra_top_bar_column_2_html'         => array(
						'default'   => '',
						'type'      => 'customind-editor',
						'title'     => esc_html__( 'Text/HTML for Column 2', 'zakra' ),
						'section'   => 'zakra_header_top',
						'priority'  => 55,
						'condition' => array(
							'zakra_enable_top_bar' => true,
							'zakra_top_bar_column_2_content_type' => 'text_html',
						),
					),
					'zakra_top_bar_column_2_menu'         => array(
						'default'   => 'none',
						'type'      => 'customind-select',
						'title'     => esc_html__( 'Select a Menu for Column 2', 'zakra' ),
						'section'   => 'zakra_header_top',
						'choices'   => zakra_get_menu_options(),
						'priority'  => 60,
						'condition' => array(
							'zakra_enable_top_bar' => true,
							'zakra_top_bar_column_2_content_type' => 'menu',
						),
					),
					'zakra_top_bar_column_2_widget_navigation' => array(
						'title'     => esc_html__( 'Column 2 Widget Navigation', 'zakra' ),
						'type'      => 'customind-navigation',
						'section'   => 'zakra_header_top',
						'to'        => 'sidebar-widgets-top-bar-col-2-sidebar',
						'nav_type'  => 'section',
						'priority'  => 55,
						'condition' => array(
							'zakra_enable_top_bar' => true,
							'zakra_top_bar_column_2_content_type' => 'widget',
						),
					),
				)
			),
			'collapsible'  => apply_filters( 'zakra_top_bar_column_2_accordion_collapsible', false ),
			'condition'    => array(
				'zakra_enable_top_bar'  => true,
				'zakra_top_bar_layout!' => 'layout-1',
			),
		),
	)
);

if ( ! zakra_is_zakra_pro_active() ) {
	$options['zakra_header_top_upgrade'] = array(
		'type'        => 'customind-upsell',
		'description' => esc_html__( 'Unlock more features available in Pro version.', 'zakra' ),
		'title'       => esc_html__( 'Learn more', 'zakra' ),
		'url'         => esc_url( 'https://zakratheme.com/pricing/?utm_medium=dash-customizer-learn-more&utm_source=zakra-theme&utm_campaign=customizer-upgrade-button&utm_content=learn-more' ),
		'section'     => 'zakra_header_top',
		'priority'    => 100,
	);
}

zakra_customind()->add_controls( $options );
