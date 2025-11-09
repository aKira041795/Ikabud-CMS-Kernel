<?php
$menus        = wp_get_nav_menus();
$menu_choices = array();

foreach ( $menus as $menu ) {
	$menu_choices[ $menu->term_id ] = $menu->name;
}
$options = array(
	'zakra_header_mobile_menu_heading' => array(
		'type'         => 'customind-accordion',
		'title'        => esc_html__( 'Mobile Menu', 'zakra' ),
		'section'      => 'zakra_header_builder_mobile_menu',
		'priority'     => 9,
		'sub_controls' => apply_filters(
			'zakra_header_mobile_menu_sub_controls',
			array(
				'zakra_header_mobile_menu_typography' => array(
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
					'section'   => 'zakra_header_builder_mobile_menu',
				),
			)
		),
		'collapsible'  => apply_filters( 'zakra_header_mobile_menu_accordion_collapsible', false ),
	),

);

if ( ! zakra_is_zakra_pro_active() ) {
	$options['zakra_mobile_menu_upgrade2'] = array(
		'type'        => 'customind-upsell',
		'description' => esc_html__( 'Unlock more features available in Pro version.', 'zakra' ),
		'title'       => esc_html__( 'Learn more', 'zakra' ),
		'url'         => esc_url( 'https://zakratheme.com/pricing/?utm_medium=dash-customizer-learn-more&utm_source=zakra-theme&utm_campaign=customizer-upgrade-button&utm_content=learn-more' ),
		'section'     => 'zakra_header_builder_mobile_menu',
		'priority'    => 100,
	);

}

zakra_customind()->add_controls( $options );
