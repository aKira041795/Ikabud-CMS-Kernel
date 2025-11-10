<?php

$options = array(
	'zakra_footer_copyright_heading' => array(
		'type'         => 'customind-accordion',
		'title'        => esc_html__( 'Copyright', 'zakra' ),
		'section'      => 'zakra_footer_builder_copyright',
		'sub_controls' => apply_filters(
			'zakra_footer_copyright_sub_controls',
			array(
				'zakra_footer_copyright'                  => array(
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
					'section'     => 'zakra_footer_builder_copyright',
				),
				'zakra_footer_copyright_text_color'       => array(
					'title'     => esc_html__( 'Color', 'zakra' ),
					'default'   => '',
					'type'      => 'customind-color',
					'section'   => 'zakra_footer_builder_copyright',
					'transport' => 'postMessage',
				),
				'zakra_footer_copyright_link_color_group' => array(
					'type'         => 'customind-color-group',
					'title'        => esc_html__( 'Links', 'zakra' ),
					'section'      => 'zakra_footer_builder_copyright',
					'sub_controls' => apply_filters(
						'zakra_footer_copyright_link_color_sub_controls',
						array(
							'zakra_footer_copyright_link_color'       => array(
								'default'   => '',
								'type'      => 'customind-color',
								'title'     => esc_html__( 'Normal', 'zakra' ),
								'transport' => 'postMessage',
								'section'   => 'zakra_footer_builder_copyright',
							),
							'zakra_footer_copyright_link_hover_color' => array(
								'default'   => '',
								'type'      => 'customind-color',
								'title'     => esc_html__( 'Hover', 'zakra' ),
								'transport' => 'postMessage',
								'section'   => 'zakra_footer_builder_copyright',
							),
						)
					),
				),
				'zakra_footer_copyright_typography'       => array(
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
					'section'   => 'zakra_footer_builder_copyright',
				),
				'zakra_footer_copyright_margin'           => array(
					'default'     => array(
						'top'    => '',
						'right'  => '',
						'bottom' => '',
						'left'   => '',
						'unit'   => 'px',
					),
					'type'        => 'customind-dimensions',
					'title'       => 'Margin',
					'section'     => 'zakra_footer_builder_copyright',
					'transport'   => 'postMessage',
					'units'       => array( 'px', 'em', '%', 'rem' ),
					'defaultUnit' => 'px',
				),
				'zakra_copyright_alignment_divider'       => array(
					'type'    => 'customind-divider',
					'variant' => 'dashed',
					'section' => 'zakra_footer_builder_copyright',
				),
				'zakra_copyright_alignment'               => array(
					'default'   => 'center',
					'type'      => 'customind-toggle-button',
					'title'     => esc_html__( 'Alignment', 'zakra' ),
					'section'   => 'zakra_footer_builder_copyright',
					'transport' => 'postMessage',
					'choices'   => array(
						'left'   => esc_html__( 'Left', 'zakra' ),
						'center' => esc_html__( 'Center', 'zakra' ),
						'right'  => esc_html__( 'Right', 'zakra' ),
					),
				),
			)
		),
		'collapsible'  => apply_filters( 'zakra_footer_copyright_accordion_collapsible', false ),
	),
);

if ( ! zakra_is_zakra_pro_active() ) {
	$options['zakra_html_1_upgrade'] = array(
		'type'        => 'customind-upsell',
		'description' => esc_html__( 'Unlock more features available in Pro version.', 'zakra' ),
		'title'       => esc_html__( 'Learn more', 'zakra' ),
		'url'         => esc_url( 'https://zakratheme.com/pricing/?utm_medium=dash-customizer-learn-more&utm_source=zakra-theme&utm_campaign=customizer-upgrade-button&utm_content=learn-more' ),
		'section'     => 'zakra_footer_builder_copyright',
		'priority'    => 100,
	);
}

zakra_customind()->add_controls( $options );
