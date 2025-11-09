<?php
$options = array(
	'zakra_page_header_heading' => array(
		'type'         => 'customind-accordion',
		'title'        => esc_html__( 'Page Header', 'zakra' ),
		'section'      => 'zakra_page_header',
		'sub_controls' => apply_filters(
			'zakra_page_header_sub_controls',
			array(
				'zakra_enable_page_header'             => array(
					'title'   => esc_html__( 'Enable', 'zakra' ),
					'default' => true,
					'type'    => 'customind-toggle',
					'section' => 'zakra_page_header',
				),
				'zakra_page_header_layout'             => array(
					'default'     => 'style-1',
					'type'        => 'customind-radio-image',
					'title'       => esc_html__( 'Layout', 'zakra' ),
					'section'     => 'zakra_page_header',
					'transport'   => 'postMessage',
					'choices'     => array(
						'style-1' => array(
							'label' => '',
							'url'   => ZAKRA_PARENT_INC_ICON_URI . '/breadcrumb-right.svg',
						),
						'style-2' => array(
							'label' => '',
							'url'   => ZAKRA_PARENT_INC_ICON_URI . '/breadcrumb-left.svg',
						),
						'style-3' => array(
							'label' => '',
							'url'   => ZAKRA_PARENT_INC_ICON_URI . '/breadcrumb-center.svg',
						),
						'style-4' => array(
							'label' => '',
							'url'   => ZAKRA_PARENT_INC_ICON_URI . '/breadcrumb-both-on-left.svg',
						),
						'style-5' => array(
							'label' => '',
							'url'   => ZAKRA_PARENT_INC_ICON_URI . '/breadcrumb-both-on-right.svg',
						),
					),
					'input_attrs' => array(
						'columns' => 2,
					),
					'condition'   => array(
						'zakra_enable_page_header' => true,
					),
				),
				'zakra_page_header_background_divider' => array(
					'type'      => 'customind-divider',
					'variant'   => 'dashed',
					'section'   => 'zakra_page_header',
					'condition' => array(
						'zakra_enable_page_header' => true,
					),
				),
				'zakra_page_header_background_heading' => array(
					'type'      => 'customind-title',
					'title'     => esc_html__( 'Style', 'zakra' ),
					'section'   => 'zakra_page_header',
					'condition' => array(
						'zakra_enable_page_header' => true,
					),
				),
				'zakra_page_header_background'         => array(
					'default'   => array(
						'background-color'      => '#E4E4E7',
						'background-image'      => '',
						'background-repeat'     => 'repeat',
						'background-position'   => 'center center',
						'background-size'       => 'contain',
						'background-attachment' => 'scroll',
					),
					'type'      => 'customind-background',
					'title'     => esc_html__( 'Background', 'zakra' ),
					'transport' => 'postMessage',
					'section'   => 'zakra_page_header',
					'condition' => array(
						'zakra_enable_page_header' => true,
					),
				),
				'zakra_page_header_padding'            => array(
					'default'     => array(
						'top'    => '20',
						'right'  => '0',
						'bottom' => '20',
						'left'   => '0',
						'unit'   => 'px',
					),
					'type'        => 'customind-dimensions',
					'title'       => esc_html__( 'Padding', 'zakra' ),
					'section'     => 'zakra_page_header',
					'units'       => array( 'px', 'em', 'rem', '%' ),
					'transport'   => 'postMessage',
					'defaultUnit' => 'px',
					'condition'   => array(
						'zakra_enable_page_header' => true,
					),
				),
			)
		),
		'collapsible'  => apply_filters( 'zakra_page_header_accordion_collapsible', false ),
	),

	'zakra_page_title_heading'  => array(
		'type'         => 'customind-accordion',
		'title'        => esc_html__( 'Page Title', 'zakra' ),
		'section'      => 'zakra_page_header',
		'sub_controls' => apply_filters(
			'zakra_page_title_sub_controls',
			array(
				'zakra_page_title_position'        => array(
					'default'   => 'page-header',
					'type'      => 'customind-radio',
					'title'     => esc_html__( 'Position', 'zakra' ),
					'section'   => 'zakra_page_header',
					'choices'   => array(
						'page-header'  => esc_html__( 'Page Header', 'zakra' ),
						'content-area' => esc_html__( 'Content Area', 'zakra' ),
					),
					'condition' => array(
						'zakra_enable_page_header' => true,
					),
				),
				'zakra_page_title_markup'          => array(
					'default'   => 'h1',
					'type'      => 'customind-select',
					'title'     => esc_html__( 'Markup', 'zakra' ),
					'section'   => 'zakra_page_header',
					'choices'   => array(
						'h1'   => esc_html__( 'Heading 1', 'zakra' ),
						'h2'   => esc_html__( 'Heading 2', 'zakra' ),
						'h3'   => esc_html__( 'Heading 3', 'zakra' ),
						'h4'   => esc_html__( 'Heading 4', 'zakra' ),
						'h5'   => esc_html__( 'Heading 5', 'zakra' ),
						'h6'   => esc_html__( 'Heading 6', 'zakra' ),
						'span' => esc_html__( 'Span', 'zakra' ),
						'p'    => esc_html__( 'Paragraph', 'zakra' ),
						'div'  => esc_html__( 'Div', 'zakra' ),
					),
					'condition' => array(
						'zakra_enable_page_header'  => true,
						'zakra_page_title_position' => 'page-header',
					),
				),
				'zakra_post_page_title_color'      => array(
					'title'     => esc_html__( 'Color', 'zakra' ),
					'default'   => '#16181a',
					'type'      => 'customind-color',
					'transport' => 'postMessage',
					'section'   => 'zakra_page_header',
					'condition' => array(
						'zakra_enable_page_header' => true,
					),
				),
				'zakra_post_page_title_typography' => array(
					'default'   => apply_filters(
						'zakra_post_page_title_typography_filter',
						array(
							'font-family'    => 'Default',
							'font-weight'    => '500',
							'subsets'        => array( 'latin' ),
							'font-size'      => array(
								'desktop' => array(
									'size' => '2.5',
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
									'size' => '1.3',
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
						)
					),
					'type'      => 'customind-typography',
					'title'     => esc_html__( 'Typography', 'zakra' ),
					'section'   => 'zakra_page_header',
					'transport' => 'postMessage',
					'condition' => array(
						'zakra_enable_page_header' => true,
					),
				),
			)
		),
		'collapsible'  => apply_filters( 'zakra_page_title_accordion_collapsible', false ),
		'condition'    => array(
			'zakra_enable_page_header' => true,
		),
	),
);

if ( ! zakra_is_zakra_pro_active() ) {
	$options['zakra_page_header_upgrade_notice'] = array(
		'type'        => 'customind-upsell',
		'description' => esc_html__( 'Unlock more features available in Pro version.', 'zakra' ),
		'title'       => esc_html__( 'Learn more', 'zakra' ),
		'url'         => esc_url( 'https://zakratheme.com/pricing/?utm_medium=dash-customizer-learn-more&utm_source=zakra-theme&utm_campaign=customizer-upgrade-button&utm_content=learn-more' ),
		'section'     => 'zakra_page_header',
		'priority'    => 100,
	);
}

zakra_customind()->add_controls( $options );
