<?php

$panel_options_id = array(
	'zakra_global'         => array(
		'title'    => esc_html__( 'Global', 'zakra' ),
		'priority' => 10,
	),
	'zakra_header'         => array(
		'title'    => esc_html__( 'Header & Navigation', 'zakra' ),
		'priority' => 10,
	),
	'zakra_header_builder' => array(
		'title'    => esc_html__( 'Header Builder', 'zakra' ),
		'priority' => 10,
	),
	'zakra_content'        => array(
		'title'    => esc_html__( 'Content', 'zakra' ),
		'priority' => 10,
	),
	'zakra_footer'         => array(
		'title'    => esc_html__( 'Footer', 'zakra' ),
		'priority' => 10,
	),
	'zakra_footer_builder' => array(
		'title'    => esc_html__( 'Footer Builder', 'zakra' ),
		'priority' => 10,
	),
	'zakra_additional'     => array(
		'title'    => esc_html__( 'Additional', 'zakra' ),
		'priority' => 10,
	),
);

$section_option_id = array(
	'zakra_builder'                        => array(
		'title'    => esc_html__( 'Builder', 'zakra' ),
		'panel'    => 'zakra_header',
		'priority' => 1,
	),
	'zakra_colors'                         => array(
		'title'    => esc_html__( 'Colors', 'zakra' ),
		'panel'    => 'zakra_global',
		'priority' => 5,
	),
	'zakra_container'                      => array(
		'title'    => esc_html__( 'Container', 'zakra' ),
		'panel'    => 'zakra_global',
		'priority' => 10,
	),
	'zakra_content_area'                   => array(
		'title'    => esc_html__( 'Content Area', 'zakra' ),
		'panel'    => 'zakra_global',
		'priority' => 15,
	),
	'zakra_sidebar_layout'                 => array(
		'title'    => esc_html__( 'Sidebar', 'zakra' ),
		'panel'    => 'zakra_global',
		'priority' => 20,
	),
	'zakra_typography'                     => array(
		'title'    => esc_html__( 'Typography', 'zakra' ),
		'panel'    => 'zakra_global',
		'priority' => 25,
	),
	'zakra_button'                         => array(
		'title'    => esc_html__( 'Button', 'zakra' ),
		'panel'    => 'zakra_global',
		'priority' => 30,
	),
	'zakra_header_top'                     => array(
		'title'    => esc_html__( 'Top Bar', 'zakra' ),
		'panel'    => 'zakra_header',
		'priority' => 5,
	),
	'title_tagline'                        => array(
		'title'    => esc_html__( 'Site Identity', 'zakra' ),
		'panel'    => 'zakra_header',
		'priority' => 10,
	),
	'zakra_header_builder_section'         => array(
		'title'    => esc_html__( 'Header Builder', 'zakra' ),
		'panel'    => 'zakra_header_builder',
		'priority' => 15,
	),
	'zakra_footer_builder_section'         => array(
		'title'    => esc_html__( 'Footer Builder', 'zakra' ),
		'panel'    => 'zakra_footer_builder',
		'priority' => 15,
	),
	'zakra_main_header'                    => array(
		'title'    => esc_html__( 'Main Header', 'zakra' ),
		'panel'    => 'zakra_header',
		'priority' => 15,
	),
	'zakra_menu'                           => array(
		'title'    => esc_html__( 'Primary Menu', 'zakra' ),
		'panel'    => 'zakra_header',
		'priority' => 20,
	),
	'zakra_header_search'                  => array(
		'title'    => esc_html__( 'Header Search', 'zakra' ),
		'panel'    => 'zakra_header',
		'priority' => 25,
	),
	'zakra_header_button'                  => array(
		'title'    => esc_html__( 'Header Button', 'zakra' ),
		'panel'    => 'zakra_header',
		'priority' => 30,
	),
	'header_image'                         => array(
		'title'    => esc_html__( 'Header Media', 'zakra' ),
		'panel'    => 'zakra_header',
		'priority' => 35,
	),
	'zakra_page_header'                    => array(
		'title'    => esc_html__( 'Page Header', 'zakra' ),
		'panel'    => 'zakra_header',
		'priority' => 40,
	),
	'zakra_breadcrumb'                     => array(
		'title'    => esc_html__( 'Breadcrumb', 'zakra' ),
		'panel'    => 'zakra_header',
		'priority' => 45,
	),
	'zakra_header_builder_socials'         => array(
		'title'    => esc_html__( 'Socials', 'zakra' ),
		'panel'    => 'zakra_header_builder',
		'priority' => 45,
	),
	'zakra_footer_builder_socials'         => array(
		'title'    => esc_html__( 'Socials', 'zakra' ),
		'panel'    => 'zakra_footer_builder',
		'priority' => 45,
	),
	'zakra_footer_builder_copyright'       => array(
		'title'    => esc_html__( 'Copyright', 'zakra' ),
		'panel'    => 'zakra_footer_builder',
		'priority' => 45,
	),
	'zakra_blog'                           => array(
		'title'    => esc_html__( 'Blog', 'zakra' ),
		'panel'    => 'zakra_content',
		'priority' => 5,
	),
	'zakra_single_blog_post'               => array(
		'title'    => esc_html__( 'Single Post', 'zakra' ),
		'panel'    => 'zakra_content',
		'priority' => 10,
	),
	'zakra_meta'                           => array(
		'title'    => esc_html__( 'Meta', 'zakra' ),
		'panel'    => 'zakra_content',
		'priority' => 15,
	),
	'zakra_sidebar'                        => array(
		'title'    => esc_html__( 'Sidebar', 'zakra' ),
		'panel'    => 'zakra_content',
		'priority' => 20,
	),
	'zakra_footer_column'                  => array(
		'title'    => esc_html__( 'Footer Column', 'zakra' ),
		'panel'    => 'zakra_footer',
		'priority' => 5,
	),
	'zakra_footer_bar'                     => array(
		'title'    => esc_html__( 'Footer Bar', 'zakra' ),
		'panel'    => 'zakra_footer',
		'priority' => 10,
	),
	'zakra_footer_scroll_to_top'           => array(
		'title'    => esc_html__( 'Scroll to Top', 'zakra' ),
		'panel'    => 'zakra_additional',
		'priority' => 15,
	),
	'zakra_optimization'                   => array(
		'title'    => esc_html__( 'Optimization', 'zakra' ),
		'panel'    => 'zakra_additional',
		'priority' => 5,
	),
	'zakra_woocommerce_sidebar_layout'     => array(
		'title'    => esc_html__( 'Sidebar Layout', 'zakra' ),
		'panel'    => 'woocommerce',
		'priority' => 10,
	),
	'zakra_header_builder_logo'            => array(
		'title'    => esc_html__( 'Logo', 'zakra' ),
		'panel'    => 'zakra_header_builder',
		'priority' => 10,
	),
	'zakra_header_builder_primary_menu'    => array(
		'title'    => esc_html__( 'Primary Menu', 'zakra' ),
		'panel'    => 'zakra_header_builder',
		'priority' => 10,
	),
	'zakra_header_builder_secondary_menu'  => array(
		'title'    => esc_html__( 'Secondary Menu', 'zakra' ),
		'panel'    => 'zakra_header_builder',
		'priority' => 10,
	),
	'zakra_header_builder_tertiary_menu'   => array(
		'title'    => esc_html__( 'Tertiary Menu', 'zakra' ),
		'panel'    => 'zakra_header_builder',
		'priority' => 10,
	),
	'zakra_header_builder_quaternary_menu' => array(
		'title'    => esc_html__( 'Quaternary Menu', 'zakra' ),
		'panel'    => 'zakra_header_builder',
		'priority' => 10,
	),
	'zakra_header_builder_mobile_menu'     => array(
		'title'    => esc_html__( 'Mobile Menu', 'zakra' ),
		'panel'    => 'zakra_header_builder',
		'priority' => 10,
	),
	'zakra_footer_builder_footer_menu'     => array(
		'title'    => esc_html__( 'Menu 1', 'zakra' ),
		'panel'    => 'zakra_footer_builder',
		'priority' => 10,
	),
	'zakra_footer_builder_footer_menu_2'   => array(
		'title'    => esc_html__( 'Menu 2', 'zakra' ),
		'panel'    => 'zakra_footer_builder',
		'priority' => 10,
	),
	'zakra_header_builder_offset_area'     => array(
		'title'    => esc_html__( 'Offset Area', 'zakra' ),
		'panel'    => 'zakra_header_builder',
		'priority' => 10,
	),
	'zakra_header_builder_button_1'        => array(
		'title'    => esc_html__( 'Header Button', 'zakra' ),
		'panel'    => 'zakra_header_builder',
		'priority' => 10,
	),
	'zakra_header_builder_search'          => array(
		'title'    => esc_html__( 'Search', 'zakra' ),
		'panel'    => 'zakra_header_builder',
		'priority' => 10,
	),
	'zakra_header_builder_html_1'          => array(
		'title'    => esc_html__( 'HTML 1', 'zakra' ),
		'panel'    => 'zakra_header_builder',
		'priority' => 10,
	),
	'zakra_header_builder_html_2'          => array(
		'title'    => esc_html__( 'HTML 2', 'zakra' ),
		'panel'    => 'zakra_header_builder',
		'priority' => 10,
	),
	'zakra_header_builder_widget_1'        => array(
		'title'    => esc_html__( 'Widget 1', 'zakra' ),
		'panel'    => 'zakra_header_builder',
		'priority' => 10,
	),
	'zakra_header_builder_widget_2'        => array(
		'title'    => esc_html__( 'Widget 2', 'zakra' ),
		'panel'    => 'zakra_header_builder',
		'priority' => 10,
	),
	'zakra_footer_builder_widget_1'        => array(
		'title'    => esc_html__( 'Widget 1', 'zakra' ),
		'panel'    => 'zakra_footer_builder',
		'priority' => 10,
	),
	'zakra_footer_builder_widget_2'        => array(
		'title'    => esc_html__( 'Widget 2', 'zakra' ),
		'panel'    => 'zakra_footer_builder',
		'priority' => 10,
	),
	'zakra_footer_builder_widget_3'        => array(
		'title'    => esc_html__( 'Widget 3', 'zakra' ),
		'panel'    => 'zakra_footer_builder',
		'priority' => 10,
	),
	'zakra_footer_builder_widget_4'        => array(
		'title'    => esc_html__( 'Widget 4', 'zakra' ),
		'panel'    => 'zakra_footer_builder',
		'priority' => 10,
	),
	'zakra_footer_builder_widget_5'        => array(
		'title'    => esc_html__( 'Widget 5', 'zakra' ),
		'panel'    => 'zakra_footer_builder',
		'priority' => 10,
	),
	'zakra_footer_builder_widget_6'        => array(
		'title'    => esc_html__( 'Widget 6', 'zakra' ),
		'panel'    => 'zakra_footer_builder',
		'priority' => 10,
	),
	'zakra_header_builder_cart'            => array(
		'title'    => esc_html__( 'Cart', 'zakra' ),
		'panel'    => 'zakra_header_builder',
		'priority' => 10,
	),
	'zakra_header_builder_toggle_button'   => array(
		'title'    => esc_html__( 'Toggle Button', 'zakra' ),
		'panel'    => 'zakra_header_builder',
		'priority' => 10,
	),
	'zakra_header_builder_top_area'        => array(
		'title'    => esc_html__( 'Top Area', 'zakra' ),
		'panel'    => 'zakra_header_builder',
		'priority' => 10,
	),
	'zakra_header_builder_main_area'       => array(
		'title'    => esc_html__( 'Main Area', 'zakra' ),
		'panel'    => 'zakra_header_builder',
		'priority' => 10,
	),
	'zakra_header_builder_bottom_area'     => array(
		'title'    => esc_html__( 'Bottom Area', 'zakra' ),
		'panel'    => 'zakra_header_builder',
		'priority' => 10,
	),
	'zakra_footer_builder_top_area'        => array(
		'title'    => esc_html__( 'Top Area', 'zakra' ),
		'panel'    => 'zakra_footer_builder',
		'priority' => 10,
	),
	'zakra_footer_builder_main_area'       => array(
		'title'    => esc_html__( 'Main Area', 'zakra' ),
		'panel'    => 'zakra_footer_builder',
		'priority' => 10,
	),
	'zakra_footer_builder_bottom_area'     => array(
		'title'    => esc_html__( 'Bottom Area', 'zakra' ),
		'panel'    => 'zakra_footer_builder',
		'priority' => 10,
	),
	'zakra_footer_builder_html_1'          => array(
		'title'    => esc_html__( 'HTML 1', 'zakra' ),
		'panel'    => 'zakra_footer_builder',
		'priority' => 10,
	),
	'zakra_footer_builder_html_2'          => array(
		'title'    => esc_html__( 'HTML 2', 'zakra' ),
		'panel'    => 'zakra_footer_builder',
		'priority' => 10,
	),
	'zakra_customize_review_link_section'  => array(
		'type'             => 'upsell-section',
		'title'            => esc_html__( 'Leave a Review on .org', 'zakra' ),
		'url'              => 'https://wordpress.org/support/theme/zakra/reviews/?filter=5/#new-post',
		'section_callback' => \Customind\Core\Types\UpsellSection::class,
		'priority'         => 200,
	),

);

if ( ! zakra_is_zakra_pro_active() ) {
	$upsell_section    = array(
		'zakra_customize_upsell_section' => array(
			'type'             => 'upsell-section',
			'title'            => esc_html__( 'View Pro Version', 'zakra' ),
			'url'              => 'https://zakratheme.com/pricing/?utm_medium=dash-customizer&utm_source=zakra-theme&utm_campaign=top-view-pro-btn&utm_content=view-pro-version',
			'section_callback' => \Customind\Core\Types\UpsellSection::class,
			'priority'         => 1,
		),
	);
	$section_option_id = array_merge( $section_option_id, $upsell_section );
}

zakra_customind()->add_panels( $panel_options_id );
zakra_customind()->add_sections( $section_option_id );
