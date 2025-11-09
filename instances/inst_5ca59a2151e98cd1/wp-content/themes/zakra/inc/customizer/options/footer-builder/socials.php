<?php

$options = array(
	'zakra_footer_builder_social_heading' => array(
		'type'         => 'customind-accordion',
		'title'        => esc_html__( 'Social', 'zakra' ),
		'section'      => 'zakra_footer_builder_socials',
		'sub_controls' => apply_filters(
			'zakra_footer_builder_social_sub_controls',
			array(
				'zakra_footer_socials'            => array(
					'type'    => 'customind-socials',
					'title'   => esc_html__( 'Social', 'zakra' ),
					'section' => 'zakra_footer_builder_socials',
					'default' => array(
						array(
							'id'    => uniqid(),
							'label' => 'facebook',
							'url'   => '#',
							'icon'  => 'fa-brands fa-facebook',
						),
						array(
							'id'    => uniqid(),
							'label' => 'twitter',
							'url'   => '#',
							'icon'  => 'fa-brands fa-x-twitter',
						),
						array(
							'id'    => uniqid(),
							'label' => 'instagram',
							'url'   => '#',
							'icon'  => 'fa-brands fa-square-instagram',
						),
					),
				),
				'zakra_socials_alignment_divider' => array(
					'type'    => 'customind-divider',
					'variant' => 'dashed',
					'section' => 'zakra_footer_builder_socials',
				),
				'zakra_socials_alignment'         => array(
					'default'   => '',
					'type'      => 'customind-toggle-button',
					'title'     => esc_html__( 'Alignment', 'zakra' ),
					'section'   => 'zakra_footer_builder_socials',
					'transport' => 'postMessage',
					'choices'   => array(
						'left'   => esc_html__( 'Left', 'zakra' ),
						'center' => esc_html__( 'Center', 'zakra' ),
						'right'  => esc_html__( 'Right', 'zakra' ),
					),
				),
			)
		),
		'collapsible'  => apply_filters( 'zakra_footer_builder_social_accordion_collapsible', false ),
	),
);

if ( ! zakra_is_zakra_pro_active() ) {
	$options['zakra_footer_builder_social_upgrade'] = array(
		'type'        => 'customind-upsell',
		'description' => esc_html__( 'Unlock more features available in Pro version.', 'zakra' ),
		'title'       => esc_html__( 'Learn more', 'zakra' ),
		'url'         => esc_url( 'https://zakratheme.com/pricing/?utm_medium=dash-customizer-learn-more&utm_source=zakra-theme&utm_campaign=customizer-upgrade-button&utm_content=learn-more' ),
		'section'     => 'zakra_footer_builder_socials',
		'priority'    => 100,
	);
}

zakra_customind()->add_controls( $options );
