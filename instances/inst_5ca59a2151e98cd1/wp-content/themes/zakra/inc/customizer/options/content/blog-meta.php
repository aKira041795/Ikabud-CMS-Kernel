<?php

$options = apply_filters(
	'zakra_post_meta_options',
	array(
		'zakra_post_meta_heading' => array(
			'type'         => 'customind-accordion',
			'title'        => esc_html__( 'Meta', 'zakra' ),
			'section'      => 'zakra_meta',
			'sub_controls' => apply_filters(
				'zakra_post_meta_sub_controls',
				array(
					'zakra_post_meta_style' => array(
						'default' => 'style-1',
						'type'    => 'customind-radio-image',
						'title'   => esc_html__( 'Layout', 'zakra' ),
						'section' => 'zakra_meta',
						'choices' => array(
							'style-1' => array(
								'label' => '',
								'url'   => ZAKRA_PARENT_INC_ICON_URI . '/meta-style-one.svg',
							),
							'style-2' => array(
								'label' => '',
								'url'   => ZAKRA_PARENT_INC_ICON_URI . '/meta-style-two.svg',
							),
						),
						'columns' => 2,
					),
				)
			),
			'collapsible'  => apply_filters( 'zakra_post_meta_accordion_collapsible', false ),
		),
	)
);

if ( ! zakra_is_zakra_pro_active() ) {
	$options['zakra_meta_upgrade'] = array(
		'type'        => 'customind-upsell',
		'description' => esc_html__( 'Unlock more features available in Pro version.', 'zakra' ),
		'title'       => esc_html__( 'Learn more', 'zakra' ),
		'url'         => esc_url( 'https://zakratheme.com/pricing/?utm_medium=dash-customizer-learn-more&utm_source=zakra-theme&utm_campaign=customizer-upgrade-button&utm_content=learn-more' ),
		'section'     => 'zakra_meta',
		'priority'    => 100,
	);
}

zakra_customind()->add_controls( $options );
