<?php
use function Customind\Core\get_social_networks;
$options = apply_filters(
	'zakra_global_color_options',
	array(
		'zakra_color_palettes' => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Customized Color palette', 'zakra' ),
			'section'      => 'zakra_colors',
			'sub_controls' => array(
				'zakra_color_palette' => array(
					'type'      => 'customind-color-palette',
					'title'     => esc_html__( 'Customized Color palette', 'zakra' ),
					'section'   => 'zakra_colors',
					'priority'  => 5,
					'transport' => 'postMessage',
					'default'   => array(
						'id'     => 'preset-1',
						'name'   => 'Preset 1',
						'colors' => array(
							'zakra-color-1' => '#eaf3fb',
							'zakra-color-2' => '#bfdcf3',
							'zakra-color-3' => '#94c4eb',
							'zakra-color-4' => '#6aace2',
							'zakra-color-5' => '#257bc1',
							'zakra-color-6' => '#1d6096',
							'zakra-color-7' => '#15446b',
							'zakra-color-8' => '#0c2941',
							'zakra-color-9' => '#040e16',
						),
					),
					'presets'   => array(
						array(
							'id'     => 'preset-1',
							'name'   => 'Preset 1',
							'colors' => array(
								'zakra-color-1' => '#eaf3fb',
								'zakra-color-2' => '#bfdcf3',
								'zakra-color-3' => '#94c4eb',
								'zakra-color-4' => '#6aace2',
								'zakra-color-5' => '#257bc1',
								'zakra-color-6' => '#1d6096',
								'zakra-color-7' => '#15446b',
								'zakra-color-8' => '#0c2941',
								'zakra-color-9' => '#040e16',
							),
						),
						array(
							'id'     => 'preset-2',
							'name'   => 'Preset 2',
							'colors' => array(
								'zakra-color-1' => '#fbebf6',
								'zakra-color-2' => '#f3c0e3',
								'zakra-color-3' => '#eb95d0',
								'zakra-color-4' => '#e36abc',
								'zakra-color-5' => '#c22590',
								'zakra-color-6' => '#971d70',
								'zakra-color-7' => '#6c1550',
								'zakra-color-8' => '#420c31',
								'zakra-color-9' => '#170411',
							),
						),
						array(
							'id'     => 'preset-3',
							'name'   => 'Preset 3',
							'colors' => array(
								'zakra-color-1' => '#fafbeb',
								'zakra-color-2' => '#f0f3c0',
								'zakra-color-3' => '#e5eb95',
								'zakra-color-4' => '#dbe36a',
								'zakra-color-5' => '#b8c225',
								'zakra-color-6' => '#8f971d',
								'zakra-color-7' => '#676c15',
								'zakra-color-8' => '#3e420c',
								'zakra-color-9' => '#161704',
							),
						),
						array(
							'id'     => 'preset-4',
							'name'   => 'Preset 4',
							'colors' => array(
								'zakra-color-1' => '#fbeeeb',
								'zakra-color-2' => '#f3c8c0',
								'zakra-color-3' => '#eba395',
								'zakra-color-4' => '#e37e6a',
								'zakra-color-5' => '#c23f25',
								'zakra-color-6' => '#97311d',
								'zakra-color-7' => '#6c2315',
								'zakra-color-8' => '#42150c',
								'zakra-color-9' => '#170704',
							),
						),
					),
				),
			),
			'priority'     => 5,
			'collapsible'  => apply_filters( 'zakra_theme_colors_accordion_collapsible', false ),
		),
		'zakra_theme_colors'   => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Theme Colors', 'zakra' ),
			'section'      => 'zakra_colors',
			'sub_controls' => apply_filters(
				'zakra_theme_colors_sub_controls',
				array(
					'zakra_primary_color' => array(
						'title'    => esc_html__( 'Primary', 'zakra' ),
						'default'  => '#027abb',
						'type'     => 'customind-color',
						'section'  => 'zakra_colors',
						'priority' => 5,
					),
					'zakra_base_color'    => array(
						'title'     => esc_html__( 'Base', 'zakra' ),
						'default'   => '#3F3F46',
						'type'      => 'customind-color',
						'section'   => 'zakra_colors',
						'transport' => 'postMessage',
						'priority'  => 5,
					),
					'zakra_border_color'  => array(
						'title'     => esc_html__( 'Border', 'zakra' ),
						'default'   => '#E4E4E7',
						'type'      => 'customind-color',
						'section'   => 'zakra_colors',
						'transport' => 'postMessage',
						'priority'  => 5,
					),
				)
			),
			'priority'     => 5,
			'collapsible'  => apply_filters( 'zakra_theme_colors_accordion_collapsible', false ),
		),
		'zakra_link_colors'    => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Links', 'zakra' ),
			'section'      => 'zakra_colors',
			'sub_controls' => apply_filters(
				'zakra_link_colors_sub_controls',
				array(
					'zakra_link_color_group' => array(
						'type'         => 'customind-color-group',
						'title'        => esc_html__( 'Links', 'zakra' ),
						'section'      => 'zakra_colors',
						'sub_controls' => apply_filters(
							'zakra_link_color_sub_controls',
							array(
								'zakra_link_color'       => array(
									'default'   => '#027abb',
									'type'      => 'customind-color',
									'title'     => esc_html__( 'Normal', 'zakra' ),
									'transport' => 'postMessage',
									'section'   => 'zakra_colors',
								),
								'zakra_link_hover_color' => array(
									'default'   => '#1e7ba6',
									'type'      => 'customind-color',
									'title'     => esc_html__( 'Hover', 'zakra' ),
									'transport' => 'postMessage',
									'section'   => 'zakra_colors',
								),
							)
						),
					),
				)
			),
			'priority'     => 15,
			'collapsible'  => apply_filters( 'zakra_link_colors_accordion_collapsible', false ),
		),
	)
);

if ( ! zakra_is_zakra_pro_active() ) {
	$options['zakra_colors_upgrade'] = array(
		'type'        => 'customind-upsell',
		'description' => esc_html__( 'Unlock more features available in Pro version.', 'zakra' ),
		'title'       => esc_html__( 'Learn more', 'zakra' ),
		'url'         => esc_url( 'https://zakratheme.com/pricing/?utm_medium=dash-customizer-learn-more&utm_source=zakra-theme&utm_campaign=customizer-upgrade-button&utm_content=learn-more' ),
		'section'     => 'zakra_colors',
		'priority'    => 100,
	);
}


zakra_customind()->add_controls( $options );
