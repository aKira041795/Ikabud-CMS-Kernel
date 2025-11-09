<?php
$menus        = wp_get_nav_menus();
$menu_choices = array();

foreach ( $menus as $menu ) {
	$menu_choices[ $menu->term_id ] = $menu->name;
}
$options = array(
	'zakra_header_quaternary_menu_heading' => array(
		'type'         => 'customind-accordion',
		'title'        => esc_html__( 'Quaternary Menu', 'zakra' ),
		'section'      => 'zakra_header_builder_quaternary_menu',
		'sub_controls' => apply_filters(
			'zakra_header_quaternary_menu_sub_controls',
			array(
				'zakra_header_quaternary_menu'             => array(
					'default' => 'none',
					'type'    => 'customind-select',
					'title'   => esc_html__( 'Select a Menu', 'zakra' ),
					'section' => 'zakra_header_builder_quaternary_menu',
					'choices' => zakra_get_menu_options(),
				),
				'zakra_header_quaternary_menu_color_group' => array(
					'type'            => 'customind-color-group',
					'title'           => 'Color',
					'section'         => 'zakra_header_builder_quaternary_menu',
					'sub_controls'    => array(
						'zakra_header_quaternary_menu_color' => array(
							'default'   => '',
							'type'      => 'customind-color',
							'title'     => esc_html__( 'Normal', 'zakra' ),
							'transport' => 'postMessage',
							'section'   => 'zakra_header_builder_quaternary_menu',
						),
						'zakra_header_quaternary_menu_hover_color' => array(
							'default'   => '',
							'type'      => 'customind-color',
							'title'     => esc_html__( 'Hover', 'zakra' ),
							'transport' => 'postMessage',
							'section'   => 'zakra_header_builder_quaternary_menu',
						),
						'zakra_header_quaternary_menu_active_color' => array(
							'default'   => '',
							'type'      => 'customind-color',
							'title'     => esc_html__( 'Active', 'zakra' ),
							'transport' => 'postMessage',
							'section'   => 'zakra_header_builder_quaternary_menu',
						),
					),
					'active_callback' => function () {
						if ( 'default' === get_theme_mod( 'zakra_quaternary_menu_item_style', 'default' ) ) {
							return true;
						}

						return false;
					},
				),
				'zakra_header_quaternary_menu_typography'  => array(
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
					'section'   => 'zakra_header_builder_quaternary_menu',
				),
			)
		),
		'collapsible'  => apply_filters( 'zakra_quaternary_menu_accordion_collapsible', false ),
	),
);

if ( ! zakra_is_zakra_pro_active() ) {
	$options['zakra_quaternary_menu_upgrade2'] = array(
		'type'        => 'customind-upsell',
		'description' => esc_html__( 'Unlock more features available in Pro version.', 'zakra' ),
		'title'       => esc_html__( 'Learn more', 'zakra' ),
		'url'         => esc_url( 'https://zakratheme.com/pricing/?utm_medium=dash-customizer-learn-more&utm_source=zakra-theme&utm_campaign=customizer-upgrade-button&utm_content=learn-more' ),
		'section'     => 'zakra_header_builder_quaternary_menu',
		'priority'    => 100,
	);

}

zakra_customind()->add_controls( $options );
