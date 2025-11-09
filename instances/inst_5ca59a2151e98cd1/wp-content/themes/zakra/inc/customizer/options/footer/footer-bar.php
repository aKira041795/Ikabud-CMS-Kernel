<?php

$options = apply_filters(
	'zakra_footer_bottom_bar_options',
	array(
		'zakra_footer_bar_heading'          => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Footer Bar', 'zakra' ),
			'section'      => 'zakra_footer_bar',
			'priority'     => 5,
			'sub_controls' => apply_filters(
				'zakra_footer_bar_sub_controls',
				array(
					'zakra_footer_bar_style_heading'      => array(
						'type'     => 'customind-title',
						'title'    => esc_html__( 'General', 'zakra' ),
						'section'  => 'zakra_footer_bar',
						'priority' => 10,
					),
					'zakra_footer_bar_style'              => array(
						'default'   => 'style-2',
						'type'      => 'customind-radio-image',
						'title'     => esc_html__( 'Style', 'zakra' ),
						'section'   => 'zakra_footer_bar',
						'transport' => 'postMessage',
						'choices'   => apply_filters(
							'zakra_footer_bar_style_choices',
							array(
								'style-1' => array(
									'label' => '',
									'url'   => ZAKRA_PARENT_INC_ICON_URI . '/footer-bar-left.svg',
								),
								'style-2' => array(
									'label' => '',
									'url'   => ZAKRA_PARENT_INC_ICON_URI . '/footer-bar-center.svg',
								),
							)
						),
						'columns'   => 2,
						'priority'  => 10,
					),
					'zakra_footer_bar_style_divider'      => array(
						'type'     => 'customind-divider',
						'variant'  => 'dashed',
						'section'  => 'zakra_footer_bar',
						'priority' => 20,
					),
					'zakra_footer_bar_background_heading' => array(
						'type'     => 'customind-title',
						'title'    => esc_html__( 'Style', 'zakra' ),
						'section'  => 'zakra_footer_bar',
						'priority' => 20,
					),
					'zakra_footer_bar_background'         => array(
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
						'priority'  => 25,
						'section'   => 'zakra_footer_bar',
					),
					'zakra_footer_bar_border_top_width_divider' => array(
						'type'     => 'customind-divider',
						'variant'  => 'dashed',
						'section'  => 'zakra_footer_bar',
						'priority' => 50,
					),
					'zakra_footer_bar_border_top_width_heading' => array(
						'type'     => 'customind-title',
						'title'    => esc_html__( 'Border Top', 'zakra' ),
						'section'  => 'zakra_footer_bar',
						'priority' => 50,
					),
					'zakra_footer_bar_border_top_width'   => array(
						'default'     => array(
							'size'  => 1,
							'units' => 'px',
						),
						'type'        => 'customind-slider',
						'title'       => esc_html__( 'Width', 'zakra' ),
						'section'     => 'zakra_footer_bar',
						'transport'   => 'postMessage',
						'priority'    => 50,
						'units'       => array( 'px' ),
						'defaultUnit' => 'px',
						'input_attrs' => array(
							'min'  => 0,
							'max'  => 100,
							'step' => 1,
						),
					),
					'zakra_footer_bar_border_top_color'   => array(
						'title'     => esc_html__( 'Color', 'zakra' ),
						'default'   => '#3f3f46',
						'type'      => 'customind-color',
						'transport' => 'postMessage',
						'priority'  => 50,
						'section'   => 'zakra_footer_bar',
					),
				)
			),
			'collapsible'  => apply_filters( 'zakra_footer_bar_accordion_collapsible', false ),
		),
		'zakra_footer_bar_column_1_heading' => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Column 1', 'zakra' ),
			'section'      => 'zakra_footer_bar',
			'priority'     => 10,
			'sub_controls' => apply_filters(
				'zakra_footer_bar_column_1_sub_controls',
				array(
					'zakra_footer_bar_column_1_content_type' => array(
						'default' => 'text_html',
						'type'    => 'customind-select',
						'title'   => esc_html__( 'Content Type', 'zakra' ),
						'section' => 'zakra_footer_bar',
						'choices' => apply_filters(
							'zakra_footer_bar_section_one_choices',
							array(
								'none'      => esc_html__( 'None', 'zakra' ),
								'text_html' => esc_html__( 'Text/HTML', 'zakra' ),
								'menu'      => esc_html__( 'Menu', 'zakra' ),
								'widget'    => esc_html__( 'Widget', 'zakra' ),
							)
						),
					),
					'zakra_footer_bar_column_1_html' => array(
						'default'     => sprintf(
						/* translators: 1: Current Year, 2: Site Name, 3: Theme Link, 4: WordPress Link. */
							esc_html__( 'Copyright &copy; %1$s %2$s. Powered by %3$s and %4$s.', 'zakra' ),
							'{the-year}',
							'{site-link}',
							'{theme-link}',
							'{wp-link}'
						),
						'type'        => 'customind-editor',
						'title'       => esc_html__( 'Text/HTML for Column 1', 'zakra' ),
						'description' => wp_kses(
							'<a href="' . esc_url( 'https://docs.zakratheme.com/en/article/dynamic-strings-for-footer-copyright-content-13empt5/' ) . '" target="_blank">' . esc_html__( 'Docs Link', 'zakra' ) . '</a>',
							array(
								'a' => array(
									'href'   => true,
									'target' => true,
								),
							)
						),
						'section'     => 'zakra_footer_bar',
						'transport'   => 'postMessage',
						'partial'     => array(
							'selector'        => '.zak-site-footer-section-1',
							'render_callback' => 'Zakra_Customizer_Partials::customize_partial_footer_bar_1_html',
						),
						'condition'   => apply_filters(
							'zakra_footer_bar_section_one_html_cb',
							array(
								'zakra_footer_bar_column_1_content_type' => 'text_html',
							)
						),
					),
					'zakra_footer_bar_column_1_menu' => array(
						'default'   => 'none',
						'type'      => 'customind-select',
						'title'     => esc_html__( 'Select a Menu for Column 1', 'zakra' ),
						'section'   => 'zakra_footer_bar',
						'choices'   => zakra_get_menu_options(),
						'condition' => apply_filters(
							'zakra_footer_bar_section_one_menu_cb',
							array(
								'zakra_footer_bar_column_1_content_type' => 'menu',
							)
						),
					),
					'zakra_footer_bar_column_1_widget_navigation' => array(
						'title'     => esc_html__( 'Column 1 Widget Navigation', 'zakra' ),
						'type'      => 'customind-navigation',
						'section'   => 'zakra_footer_bar',
						'to'        => 'sidebar-widgets-footer-bar-col-1-sidebar',
						'nav_type'  => 'section',
						'condition' => array(
							'zakra_footer_bar_column_1_content_type' => 'widget',
						),
					),
				)
			),
			'collapsible'  => apply_filters( 'zakra_footer_bar_column_1_accordion_collapsible', false ),
		),
		'zakra_footer_bar_column_2_heading' => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Column 2', 'zakra' ),
			'section'      => 'zakra_footer_bar',
			'priority'     => 20,
			'sub_controls' => apply_filters(
				'zakra_footer_bar_column_2_sub_controls',
				array(
					'zakra_footer_bar_column_2_content_type' => array(
						'default' => 'none',
						'type'    => 'customind-select',
						'title'   => esc_html__( 'Content Type', 'zakra' ),
						'section' => 'zakra_footer_bar',
						'choices' => apply_filters(
							'zakra_footer_bar_section_two_choices',
							array(
								'none'      => esc_html__( 'None', 'zakra' ),
								'text_html' => esc_html__( 'Text/HTML', 'zakra' ),
								'menu'      => esc_html__( 'Menu', 'zakra' ),
								'widget'    => esc_html__( 'Widget', 'zakra' ),
							)
						),
					),
					'zakra_footer_bar_column_2_html' => array(
						'default'   => '',
						'type'      => 'customind-editor',
						'title'     => esc_html__( 'Text/HTML for Column 2', 'zakra' ),
						'section'   => 'zakra_footer_bar',
						'transport' => 'postMessage',
						'partial'   => array(
							'selector'        => '.zak-site-footer-section-2',
							'render_callback' => 'Zakra_Customizer_Partials::customize_partial_footer_bar_section_two_html',
						),
						'condition' => apply_filters(
							'zakra_footer_bar_section_two_html_cb',
							array(
								'zakra_footer_bar_column_2_content_type' => 'text_html',
							)
						),
					),
					'zakra_footer_bar_column_2_menu' => array(
						'default'   => 'none',
						'type'      => 'customind-select',
						'title'     => esc_html__( 'Select a Menu for Column 2', 'zakra' ),
						'section'   => 'zakra_footer_bar',
						'choices'   => zakra_get_menu_options(),
						'condition' => apply_filters(
							'zakra_footer_bar_section_two_menu_cb',
							array(
								'zakra_footer_bar_column_2_content_type' => 'menu',
							)
						),
					),
					'zakra_footer_bar_column_2_widget_navigation' => array(
						'title'     => esc_html__( 'Column 2 Widget Navigation', 'zakra' ),
						'type'      => 'customind-navigation',
						'section'   => 'zakra_footer_bar',
						'to'        => 'sidebar-widgets-footer-bar-col-2-sidebar',
						'nav_type'  => 'section',
						'condition' => array(
							'zakra_footer_bar_column_2_content_type' => 'widget',
						),
					),
				)
			),
			'collapsible'  => apply_filters( 'zakra_footer_bar_column_2_accordion_collapsible', false ),
		),
		'zakra_footer_bar_content_heading'  => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Footer Content', 'zakra' ),
			'section'      => 'zakra_footer_bar',
			'priority'     => 40,
			'sub_controls' => apply_filters(
				'zakra_footer_bar_content_sub_controls',
				array(
					'zakra_footer_bar_text_color' => array(
						'title'     => esc_html__( 'Color', 'zakra' ),
						'default'   => '#fafafa',
						'type'      => 'customind-color',
						'transport' => 'postMessage',
						'section'   => 'zakra_footer_bar',
					),
				)
			),
			'collapsible'  => apply_filters( 'zakra_footer_bar_content_accordion_collapsible', false ),
		),
		'zakra_footer_bar_link_heading'     => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Link', 'zakra' ),
			'section'      => 'zakra_footer_bar',
			'priority'     => 50,
			'sub_controls' => apply_filters(
				'zakra_footer_bar_link_sub_controls',
				array(
					'zakra_footer_bar_link_color_group' => array(
						'type'         => 'customind-color-group',
						'title'        => esc_html__( 'Color', 'zakra' ),
						'section'      => 'zakra_footer_bar',
						'sub_controls' => array(
							'zakra_footer_bar_link_color' => array(
								'default'   => '',
								'type'      => 'customind-color',
								'title'     => esc_html__( 'Normal', 'zakra' ),
								'transport' => 'postMessage',
								'section'   => 'zakra_footer_bar',
							),
							'zakra_footer_bar_link_hover_color' => array(
								'default'   => '',
								'type'      => 'customind-color',
								'title'     => esc_html__( 'Hover', 'zakra' ),
								'transport' => 'postMessage',
								'section'   => 'zakra_footer_bar',
							),
						),
					),
				)
			),
			'collapsible'  => apply_filters( 'zakra_footer_bar_link_accordion_collapsible', false ),
		),
	)
);
if ( ! zakra_is_zakra_pro_active() ) {
	$options['zakra_footer_bottom_bar_upgrade'] = array(
		'type'        => 'customind-upsell',
		'description' => esc_html__( 'Unlock more features available in Pro version.', 'zakra' ),
		'title'       => esc_html__( 'Learn more', 'zakra' ),
		'url'         => esc_url( 'https://zakratheme.com/pricing/?utm_medium=dash-customizer-learn-more&utm_source=zakra-theme&utm_campaign=customizer-upgrade-button&utm_content=learn-more' ),
		'section'     => 'zakra_footer_bar',
		'priority'    => 100,
	);
}

zakra_customind()->add_controls( $options );
