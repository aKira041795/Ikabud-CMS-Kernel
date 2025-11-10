<?php

/**
 * Bloghash Options Class.
 *
 * @package  Bloghash
 * @author   Peregrine Themes
 * @since    1.0.0
 */

/**
 * Do not allow direct script access.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Bloghash_Options' ) ) :

	/**
	 * Bloghash Options Class.
	 */
	class Bloghash_Options {

		/**
		 * Singleton instance of the class.
		 *
		 * @since 1.0.0
		 * @var object
		 */
		private static $instance;

		/**
		 * Options variable.
		 *
		 * @since 1.0.0
		 * @var mixed $options
		 */
		private static $options;

		/**
		 * Main Bloghash_Options Instance.
		 *
		 * @since 1.0.0
		 * @return Bloghash_Options
		 */
		public static function instance() {

			if ( ! isset( self::$instance ) && ! ( self::$instance instanceof Bloghash_Options ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Primary class constructor.
		 *
		 * @since 1.0.0
		 */
		public function __construct() {

			// Refresh options.
			add_action( 'after_setup_theme', array( $this, 'refresh' ) );
		}

		/**
		 * Set default option values.
		 *
		 * @since  1.0.0
		 * @return array Default values.
		 */
		public function get_defaults() {

			$categories                        = get_categories( array( 'hide_empty' => 1 ) );
			$bloghash_categories_color_options = array();
			foreach ( $categories as $category ) {
				$bloghash_categories_color_options[ 'bloghash_category_color_' . $category->term_id ] = '#F43676';
			}

			$defaults = array(

				/**
				 * General Settings.
				 */

				// Layout.
				'bloghash_site_layout'                     => 'fw-contained',
				'bloghash_container_width'                 => 1480,

				// Base Colors.
				'bloghash_accent_color'                    => '#F43676',
				'bloghash_dark_mode'                       => false,
				'bloghash_body_animation'                  => '1',
				'bloghash_content_text_color'              => '#002050',
				'bloghash_headings_color'                  => '#302D55',
				'bloghash_content_link_hover_color'        => '#302D55',
				'bloghash_body_background_heading'         => true,
				'bloghash_content_background_heading'      => true,
				'bloghash_boxed_content_background_color'  => '#FFFFFF',
				'bloghash_scroll_top_visibility'           => 'all',

				// Base Typography.
				'bloghash_html_base_font_size'             => array(
					'desktop' => 62.5,
					'tablet'  => 53,
					'mobile'  => 50,
				),
				'bloghash_font_smoothing'                  => true,
				'bloghash_typography_body_heading'         => false,
				'bloghash_typography_headings_heading'     => false,
				'bloghash_body_font'                       => bloghash_typography_defaults(
					array(
						'font-family'         => 'Be Vietnam Pro',
						'font-weight'         => 400,
						'font-size-desktop'   => '1.7',
						'font-size-unit'      => 'rem',
						'line-height-desktop' => '1.75',
					)
				),
				'bloghash_headings_font'                   => bloghash_typography_defaults(
					array(
						'font-family'     => 'Be Vietnam Pro',
						'font-weight'     => 700,
						'font-style'      => 'normal',
						'text-transform'  => 'none',
						'text-decoration' => 'none',
					)
				),
				'bloghash_h1_font'                         => bloghash_typography_defaults(
					array(
						'font-weight'         => 700,
						'font-size-desktop'   => '4',
						'font-size-unit'      => 'rem',
						'line-height-desktop' => '1.4',
					)
				),
				'bloghash_h2_font'                         => bloghash_typography_defaults(
					array(
						'font-weight'         => 700,
						'font-size-desktop'   => '3.6',
						'font-size-unit'      => 'rem',
						'line-height-desktop' => '1.4',
					)
				),
				'bloghash_h3_font'                         => bloghash_typography_defaults(
					array(
						'font-weight'         => 700,
						'font-size-desktop'   => '2.8',
						'font-size-unit'      => 'rem',
						'line-height-desktop' => '1.4',
					)
				),
				'bloghash_h4_font'                         => bloghash_typography_defaults(
					array(
						'font-weight'         => 700,
						'font-size-desktop'   => '2.4',
						'font-size-unit'      => 'rem',
						'line-height-desktop' => '1.4',
					)
				),
				'bloghash_h5_font'                         => bloghash_typography_defaults(
					array(
						'font-weight'         => 700,
						'font-size-desktop'   => '2',
						'font-size-unit'      => 'rem',
						'line-height-desktop' => '1.4',
					)
				),
				'bloghash_h6_font'                         => bloghash_typography_defaults(
					array(
						'font-weight'         => 600,
						'font-size-desktop'   => '1.8',
						'font-size-unit'      => 'rem',
						'line-height-desktop' => '1.72',
					)
				),
				'bloghash_heading_em_font'                 => bloghash_typography_defaults(
					array(
						'font-family' => 'Playfair Display',
						'font-weight' => 'inherit',
						'font-style'  => 'italic',
					)
				),
				'bloghash_section_heading_style'           => '1',
				'bloghash_footer_widget_title_font_size'   => array(
					'desktop' => 2,
					'unit'    => 'rem',
				),

				// Primary Button.
				'bloghash_primary_button_heading'          => false,
				'bloghash_primary_button_bg_color'         => '',
				'bloghash_primary_button_hover_bg_color'   => '',
				'bloghash_primary_button_text_color'       => '#fff',
				'bloghash_primary_button_hover_text_color' => '#fff',
				'bloghash_primary_button_border_radius'    => array(
					'top-left'     => '0.8',
					'top-right'    => '0.8',
					'bottom-right' => '0.8',
					'bottom-left'  => '0.8',
					'unit'         => 'rem',
				),
				'bloghash_primary_button_border_width'     => 0.1,
				'bloghash_primary_button_border_color'     => 'rgba(0, 0, 0, 0.12)',
				'bloghash_primary_button_hover_border_color' => 'rgba(0, 0, 0, 0.12)',
				'bloghash_primary_button_typography'       => bloghash_typography_defaults(
					array(
						'font-family'         => 'Be Vietnam Pro',
						'font-weight'         => 500,
						'font-size-desktop'   => '1.8',
						'font-size-unit'      => 'rem',
						'line-height-desktop' => '',
					)
				),

				// Secondary Button.
				'bloghash_secondary_button_heading'        => false,
				'bloghash_secondary_button_bg_color'       => '#302D55',
				'bloghash_secondary_button_hover_bg_color' => '#002050',
				'bloghash_secondary_button_text_color'     => '#FFFFFF',
				'bloghash_secondary_button_hover_text_color' => '#FFFFFF',
				'bloghash_secondary_button_border_radius'  => array(
					'top-left'     => '',
					'top-right'    => '',
					'bottom-right' => '',
					'bottom-left'  => '',
					'unit'         => 'rem',
				),
				'bloghash_secondary_button_border_width'   => .1,
				'bloghash_secondary_button_border_color'   => 'rgba(0, 0, 0, 0.12)',
				'bloghash_secondary_button_hover_border_color' => 'rgba(0, 0, 0, 0.12)',
				'bloghash_secondary_button_typography'     => bloghash_typography_defaults(
					array(
						'font-family'         => 'Be Vietnam Pro',
						'font-weight'         => 500,
						'font-size-desktop'   => '1.8',
						'font-size-unit'      => 'rem',
						'line-height-desktop' => '1.6',
					)
				),

				// Text button.
				'bloghash_text_button_heading'             => false,
				'bloghash_text_button_text_color'          => '#302D55',
				'bloghash_text_button_hover_text_color'    => '',
				'bloghash_text_button_typography'          => bloghash_typography_defaults(
					array(
						'font-family'         => 'Be Vietnam Pro',
						'font-weight'         => 500,
						'font-size-desktop'   => '1.6',
						'font-size-unit'      => 'rem',
						'line-height-desktop' => '1.5',
					)
				),

				// Misc Settings.
				'bloghash_enable_schema'                   => true,
				'bloghash_custom_input_style'              => true,
				'bloghash_preloader_heading'               => false,
				'bloghash_preloader'                       => false,
				'bloghash_preloader_style'                 => '1',
				'bloghash_preloader_visibility'            => 'all',
				'bloghash_scroll_top_heading'              => false,
				'bloghash_scroll_top'                      => true,
				'bloghash_scroll_top_visibility'           => 'all',
				'bloghash_cursor_dot_heading'              => false,
				'bloghash_cursor_dot'                      => false,

				/**
				 * Logos & Site Title.
				 */
				'bloghash_logo_default_retina'             => '',
				'bloghash_logo_max_height'                 => array(
					'desktop' => 45,
				),
				'bloghash_logo_margin'                     => array(
					'desktop' => array(
						'top'    => 27,
						'right'  => 10,
						'bottom' => 27,
						'left'   => 10,
					),
					'tablet'  => array(
						'top'    => 25,
						'right'  => 1,
						'bottom' => 25,
						'left'   => 0,
					),
					'mobile'  => array(
						'top'    => '',
						'right'  => '',
						'bottom' => '',
						'left'   => '',
					),
					'unit'    => 'px',
				),
				'bloghash_display_tagline'                 => false,
				'bloghash_logo_heading_site_identity'      => true,
				'bloghash_typography_logo_heading'         => false,
				'bloghash_logo_text_font_size'             => array(
					'desktop' => 3,
					'unit'    => 'rem',
				),

				/**
				 * Header.
				 */

				// Top Bar.
				'bloghash_top_bar_enable'                  => false,
				'bloghash_top_bar_container_width'         => 'content-width',
				'bloghash_top_bar_visibility'              => 'all',
				'bloghash_top_bar_heading_widgets'         => true,
				'bloghash_top_bar_widgets'                 => array(
					array(
						'classname' => 'bloghash_customizer_widget_text',
						'type'      => 'text',
						'values'    => array(
							'content'    => wp_kses( '<i class="far fa-calendar-alt fa-lg bloghash-icon"></i><strong><span id="bloghash-date"></span> - <span id="bloghash-time"></span></strong>', bloghash_get_allowed_html_tags() ),
							'location'   => 'left',
							'visibility' => 'all',
						),
					),
					array(
						'classname' => 'bloghash_customizer_widget_text',
						'type'      => 'text',
						'values'    => array(
							'content'    => wp_kses( '<i class="far fa-location-arrow fa-lg bloghash-icon"></i> Subscribe to our bloghashter & never miss our best posts. <a href="#"><strong>Subscribe Now!</strong></a>', bloghash_get_allowed_html_tags() ),
							'location'   => 'right',
							'visibility' => 'all',
						),
					),
				),
				'bloghash_top_bar_widgets_separator'       => 'regular',
				'bloghash_top_bar_heading_design_options'  => false,
				'bloghash_top_bar_background'              => bloghash_design_options_defaults(
					array(
						'background' => array(
							'color'    => array(
								'background-color' => 'rgba(247,229,183,0.35)',
							),
							'gradient' => array(
								'gradient-color-1' => 'rgba(247,229,183,0.35)',
								'gradient-color-2' => 'rgba(226,181,181,0.39)',
							),
						),
					)
				),
				'bloghash_top_bar_text_color'              => bloghash_design_options_defaults(
					array(
						'color' => array(
							'text-color'       => '#002050',
							'link-color'       => '#302D55',
							'link-hover-color' => '#F43676',
						),
					)
				),
				'bloghash_top_bar_border'                  => bloghash_design_options_defaults(
					array(
						'border' => array(
							'border-top-width' => '',
							'border-style'     => 'solid',
							'border-color'     => '',
							'separator-color'  => '#cccccc',
						),
					)
				),

				// Main Header.
				'bloghash_header_layout'                   => 'layout-1',

				'bloghash_header_container_width'          => 'content-width',
				'bloghash_header_heading_widgets'          => true,
				'bloghash_header_widgets'                  => array(
					array(
						'classname' => 'bloghash_customizer_widget_socials',
						'type'      => 'socials',
						'values'    => array(
							'style'      => 'rounded-border',
							'size'       => 'standard',
							'location'   => 'left',
							'visibility' => 'hide-mobile-tablet',
						),
					),
					array(
						'classname' => 'bloghash_customizer_widget_darkmode',
						'type'      => 'darkmode',
						'values'    => array(
							'style'      => 'rounded-border',
							'location'   => 'right',
							'visibility' => 'hide-mobile-tablet',
						),
					),
					array(
						'classname' => 'bloghash_customizer_widget_search',
						'type'      => 'search',
						'values'    => array(
							'style'      => 'rounded-fill',
							'location'   => 'right',
							'visibility' => 'hide-mobile-tablet',
						),
					),
					array(
						'classname' => 'bloghash_customizer_widget_button',
						'type'      => 'button',
						'values'    => array(
							'text'       => '<i class="far fa-bell mr-1 bloghash-icon"></i> Subscribe',
							'url'        => '#',
							'class'      => 'btn-small',
							'target'     => '_self',
							'location'   => 'right',
							'visibility' => 'hide-mobile-tablet',
						),
					),
				),

				// Ad Widget
				'bloghash_ad_widgets'                      => array(
					array(
						'classname' => 'bloghash_customizer_widget_advertisements',
						'type'      => 'advertisements',
					),
				),

				'bloghash_header_widgets_separator'        => 'none',
				'bloghash_header_heading_design_options'   => false,
				'bloghash_header_background'               => bloghash_design_options_defaults(
					array(
						'background' => array(
							'color'    => array(
								'background-color' => '#FFFFFF',
							),
							'gradient' => array(),
							'image'    => array(),
						),
					)
				),
				'bloghash_header_border'                   => bloghash_design_options_defaults(
					array(
						'border' => array(
							'border-bottom-width' => 1,
							'border-color'        => 'rgba(185, 185, 185, 0.4)',
							'separator-color'     => '#cccccc',
						),
					)
				),
				'bloghash_header_text_color'               => bloghash_design_options_defaults(
					array(
						'color' => array(
							'text-color' => '#66717f',
							'link-color' => '#131315',
						),
					)
				),

				// Header navigation widgets
				'bloghash_header_navigation_heading_widgets' => true,
				'bloghash_header_navigation_widgets'       => array(),

				// Transparent Header.
				'bloghash_tsp_header'                      => false,
				'bloghash_tsp_header_disable_on'           => array(
					'404',
					'posts_page',
					'archive',
					'search',
				),

				// Sticky Header.
				'bloghash_sticky_header'                   => false,
				'bloghash_sticky_header_hide_on'           => array( '' ),

				// Main Navigation.
				'bloghash_main_nav_heading_animation'      => false,
				'bloghash_main_nav_hover_animation'        => 'underline',
				'bloghash_main_nav_heading_sub_menus'      => true,
				'bloghash_main_nav_sub_indicators'         => true,
				'bloghash_main_nav_heading_mobile_menu'    => false,
				'bloghash_main_nav_mobile_breakpoint'      => 960,
				'bloghash_main_nav_mobile_label'           => '',
				'bloghash_nav_design_options'              => false,
				'bloghash_main_nav_background'             => bloghash_design_options_defaults(
					array(
						'background' => array(
							'color'    => array(
								'background-color' => '#FFFFFF',
							),
							'gradient' => array(),
						),
					)
				),
				'bloghash_main_nav_border'                 => bloghash_design_options_defaults(
					array(
						'border' => array(
							'border-top-width'    => 1,
							'border-bottom-width' => 0,
							'border-style'        => 'solid',
							'border-color'        => 'rgba(185, 185, 185, 0.4)',
						),
					)
				),
				'bloghash_main_nav_font_color'             => bloghash_design_options_defaults(
					array(
						'color' => array(),
					)
				),
				'bloghash_typography_main_nav_heading'     => false,
				'bloghash_main_nav_font'                   => bloghash_typography_defaults(
					array(
						'font-family'         => 'Inter Tight',
						'font-weight'         => 600,
						'font-size-desktop'   => '1.7',
						'font-size-unit'      => 'rem',
						'line-height-desktop' => '1.5',
					)
				),

				// Page Header.
				'bloghash_page_header_enable'              => true,
				'bloghash_page_header_alignment'           => 'left',
				'bloghash_page_header_spacing'             => array(
					'desktop' => array(
						'top'    => 30,
						'bottom' => 30,
					),
					'tablet'  => array(
						'top'    => '',
						'bottom' => '',
					),
					'mobile'  => array(
						'top'    => '',
						'bottom' => '',
					),
					'unit'    => 'px',
				),
				'bloghash_page_header_background'          => bloghash_design_options_defaults(
					array(
						'background' => array(
							'color'    => array( 'background-color' => 'rgba(244,54,118,0.1)' ),
							'gradient' => array(),
							'image'    => array(),
						),
					)
				),
				'bloghash_page_header_text_color'          => bloghash_design_options_defaults(
					array(
						'color' => array(),
					)
				),
				'bloghash_page_header_border'              => bloghash_design_options_defaults(
					array(
						'border' => array(
							'border-bottom-width' => 1,
							'border-style'        => 'solid',
							'border-color'        => 'rgba(0,0,0,.062)',
						),
					)
				),
				'bloghash_typography_page_header'          => false,
				'bloghash_page_header_font_size'           => array(
					'desktop' => 2.6,
					'unit'    => 'rem',
				),

				// Breadcrumbs.
				'bloghash_breadcrumbs_enable'              => true,
				'bloghash_breadcrumbs_hide_on'             => array( 'home' ),
				'bloghash_breadcrumbs_position'            => 'in-page-header',
				'bloghash_breadcrumbs_alignment'           => 'left',
				'bloghash_breadcrumbs_spacing'             => array(
					'desktop' => array(
						'top'    => 15,
						'bottom' => 15,
					),
					'tablet'  => array(
						'top'    => '',
						'bottom' => '',
					),
					'mobile'  => array(
						'top'    => '',
						'bottom' => '',
					),
					'unit'    => 'px',
				),
				'bloghash_breadcrumbs_heading_design'      => false,
				'bloghash_breadcrumbs_background'          => bloghash_design_options_defaults(
					array(
						'background' => array(
							'color'    => array(),
							'gradient' => array(),
							'image'    => array(),
						),
					)
				),
				'bloghash_breadcrumbs_text_color'          => bloghash_design_options_defaults(
					array(
						'color' => array(),
					)
				),
				'bloghash_breadcrumbs_border'              => bloghash_design_options_defaults(
					array(
						'border' => array(
							'border-top-width'    => 0,
							'border-bottom-width' => 0,
							'border-color'        => '',
							'border-style'        => 'solid',
						),
					)
				),

				/**
				 * Hero.
				 */
				'bloghash_enable_hero'                     => true,
				'bloghash_hero_type'                       => 'horizontal-slider',
				'bloghash_hero_slider_align'			   => 'center',
				'bloghash_hero_enable_on'                  => array( 'home' ),
				'bloghash_hero_slider'                     => false,
				'bloghash_hero_slider_orderby'             => 'date-desc',
				'bloghash_hero_slider_title_font_size'     => array(
					'desktop' => 2.4,
					'unit'    => 'rem',
				),
				'bloghash_hero_slider_elements'            => array(
					'category'  => true,
					'meta'      => true,
					'read_more' => true,
				),
				'bloghash_hero_entry_meta_elements'        => array(
					'author'   => true,
					'date'     => true,
					'comments' => false,
				),
				'bloghash_hero_slider_posts'               => false,
				'bloghash_hero_slider_post_number'         => 6,
				'bloghash_hero_slider_category'            => array(),
				'bloghash_hero_slider_read_more'           => esc_html__( 'Continue Reading', 'bloghash' ),

				/**
				 * Featured Links
				 */
				'bloghash_enable_featured_links'           => false,
				'bloghash_featured_links_title'            => esc_html__( 'Today Best Trending Topics', 'bloghash' ),
				'bloghash_featured_links_enable_on'        => array( 'home' ),
				'bloghash_featured_links_style'            => false,
				'bloghash_featured_links_type'             => 'one',
				'bloghash_featured_links_title_type'       => '1',
				'bloghash_featured_links_card_border'      => true,
				'bloghash_featured_links_card_shadow'      => true,
				'bloghash_featured_links'                  => apply_filters(
					'bloghash_featured_links_default',
					array(
						array(
							'link'  => '',
							'image' => array(),
						),
						array(
							'link'  => '',
							'image' => array(),
						),
						array(
							'link'  => '',
							'image' => array(),
						),
					),
				),

				/**
				 * PYML
				 */
				'bloghash_enable_pyml'                     => true,
				'bloghash_pyml_title'                      => esc_html__( 'You May Have Missed', 'bloghash' ),
				'bloghash_pyml_enable_on'                  => array( 'home' ),
				'bloghash_pyml_style'                      => false,
				'bloghash_pyml_type'                       => '1',
				'bloghash_pyml_orderby'                    => 'date-desc',
				'bloghash_pyml_card_border'                => true,
				'bloghash_pyml_card_shadow'                => true,
				'bloghash_pyml_elements'                   => array(
					'category' => true,
					'meta'     => true,
				),
				'bloghash_pyml_posts'                      => true,
				'bloghash_pyml_post_number'                => 4,
				'bloghash_pyml_post_title_font_size'       => array(
					'desktop' => 2,
					'unit'    => 'rem',
				),
				'bloghash_pyml_category'                   => array(),

				/**
				 * Ticker Slider
				 */
				'bloghash_enable_ticker'                   => true,
				'bloghash_ticker_title'                    => esc_html__( 'Top Stories', 'bloghash' ),
				'bloghash_ticker_enable_on'                => array( 'home' ),
				'bloghash_ticker_type'                     => 'one-ticker',
				'bloghash_ticker_elements'                 => array(
					'meta' => true,
				),
				'bloghash_ticker_posts'                    => false,
				'bloghash_ticker_post_number'              => 100,
				'bloghash_ticker_category'                 => array(),

				/**
				 * Blog.
				 */

				// Blog Page / Archive.
				'bloghash_blog_entry_elements'             => array(
					'thumbnail'      => true,
					'header'         => true,
					'meta'           => true,
					'summary'        => true,
					'summary-footer' => true,
				),
				'bloghash_blog_entry_meta_elements'        => array(
					'author'   => true,
					'date'     => true,
					'category' => false,
					'tag'      => false,
					'comments' => false,
				),
				'bloghash_related_posts'                   => false,
				'bloghash_related_posts_enable'            => false,
				'bloghash_related_posts_heading'           => esc_html__( 'Related posts', 'bloghash' ),
				'bloghash_related_post_number'             => 3,
				'bloghash_related_posts_column'            => 4,
				'bloghash_entry_meta_icons'                => true,
				'bloghash_excerpt_length'                  => 30,
				'bloghash_excerpt_more'                    => '&hellip;',
				'bloghash_blog_layout'                     => 'blog-horizontal',
				'bloghash_blog_image_wrap'                 => true,
				'bloghash_blog_zig_zag'                    => false,
				'bloghash_blog_masonry'                    => false,
				'bloghash_blog_layout_column'              => 6,
				'bloghash_blog_image_position'             => 'left',
				'bloghash_blog_image_size'                 => 'large',
				'bloghash_blog_card_border'                => true,
				'bloghash_blog_card_shadow'                => true,
				'bloghash_blog_heading'                    => '',
				'bloghash_blog_read_more'                  => esc_html__( 'Read More', 'bloghash' ),
				'bloghash_blog_horizontal_post_categories' => true,
				'bloghash_blog_horizontal_read_more'       => false,

				// Single Post.
				'bloghash_single_post_layout_heading'      => false,
				'bloghash_single_title_position'           => 'in-content',
				'bloghash_single_title_alignment'          => 'left',
				'bloghash_single_title_spacing'            => array(
					'desktop' => array(
						'top'    => 152,
						'bottom' => 100,
					),
					'tablet'  => array(
						'top'    => 90,
						'bottom' => 55,
					),
					'mobile'  => array(
						'top'    => '',
						'bottom' => '',
					),
					'unit'    => 'px',
				),
				'bloghash_single_content_width'            => 'wide',
				'bloghash_single_narrow_container_width'   => 700,
				'bloghash_single_post_elements_heading'    => false,
				'bloghash_single_post_meta_elements'       => array(
					'author'   => true,
					'date'     => true,
					'comments' => true,
					'category' => false,
				),
				'bloghash_single_post_thumb'               => true,
				'bloghash_single_post_categories'          => true,
				'bloghash_single_post_tags'                => true,
				'bloghash_single_last_updated'             => true,
				'bloghash_single_about_author'             => true,
				'bloghash_single_post_next_prev'           => true,
				'bloghash_single_post_elements'            => array(
					'thumb'          => true,
					'category'       => true,
					'tags'           => true,
					'last-updated'   => true,
					'about-author'   => true,
					'prev-next-post' => true,
				),
				'bloghash_single_toggle_comments'          => false,
				'bloghash_single_entry_meta_icons'         => true,
				'bloghash_typography_single_post_heading'  => false,
				'bloghash_single_content_font_size'        => array(
					'desktop' => '1.6',
					'unit'    => 'rem',
				),

				/**
				 * Sidebar.
				 */

				'bloghash_sidebar_position'                => 'right-sidebar',
				'bloghash_single_post_sidebar_position'    => 'default',
				'bloghash_single_page_sidebar_position'    => 'default',
				'bloghash_archive_sidebar_position'        => 'default',
				'bloghash_sidebar_options_heading'         => false,
				'bloghash_sidebar_style'                   => '2',
				'bloghash_sidebar_width'                   => 30,
				'bloghash_sidebar_sticky'                  => 'sidebar',
				'bloghash_typography_sidebar_heading'      => false,
				'bloghash_sidebar_widget_title_font_size'  => array(
					'desktop' => 2.4,
					'unit'    => 'rem',
				),

				/**
				 * Footer.
				 */

				// Copyright.
				'bloghash_enable_copyright'                => true,
				'bloghash_copyright_layout'                => 'layout-1',
				'bloghash_copyright_separator'             => 'contained-separator',
				'bloghash_copyright_visibility'            => 'all',
				'bloghash_copyright_heading_widgets'       => true,
				'bloghash_copyright_widgets'               => array(
					array(
						'classname' => 'bloghash_customizer_widget_text',
						'type'      => 'text',
						'values'    => array(
							'content'    => wp_kses( 'Copyright {{the_year}} &mdash; <b>{{site_title}}</b>. All rights reserved. <b>{{theme_link}}</b>', bloghash_get_allowed_html_tags() ),
							// 'content'    => esc_html__( '', 'bloghash' ),
							'location'   => 'start',
							'visibility' => 'all',
						),
					),
				),
				'bloghash_copyright_heading_design_options' => false,
				'bloghash_copyright_background'            => bloghash_design_options_defaults(
					array(
						'background' => array(
							'color'    => array(
								'background-color' => '',
							),
							'gradient' => array(),
						),
					)
				),
				'bloghash_copyright_text_color'            => bloghash_design_options_defaults(
					array(
						'color' => array(
							'text-color'       => '#d9d9d9',
							'link-color'       => '#ffffff',
							'link-hover-color' => '#F43676',
						),
					)
				),

				// Main Footer.
				'bloghash_enable_footer'                   => true,
				'bloghash_footer_layout'                   => 'layout-2',
				'bloghash_footer_widgets_align_center'     => false,
				'bloghash_footer_visibility'               => 'all',
				'bloghash_footer_widget_heading_style'     => '0',
				'bloghash_footer_heading_design_options'   => false,
				'bloghash_footer_background'               => bloghash_design_options_defaults(
					array(
						'background' => array(
							'color'    => array(
								'background-color' => '#302d55',
							),
							'gradient' => array(),
							'image'    => array(),
						),
					)
				),
				'bloghash_footer_text_color'               => bloghash_design_options_defaults(
					array(
						'color' => array(
							'text-color'         => '#d9d9d9',
							'link-color'         => '#d9d9d9',
							'link-hover-color'   => '#F43676',
							'widget-title-color' => '#ffffff',
						),
					)
				),
				'bloghash_footer_border'                   => bloghash_design_options_defaults(
					array(
						'border' => array(
							'border-top-width'    => 1,
							'border-bottom-width' => 0,
							'border-color'        => 'rgba(255,255,255,0.1)',
							'border-style'        => 'solid',
						),
					)
				),
				'bloghash_typography_main_footer_heading'  => false,
			);

			$defaults = array_merge( $defaults, $bloghash_categories_color_options );

			$defaults = apply_filters( 'bloghash_default_option_values', $defaults );
			return $defaults;
		}

		/**
		 * Get the options from static array()
		 *
		 * @since  1.0.0
		 * @return array    Return array of theme options.
		 */
		public function get_options() {
			return self::$options;
		}

		/**
		 * Get the options from static array().
		 *
		 * @since  1.0.0
		 * @param string $id Options jet to get.
		 * @return array Return array of theme options.
		 */
		public function get( $id ) {
			$value = isset( self::$options[ $id ] ) ? self::$options[ $id ] : self::get_default( $id );
			$value = apply_filters("theme_mod_{$id}", $value); // phpcs:ignore
			return $value;
		}

		/**
		 * Set option.
		 *
		 * @since  1.0.0
		 * @param string $id Option key.
		 * @param any    $value Option value.
		 * @return void
		 */
		public function set( $id, $value ) {
			set_theme_mod( $id, $value );
			self::$options[ $id ] = $value;
		}

		/**
		 * Refresh options.
		 *
		 * @since  1.0.0
		 * @return void
		 */
		public function refresh() {
			self::$options = wp_parse_args(
				get_theme_mods(),
				self::get_defaults()
			);
		}

		/**
		 * Returns the default value for option.
		 *
		 * @since  1.0.0
		 * @param  string $id Option ID.
		 * @return mixed      Default option value.
		 */
		public function get_default( $id ) {
			$defaults = self::get_defaults();
			return isset( $defaults[ $id ] ) ? $defaults[ $id ] : false;
		}
	}

endif;
