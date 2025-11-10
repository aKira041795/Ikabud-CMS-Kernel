<?php
/**
 * Zakra dynamic CSS generation file for theme options.
 *
 * Class Zakra_Dynamic_CSS
 *
 * @package    ThemeGrill
 * @subpackage Zakra
 * @since      Zakra 1.5.4
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Zakra_Dynamic_CSS' ) ) {

	/**
	 * Zakra dynamic CSS generation file for theme options.
	 *
	 * Class Zakra_Dynamic_CSS
	 */
	class Zakra_Dynamic_CSS {

		/**
		 * Return dynamic CSS output.
		 *
		 * @param string $dynamic_css Dynamic CSS.
		 * @param string $dynamic_css_filtered Dynamic CSS Filters.
		 *
		 * @return string Generated CSS.
		 */
		public static function render_output( $dynamic_css, $dynamic_css_filtered = '' ) {

			// Generate dynamic CSS.
			$parse_css = '';

			$color_palette_default = array(
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
			);

			// Color palette.
			$color_palette = get_theme_mod('zakra_color_palette', $color_palette_default );
			$parse_css .= sprintf(' :root{%s}', array_reduce( array_keys($color_palette['colors'] ?? []), function($acc, $curr) use ($color_palette) {
				$acc .= "--{$curr}: {$color_palette['colors'][$curr]};";

				return $acc;
			}, '' ));

			// Breakpoint media.
			$breakpoint_media_default = array(
				'size' => 768,
				'unit' => 'px',
			);
			$breakpoint_media = get_theme_mod( 'zakra_mobile_menu_breakpoint', $breakpoint_media_default );

			if ( is_string($breakpoint_media)) {

				$breakpoint_media = array(
					'size' => $breakpoint_media,
					'unit' => 'px',
				);
			}

			// Content margin.
			$content_padding_default = array(
				'size' => '',
				'unit' => 'px',
			);

			$content_padding = get_theme_mod( 'zakra_content_area_padding', $content_padding_default );

			$parse_css .= zakra_parse_slider_css(
				$content_padding_default,
				$content_padding,
				'.zak-primary, .zak-secondary',
				'padding-top, padding-bottom'
			);

			/**
			 * Container width.
			 */
			$container_width_default = array(
				'size' => 1170,
				'unit' => 'px',
			);

			$container_width = get_theme_mod( 'zakra_container_width', $container_width_default );

			$parse_css .= zakra_parse_slider_css(
				$container_width_default,
				$container_width,
				'.zak-container, .zak-container--boxed .zak-site',
				'max-width'
			);

			/**
			 * Sidebar width.
			 */
			$sidebar_width_default = array(
				'size' => 30,
				'unit' => '%',
			);

			$sidebar_width = get_theme_mod( 'zakra_sidebar_width', $sidebar_width_default );

			$content_width_css = array(
				'.zak-primary' => array(
					'width' => ( 100 - (float) $sidebar_width['size'] ) . '%',
				),
			);

			$parse_css .= '@media screen and (min-width: ' . $breakpoint_media['size'] . 'px) {';
			$parse_css .= zakra_parse_css( 70, ( 100 - (float) $sidebar_width['size'] ), $content_width_css );
			$parse_css .= zakra_parse_slider_css(
				$sidebar_width_default,
				$sidebar_width,
				'.zak-secondary ',
				'width'
			);
			$parse_css .= '}';

			// Primary color.
			$primary_color     = get_theme_mod( 'zakra_primary_color', '#027abb' );
			$primary_color_css = array(
				'a:hover, a:focus,
				.zak-primary-nav ul li:hover > a,
				.zak-primary-nav ul .current_page_item > a,
				.zak-entry-summary a,
				.zak-entry-meta a, .zak-post-content .zak-entry-footer a:hover,
				.pagebuilder-content a, .zak-style-2 .zak-entry-meta span,
				.zak-style-2 .zak-entry-meta a,
				.entry-title:hover a,
				.zak-breadcrumbs .trail-items a,
				.breadcrumbs .trail-items a,
				.entry-content a,
				.edit-link a,
				.zak-footer-bar a:hover,
				.widget li a,
				#comments .comment-content a,
				#comments .reply,
				button:hover,
				.zak-button:hover,
				.zak-entry-footer .edit-link a,
				.zak-header-action .yith-wcwl-items-count .yith-wcwl-icon span,
				.pagebuilder-content a, .zak-entry-footer a,
				.zak-header-buttons .zak-header-button--2 .zak-button,
				.zak-header-buttons .zak-header-button .zak-button:hover,
				.woocommerce-cart .coupon button.button' => array(
					'color' => esc_html( $primary_color ),
				),

				'.zak-post-content .entry-button:hover .zak-icon,
				.zak-error-404 .zak-button:hover svg,
				.zak-style-2 .zak-entry-meta span .zak-icon,
				.entry-button .zak-icon' => array(
					'fill' => esc_html( $primary_color ),
				),
				'blockquote, .wp-block-quote,
				button, input[type="button"],
				input[type="reset"],
				input[type="submit"],
				.wp-block-button .wp-block-button__link,
				blockquote.has-text-align-right, .wp-block-quote.has-text-align-right,
				button:hover,
				.wp-block-button .wp-block-button__link:hover,
				.zak-button:hover,
				.zak-header-buttons .zak-header-button .zak-button,
				.zak-header-buttons .zak-header-button.zak-header-button--2 .zak-button,
				.zak-header-buttons .zak-header-button .zak-button:hover,
				.woocommerce-cart .coupon button.button,
				.woocommerce-cart .actions > button.button' => array(
					'border-color' => esc_html( $primary_color ),
				),

				'.zak-primary-nav.zak-layout-1-style-2 > ul > li.current_page_item > a::before,
				.zak-primary-nav.zak-layout-1-style-2 > ul a:hover::before,
				.zak-primary-nav.zak-layout-1-style-2 > ul > li.current-menu-item > a::before,
				.zak-primary-nav.zak-layout-1-style-3 > ul > li.current_page_item > a::before,
				.zak-primary-nav.zak-layout-1-style-3 > ul > li.current-menu-item > a::before,
				.zak-primary-nav.zak-layout-1-style-4 > ul > li.current_page_item > a::before,
				.zak-primary-nav.zak-layout-1-style-4 > ul > li.current-menu-item > a::before,
				.zak-scroll-to-top:hover, button, input[type="button"], input[type="reset"],
				input[type="submit"], .zak-header-buttons .zak-header-button--1 .zak-button,
				.wp-block-button .wp-block-button__link,
				.zak-menu-item-cart .cart-page-link .count,
				.widget .wp-block-heading::before,
				#comments .comments-title::before,
				#comments .comment-reply-title::before,
				.widget .widget-title::before,
				.zak-footer-builder .zak-footer-main-row .widget .wp-block-heading::before,
				.zak-footer-builder .zak-footer-top-row .widget .wp-block-heading::before,
				.zak-footer-builder .zak-footer-bottom-row .widget .wp-block-heading::before,
				.zak-footer-builder .zak-footer-main-row .widget .widget-title::before,
				.zak-footer-builder .zak-footer-top-row .widget .widget-title::before,
				.zak-footer-builder .zak-footer-bottom-row .widget .widget-title::before,
				.woocommerce-cart .actions .coupon button.button:hover,
				.woocommerce-cart .actions > button.button,
				.woocommerce-cart .actions > button.button:hover' => array(
					'background-color' => esc_html( $primary_color ),
				),

				'button, input[type="button"],
				input[type="reset"],
				input[type="submit"],
				.wp-block-button .wp-block-button__link,
				.zak-button' => array(
					'border-color'     => esc_html( $primary_color ),
					'background-color' => esc_html( $primary_color ),
				),
			);
			$parse_css         .= zakra_parse_css( '#027abb', $primary_color, $primary_color_css );

			// Text color.
			$text_color     = get_theme_mod( 'zakra_base_color', '#3F3F46' );
			$text_color_css = array(
				'body, .woocommerce-ordering select' => array(
					'color' => esc_html( $text_color ),
				),
			);
			$parse_css      .= zakra_parse_css( '#3F3F46', $text_color, $text_color_css );

			// Outside container background.
			$container_background_color     = get_theme_mod( 'background_color', '' );
			$container_background_color_css = array(
				'body.custom-background' => array(
					'background-color' => esc_html( $container_background_color ),
				),
			);
			$parse_css      .= zakra_parse_css( '', $container_background_color, $container_background_color_css );

			// Border color.
			$border_color     = get_theme_mod( 'zakra_border_color', '#E4E4E7' );
			$border_color_css = array(
				'.zak-header, .zak-post, .zak-secondary, .zak-footer-bar, .zak-primary-nav .sub-menu, .zak-primary-nav .sub-menu li, .posts-navigation, #comments, .post-navigation, blockquote, .wp-block-quote, .zak-posts .zak-post, .zak-content-area--boxed .widget' => array(
					'border-color' => esc_html( $border_color ),
				),

				'hr .zak-container--separate, '                                                                                                                                                                   => array(
					'background-color' => esc_html( $border_color ),
				),
			);
			$parse_css        .= zakra_parse_css( '#E4E4E7', $border_color, $border_color_css );

			// Link colors.
			$link_color_normal     = get_theme_mod( 'zakra_link_color', '#027abb' );
			$link_color_normal_css = array(
				'.entry-content a' => array(
					'color' => esc_html( $link_color_normal ),
				),
			);
			$parse_css             .= zakra_parse_css( '#027abb', $link_color_normal, $link_color_normal_css );

			// Link hover color.
			$link_color_hover     = get_theme_mod( 'zakra_link_hover_color', '#027abb' );
			$link_color_hover_css = array(
				'.zak-entry-footer a:hover,
				.entry-button:hover,
				.zak-entry-footer a:hover,
				.entry-content a:hover,
				.pagebuilder-content a:hover, .pagebuilder-content a:hover' => array(
					'color' => esc_html( $link_color_hover ),
				),
				'.entry-button:hover .zak-icon'                                                                                                => array(
					'fill' => esc_html( $link_color_hover ),
				),
			);
			$parse_css            .= zakra_parse_css( '#027abb', $link_color_hover, $link_color_hover_css );

			// Inside container background color.
			$inside_container_background_default = array(
				'background-color'      => '#ffffff',
				'background-image'      => '',
				'background-position'   => 'center center',
				'background-size'       => 'auto',
				'background-attachment' => 'scroll',
				'background-repeat'     => 'repeat',
			);
			$inside_container_background         = get_theme_mod( 'zakra_inside_container_background', $inside_container_background_default );
			$parse_css                           .= zakra_parse_background_css( $inside_container_background_default, $inside_container_background, '.zak-content' );

			$body_typography_default = array(
				'font-family'    => 'Default',
				'font-weight'    => '400',
				'font-size'      => array(
					'desktop' => array(
						'size' => '15',
						'unit' => 'px',
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
			);

			$body_typography = get_theme_mod( 'zakra_body_typography', $body_typography_default );

			$parse_css .= zakra_parse_typography_css(
				$body_typography_default,
				$body_typography,
				'body',
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			$heading_typography_default = apply_filters(
				'zakra_heading_typography_filter',
				array(
					'font-family'    => 'Default',
					'font-weight'    => '400',
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
			);

			$heading_typography = get_theme_mod( 'zakra_heading_typography', $heading_typography_default );

			$parse_css .= zakra_parse_typography_css(
				$heading_typography_default,
				$heading_typography,
				'h1, h2, h3, h4, h5, h6',
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			$h1_typography_default = apply_filters(
				'zakra_h1_typography_filter',
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
			);

			$h1_typography = get_theme_mod( 'zakra_h1_typography', $h1_typography_default );

			$parse_css .= zakra_parse_typography_css(
				$h1_typography_default,
				$h1_typography,
				'h1',
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			$h2_typography_default = apply_filters(
				'zakra_h2_typography_filter',
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
			);

			$h2_typography = get_theme_mod( 'zakra_h2_typography', $h2_typography_default );

			$parse_css .= zakra_parse_typography_css(
				$h2_typography_default,
				$h2_typography,
				'h2',
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			$h3_typography_default = apply_filters(
				'zakra_h3_typography_filter',
				array(
					'font-family'    => 'Default',
					'font-weight'    => '500',
					'subsets'        => array( 'latin' ),
					'font-size'      => array(
						'desktop' => array(
							'size' => '2',
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
			);

			$h3_typography = get_theme_mod( 'zakra_h3_typography', $h3_typography_default );

			$parse_css .= zakra_parse_typography_css(
				$h3_typography_default,
				$h3_typography,
				'h3',
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			$h4_typography_default = apply_filters(
				'zakra_h4_typography_filter',
				array(
					'font-family'    => 'Default',
					'font-weight'    => '500',
					'subsets'        => array( 'latin' ),
					'font-size'      => array(
						'desktop' => array(
							'size' => '1.75',
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
			);

			$h4_typography = get_theme_mod( 'zakra_h4_typography', $h4_typography_default );

			$parse_css .= zakra_parse_typography_css(
				$h4_typography_default,
				$h4_typography,
				'h4',
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			$h5_typography_default = apply_filters(
				'zakra_h5_typography_filter',
				array(
					'font-family'    => 'Default',
					'font-weight'    => '500',
					'subsets'        => array( 'latin' ),
					'font-size'      => array(
						'desktop' => array(
							'size' => '1.313',
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
			);

			$h5_typography = get_theme_mod( 'zakra_h5_typography', $h5_typography_default );

			$parse_css .= zakra_parse_typography_css(
				$h5_typography_default,
				$h5_typography,
				'h5',
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			$h6_typography_default = apply_filters(
				'zakra_h6_typography_filter',
				array(
					'font-family'    => 'Default',
					'font-weight'    => '500',
					'subsets'        => array( 'latin' ),
					'font-size'      => array(
						'desktop' => array(
							'size' => '1.125',
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
			);

			$h6_typography = get_theme_mod( 'zakra_h6_typography', $h6_typography_default );

			$parse_css .= zakra_parse_typography_css(
				$h6_typography_default,
				$h6_typography,
				'h6',
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			// Button padding.
			$button_padding_default = array(
				'top'    => '10',
				'right'  => '15',
				'bottom' => '10',
				'left'   => '15',
				'unit'   => 'px',
			);

			$button_padding = get_theme_mod( 'zakra_button_padding', $button_padding_default );

			$parse_css .= zakra_parse_dimension_css(
				$button_padding_default,
				$button_padding,
				'button, input[type="button"], input[type="reset"], input[type="submit"], #infinite-handle span, .wp-block-button .wp-block-button__link',
				'padding'
			);

			// Button typography.
			$button_typography_default = array(
				'font-family'    => 'default',
				'font-weight'    => '400',
				'subsets'        => array( 'latin' ),
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
			);
			$button_typography         = get_theme_mod( 'zakra_button_typography', $button_typography_default );
			$parse_css                .= zakra_parse_typography_css(
				$button_typography_default,
				$button_typography,
				'button, input[type="button"], input[type="reset"], input[type="submit"], .wp-block-button .wp-block-button__link',
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			// Button color.
			$button_color     = get_theme_mod( 'zakra_button_color', '' );
			$button_color_css = array(
				'button, input[type="button"], input[type="reset"], input[type="submit"], #infinite-handle span, .wp-block-button .wp-block-button__link' => array(
					'color' => esc_html( $button_color ),
				),
			);
			$parse_css        .= zakra_parse_css( '', $button_color, $button_color_css );

			// Button hover color.
			$button_hover_color     = get_theme_mod( 'zakra_button_hover_color', '' );
			$button_hover_color_css = array(
				'button:hover, input[type="button"]:hover, input[type="reset"]:hover, input[type="submit"]:hover, #infinite-handle span:hover, .wp-block-button .wp-block-button__link:hover' => array(
					'color' => esc_html( $button_hover_color ),
				),
			);
			$parse_css              .= zakra_parse_css( '', $button_hover_color, $button_hover_color_css );

			// Button background color.
			$button_background_color     = get_theme_mod( 'zakra_button_background_color', '#027abb' );
			$button_background_color_css = array(
				'button, input[type="button"], input[type="reset"], input[type="submit"], #infinite-handle span, .wp-block-button .wp-block-button__link' => array(
					'background-color' => esc_html( $button_background_color ),
				),
			);
			$parse_css                   .= zakra_parse_css( '#027abb', $button_background_color, $button_background_color_css );

			// Button background hover color.
			$button_background_hover_color     = get_theme_mod( 'zakra_button_background_hover_color', '#ffffff' );
			$button_background_hover_color_css = array(
				'button:hover, input[type="button"]:hover, input[type="reset"]:hover, input[type="submit"]:hover, #infinite-handle span:hover, .wp-block-button .wp-block-button__link:hover' => array(
					'background-color' => esc_html( $button_background_hover_color ),
				),
			);
			$parse_css                         .= zakra_parse_css( '#ffffff', $button_background_hover_color, $button_background_hover_color_css );

			/**
			 * Root font size.
			 */
			$html_font_size_default = array(
				'size' => '10',
				'unit' => 'px',
			);

			$html_font_size = get_theme_mod( 'zakra_root_font_size', $html_font_size_default );

			$parse_css .= zakra_parse_slider_css(
				$html_font_size_default,
				$html_font_size,
				':root',
				'--zak-root-font-size'
			);

			/**
			 * Button border radius.
			 */
			$button_border_radius_default = array(
				'size' => 0,
				'unit' => 'px',
			);

			$button_border_radius = get_theme_mod( 'zakra_button_border_radius', $button_border_radius_default );

			$parse_css .= zakra_parse_slider_css(
				$button_border_radius_default,
				$button_border_radius,
				'button, input[type="button"], input[type="reset"], input[type="submit"], #infinite-handle span, .wp-block-button .wp-block-button__link',
				'border-radius'
			);

			// Site title color.
			$site_title_color     = get_theme_mod( 'zakra_site_identity_color', '#16181a' );
			$site_title_color_css = array(
				'.site-title' => array(
					'color' => esc_html( $site_title_color ),
				),
			);
			$parse_css            .= zakra_parse_css( '#16181a', $site_title_color, $site_title_color_css );

			// Site logo width.
			$site_logo_width_default = array(
				'size' => '',
				'unit' => 'px',
			);

			$site_logo_width = get_theme_mod( 'zakra_site_logo_height', $site_logo_width_default );

			$parse_css .= zakra_parse_slider_css(
				$site_logo_width_default,
				$site_logo_width,
				'.site-branding .custom-logo-link img',
				'max-width'
			);

			$typography_site_title_default = array(
				'font-family'    => 'Default',
				'font-weight'    => 'regular',
				'subsets'        => array( 'latin' ),
				'font-size'      => array(
					'desktop' => array(
						'size' => '4',
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
						'size' => '1.5',
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
			);

			$typography_site_title = get_theme_mod( 'zakra_site_title_typography', $typography_site_title_default );

			$parse_css .= zakra_parse_typography_css(
				$typography_site_title_default,
				$typography_site_title,
				'.site-branding .site-title',
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			// Site title tagline color.
			$site_tagline_color     = get_theme_mod( 'zakra_site_tagline_color', '#54595f' );
			$site_tagline_color_css = array(
				'.site-description' => array(
					'color' => esc_html( $site_tagline_color ),
				),
			);
			$parse_css              .= zakra_parse_css( '#54595f', $site_tagline_color, $site_tagline_color_css );

			$site_tagline_typography_default = array(
				'font-family'    => 'Default',
				'font-weight'    => 'regular',
				'subsets'        => array( 'latin' ),
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
			);

			$site_tagline_typography = get_theme_mod( 'zakra_site_tagline_typography', $site_tagline_typography_default );

			$parse_css .= zakra_parse_typography_css(
				$site_tagline_typography_default,
				$site_tagline_typography,
				'.site-branding .site-description',
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			// Header builder top text color.
			$header_top_text_color     = get_theme_mod( 'zakra_top_bar_color', '#FAFAFA' );
			$header_top_text_color_css = array(
				'.zak-header .zak-top-bar' => array(
					'color' => esc_html( $header_top_text_color ),
				),
			);
			$parse_css                 .= zakra_parse_css( '#FAFAFA', $header_top_text_color, $header_top_text_color_css );

			// Header builder top background.
			$header_top_background_default = array(
				'background-color'      => '#18181B',
				'background-image'      => '',
				'background-position'   => 'center center',
				'background-size'       => 'auto',
				'background-attachment' => 'scroll',
				'background-repeat'     => 'repeat',
			);

			$header_top_background = get_theme_mod( 'zakra_top_bar_background', $header_top_background_default );

			$parse_css .= zakra_parse_background_css( $header_top_background_default, $header_top_background, '.zak-header .zak-top-bar' );

			// Header builder main background.
			$header_main_background_default = array(
				'background-color'      => '',
				'background-image'      => '',
				'background-position'   => 'center center',
				'background-size'       => 'auto',
				'background-attachment' => 'scroll',
				'background-repeat'     => 'repeat',
			);
			$header_main_background         = get_theme_mod( 'zakra_main_header_background_color', $header_main_background_default );
			$parse_css                      .= zakra_parse_background_css( $header_main_background_default, $header_main_background, '.zak-header .zak-main-header' );

			// Header builder main border bottom.
			$is_header_transparent                  = zakra_is_header_transparent_enabled();
			$header_main_border_bottom_css_selector = $is_header_transparent ? '.zak-header.zak-layout-1-transparent .zak-header-transparent-wrapper' : '.zak-header, .zak-header-sticky-wrapper .sticky-header';

			/**
			 * Header builder main border bottom width.
			 */
			$header_main_border_bottom_width_default = array(
				'size' => 1,
				'unit' => 'px',
			);

			$header_main_border_bottom_width = get_theme_mod( 'zakra_main_header_border_bottom_width', $header_main_border_bottom_width_default );

			$parse_css .= zakra_parse_slider_css(
				$header_main_border_bottom_width_default,
				$header_main_border_bottom_width,
				$header_main_border_bottom_css_selector,
				'border-bottom-width'
			);

			// Header builder main border bottom color.
			$header_main_border_bottom_color = get_theme_mod( 'zakra_main_header_border_bottom_color', '#E4E4E7' );

			$header_main_border_bottom_color_css = array(
				$header_main_border_bottom_css_selector => array(
					'border-bottom-color' => esc_html( $header_main_border_bottom_color ),
				),
			);

			$parse_css .= zakra_parse_css( '#E4E4E7', $header_main_border_bottom_color, $header_main_border_bottom_color_css );

			/**
			 *  Header builder button1 dynamic CSS.
			 */
			$button_on_mobile      = get_theme_mod( 'zakra_header_button_mobile' );
			$_mobile_button1_class = ( 1 === $button_on_mobile ) ? ', .zak-header-buttons .zak-button' : '';
			$button1_combine_class = '.zak-header-buttons .zak-header-button.zak-header-button--1 .zak-button' . $_mobile_button1_class;
			$mobile_button1_hover  = ( 1 === $button_on_mobile ) ? ', .zak-header-buttons .zak-button:hover' : '';
			$button1_combine_hover = '.zak-header-buttons .zak-header-button.zak-header-button--1 .zak-button:hover' . $mobile_button1_hover;

			// Header builder button padding.
			$header_button_padding_default = array(
				'top'    => '5',
				'right'  => '10',
				'bottom' => '5',
				'left'   => '10',
				'unit'   => 'px',
			);

			$header_button_padding = get_theme_mod( 'zakra_header_button_padding', $header_button_padding_default );

			$parse_css .= zakra_parse_dimension_css(
				$header_button_padding_default,
				$header_button_padding,
				$button1_combine_class,
				'padding'
			);

			// Header Button typography.
			$header_button_typography_default = array(
				'font-family'    => 'default',
				'font-weight'    => '400',
				'subsets'        => array( 'latin' ),
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
			);
			$header_button_typography         = get_theme_mod( 'zakra_header_button_typography', $header_button_typography_default );
			$parse_css                .= zakra_parse_typography_css(
				$header_button_typography_default,
				$header_button_typography,
				$button1_combine_class,
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			// Header builder button text color.
			$header_button_text_color     = get_theme_mod( 'zakra_header_button_color', '#ffffff' );
			$header_button_text_color_css = array(
				$button1_combine_class => array(
					'color' => esc_html( $header_button_text_color ),
				),
			);
			$parse_css                    .= zakra_parse_css( '#ffffff', $header_button_text_color, $header_button_text_color_css );

			// Header builder button hover text color.
			$header_button_hover_text_color     = get_theme_mod( 'zakra_header_button_hover_color', '#ffffff' );
			$header_button_hover_text_color_css = array(
				$button1_combine_hover => array(
					'color' => esc_html( $header_button_hover_text_color ),
				),
			);
			$parse_css                          .= zakra_parse_css( '#ffffff', $header_button_hover_text_color, $header_button_hover_text_color_css );

			// Header builder background color.
			$header_button_background_color     = get_theme_mod( 'zakra_header_button_background_color', '#027abb' );
			$header_button_background_color_css = array(
				$button1_combine_class => array(
					'background-color' => esc_html( $header_button_background_color ),
				),
			);
			$parse_css                          .= zakra_parse_css( '#027abb', $header_button_background_color, $header_button_background_color_css );

			// Header builder button hover background color.
			$header_button_background_hover_color     = get_theme_mod( 'zakra_header_button_background_hover_color', '' );
			$header_button_background_hover_color_css = array(
				$button1_combine_hover => array(
					'background-color' => esc_html( $header_button_background_hover_color ),
				),
			);
			$parse_css                                .= zakra_parse_css( '#ffffff', $header_button_background_hover_color, $header_button_background_hover_color_css );

			/**
			 * Header builder button border radius.
			 */
			$header_button_border_radius_default = array(
				'size' => 0,
				'unit' => 'px',
			);

			$header_button_border_radius = get_theme_mod( 'zakra_header_button_border_radius', $header_button_border_radius_default );

			$parse_css .= zakra_parse_slider_css(
				$header_button_border_radius_default,
				$header_button_border_radius,
				$button1_combine_class,
				'border-radius'
			);

			/**
			 * Primary menu border bottom.
			 */
			$primary_menu_border_bottom_default = array(
				'size' => 0,
				'unit' => 'px',
			);

			$primary_menu_border_bottom = get_theme_mod( 'zakra_primary_menu_border_bottom_width', $primary_menu_border_bottom_default );

			$parse_css .= zakra_parse_slider_css(
				$primary_menu_border_bottom_default,
				$primary_menu_border_bottom,
				'.zak-header .main-navigation',
				'border-bottom-width'
			);

			// Primary menu border bottom.
			$primary_menu_border_bottom_color     = get_theme_mod( 'zakra_primary_menu_border_bottom_color', '#e9ecef' );
			$primary_menu_border_bottom_color_css = array(
				'.zak-header .main-navigation' => array(
					'border-bottom-color' => esc_html( $primary_menu_border_bottom_color ),
				),
			);
			$parse_css                            .= zakra_parse_css( '#e9ecef', $primary_menu_border_bottom_color, $primary_menu_border_bottom_color_css );

			// Primary menu item color.
			$primary_menu_item_color_normal     = get_theme_mod( 'zakra_main_menu_color', '' );
			$primary_menu_item_color_normal_css = array(
				'.zak-primary-nav ul li > a, .zak-main-nav.zak-primary-nav ul.zak-primary-menu > li > a, .zak-primary-nav.zak-menu-item--layout-2 > ul > li > a'                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          => array(
					'color' => esc_html( $primary_menu_item_color_normal ),
				),
				'.zak-primary-nav ul li > a .zak-icon, zak-main-nav.zak-primary-nav ul.zak-primary-menu li .zak-icon, .zak-primary-nav.zak-menu-item--layout-2 > ul > li > .zak-icon' => array(
					'fill' => esc_html( $primary_menu_item_color_normal ),
				),
			);
			$parse_css                          .= zakra_parse_css( '', $primary_menu_item_color_normal, $primary_menu_item_color_normal_css );

			// Primary menu item hover color.
			$primary_menu_item_color_hover     = get_theme_mod( 'zakra_main_menu_hover_color', '' );
			$primary_menu_item_color_hover_css = array(
				'.zak-primary-nav ul li:not(.current-menu-item):hover > a, .zak-primary-nav.zak-menu-item--layout-2 > ul > li:not(.current-menu-item):hover > a, .zak-primary-nav ul li:not(.current-menu-item):hover > a, .zak-main-nav.zak-primary-nav ul.zak-primary-menu li:not(.current-menu-item):hover > a'                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          => array(
					'color' => esc_html( $primary_menu_item_color_hover ),
				),
				'.zak-primary-nav ul li:hover > .zak-icon, .zak-primary-nav.zak-menu-item--layout-2 > ul > li:hover > .zak-icon' => array(
					'fill' => esc_html( $primary_menu_item_color_hover ),
				),
				'.zak-primary-nav.zak-layout-1-style-2 > ul li a:hover::before' => array(
					'background-color' => esc_html( $primary_menu_item_color_hover ),
				),
			);
			$parse_css                         .= zakra_parse_css( '', $primary_menu_item_color_hover, $primary_menu_item_color_hover_css );

			// Primary menu item active color.
			$primary_menu_item_color_active     = get_theme_mod( 'zakra_main_menu_active_color', '' );
			$primary_menu_item_color_active_css = array(
				'.zak-primary-nav ul li:active > a, .zak-primary-nav ul > li:not(.zak-header-button).current_page_item > a, .zak-primary-nav ul > li:not(.zak-header-button).current_page_ancestor > a, .zak-primary-nav ul > li:not(.zak-header-button).current-menu-item > a, .zak-primary-nav ul > li:not(.zak-header-button).current-menu-ancestor > a'                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          => array(
					'color' => esc_html( $primary_menu_item_color_active ),
				),
				'.zak-primary-nav.zak-layout-1-style-2 ul > li:not(.zak-header-button).current_page_item > a::before, .zak-primary-nav.zak-layout-1-style-2 ul > li:not(.zak-header-button).current_page_ancestor > a::before, .zak-primary-nav.zak-layout-1-style-2 ul > li:not(.zak-header-button).current-menu-item > a::before, .zak-primary-nav.zak-layout-1-style-2 ul > li:not(.zak-header-button).current-menu-ancestor > a::before, .zak-primary-nav.zak-layout-1-style-3 ul > li:not(.zak-header-button).current_page_item > a::before, .zak-primary-nav.zak-layout-1-style-3 ul > li:not(.zak-header-button).current_page_ancestor > a::before, .zak-primary-nav.zak-layout-1-style-3 ul > li:not(.zak-header-button).current-menu-item > a::before, .zak-primary-nav.zak-layout-1-style-3 ul > li:not(.zak-header-button).current-menu-ancestor > a::before, .zak-primary-nav.zak-layout-1-style-4 ul > li:not(.zak-header-button).current_page_item > a::before, .zak-primary-nav.zak-layout-1-style-4 ul > li:not(.zak-header-button).current_page_ancestor > a::before, .zak-primary-nav.zak-layout-1-style-4 ul > li:not(.zak-header-button).current-menu-item > a::before, .zak-primary-nav.zak-layout-1-style-4 ul > li:not(.zak-header-button).current-menu-ancestor > a::before' => array(
					'background-color' => esc_html( $primary_menu_item_color_active ),
				),
				'.zak-primary-nav ul li:hover > .zak-icon, .zak-primary-nav.zak-menu-item--layout-2 > ul > li span' => array(
					'fill' => esc_html( $primary_menu_item_color_active ),
				),
			);
			$parse_css                          .= zakra_parse_css( '', $primary_menu_item_color_active, $primary_menu_item_color_active_css );

			$main_menu_typography_default = array(
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
			);

			$main_menu_typography = get_theme_mod( 'zakra_main_menu_typography', $main_menu_typography_default );

			$parse_css .= zakra_parse_typography_css(
				$main_menu_typography_default,
				$main_menu_typography,
				'.zak-primary-nav ul li a',
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			$sub_menu_typography_default = array(
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
			);

			$sub_menu_typography = get_theme_mod( 'zakra_sub_menu_typography', $sub_menu_typography_default );

			$parse_css .= zakra_parse_typography_css(
				$sub_menu_typography_default,
				$sub_menu_typography,
				'.zak-primary-nav ul li ul li a',
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			$mobile_menu_typography_default = array(
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
			);

			$mobile_menu_typography = get_theme_mod( 'zakra_mobile_menu_typography', $mobile_menu_typography_default );

			$parse_css .= zakra_parse_typography_css(
				$mobile_menu_typography_default,
				$mobile_menu_typography,
				'.zak-mobile-menu a',
				array(
					'mobile' => 600,
					'tablet' => 768,
				)
			);

			// Page header padding.
			$page_title_padding_default = array(
				'top'    => '20',
				'right'  => '0',
				'bottom' => '20',
				'left'   => '0',
				'unit'   => 'px',
			);

			$page_title_padding = get_theme_mod( 'zakra_page_header_padding', $page_title_padding_default );

			$parse_css .= zakra_parse_dimension_css(
				$page_title_padding_default,
				$page_title_padding,
				'.has-page-header .zak-page-header',
				'padding'
			);

			// Breadcrumbs font size.
			$breadcrumb_typography_default = array(
				'font-family' => 'Default',
				'font-weight' => '500',
				'font-size'   => array(
					'desktop' => array(
						'size' => '16',
						'unit' => 'px',
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
			);

			$breadcrumb_typography = get_theme_mod( 'zakra_breadcrumb_typography', $breadcrumb_typography_default );

			$parse_css .= zakra_parse_typography_css(
				$breadcrumb_typography_default,
				$breadcrumb_typography,
				apply_filters( 'zakra_breadcrumb_typography_selector', '.zak-page-header .breadcrumb-trail ul li' ),
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			// Page/Post title color.
			$post_page_title_color     = get_theme_mod( 'zakra_post_page_title_color', '#16181a' );
			$post_page_title_color_css = array(
				'.zak-page-header .zak-page-title, .zakra-single-article .zak-entry-header .entry-title' => array(
					'color' => esc_html( $post_page_title_color ),
				),
			);
			$parse_css                 .= zakra_parse_css( '#16181a', $post_page_title_color, $post_page_title_color_css );

			// Page header background.
			$page_header_background_default = array(
				'background-color'      => '#E4E4E7',
				'background-image'      => '',
				'background-position'   => 'top left',
				'background-size'       => 'auto',
				'background-attachment' => 'scroll',
				'background-repeat'     => 'repeat',
			);
			$page_header_background         = get_theme_mod( 'zakra_page_header_background', $page_header_background_default );
			$parse_css                      .= zakra_parse_background_css( $page_header_background_default, $page_header_background, '.zak-page-header, .zak-container--separate .zak-page-header' );

			// Breadcrumbs text color.
			$breadcrumb_text_color     = get_theme_mod( 'zakra_breadcrumbs_text_color', '#16181a' );
			$breadcrumb_text_color_css = array(
				apply_filters( 'zakra_breadcrumbs_text_color_selector', '.zak-page-header .breadcrumb-trail ul li' ) => array(
					'color' => esc_html( $breadcrumb_text_color ),
				),
			);
			$parse_css                 .= zakra_parse_css( '#16181a', $breadcrumb_text_color, $breadcrumb_text_color_css );

			// Breadcrumbs separator color.
			$breadcrumb_separator_color     = get_theme_mod( 'zakra_breadcrumb_separator_color', '#51585f' );
			$breadcrumb_separator_color_css = array(
				apply_filters( 'zakra_breadcrumb_separator_color_selector', '.zak-page-header .breadcrumb-trail ul li::after' ) => array(
					'color' => esc_html( $breadcrumb_separator_color ),
				),
			);
			$parse_css                      .= zakra_parse_css( '#51585f', $breadcrumb_separator_color, $breadcrumb_separator_color_css );

			// Breadcrumbs link color.
			$breadcrumb_link_color     = get_theme_mod( 'zakra_breadcrumbs_link_color', '#16181a' );
			$breadcrumb_link_color_css = array(
				apply_filters( 'zakra_breadcrumbs_link_color_selector', '.zak-page-header .breadcrumb-trail ul li a' ) => array(
					'color' => esc_html( $breadcrumb_link_color ),
				),
			);
			$parse_css                 .= zakra_parse_css( '#16181a', $breadcrumb_link_color, $breadcrumb_link_color_css );

			// Breadcrumbs link hover color.
			$breadcrumb_link_hover_color     = get_theme_mod( 'zakra_breadcrumbs_link_hover_color', '#027abb' );
			$breadcrumb_link_hover_color_css = array(
				apply_filters( 'zakra_breadcrumbs_link_hover_color_selector', '.zak-page-header .breadcrumb-trail ul li a:hover ' ) => array(
					'color' => esc_html( $breadcrumb_link_hover_color ),
				),
			);
			$parse_css                       .= zakra_parse_css( '#027abb', $breadcrumb_link_hover_color, $breadcrumb_link_hover_color_css );

			$page_title_typography_default = apply_filters(
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
			);

			$page_title_typography = get_theme_mod( 'zakra_post_page_title_typography', $page_title_typography_default );

			$parse_css .= zakra_parse_typography_css(
				$page_title_typography_default,
				$page_title_typography,
				'.zak-page-header .zak-page-title, .zakra-single-article .zak-entry-header .entry-title',
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			$blog_post_title_typography_default = array(
				'font-family'    => 'Default',
				'font-weight'    => '500',
				'subsets'        => array( 'latin' ),
				'font-size'      => array(
					'desktop' => array(
						'size' => '2.25',
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
			);

			$blog_post_title_typography = get_theme_mod( 'zakra_blog_post_title_typography', $blog_post_title_typography_default );

			$parse_css .= zakra_parse_typography_css(
				$blog_post_title_typography_default,
				$blog_post_title_typography,
				apply_filters( 'zakra_blog_post_title_typography_selector', '.entry-title:not(.zak-page-title)' ),
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			$widget_title_typography_default = apply_filters(
				'zakra_widget_title_typography_filter',
				array(
					'font-family'    => 'Default',
					'font-weight'    => '500',
					'subsets'        => array( 'latin' ),
					'font-size'      => array(
						'desktop' => array(
							'size' => '1.2',
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
			);

			$widget_title_typography = get_theme_mod( 'zakra_widget_title_typography', $widget_title_typography_default );

			$parse_css .= zakra_parse_typography_css(
				$widget_title_typography_default,
				$widget_title_typography,
				'.zak-secondary .widget .widget-title, .zak-secondary .widget .wp-block-heading',
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			$widget_content_typography_default = apply_filters(
				'zakra_widget_content_typography_filter',
				array(
					'font-family'    => 'Default',
					'font-weight'    => 'regular',
					'subsets'        => array( 'latin' ),
					'font-size'      => array(
						'desktop' => array(
							'size' => '14',
							'unit' => 'px',
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
				)
			);

			$widget_content_typography = get_theme_mod( 'zakra_widget_content_typography', $widget_content_typography_default );

			$parse_css .= zakra_parse_typography_css(
				$widget_content_typography_default,
				$widget_content_typography,
				'.zak-secondary .widget, .zak-secondary .widget li a',
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			// Footer background.
			$footer_widgets_background_defaults = array(
				'background-color'      => '#18181B',
				'background-image'      => '',
				'background-repeat'     => 'repeat',
				'background-position'   => 'center center',
				'background-size'       => 'contain',
				'background-attachment' => 'scroll',
			);
			$footer_widgets_background          = get_theme_mod( 'zakra_footer_column_background', $footer_widgets_background_defaults );
			$parse_css                          .= zakra_parse_background_css( $footer_widgets_background_defaults, $footer_widgets_background, apply_filters( 'zakra_footer_widgets_bg_selector', '.zak-footer-cols' ) );

			// Footer widgets title color.
			$footer_widgets_title_color     = get_theme_mod( 'zakra_footer_widgets_title_color', '' );
			$footer_widgets_title_color_css = array(
				'.zak-footer .zak-footer-cols .widget-title, .zak-footer-cols h1, .zak-footer-cols h2, .zak-footer-cols h3, .zak-footer-cols h4, .zak-footer-cols h5, .zak-footer-cols h6' => array(
					'color' => esc_html( $footer_widgets_title_color ),
				),
			);
			$parse_css                      .= zakra_parse_css( '', $footer_widgets_title_color, $footer_widgets_title_color_css );

			// Footer widgets text color.
			$footer_widgets_text_color     = get_theme_mod( 'zakra_footer_column_widget_text_color', '' );
			$footer_widgets_text_color_css = array(
				'.zak-footer .zak-footer-cols, .zak-footer .zak-footer-cols p' => array(
					'color' => esc_html( $footer_widgets_text_color ),
				),
			);
			$parse_css                     .= zakra_parse_css( '', $footer_widgets_text_color, $footer_widgets_text_color_css );

			// Footer widgets link color.
			$footer_widgets_link_color     = get_theme_mod( 'zakra_footer_column_widget_link_color', '' );
			$footer_widgets_link_color_css = array(
				'.zak-footer .zak-footer-cols a, .zak-footer-col .widget ul a' => array(
					'color' => esc_html( $footer_widgets_link_color ),
				),
			);
			$parse_css                     .= zakra_parse_css( '', $footer_widgets_link_color, $footer_widgets_link_color_css );

			// Footer widgets link hover color.
			$footer_widgets_link_hover_color     = get_theme_mod( 'zakra_footer_column_widget_link_hover_color', '' );
			$footer_widgets_link_hover_color_css = array(
				'.zak-footer .zak-footer-cols a:hover, .zak-footer-col .widget ul a:hover, .zak-footer .zak-footer-cols a:focus' => array(
					'color' => esc_html( $footer_widgets_link_hover_color ),
				),
			);
			$parse_css                           .= zakra_parse_css( '', $footer_widgets_link_hover_color, $footer_widgets_link_hover_color_css );

			// Outside background.
			$outside_container_background_defaults = array(
				'background-color'      => '',
				'background-image'      => '',
				'background-repeat'     => 'repeat',
				'background-position'   => 'center center',
				'background-size'       => 'contain',
				'background-attachment' => 'scroll',
			);
			$outside_container_background          = get_theme_mod( 'zakra_outside_container_background', $outside_container_background_defaults );
			$parse_css                          .= zakra_parse_background_css( $outside_container_background_defaults, $outside_container_background, apply_filters( 'zakra_outside_container_background', 'body,body.page-template-pagebuilder' ) );

			/**
			 * Footer widgets border top width.
			 */
			$footer_widgets_border_top_width_default = array(
				'size' => 1,
				'unit' => 'px',
			);

			$footer_widgets_border_top_width = get_theme_mod( 'zakra_footer_column_border_top_width', $footer_widgets_border_top_width_default );

			$parse_css .= zakra_parse_slider_css(
				$footer_widgets_border_top_width_default,
				$footer_widgets_border_top_width,
				'.zak-footer-cols',
				'border-top-width'
			);

			// Footer widgets border top color.
			$footer_widgets_border_top_color     = get_theme_mod( 'zakra_footer_column_border_top_color', '#e9ecef' );
			$footer_widgets_border_top_color_css = array(
				'.zak-footer-cols' => array(
					'border-top-color' => esc_html( $footer_widgets_border_top_color ),
				),
			);
			$parse_css                           .= zakra_parse_css( '#e9ecef', $footer_widgets_border_top_color, $footer_widgets_border_top_color_css );

			/**
			 * Footer widgets border bottom width.
			 */
			$footer_widgets_item_border_bottom_width_default = array(
				'size' => '',
				'unit' => 'px',
			);

			$footer_widgets_item_border_bottom_width = get_theme_mod( 'zakra_footer_widgets_item_border_bottom_width', $footer_widgets_item_border_bottom_width_default );

			$parse_css .= zakra_parse_slider_css(
				$footer_widgets_item_border_bottom_width_default,
				$footer_widgets_item_border_bottom_width,
				'.zak-footer-cols ul li',
				'border-bottom-width'
			);

			// Footer widgets item border bottom color.
			$footer_widgets_item_border_bottom__color     = get_theme_mod( 'zakra_footer_widgets_item_border_bottom_color', '#e9ecef' );
			$footer_widgets_item_border_bottom__color_css = array(
				'.zak-footer-cols ul li' => array(
					'border-bottom-color' => esc_html( $footer_widgets_item_border_bottom__color ),
				),
			);
			$parse_css                                    .= zakra_parse_css( '#e9ecef', $footer_widgets_item_border_bottom__color, $footer_widgets_item_border_bottom__color_css );

			// Footer bottom bar background.
			$footer_bar_background_defaults = array(
				'background-color'      => '#18181B',
				'background-image'      => '',
				'background-repeat'     => 'repeat',
				'background-position'   => 'center center',
				'background-size'       => 'contain',
				'background-attachment' => 'scroll',
			);
			$footer_bar                     = get_theme_mod( 'zakra_footer_bar_background', $footer_bar_background_defaults );
			$parse_css                      .= zakra_parse_background_css( $footer_bar_background_defaults, $footer_bar, '.zak-footer-bar' );

			// Footer bottom bar text color.
			$footer_bar_text_color     = get_theme_mod( 'zakra_footer_bar_text_color', '#fafafa' );
			$footer_bar_text_color_css = array(
				'.zak-footer-bar' => array(
					'color' => esc_html( $footer_bar_text_color ),
				),
			);
			$parse_css                 .= zakra_parse_css( '#51585f', $footer_bar_text_color, $footer_bar_text_color_css );

			// Footer bottom bar link color.
			$footer_bar_link_color     = get_theme_mod( 'zakra_footer_bar_link_color', '#16181a' );
			$footer_bar_link_color_css = array(
				'.zak-footer-bar a' => array(
					'color' => esc_html( $footer_bar_link_color ),
				),
			);
			$parse_css                 .= zakra_parse_css( '#16181a', $footer_bar_link_color, $footer_bar_link_color_css );

			// Footer bottom bar link hover color.
			$footer_bar_link_hover_color     = get_theme_mod( 'zakra_footer_bar_link_hover_color', '#027abb' );
			$footer_bar_link_hover_color_css = array(
				'.zak-footer-bar a:hover, .zak-footer-bar a:focus' => array(
					'color' => esc_html( $footer_bar_link_hover_color ),
				),
			);
			$parse_css                       .= zakra_parse_css( '#027abb', $footer_bar_link_hover_color, $footer_bar_link_hover_color_css );

			/**
			 * Footer bar border top width.
			 */
			$footer_bar_border_top_width_default = array(
				'size' => 1,
				'unit' => 'px',
			);

			$footer_bar_border_top_width = get_theme_mod( 'zakra_footer_bar_border_top_width', $footer_bar_border_top_width_default );

			$parse_css .= zakra_parse_slider_css(
				$footer_bar_border_top_width_default,
				$footer_bar_border_top_width,
				'.zak-footer-bar',
				'border-top-width'
			);

			// Footer bar border top color.
			$footer_bar_border_top_color     = get_theme_mod( 'zakra_footer_bar_border_top_color', '#3f3f46' );
			$footer_bar_border_top_color_css = array(
				'.zak-footer-bar' => array(
					'border-top-color' => esc_html( $footer_bar_border_top_color ),
				),
			);
			$parse_css                       .= zakra_parse_css( '#3f3f46', $footer_bar_border_top_color, $footer_bar_border_top_color_css );

			$scroll_to_top_normal_background_color     = get_theme_mod( 'zakra_scroll_to_top_background', '#16181a' );
			$scroll_to_top_normal_background_color_css = array(
				'.zak-scroll-to-top' => array(
					'background-color' => esc_html( $scroll_to_top_normal_background_color ),
				),
			);
			$parse_css                                 .= zakra_parse_css( '#16181a', $scroll_to_top_normal_background_color, $scroll_to_top_normal_background_color_css );

			$scroll_to_top_hover_background_color     = get_theme_mod( 'zakra_scroll_to_top_hover_background', '#1e7ba6' );
			$scroll_to_top_hover_background_color_css = array(
				'.zak-scroll-to-top:hover' => array(
					'background-color' => esc_html( $scroll_to_top_hover_background_color ),
				),
			);
			$parse_css                                .= zakra_parse_css( '#1e7ba6', $scroll_to_top_hover_background_color, $scroll_to_top_hover_background_color_css );

			$scroll_to_top_normal_color     = get_theme_mod( 'zakra_scroll_to_top_icon_color', '#ffffff' );
			$scroll_to_top_normal_color_css = array(
				'.zak-scroll-to-top' => array(
					'color' => esc_html( $scroll_to_top_normal_color ),
				),
				'.zak-scroll-to-top .zak-icon'                                                                                                => array(
					'fill' => esc_html( $scroll_to_top_normal_color ),
				),
			);
			$parse_css            .= zakra_parse_css( '#ffffff', $scroll_to_top_normal_color, $scroll_to_top_normal_color_css );

			$scroll_to_top_hover_color     = get_theme_mod( 'zakra_scroll_to_top_icon_hover_color', '#ffffff' );
			$scroll_to_top_hover_color_css = array(
				'.zak-scroll-to-top:hover' => array(
					'color' => esc_html( $scroll_to_top_hover_color ),
				),
				'.zak-scroll-to-top:hover .zak-icon' => array(
					'fill' => esc_html( $scroll_to_top_hover_color ),
				),
			);
			$parse_css            .= zakra_parse_css( '#ffffff', $scroll_to_top_hover_color, $scroll_to_top_hover_color_css );

			// Blog post title color.
			$blog_post_title_color     = get_theme_mod( 'zakra_blog_post_title_color', '' );
			$blog_post_title_color_css = array(
				'.entry-title a' => array(
					'color' => esc_html( $blog_post_title_color ),
				),
			);
			$parse_css                .= zakra_parse_css( '', $blog_post_title_color, $blog_post_title_color_css );

			// Blog post title hover color.
			$blog_post_title_hover_color     = get_theme_mod( 'zakra_blog_post_title_hover_color', '' );
			$blog_post_title_hover_color_css = array(
				'.entry-title a:hover' => array(
					'color' => esc_html( $blog_post_title_hover_color ),
				),
			);
			$parse_css                      .= zakra_parse_css( '', $blog_post_title_hover_color, $blog_post_title_hover_color_css );

			$parse_css .= $dynamic_css;

			return apply_filters( 'zakra_theme_dynamic_css', $parse_css );
		}

		/**
		 * Return dynamic CSS output.
		 *
		 * @param string $dynamic_css Dynamic CSS.
		 * @param string $dynamic_css_filtered Dynamic CSS Filters.
		 *
		 * @return string Generated CSS.
		 */
		public static function render_wc_output( $dynamic_css, $dynamic_css_filtered = '' ) {

			$parse_wc_css = '';

			$base_wc_primary_color     = get_theme_mod( 'zakra_primary_color', '#027abb' );
			$base_wc_primary_color_css = array(
				'.woocommerce-info::before,
				.woocommerce ul.products li.product .woocommerce-loop-product__title:hover,
				.wc-block-grid__product .wc-block-grid__product-title:hover,
				.woocommerce nav.woocommerce-pagination ul li a,.woocommerce nav.woocommerce-pagination ul li span,
				.woocommerce div.product p.price,.woocommerce div.product span.price,
				.woocommerce div.product .woocommerce-tabs ul.tabs li.active a,
				.woocommerce .widget_price_filter .price_slider_amount .button,
				.single-product .product .product_meta > span a'                                                                                                                                                                                                                                                                                                                                                                                                            => array(
					'color' => esc_html( $base_wc_primary_color ),
				),
				'.wc-block-grid__product-onsale,
				.woocommerce ul.products a.button,
				.wp-block-button .wp-block-button__link,
				.woocommerce a.button.alt,
				.woocommerce button.button,
				.woocommerce button.button.alt,
				.woocommerce nav.woocommerce-pagination ul li span.current,
				.woocommerce nav.woocommerce-pagination ul li a:hover,
				.woocommerce nav.woocommerce-pagination ul li a:focus,
				.woocommerce div.product form.cart .button,
				.woocommerce div.product .woocommerce-tabs #respond input#submit,
				.woocommerce .widget_price_filter .ui-slider-horizontal .ui-slider-range,
				.woocommerce .widget_price_filter .price_slider_amount .button:hover,
				.wc-block-grid__products .wc-block-grid__product .zakra-onsale-normal-wrapper span' => array(
					'background-color' => esc_html( $base_wc_primary_color ),
				),
				'.woocommerce nav.woocommerce-pagination ul li, .woocommerce div.product .woocommerce-tabs ul.tabs li.active, .woocommerce .widget_price_filter .ui-slider .ui-slider-handle, .woocommerce .widget_price_filter .price_slider_amount .button, .woocommerce-info'                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             => array(
					'border-color' => esc_html( $base_wc_primary_color ),
				),
				'.wp-block-woocommerce-cart .wp-block-woocommerce-cart-order-summary-block .wc-block-components-totals-coupon__content button.wc-block-components-totals-coupon__button,
				.wc-block-checkout button.wc-block-components-totals-coupon__button,
				.woocommerce .woocommerce-pagination .page-numbers li > a, .woocommerce .woocommerce-pagination .page-numbers li > span' => array(
					'color'        => esc_html( $base_wc_primary_color ),
					'border-color' => esc_html( $base_wc_primary_color ),
				),
				'.wc-block-checkout .wc-block-checkout__actions_row button.wc-block-components-checkout-place-order-button, .wc-block-checkout .wc-block-checkout__actions_row button.wc-block-components-checkout-place-order-button:hover,
				.wc-block-checkout .wp-block-woocommerce-checkout-actions-block .wc-block-checkout__actions_row .wc-block-components-checkout-return-to-cart-button:hover, .wc-block-checkout .wp-block-woocommerce-checkout-order-summary-block .wc-block-components-order-summary-item__image .wc-block-components-order-summary-item__quantity,
				.wc-block-components-drawer__content .wc-block-mini-cart__footer .wc-block-mini-cart__footer-actions .wp-element-button.wc-block-mini-cart__footer-checkout,
				.wc-block-components-drawer__content .wc-block-mini-cart__footer .wc-block-mini-cart__footer-actions .wp-element-button.wc-block-mini-cart__footer-cart:hover,
				.wc-block-grid__products .wc-block-grid__product .wp-block-button .wp-block-button__link:hover,
				.woocommerce .woocommerce-pagination .page-numbers .current' => array(
					'background-color' => esc_html( $base_wc_primary_color ),
				),
			);
			$parse_wc_css              .= zakra_parse_css( '#027abb', $base_wc_primary_color, $base_wc_primary_color_css );

			$base_wc_text_color     = get_theme_mod( 'zakra_base_color', '#3F3F46' );
			$base_wc_text_color_css = array(
				'.woocommerce ul.products li.product .price, .woocommerce .star-rating span, ul li.product .price, .wc-block-components-formatted-money-amount, .wc-block-grid__products .wc-block-grid__product .wc-block-grid__product-price' => array(
					'color' => esc_html( $base_wc_text_color ),
				),
			);
			$parse_wc_css           .= zakra_parse_css( '#3F3F46', $base_wc_text_color, $base_wc_text_color_css );

			$button_wc_text_color     = get_theme_mod( 'zakra_button_color', '' );
			$button_wc_text_color_css = array(
				'.woocommerce a.button, .woocommerce a.button.alt, .woocommerce button.button, .woocommerce button.button.alt, .woocommerce ul.products a.button, .woocommerce div.product form.cart .button, .wp-block-button .wp-block-button__link, .woocommerce button.button:disabled[disabled], .tg-sticky-panel .tg-checkout-btn a' => array(
					'color' => esc_html( $button_wc_text_color ),
				),
			);
			$parse_wc_css             .= zakra_parse_css( '', $button_wc_text_color, $button_wc_text_color_css );

			$button_wc_hover_text_color     = get_theme_mod( 'zakra_button_hover_color', '' );
			$button_wc_hover_text_color_css = array(
				'.woocommerce a.button:hover, .woocommerce a.button.alt:hover, .woocommerce button.button:hover, .woocommerce button.button.alt:hover, .woocommerce ul.products a.button:hover, .woocommerce div.product form.cart .button:hover, .tg-sticky-panel .tg-checkout-btn a:hover' => array(
					'color' => esc_html( $button_wc_hover_text_color ),
				),
			);
			$parse_wc_css                   .= zakra_parse_css( '', $button_wc_hover_text_color, $button_wc_hover_text_color_css );

			$button_wc_bg_color     = get_theme_mod( 'zakra_button_background_color', '#027abb' );
			$button_wc_bg_color_css = array(
				'.woocommerce a.button.alt, .woocommerce button.button, .woocommerce button.button.alt, .woocommerce ul.products a.button, .woocommerce div.product form.cart .button, .wp-block-button .wp-block-button__link, .tg-sticky-panel .tg-checkout-btn a' => array(
					'background-color' => esc_html( $button_wc_bg_color ),
				),
			);
			$parse_wc_css           .= zakra_parse_css( '#027abb', $button_wc_bg_color, $button_wc_bg_color_css );

			$button_wc_bg_hover_color     = get_theme_mod( 'zakra_button_background_hover_color', '#027ABB' );
			$button_wc_bg_hover_color_css = array(
				'.woocommerce a.button.alt:hover, .woocommerce button.button:hover, .woocommerce button.button.alt:hover, .woocommerce ul.products a.button:hover, .woocommerce div.product form.cart .button:hover, .product .wc-block-grid__product-add-to-cart .wp-block-button__link:hover, .tg-sticky-panel .tg-checkout-btn a:hover' => array(
					'background-color' => esc_html( $button_wc_bg_hover_color ),
				),
			);
			$parse_wc_css                 .= zakra_parse_css( '#027ABB', $button_wc_bg_hover_color, $button_wc_bg_hover_color_css );

			/**
			 * Button border radius for WooCommerce button.
			 */
			$button_wc_border_radius_default = array(
				'size' => 0,
				'unit' => 'px',
			);

			$button_wc_border_radius = get_theme_mod( 'zakra_button_roundness', $button_wc_border_radius_default );

			$parse_wc_css .= zakra_parse_slider_css(
				$button_wc_border_radius_default,
				$button_wc_border_radius,
				'.woocommerce a.button, .woocommerce a.button.alt, .woocommerce button.button, .woocommerce button.button.alt, .woocommerce ul.products a.button, .woocommerce div.product form.cart .button, .wp-block-button .wp-block-button__link, .tg-sticky-panel .tg-checkout-btn a',
				'border-radius'
			);

			// Button padding.
			$button_wc_padding_default = array(
				'top'    => '10',
				'right'  => '15',
				'bottom' => '10',
				'left'   => '15',
				'unit'   => 'px',
			);

			$button_wc_padding = get_theme_mod( 'zakra_button_padding', $button_wc_padding_default );

			$parse_wc_css .= zakra_parse_dimension_css(
				$button_wc_padding_default,
				$button_wc_padding,
				'.woocommerce a.button,.woocommerce-cart .actions > .button, .woocommerce a.button.alt, .woocommerce button.button, .woocommerce button.button.alt, .woocommerce ul.products a.button, .woocommerce div.product form.cart .button, .woocommerce ul.products li.product .button, .woocommerce button.button:disabled[disabled], .tg-sticky-panel .tg-checkout-btn a, .wc-block-grid__product .wc-block-grid__product-add-to-cart .wp-block-button__link',
				'padding'
			);

			$parse_wc_css .= $dynamic_css;

			return apply_filters( 'zakra_theme_wc_dynamic_css', $parse_wc_css );
		}

		/**
		 * Return dynamic CSS output.
		 *
		 * @param string $dynamic_css Dynamic CSS.
		 * @param string $dynamic_css_filtered Dynamic CSS Filters.
		 *
		 * @return string Generated CSS.
		 */
		public static function render_builder_output( $dynamic_css, $dynamic_css_filtered = '' ) {

			$parse_builder_css = '';

			/**
			 * Header builder top area height.
			 */
			$header_top_area_height_default = array(
				'size' => 0,
				'unit' => 'px',
			);

			$header_top_area_height = get_theme_mod( 'zakra_header_top_area_height', $header_top_area_height_default );

			$parse_builder_css .= zakra_parse_slider_css(
				$header_top_area_height_default,
				$header_top_area_height,
				'.zak-header-builder .zak-top-row',
				'height'
			);

			/**
			 * Header builder top area container.
			 */
			$header_top_area_container_default = array(
				'size' => 0,
				'unit' => 'px',
			);

			$header_top_area_container = get_theme_mod( 'zakra_header_top_area_container', $header_top_area_container_default );

			$parse_builder_css .= zakra_parse_slider_css(
				$header_top_area_container_default,
				$header_top_area_container,
				'.zak-header-builder .zak-header-top-row .zak-container',
				'max-width'
			);

			// Header builder top area background.
			$header_top_area_background_default = array(
				'background-color'      => '',
				'background-image'      => '',
				'background-repeat'     => 'repeat',
				'background-position'   => 'center center',
				'background-size'       => 'contain',
				'background-attachment' => 'scroll',
			);
			$header_top_area_background         = get_theme_mod( 'zakra_header_top_area_background', $header_top_area_background_default );
			$parse_builder_css                           .= zakra_parse_background_css( $header_top_area_background_default, $header_top_area_background, '.zak-header-builder .zak-header-top-row' );

			// Header builder top area padding.
			$header_top_area_padding_default = array(
				'top'    => '14',
				'right'  => '0',
				'bottom' => '14',
				'left'   => '0',
				'unit'   => 'px',
			);

			$header_top_area_padding = get_theme_mod( 'zakra_header_top_area_padding', $header_top_area_padding_default );

			$parse_builder_css .= zakra_parse_dimension_css(
				$header_top_area_padding_default,
				$header_top_area_padding,
				'.zak-header-builder .zak-header-top-row',
				'padding'
			);

			// Header builder top area border width.
			$header_top_area_border_width_default = array(
				'top'    => '0',
				'right'  => '0',
				'bottom' => '0',
				'left'   => '0',
				'unit'   => 'px',
			);

			$header_top_area_border_width = get_theme_mod( 'zakra_header_top_area_border_width', $header_top_area_border_width_default );

			$parse_builder_css .= zakra_parse_dimension_css(
				$header_top_area_border_width_default,
				$header_top_area_border_width,
				'.zak-header-builder .zak-header-top-row',
				'border-width'
			);

			// Header builder top area border color.
			$header_top_area_border_color     = get_theme_mod( 'zakra_header_top_area_border_color', '#FAFAFA' );
			$header_top_area_border_color_css = array(
				'.zak-header-builder .zak-header-top-row' => array(
					'border-color' => esc_html( $header_top_area_border_color ),
				),
			);
			$parse_builder_css                   .= zakra_parse_css( '#FAFAFA', $header_top_area_border_color, $header_top_area_border_color_css );

			// Header builder top area color.
			$header_top_area_color     = get_theme_mod( 'zakra_header_top_area_color', '' );
			$header_top_area_color_css = array(
				'.zak-header-builder .zak-header-top-row' => array(
					'color' => esc_html( $header_top_area_color ),
				),
			);
			$parse_builder_css                   .= zakra_parse_css( '', $header_top_area_color, $header_top_area_color_css );

			// Header builder top area height.
			$header_main_area_height_default = array(
				'size' => 0,
				'unit' => 'px',
			);

			$header_main_area_height = get_theme_mod( 'zakra_header_main_area_height', $header_main_area_height_default );

			$parse_builder_css .= zakra_parse_slider_css(
				$header_main_area_height_default,
				$header_main_area_height,
				'.zak-header-builder .zak-main-row',
				'height'
			);

			// Header builder main area container.
			$header_main_area_container_default = array(
				'size' => 0,
				'unit' => 'px',
			);

			$header_main_area_container = get_theme_mod( 'zakra_header_main_area_container', $header_main_area_container_default );

			$parse_builder_css .= zakra_parse_slider_css(
				$header_main_area_container_default,
				$header_main_area_container,
				'.zak-header-builder .zak-header-main-row .zak-container',
				'max-width'
			);

			// Header builder main area background.
			$header_main_area_background_default = array(
				'background-color'      => '#FAFAFA',
				'background-image'      => '',
				'background-repeat'     => 'repeat',
				'background-position'   => 'center center',
				'background-size'       => 'contain',
				'background-attachment' => 'scroll',
			);
			$header_main_area_background         = get_theme_mod( 'zakra_header_main_area_background', $header_main_area_background_default );
			$parse_builder_css                           .= zakra_parse_background_css( $header_main_area_background_default, $header_main_area_background, '.zak-header-builder .zak-header-main-row' );

			// Header builder main area padding.
			$header_main_area_padding_default = array(
				'top'    => '20',
				'right'  => '20',
				'bottom' => '20',
				'left'   => '20',
				'unit'   => 'px',
			);

			$header_main_area_padding = get_theme_mod( 'zakra_header_main_area_padding', $header_main_area_padding_default );

			$parse_builder_css .= zakra_parse_dimension_css(
				$header_main_area_padding_default,
				$header_main_area_padding,
				'.zak-header-builder .zak-header-main-row',
				'padding'
			);

			// Header builder main area padding.
			$header_main_area_margin_default = array(
				'top'    => '',
				'right'  => '',
				'bottom' => '',
				'left'   => '',
				'unit'   => 'px',
			);

			$header_main_area_margin = get_theme_mod( 'zakra_header_main_area_margin', $header_main_area_margin_default );

			$parse_builder_css .= zakra_parse_dimension_css(
				$header_main_area_margin_default,
				$header_main_area_margin,
				'.zak-header-builder .zak-header-main-row',
				'margin'
			);

			// Header builder main area border.
			$header_main_area_border_width_default = array(
				'top'    => '0',
				'right'  => '0',
				'bottom' => '1',
				'left'   => '0',
				'unit'   => 'px',
			);

			$header_main_area_border_width = get_theme_mod( 'zakra_header_main_area_border_width', $header_main_area_border_width_default );

			$parse_builder_css .= zakra_parse_dimension_css(
				$header_main_area_border_width_default,
				$header_main_area_border_width,
				'.zak-header-builder .zak-header-main-row',
				'border-width'
			);

			// Header builder main area border color.
			$header_main_area_border_color     = get_theme_mod( 'zakra_header_main_area_border_color', '#E4E4E7' );
			$header_main_area_border_color_css = array(
				'.zak-header-builder .zak-header-main-row' => array(
					'border-color' => esc_html( $header_main_area_border_color ),
				),
			);
			$parse_builder_css                   .= zakra_parse_css( '#E4E4E7', $header_main_area_border_color, $header_main_area_border_color_css );

			$header_main_area_color     = get_theme_mod( 'zakra_header_main_area_color', '' );
			$header_main_area_color_css = array(
				'.zak-header-builder .zak-header-main-row' => array(
					'color' => esc_html( $header_main_area_color ),
				),
			);
			$parse_builder_css                   .= zakra_parse_css( '', $header_main_area_color, $header_main_area_color_css );

			// Header builder bottom area height.
			$header_bottom_area_height_default = array(
				'size' => 0,
				'unit' => 'px',
			);

			$header_bottom_area_height = get_theme_mod( 'zakra_header_bottom_area_height', $header_bottom_area_height_default );

			$parse_builder_css .= zakra_parse_slider_css(
				$header_bottom_area_height_default,
				$header_bottom_area_height,
				'.zak-header-builder .zak-bottom-row',
				'height'
			);

			// Header builder bottom area container.
			$header_bottom_area_container_default = array(
				'size' => 0,
				'unit' => 'px',
			);

			$header_bottom_area_container = get_theme_mod( 'zakra_header_bottom_area_container', $header_bottom_area_container_default );

			$parse_builder_css .= zakra_parse_slider_css(
				$header_bottom_area_container_default,
				$header_bottom_area_container,
				'.zak-header-builder .zak-header-bottom-row .zak-container',
				'max-width'
			);

			// Header builder bottom area background.
			$header_bottom_area_background_default = array(
				'background-color'      => '',
				'background-image'      => '',
				'background-repeat'     => 'repeat',
				'background-position'   => 'center center',
				'background-size'       => 'contain',
				'background-attachment' => 'scroll',
			);
			$header_bottom_area_background         = get_theme_mod( 'zakra_header_bottom_area_background', $header_bottom_area_background_default );
			$parse_builder_css                           .= zakra_parse_background_css( $header_bottom_area_background_default, $header_bottom_area_background, '.zak-header-builder .zak-header-bottom-row' );

			// Header builder bottom area padding.
			$header_bottom_area_padding_default = array(
				'top'    => '',
				'right'  => '',
				'bottom' => '',
				'left'   => '',
				'unit'   => 'px',
			);

			$header_bottom_area_padding = get_theme_mod( 'zakra_header_bottom_area_padding', $header_bottom_area_padding_default );

			$parse_builder_css .= zakra_parse_dimension_css(
				$header_bottom_area_padding_default,
				$header_bottom_area_padding,
				'.zak-header-builder .zak-header-bottom-row',
				'padding'
			);

			// Header builder bottom area padding.
			$header_bottom_area_margin_default = array(
				'top'    => '',
				'right'  => '',
				'bottom' => '',
				'left'   => '',
				'unit'   => 'px',
			);

			$header_bottom_area_margin = get_theme_mod( 'zakra_header_bottom_area_margin', $header_bottom_area_margin_default );

			$parse_builder_css .= zakra_parse_dimension_css(
				$header_bottom_area_margin_default,
				$header_bottom_area_margin,
				'.zak-header-builder .zak-header-bottom-row',
				'margin'
			);

			// Header builder bottom border width.
			$header_bottom_area_border_width_default = array(
				'top'    => '0',
				'right'  => '0',
				'bottom' => '0',
				'left'   => '0',
				'unit'   => 'px',
			);

			$header_bottom_area_border_width = get_theme_mod( 'zakra_header_bottom_area_border_width', $header_bottom_area_border_width_default );

			$parse_builder_css .= zakra_parse_dimension_css(
				$header_bottom_area_border_width_default,
				$header_bottom_area_border_width,
				'.zak-header-builder .zak-header-bottom-row',
				'border-width'
			);

			// Header builder bottom border color.
			$header_bottom_area_border_color     = get_theme_mod( 'zakra_header_bottom_area_border_color', '' );
			$header_bottom_area_border_color_css = array(
				'.zak-header-builder .zak-header-bottom-row' => array(
					'border-color' => esc_html( $header_bottom_area_border_color ),
				),
			);
			$parse_builder_css                   .= zakra_parse_css( '', $header_bottom_area_border_color, $header_bottom_area_border_color_css );

			// Header builder bottom area color.
			$header_bottom_area_color     = get_theme_mod( 'zakra_header_bottom_area_color', '' );
			$header_bottom_area_color_css = array(
				'.zak-header-builder .zak-header-bottom-row' => array(
					'color' => esc_html( $header_bottom_area_color ),
				),
			);
			$parse_builder_css                   .= zakra_parse_css( '', $header_bottom_area_color, $header_bottom_area_color_css );

			// Header builder menu border.
			$header_menu_border_bottom_default = array(
				'size' => 0,
				'unit' => 'px',
			);

			$header_menu_border_bottom = get_theme_mod( 'zakra_header_menu_border_bottom_width', $header_menu_border_bottom_default );

			$parse_builder_css .= zakra_parse_slider_css(
				$header_menu_border_bottom_default,
				$header_menu_border_bottom,
				'.zak-header-builder .zak-main-nav',
				'border-bottom-width'
			);

			// Header builder secondary menu border.
			$header_secondary_menu_border_bottom_default = array(
				'size' => 0,
				'unit' => 'px',
			);

			$header_secondary_menu_border_bottom = get_theme_mod( 'zakra_header_secondary_menu_border_bottom_width', $header_secondary_menu_border_bottom_default );

			$parse_builder_css .= zakra_parse_slider_css(
				$header_secondary_menu_border_bottom_default,
				$header_secondary_menu_border_bottom,
				'.zak-header-builder .zak-secondary-nav',
				'border-bottom-width'
			);

			// Header builder tertiary menu border bottom.
			$header_tertiary_menu_border_bottom_default = array(
				'size' => 0,
				'unit' => 'px',
			);
			$header_tertiary_menu_border_bottom = get_theme_mod( 'zakra_header_tertiary_menu_border_bottom_width', $header_tertiary_menu_border_bottom_default );
			$parse_builder_css .= zakra_parse_slider_css(
				$header_tertiary_menu_border_bottom_default,
				$header_tertiary_menu_border_bottom,
				'.zak-header-builder .zak-tertiary-nav',
				'border-bottom-width'
			);

			// Header builder primary menu border bottom.
			$header_menu_border_bottom_color     = get_theme_mod( 'zakra_header_menu_border_bottom_color', '#e9ecef' );
			$header_menu_border_bottom_color_css = array(
				'.zak-header-builder .zak-main-nav' => array(
					'border-bottom-color' => esc_html( $header_menu_border_bottom_color ),
				),
			);
			$parse_builder_css                            .= zakra_parse_css( '#e9ecef', $header_menu_border_bottom_color, $header_menu_border_bottom_color_css );

			// Header builder secondary menu border bottom.
			$header_secondary_menu_border_bottom_color     = get_theme_mod( 'zakra_header_secondary_menu_border_bottom_color', '#e9ecef' );
			$header_secondary_menu_border_bottom_color_css = array(
				'.zak-header-builder .zak-secondary-nav' => array(
					'border-bottom-color' => esc_html( $header_secondary_menu_border_bottom_color ),
				),
			);
			$parse_builder_css                            .= zakra_parse_css( '#e9ecef', $header_secondary_menu_border_bottom_color, $header_secondary_menu_border_bottom_color_css );

			// Header builder tertiary menu border bottom.
			$header_tertiary_menu_border_bottom_color     = get_theme_mod( 'zakra_header_tertiary_menu_border_bottom_color', '#e9ecef' );
			$header_tertiary_menu_border_bottom_color_css = array(
				'.zak-header-builder .zak-tertiary-menu' => array(
					'border-bottom-color' => esc_html( $header_tertiary_menu_border_bottom_color ),
				),
			);
			$parse_builder_css                            .= zakra_parse_css( '#e9ecef', $header_tertiary_menu_border_bottom_color, $header_tertiary_menu_border_bottom_color_css );


			// Header builder primary menu item color.
			$header_menu_item_color_normal     = get_theme_mod( 'zakra_header_main_menu_color', '' );
			$header_menu_item_color_normal_css = array(
				'.zak-header-builder .zak-primary-nav ul li > a, .zak-header-builder .zak-main-nav.zak-primary-nav ul.zak-primary-menu > li > a, .zak-header-builder .zak-primary-nav.zak-menu-item--layout-2 > ul > li > a'                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          => array(
					'color' => esc_html( $header_menu_item_color_normal ),
				),
				'.zak-header-builder .zak-primary-nav ul li > a .zak-icon, .zak-header-builder .zak-main-nav.zak-primary-nav ul.zak-primary-menu li .zak-icon, .zak-header-builder .zak-primary-nav.zak-menu-item--layout-2 > ul > li > .zak-icon' => array(
					'fill' => esc_html( $header_menu_item_color_normal ),
				),
			);
			$parse_builder_css                          .= zakra_parse_css( '', $header_menu_item_color_normal, $header_menu_item_color_normal_css );

			// Header builder secondary menu item color.
			$header_secondary_menu_item_color_normal     = get_theme_mod( 'zakra_header_secondary_menu_color', '' );
			$header_secondary_menu_item_color_normal_css = array(
				'.zak-header-builder .zak-secondary-nav > ul > li > a, .zak-header-builder .zak-main-nav.zak-secondary-nav ul.zak-secondary-menu > li > a, .zak-header-builder .zak-secondary-nav.zak-menu-item--layout-2 > ul > li > a'                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          => array(
					'color' => esc_html( $header_secondary_menu_item_color_normal ),
				),
				'.zak-header-builder .zak-secondary-nav > ul > li > a .zak-icon, .zak-header-builder .zak-main-nav.zak-secondary-nav ul.zak-secondary-menu li .zak-icon, .zak-header-builder .zak-secondary-nav.zak-menu-item--layout-2 > ul > li > .zak-icon' => array(
					'fill' => esc_html( $header_secondary_menu_item_color_normal ),
				),
			);
			$parse_builder_css                          .= zakra_parse_css( '', $header_secondary_menu_item_color_normal, $header_secondary_menu_item_color_normal_css );

			// Header builder tertiary menu item color.
			$header_tertiary_menu_item_color_normal     = get_theme_mod( 'zakra_header_tertiary_menu_color', '' );
			$header_tertiary_menu_item_color_normal_css = array(
				'.zak-header-builder .zak-tertiary-nav ul li > a, .zak-header-builder .zak-main-nav.zak-tertiary-nav ul.zak-tertiary-menu > li > a, .zak-header-builder .zak-tertiary-nav.zak-menu-item--layout-2 > ul > li > a'                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          => array(
					'color' => esc_html( $header_tertiary_menu_item_color_normal ),
				),
				'.zak-header-builder .zak-tertiary-nav ul li > a .zak-icon, .zak-header-builder .zak-main-nav.zak-tertiary-nav ul.zak-tertiary-menu li .zak-icon, .zak-header-builder .zak-tertiary-nav.zak-menu-item--layout-2 > ul > li > .zak-icon' => array(
					'fill' => esc_html( $header_tertiary_menu_item_color_normal ),
				),
			);
			$parse_builder_css                          .= zakra_parse_css( '', $header_tertiary_menu_item_color_normal, $header_tertiary_menu_item_color_normal_css );

			// Header builder quaternary menu item color.
			$header_quaternary_menu_item_color_normal     = get_theme_mod( 'zakra_header_quaternary_menu_color', '' );
			$header_quaternary_menu_item_color_normal_css = array(
				'.zak-header-builder .zak-quaternary-nav ul li > a, .zak-header-builder .zak-main-nav.zak-quaternary-nav ul.zak-quaternary-menu > li > a, .zak-header-builder .zak-quaternary-nav.zak-menu-item--layout-2 > ul > li > a'                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          => array(
					'color' => esc_html( $header_quaternary_menu_item_color_normal ),
				),
				'.zak-header-builder .zak-quaternary-nav ul li > a .zak-icon, .zak-header-builder .zak-main-nav.zak-quaternary-nav ul.zak-quaternary-menu li .zak-icon, .zak-header-builder .zak-quaternary-nav.zak-menu-item--layout-2 > ul > li > .zak-icon' => array(
					'fill' => esc_html( $header_quaternary_menu_item_color_normal ),
				),
			);
			$parse_builder_css                          .= zakra_parse_css( '', $header_quaternary_menu_item_color_normal, $header_quaternary_menu_item_color_normal_css );

			// Header builder primary menu item hover color.
			$header_menu_item_color_hover     = get_theme_mod( 'zakra_header_main_menu_hover_color', '' );
			$header_menu_item_color_hover_css = array(
				'.zak-header-builder .zak-primary-nav ul li:hover > a, .zak-header-builder .zak-primary-nav.zak-menu-item--layout-2 > ul > li:hover > a, .zak-header-builder .zak-primary-nav ul li:hover > a, .zak-header-builder .zak-main-nav.zak-primary-nav ul.zak-primary-menu li:hover > a'                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          => array(
					'color' => esc_html( $header_menu_item_color_hover ),
				),
				'.zak-header-builder .zak-primary-nav ul li:hover > a .zak-icon, .zak-header-builder .zak-main-nav.zak-primary-nav ul.zak-primary-menu li:hover .zak-icon, .zak-header-builder .zak-primary-nav.zak-menu-item--layout-2 > ul > li:hover > .zak-icon' => array(
					'fill' => esc_html( $header_menu_item_color_hover ),
				),
			);
			$parse_builder_css                         .= zakra_parse_css( '', $header_menu_item_color_hover, $header_menu_item_color_hover_css );

			// Header builder secondary menu item hover color.
			$header_secondary_menu_item_color_hover     = get_theme_mod( 'zakra_header_secondary_menu_hover_color', '' );
			$header_secondary_menu_item_color_hover_css = array(
				'.zak-header-builder .zak-secondary-nav ul li:hover > a, .zak-header-builder .zak-secondary-nav.zak-menu-item--layout-2 > ul > li:hover > a, .zak-header-builder .zak-secondary-nav ul li:hover > a, .zak-header-builder .zak-main-nav.zak-secondary-nav ul.zak-secondary-menu li:hover > a'                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          => array(
					'color' => esc_html( $header_secondary_menu_item_color_hover ),
				),
				'.zak-header-builder .zak-secondary-nav ul li:hover > a .zak-icon, .zak-header-builder .zak-main-nav.zak-secondary-nav ul.zak-secondary-menu li:hover .zak-icon, .zak-header-builder .zak-secondary-nav.zak-menu-item--layout-2 > ul > li:hover > .zak-icon' => array(
					'fill' => esc_html( $header_secondary_menu_item_color_hover ),
				),
			);
			$parse_builder_css                         .= zakra_parse_css( '', $header_secondary_menu_item_color_hover, $header_secondary_menu_item_color_hover_css );

			// Header builder tertiary menu item hover color.
			$header_tertiary_menu_item_color_hover     = get_theme_mod( 'zakra_header_tertiary_menu_hover_color', '' );
			$header_tertiary_menu_item_color_hover_css = array(
				'.zak-header-builder .zak-tertiary-nav ul li:hover > a, .zak-header-builder .zak-tertiary-nav.zak-menu-item--layout-2 > ul > li:hover > a, .zak-header-builder .zak-tertiary-nav ul li:hover > a, .zak-header-builder .zak-main-nav.zak-tertiary-nav ul.zak-tertiary-menu li:hover > a'                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          => array(
					'color' => esc_html( $header_tertiary_menu_item_color_hover ),
				),
				'.zak-header-builder .zak-tertiary-nav ul li:hover > a .zak-icon, .zak-header-builder .zak-main-nav.zak-tertiary-nav ul.zak-tertiary-menu li:hover .zak-icon, .zak-header-builder .zak-tertiary-nav.zak-menu-item--layout-2 > ul > li:hover > .zak-icon' => array(
					'fill' => esc_html( $header_tertiary_menu_item_color_hover ),
				),
			);
			$parse_builder_css                         .= zakra_parse_css( '', $header_tertiary_menu_item_color_hover, $header_tertiary_menu_item_color_hover_css );

			// Header builder quaternary menu item hover color.
			$header_quaternary_menu_item_color_hover     = get_theme_mod( 'zakra_header_quaternary_menu_hover_color', '' );
			$header_quaternary_menu_item_color_hover_css = array(
				'.zak-header-builder .zak-quaternary-nav ul li:hover > a, .zak-header-builder .zak-quaternary-nav.zak-menu-item--layout-2 > ul > li:hover > a, .zak-header-builder .zak-quaternary-nav ul li:hover > a, .zak-header-builder .zak-main-nav.zak-quaternary-nav ul.zak-quaternary-menu li:hover > a'                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          => array(
					'color' => esc_html( $header_quaternary_menu_item_color_hover ),
				),
				'.zak-header-builder .zak-quaternary-nav ul li:hover > a .zak-icon, .zak-header-builder .zak-main-nav.zak-quaternary-nav ul.zak-quaternary-menu li:hover .zak-icon, .zak-header-builder .zak-quaternary-nav.zak-menu-item--layout-2 > ul > li:hover > .zak-icon' => array(
					'fill' => esc_html( $header_quaternary_menu_item_color_hover ),
				),
			);
			$parse_builder_css                         .= zakra_parse_css( '', $header_quaternary_menu_item_color_hover, $header_quaternary_menu_item_color_hover_css );

			// Header builder primary menu item active color.
			$header_menu_item_color_active     = get_theme_mod( 'zakra_header_main_menu_active_color', '' );
			$header_menu_item_color_active_css = array(
				'.zak-header-builder .zak-primary-nav ul li:active > a, .zak-header-builder .zak-primary-nav ul > li:not(.zak-header-button).current_page_item > a, .zak-header-builder .zak-primary-nav ul > li:not(.zak-header-button).current_page_ancestor > a, .zak-header-builder .zak-primary-nav ul > li:not(.zak-header-button).current-menu-item > a, .zak-header-builder .zak-primary-nav ul > li:not(.zak-header-button).current-menu-ancestor > a'                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          => array(
					'color' => esc_html( $header_menu_item_color_active ),
				),
				'.zak-header-builder .zak-primary-nav ul li.current-menu-item > a .zak-icon, .zak-header-builder .zak-main-nav.zak-primary-nav ul.zak-primary-menu li.current-menu-item .zak-icon' => array(
					'fill' => esc_html( $header_menu_item_color_active ),
				),
			);
			$parse_builder_css                          .= zakra_parse_css( '', $header_menu_item_color_active, $header_menu_item_color_active_css );

			// Header builder secondary menu item active color.
			$header_secondary_menu_item_color_active     = get_theme_mod( 'zakra_header_secondary_menu_active_color', '' );
			$header_secondary_menu_item_color_active_css = array(
				'.zak-header-builder .zak-secondary-nav ul li:active > a, .zak-header-builder .zak-secondary-nav ul > li:not(.zak-header-button).current_page_item > a, .zak-header-builder .zak-secondary-nav ul > li:not(.zak-header-button).current_page_ancestor > a, .zak-header-builder .zak-secondary-nav ul > li:not(.zak-header-button).current-menu-item > a, .zak-header-builder .zak-secondary-nav ul > li:not(.zak-header-button).current-menu-ancestor > a'                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          => array(
					'color' => esc_html( $header_secondary_menu_item_color_active ),
				),
				'.zak-header-builder .zak-secondary-nav ul li.current-menu-item > a .zak-icon, .zak-header-builder .zak-main-nav.zak-secondary-nav ul.zak-secondary-menu li.current-menu-item .zak-icon' => array(
					'fill' => esc_html( $header_secondary_menu_item_color_active ),
				),
			);
			$parse_builder_css                          .= zakra_parse_css( '', $header_secondary_menu_item_color_active, $header_secondary_menu_item_color_active_css );

			// Header builder tertiary menu item active color.
			$header_tertiary_menu_item_color_active     = get_theme_mod( 'zakra_header_tertiary_menu_active_color', '' );
			$header_tertiary_menu_item_color_active_css = array(
				'.zak-header-builder .zak-tertiary-nav ul li:active > a, .zak-header-builder .zak-tertiary-nav ul > li:not(.zak-header-button).current_page_item > a, .zak-header-builder .zak-tertiary-nav ul > li:not(.zak-header-button).current_page_ancestor > a, .zak-header-builder .zak-tertiary-nav ul > li:not(.zak-header-button).current-menu-item > a, .zak-header-builder .zak-tertiary-nav ul > li:not(.zak-header-button).current-menu-ancestor > a'                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          => array(
					'color' => esc_html( $header_tertiary_menu_item_color_active ),
				),
				'.zak-header-builder .zak-tertiary-nav ul li.current-menu-item > a .zak-icon, .zak-header-builder .zak-main-nav.zak-tertiary-nav ul.zak-tertiary-menu li.current-menu-item .zak-icon' => array(
					'fill' => esc_html( $header_tertiary_menu_item_color_active ),
				),
			);
			$parse_builder_css                          .= zakra_parse_css( '', $header_tertiary_menu_item_color_active, $header_tertiary_menu_item_color_active_css );

			// Header builder quaternary menu item active color.
			$header_quaternary_menu_item_color_active     = get_theme_mod( 'zakra_header_quaternary_menu_active_color', '' );
			$header_quaternary_menu_item_color_active_css = array(
				'.zak-header-builder .zak-quaternary-nav ul li:active > a, .zak-header-builder .zak-quaternary-nav ul > li:not(.zak-header-button).current_page_item > a, .zak-header-builder .zak-quaternary-nav ul > li:not(.zak-header-button).current_page_ancestor > a, .zak-header-builder .zak-quaternary-nav ul > li:not(.zak-header-button).current-menu-item > a, .zak-header-builder .zak-quaternary-nav ul > li:not(.zak-header-button).current-menu-ancestor > a'                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          => array(
					'color' => esc_html( $header_quaternary_menu_item_color_active ),
				),
				'.zak-header-builder .zak-quaternary-nav ul li.current-menu-item > a .zak-icon, .zak-header-builder .zak-main-nav.zak-quaternary-nav ul.zak-quaternary-menu li.current-menu-item .zak-icon' => array(
					'fill' => esc_html( $header_quaternary_menu_item_color_active ),
				),
			);
			$parse_builder_css                          .= zakra_parse_css( '', $header_quaternary_menu_item_color_active, $header_quaternary_menu_item_color_active_css );

			// Header builder primary menu typography.
			$header_menu_typography_default = array(
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
			);

			$header_menu_typography = get_theme_mod( 'zakra_header_main_menu_typography', $header_menu_typography_default );

			$parse_builder_css .= zakra_parse_typography_css(
				$header_menu_typography_default,
				$header_menu_typography,
				'.zak-header-builder .zak-primary-nav ul li a',
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			// Header builder secondary menu typography.
			$header_secondary_menu_typography_default = array(
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
			);

			$header_secondary_menu_typography = get_theme_mod( 'zakra_header_secondary_menu_typography', $header_secondary_menu_typography_default );

			$parse_builder_css .= zakra_parse_typography_css(
				$header_secondary_menu_typography_default,
				$header_secondary_menu_typography,
				'.zak-header-builder .zak-secondary-nav ul li a',
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			// Header builder tertiary menu typography.
			$header_tertiary_menu_typography_default = array(
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
			);

			$header_tertiary_menu_typography = get_theme_mod( 'zakra_header_tertiary_menu_typography', $header_tertiary_menu_typography_default );

			$parse_builder_css .= zakra_parse_typography_css(
				$header_tertiary_menu_typography_default,
				$header_tertiary_menu_typography,
				'.zak-header-builder .zak-tertiary-nav ul li a',
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			// Header builder quaternary menu typography.
			$header_quaternary_menu_typography_default = array(
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
			);

			$header_quaternary_menu_typography = get_theme_mod( 'zakra_header_quaternary_menu_typography', $header_quaternary_menu_typography_default );

			$parse_builder_css .= zakra_parse_typography_css(
				$header_quaternary_menu_typography_default,
				$header_quaternary_menu_typography,
				'.zak-header-builder .zak-quaternary-nav ul li a',
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			// Header builder primary sub menu typography.
			$header_sub_menu_typography_default = array(
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
			);

			$header_sub_menu_typography = get_theme_mod( 'zakra_header_sub_menu_typography', $header_sub_menu_typography_default );

			$parse_builder_css .= zakra_parse_typography_css(
				$header_sub_menu_typography_default,
				$header_sub_menu_typography,
				'.zak-header-builder .zak-primary-nav ul li ul li a',
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			// Header builder secondary sub menu typography.
			$header_secondary_sub_menu_typography_default = array(
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
			);

			$header_secondary_sub_menu_typography = get_theme_mod( 'zakra_header_secondary_sub_menu_typography', $header_secondary_sub_menu_typography_default );

			$parse_builder_css .= zakra_parse_typography_css(
				$header_secondary_sub_menu_typography_default,
				$header_secondary_sub_menu_typography,
				'.zak-header-builder .zak-primary-nav ul li ul li a',
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			// Header builder search icon color.
			$header_search_icon_color = get_theme_mod( 'zakra_header_search_icon_color', '' );
			$header_search_icon_color_css = array(
				'.zak-header-builder .zak-header-search .zak-icon' => array(
					'fill' => esc_html( $header_search_icon_color ),
				),
			);
			$parse_builder_css                            .= zakra_parse_css( '', $header_search_icon_color, $header_search_icon_color_css );

			// Header builder search background.
			$header_search_background_default = array(
				'background-color'      => '',
				'background-image'      => '',
				'background-repeat'     => 'repeat',
				'background-position'   => 'center center',
				'background-size'       => 'contain',
				'background-attachment' => 'scroll',
			);
			$header_search_background         = get_theme_mod( 'zakra_header_search_background', $header_search_background_default );
			$parse_builder_css                           .= zakra_parse_background_css( $header_search_background_default, $header_search_background, '.zak-header-builder .zak-main-header.zak-header-search--opened' );

			// Header builder search text color.
			$header_search_text_color = get_theme_mod( 'zakra_header_search_text_color', '' );
			$header_search_text_color_css = array(
				'.zak-header-builder .zak-header-search .zak-search-field, .zak-header-builder .zak-header-search .zak-search-field:focus' => array(
					'color' => esc_html( $header_search_text_color ),
				),
				'.zak-header-builder .zak-header-search .zak-icon--close::after , .zak-header-builder .zak-header-search .zak-icon--close::before' => array(
					'background' => esc_html( $header_search_text_color ),
				),
				'.zak-header-builder .zak-header-search .zak-icon--search .zak-icon' => array(
					'fill' => esc_html( $header_search_text_color ),
				),
			);
			$parse_builder_css                            .= zakra_parse_css( '', $header_search_text_color, $header_search_text_color_css );

			// Header builder button text color.
			$header_button_text_color     = get_theme_mod( 'zakra_header_button_color', '#ffffff' );
			$header_button_text_color_css = array(
				'.zak-header-builder .zak-header-buttons .zak-header-button .zak-button' => array(
					'color' => esc_html( $header_button_text_color ),
				),
			);
			$parse_builder_css                    .= zakra_parse_css( '#ffffff', $header_button_text_color, $header_button_text_color_css );

			// Header builder button hover text color.
			$header_button_hover_text_color     = get_theme_mod( 'zakra_header_button_hover_color', '#ffffff' );
			$header_button_hover_text_color_css = array(
				'.zak-header-builder .zak-header-buttons .zak-header-button .zak-button:hover' => array(
					'color' => esc_html( $header_button_hover_text_color ),
				),
			);
			$parse_builder_css                          .= zakra_parse_css( '#ffffff', $header_button_hover_text_color, $header_button_hover_text_color_css );

			// Header builder button background color.
			$header_button_background_color     = get_theme_mod( 'zakra_header_button_background_color', '#027abb' );
			$header_button_background_color_css = array(
				'.zak-header-builder .zak-header-buttons .zak-header-button .zak-button' => array(
					'background-color' => esc_html( $header_button_background_color ),
				),
			);
			$parse_builder_css                          .= zakra_parse_css( '#027abb', $header_button_background_color, $header_button_background_color_css );

			// Header builder button hover background color.
			$header_button_background_hover_color     = get_theme_mod( 'zakra_header_button_background_hover_color', '' );
			$header_button_background_hover_color_css = array(
				'.zak-header-builder .zak-header-buttons .zak-header-button .zak-button:hover' => array(
					'background-color' => esc_html( $header_button_background_hover_color ),
				),
			);
			$parse_builder_css                                .= zakra_parse_css( '#ffffff', $header_button_background_hover_color, $header_button_background_hover_color_css );

			// Header builder button padding.
			$header_button_padding_default = array(
				'top'    => '5',
				'right'  => '10',
				'bottom' => '5',
				'left'   => '10',
				'unit'   => 'px',
			);

			$header_button_padding = get_theme_mod( 'zakra_header_button_padding', $header_button_padding_default );

			$parse_builder_css .= zakra_parse_dimension_css(
				$header_button_padding_default,
				$header_button_padding,
				'.zak-header-builder .zak-header-buttons .zak-header-button .zak-button',
				'padding'
			);

			// Header builder button border width.
			$header_button_border_width_default = array(
				'top'    => '',
				'right'  => '',
				'bottom' => '',
				'left'   => '',
				'unit'   => 'px',
			);

			$header_button_border_width = get_theme_mod( 'zakra_header_button_border_width', $header_button_border_width_default );

			$parse_builder_css .= zakra_parse_dimension_css(
				$header_button_border_width_default,
				$header_button_border_width,
				'.zak-header-builder .zak-header-buttons .zak-header-button .zak-button',
				'border-width'
			);

			// Header builder button border color.
			$header_button_border_color     = get_theme_mod( 'zakra_header_button_border_color', '' );
			$header_button_border_color_css = array(
				'.zak-header-builder .zak-header-buttons .zak-header-button .zak-button' => array(
					'border-color' => esc_html( $header_button_border_color ),
				),
			);
			$parse_builder_css                          .= zakra_parse_css( '', $header_button_border_color, $header_button_border_color_css );

			// Header builder button radius.
			$header_button_border_radius_default = array(
				'size' => 0,
				'unit' => 'px',
			);

			$header_button_border_radius = get_theme_mod( 'zakra_header_button_border_radius', $header_button_border_radius_default );

			$parse_builder_css .= zakra_parse_slider_css(
				$header_button_border_radius_default,
				$header_button_border_radius,
				'.zak-header-builder .zak-header-buttons .zak-header-button .zak-button',
				'border-radius'
			);

			// Header builder html 1 color.
			$header_html_1_text_color     = get_theme_mod( 'zakra_header_html_1_text_color', '' );
			$header_html_1_text_color_css = array(
				'.zak-header-builder .zak-html-1 *' => array(
					'color' => esc_html( $header_html_1_text_color ),
				),
			);
			$parse_builder_css                          .= zakra_parse_css( '', $header_html_1_text_color, $header_html_1_text_color_css );

			// Header builder html 1 link color.
			$header_html_1_link_color     = get_theme_mod( 'zakra_header_html_1_link_color', '' );
			$header_html_1_link_color_css = array(
				'.zak-header-builder .zak-html-1 a' => array(
					'color' => esc_html( $header_html_1_link_color ),
				),
			);
			$parse_builder_css                          .= zakra_parse_css( '', $header_html_1_link_color, $header_html_1_link_color_css );

			// Header builder html 1 link hover color.
			$header_html_1_link_hover_color     = get_theme_mod( 'zakra_header_html_1_link_hover_color', '' );
			$header_html_1_link_hover_color_css = array(
				'.zak-header-builder .zak-html-1 a:hover' => array(
					'color' => esc_html( $header_html_1_link_hover_color ),
				),
			);
			$parse_builder_css                          .= zakra_parse_css( '', $header_html_1_link_hover_color, $header_html_1_link_hover_color_css );

			// Header builder html 1 font size.
			$header_html_1_font_size_default = array(
				'size' => '',
				'unit' => 'px',
			);

			$header_html_1_font_size = get_theme_mod( 'zakra_header_html_1_font_size', $header_html_1_font_size_default );

			$parse_builder_css .= zakra_parse_slider_css(
				$header_html_1_font_size_default,
				$header_html_1_font_size,
				'.zak-header-builder .zak-html-1 *',
				'font-size'
			);

			// Header builder html 1 margin.
			$header_html_1_margin_default = array(
				'top'    => '',
				'right'  => '',
				'bottom' => '',
				'left'   => '',
				'unit'   => 'px',
			);

			$header_html_1_margin = get_theme_mod( 'zakra_header_html_1_margin', $header_html_1_margin_default );

			$parse_builder_css .= zakra_parse_dimension_css(
				$header_html_1_margin_default,
				$header_html_1_margin,
				'.zak-header-builder .zak-html-1',
				'margin'
			);

			// Header builder html 2 color.
			$header_html_2_text_color     = get_theme_mod( 'zakra_header_html_2_text_color', '' );
			$header_html_2_text_color_css = array(
				'.zak-header-builder .zak-html-2 *' => array(
					'color' => esc_html( $header_html_2_text_color ),
				),
			);
			$parse_builder_css                          .= zakra_parse_css( '', $header_html_2_text_color, $header_html_2_text_color_css );

			// Header builder html 2 link color.
			$header_html_2_link_color     = get_theme_mod( 'zakra_header_html_2_link_color', '' );
			$header_html_2_link_color_css = array(
				'.zak-header-builder .zak-html-2 a' => array(
					'color' => esc_html( $header_html_2_link_color ),
				),
			);
			$parse_builder_css                          .= zakra_parse_css( '', $header_html_2_link_color, $header_html_2_link_color_css );

			// Header builder html 2 link hover color.
			$header_html_2_link_hover_color     = get_theme_mod( 'zakra_header_html_2_link_hover_color', '' );
			$header_html_2_link_hover_color_css = array(
				'.zak-header-builder .zak-html-2 a:hover' => array(
					'color' => esc_html( $header_html_2_link_hover_color ),
				),
			);
			$parse_builder_css                          .= zakra_parse_css( '', $header_html_2_link_hover_color, $header_html_2_link_hover_color_css );

			// Header builder html 2 font size.
			$header_html_2_font_size_default = array(
				'size' => '',
				'unit' => 'px',
			);

			$header_html_2_font_size = get_theme_mod( 'zakra_header_html_2_font_size', $header_html_2_font_size_default );

			$parse_builder_css .= zakra_parse_slider_css(
				$header_html_2_font_size_default,
				$header_html_2_font_size,
				'.zak-header-builder .zak-html-2 p',
				'font-size'
			);

			// Header builder html 2 margin.
			$header_html_2_margin_default = array(
				'top'    => '',
				'right'  => '',
				'bottom' => '',
				'left'   => '',
				'unit'   => 'px',
			);

			$header_html_2_margin = get_theme_mod( 'zakra_header_html_2_margin', $header_html_2_margin_default );

			$parse_builder_css .= zakra_parse_dimension_css(
				$header_html_2_margin_default,
				$header_html_2_margin,
				'.zak-header-builder .zak-html-2',
				'margin'
			);

			// Footer builder top area container.
			$footer_top_area_container_default = array(
				'size' => 0,
				'unit' => 'px',
			);

			$footer_top_area_container = get_theme_mod( 'zakra_footer_top_area_container', $footer_top_area_container_default );

			$parse_builder_css .= zakra_parse_slider_css(
				$footer_top_area_container_default,
				$footer_top_area_container,
				'.zak-footer-builder .zak-footer-top-row .zak-container',
				'max-width'
			);

			// Footer builder top area height.
			$footer_top_area_height_default = array(
				'size' => 0,
				'unit' => 'px',
			);

			$footer_top_area_height = get_theme_mod( 'zakra_footer_top_area_height', $footer_top_area_height_default );

			$parse_builder_css .= zakra_parse_slider_css(
				$footer_top_area_height_default,
				$footer_top_area_height,
				'.zak-footer-builder .zak-top-row',
				'height'
			);

			// Footer top area background.
			$footer_top_area_background_default = array(
				'background-color'      => '',
				'background-image'      => '',
				'background-repeat'     => 'repeat',
				'background-position'   => 'center center',
				'background-size'       => 'contain',
				'background-attachment' => 'scroll',
			);
			$footer_top_area_background         = get_theme_mod( 'zakra_footer_top_area_background', $footer_top_area_background_default );
			$parse_builder_css                           .= zakra_parse_background_css( $footer_top_area_background_default, $footer_top_area_background, '.zak-footer-builder .zak-footer-top-row' );

			// Footer top area padding.
			$footer_top_area_padding_default = array(
				'top'    => '',
				'right'  => '',
				'bottom' => '',
				'left'   => '',
				'unit'   => 'px',
			);

			$footer_top_area_padding = get_theme_mod( 'zakra_footer_top_area_padding', $footer_top_area_padding_default );

			$parse_builder_css .= zakra_parse_dimension_css(
				$footer_top_area_padding_default,
				$footer_top_area_padding,
				'.zak-footer-builder .zak-footer-top-row',
				'padding'
			);

			// Footer top area margin.
			$footer_top_area_margin_default = array(
				'top'    => '',
				'right'  => '',
				'bottom' => '',
				'left'   => '',
				'unit'   => 'px',
			);

			$footer_top_area_margin = get_theme_mod( 'zakra_footer_top_area_margin', $footer_top_area_margin_default );

			$parse_builder_css .= zakra_parse_dimension_css(
				$footer_top_area_margin_default,
				$footer_top_area_margin,
				'.zak-footer-builder .zak-footer-top-row',
				'margin'
			);

			// Footer top area border width.
			$footer_top_area_border_width_default = array(
				'top'    => '0',
				'right'  => '0',
				'bottom' => '0',
				'left'   => '0',
				'unit'   => 'px',
			);

			$footer_top_area_border_width = get_theme_mod( 'zakra_footer_top_area_border_width', $footer_top_area_border_width_default );

			$parse_builder_css .= zakra_parse_dimension_css(
				$footer_top_area_border_width_default,
				$footer_top_area_border_width,
				'.zak-footer-builder .zak-footer-top-row',
				'border-width'
			);

			// Footer builder top area border color.
			$footer_top_area_border_color     = get_theme_mod( 'zakra_footer_top_area_border_color', '' );
			$footer_top_area_border_color_css = array(
				'.zak-footer-builder .zak-footer-top-row' => array(
					'border-color' => esc_html( $footer_top_area_border_color ),
				),
			);
			$parse_builder_css                   .= zakra_parse_css( '', $footer_top_area_border_color, $footer_top_area_border_color_css );

			// Footer builder main area height.
			$footer_main_area_height_default = array(

				'size' => 0,
				'unit' => 'px',
			);

			$footer_main_area_height = get_theme_mod( 'zakra_footer_main_area_height', $footer_main_area_height_default );

			$parse_builder_css .= zakra_parse_slider_css(
				$footer_main_area_height_default,
				$footer_main_area_height,
				'.zak-footer-builder .zak-main-row',
				'height'
			);

			// Footer builder main area container.
			$footer_main_area_container_default = array(
				'size' => 0,
				'unit' => 'px',
			);

			$footer_main_area_container = get_theme_mod( 'zakra_footer_main_area_container', $footer_main_area_container_default );

			$parse_builder_css .= zakra_parse_slider_css(
				$footer_main_area_container_default,
				$footer_main_area_container,
				'.zak-footer-builder .zak-footer-main-row .zak-container',
				'max-width'
			);

			// Footer builder main area background.
			$footer_main_area_background_default = array(
				'background-color'      => '',
				'background-image'      => '',
				'background-repeat'     => 'repeat',
				'background-position'   => 'center center',
				'background-size'       => 'contain',
				'background-attachment' => 'scroll',
			);
			$footer_main_area_background         = get_theme_mod( 'zakra_footer_main_area_background', $footer_main_area_background_default );
			$parse_builder_css                           .= zakra_parse_background_css( $footer_main_area_background_default, $footer_main_area_background, '.zak-footer-builder .zak-footer-main-row' );

			// Footer builder main area padding.
			$footer_main_area_padding_default = array(
				'top'    => '',
				'right'  => '',
				'bottom' => '',
				'left'   => '',
				'unit'   => 'px',
			);

			$footer_main_area_padding = get_theme_mod( 'zakra_footer_main_area_padding', $footer_main_area_padding_default );

			$parse_builder_css .= zakra_parse_dimension_css(
				$footer_main_area_padding_default,
				$footer_main_area_padding,
				'.zak-footer-builder .zak-footer-main-row',
				'padding'
			);

			// Footer builder main area margin.
			$footer_main_area_margin_default = array(
				'top'    => '',
				'right'  => '',
				'bottom' => '',
				'left'   => '',
				'unit'   => 'px',
			);

			$footer_main_area_margin = get_theme_mod( 'zakra_footer_main_area_margin', $footer_main_area_margin_default );

			$parse_builder_css .= zakra_parse_dimension_css(
				$footer_main_area_margin_default,
				$footer_main_area_margin,
				'.zak-footer-builder .zak-footer-main-row',
				'margin'
			);

			// Footer builder main area border width.
			$footer_main_area_border_width_default = array(
				'top'    => '0',
				'right'  => '0',
				'bottom' => '0',
				'left'   => '0',
				'unit'   => 'px',
			);

			$footer_main_area_border_width = get_theme_mod( 'zakra_footer_main_area_border_width', $footer_main_area_border_width_default );

			$parse_builder_css .= zakra_parse_dimension_css(
				$footer_main_area_border_width_default,
				$footer_main_area_border_width,
				'.zak-footer-builder .zak-footer-main-row',
				'border-width'
			);

			// Footer builder main area border color.
			$footer_main_area_border_color     = get_theme_mod( 'zakra_footer_main_area_border_color', '' );
			$footer_main_area_border_color_css = array(
				'.zak-footer-builder .zak-footer-main-row' => array(
					'border-color' => esc_html( $footer_main_area_border_color ),
				),
			);
			$parse_builder_css                   .= zakra_parse_css( '', $footer_main_area_border_color, $footer_main_area_border_color_css );

			// Footer builder bottom area height.
			$footer_bottom_area_height_default = array(
				'size' => 0,
				'unit' => 'px',
			);

			$footer_bottom_area_height = get_theme_mod( 'zakra_footer_bottom_area_height', $footer_bottom_area_height_default );

			$parse_builder_css .= zakra_parse_slider_css(
				$footer_bottom_area_height_default,
				$footer_bottom_area_height,
				'.zak-footer-builder .zak-bottom-row',
				'height'
			);

			// Footer builder bottom area container.
			$footer_bottom_area_container_default = array(
				'size' => 0,
				'unit' => 'px',
			);

			$footer_bottom_area_container = get_theme_mod( 'zakra_footer_bottom_area_container', $footer_bottom_area_container_default );

			$parse_builder_css .= zakra_parse_slider_css(
				$footer_bottom_area_container_default,
				$footer_bottom_area_container,
				'.zak-footer-builder .zak-footer-bottom-row .zak-container',
				'max-width'
			);

			// Footer builder bottom area background.
			$footer_bottom_area_background_default = array(
				'background-color'      => '',
				'background-image'      => '',
				'background-repeat'     => 'repeat',
				'background-position'   => 'center center',
				'background-size'       => 'contain',
				'background-attachment' => 'scroll',
			);
			$footer_bottom_area_background         = get_theme_mod( 'zakra_footer_bottom_area_background', $footer_bottom_area_background_default );
			$parse_builder_css                           .= zakra_parse_background_css( $footer_bottom_area_background_default, $footer_bottom_area_background, '.zak-footer-builder .zak-footer-bottom-row' );

			// Footer builder bottom area padding.
			$footer_bottom_area_padding_default = array(
				'top'    => '',
				'right'  => '',
				'bottom' => '',
				'left'   => '',
				'unit'   => 'px',
			);

			$footer_bottom_area_padding = get_theme_mod( 'zakra_footer_bottom_area_padding', $footer_bottom_area_padding_default );

			$parse_builder_css .= zakra_parse_dimension_css(
				$footer_bottom_area_padding_default,
				$footer_bottom_area_padding,
				'.zak-footer-builder .zak-footer-bottom-row',
				'padding'
			);

			// Footer builder bottom area margin.
			$footer_bottom_area_margin_default = array(
				'top'    => '',
				'right'  => '',
				'bottom' => '',
				'left'   => '',
				'unit'   => 'px',
			);

			$footer_bottom_area_margin = get_theme_mod( 'zakra_footer_bottom_area_margin', $footer_bottom_area_margin_default );

			$parse_builder_css .= zakra_parse_dimension_css(
				$footer_bottom_area_margin_default,
				$footer_bottom_area_margin,
				'.zak-footer-builder .zak-footer-bottom-row',
				'margin'
			);

			// Footer builder bottom area border width.
			$footer_bottom_area_border_width_default = array(
				'top'    => '0',
				'right'  => '0',
				'bottom' => '0',
				'left'   => '0',
				'unit'   => 'px',
			);

			$footer_bottom_area_border_width = get_theme_mod( 'zakra_footer_bottom_area_border_width', $footer_bottom_area_border_width_default );

			$parse_builder_css .= zakra_parse_dimension_css(
				$footer_bottom_area_border_width_default,
				$footer_bottom_area_border_width,
				'.zak-footer-builder .zak-footer-bottom-row',
				'border-width'
			);

			$footer_bottom_area_border_color     = get_theme_mod( 'zakra_footer_bottom_area_border_color', '' );
			$footer_bottom_area_border_color_css = array(
				'.zak-footer-builder .zak-footer-bottom-row' => array(
					'border-color' => esc_html( $footer_bottom_area_border_color ),
				),
			);
			$parse_builder_css                   .= zakra_parse_css( '', $footer_bottom_area_border_color, $footer_bottom_area_border_color_css );

			// Header builder mobile menu item text color.
			$header_mobile_menu_item_text_color     = get_theme_mod( 'zakra_header_mobile_menu_item_color', '' );
			$header_mobile_menu_item_text_color_css = array(
				'.zak-mobile-nav a' => array(
					'color' => esc_html( $header_mobile_menu_item_text_color ),
				),
				'.zak-mobile-nav li.page_item_has_children .zak-submenu-toggle .zak-icon, .zak-mobile-nav li.menu-item-has-children .zak-submenu-toggle .zak-icon' => array(
					'fill' => esc_html( $header_mobile_menu_item_text_color ),
				),
			);
			$parse_builder_css                      .= zakra_parse_css( '', $header_mobile_menu_item_text_color, $header_mobile_menu_item_text_color_css );

			// Header builder mobile menu item text hover color.
			$header_mobile_menu_item_hover_color     = get_theme_mod( 'zakra_header_mobile_menu_item_hover_color', '' );
			$header_mobile_menu_item_hover_color_css = array(
				'.zak-mobile-nav li:hover > a' => array(
					'color' => esc_html( $header_mobile_menu_item_hover_color ),
				),
			);
			$parse_builder_css                       .= zakra_parse_css( '', $header_mobile_menu_item_hover_color, $header_mobile_menu_item_hover_color_css );

			// Header builder mobile menu item text active color.
			$header_mobile_menu_item_active_color     = get_theme_mod( 'zakra_header_mobile_menu_item_active_color', '' );
			$header_mobile_menu_item_active_color_css = array(
				'.zak-mobile-nav .current_page_item a, .zak-mobile-nav > .menu ul li.current-menu-item > a' => array(
					'color' => esc_html( $header_mobile_menu_item_active_color ),
				),
			);
			$parse_builder_css                        .= zakra_parse_css( '', $header_mobile_menu_item_active_color, $header_mobile_menu_item_active_color_css );

			// Header builder mobile menu background color.
			$header_mobile_menu_background_color     = get_theme_mod( 'zakra_header_mobile_menu_background', '' );
			$header_mobile_menu_background_color_css = array(
				'.zak-mobile-nav, .zak-search-form .zak-search-field' => array(
					'background-color' => esc_html( $header_mobile_menu_background_color ),
				),
			);
			$parse_builder_css                       .= zakra_parse_css( '', $header_mobile_menu_background_color, $header_mobile_menu_background_color_css );

			// Header builder mobile menu item border bottom.
			$header_mobile_menu_item_border_bottom_default = array(
				'size' => 1,
				'unit' => 'px',
			);

			$header_mobile_menu_item_border_bottom = get_theme_mod( 'zakra_header_mobile_menu_item_border_bottom', $header_mobile_menu_item_border_bottom_default );

			$parse_builder_css .= zakra_parse_slider_css(
				$header_mobile_menu_item_border_bottom_default,
				$header_mobile_menu_item_border_bottom,
				'.zak-mobile-nav li:not(:last-child)',
				'border-bottom-width'
			);

			// Header builer mobile menu item border bottom style.
			$header_mobile_menu_item_border_bottom_style     = get_theme_mod( 'zakra_header_mobile_menu_item_border_bottom_style', 'solid' );
			$header_mobile_menu_item_border_bottom_style_css = array(
				'.zak-mobile-nav li' => array(
					'border-bottom-style' => esc_html( $header_mobile_menu_item_border_bottom_style ),
				),
			);
			$parse_builder_css                               .= zakra_parse_css( 'solid', $header_mobile_menu_item_border_bottom_style, $header_mobile_menu_item_border_bottom_style_css );

			// Header builder mobile menu item border bottom color.
			$header_mobile_menu_item_border_bottom_color     = get_theme_mod( 'zakra_header_mobile_menu_item_border_bottom_color', '' );
			$header_mobile_menu_item_border_bottom_color_css = array(
				'.zak-mobile-nav li' => array(
					'border-color' => esc_html( $header_mobile_menu_item_border_bottom_color ),
				),
			);
			$parse_builder_css                               .= zakra_parse_css( '', $header_mobile_menu_item_border_bottom_color, $header_mobile_menu_item_border_bottom_color_css );

			// Header builder mobile menu typography.
			$header_mobile_menu_typography_default = array(
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
			);

			$header_mobile_menu_typography = get_theme_mod( 'zakra_header_mobile_menu_typography', $header_mobile_menu_typography_default );

			$parse_builder_css .= zakra_parse_typography_css(
				$header_mobile_menu_typography_default,
				$header_mobile_menu_typography,
				'.zak-mobile-menu a',
				array(
					'mobile' => 600,
					'tablet' => 768,
				)
			);

			// Header builder widget title color.
			$header_widget_title_color     = get_theme_mod( 'zakra_widget_1_title_color', '' );
			$header_widget_title_color_css = array(
				'.zak-header-builder .widget.widget-top-bar-col-1-sidebar .widget-title' => array(
					'color' => esc_html( $header_widget_title_color ),
				),
			);
			$parse_builder_css                               .= zakra_parse_css( '', $header_widget_title_color, $header_widget_title_color_css );

			// Header builder widget content color.
			$header_widget_content_color     = get_theme_mod( 'zakra_widget_1_content_color', '' );
			$header_widget_content_color_css = array(
				'.zak-header-builder .widget.widget-top-bar-col-1-sidebar' => array(
					'color' => esc_html( $header_widget_content_color ),
				),
			);
			$parse_builder_css                               .= zakra_parse_css( '', $header_widget_content_color, $header_widget_content_color_css );

			// Header builder widget link color.
			$header_widget_link_color     = get_theme_mod( 'zakra_widget_1_link_color', '' );
			$header_widget_link_color_css = array(
				'.zak-header-builder .widget.widget-top-bar-col-1-sidebar a' => array(
					'color' => esc_html( $header_widget_link_color ),
				),
			);
			$parse_builder_css                               .= zakra_parse_css( '', $header_widget_link_color, $header_widget_link_color_css );

			// Header builder widget title typography.
			$header_widget_1_title_typography_default = array(
				'font-family'    => 'Default',
				'font-weight'    => '400',
				'font-size'      => array(
					'desktop' => array(
						'size' => '2',
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
			);

			$header_widget_1_title_typography = get_theme_mod( 'zakra_widget_1_title_typography', $header_widget_1_title_typography_default );

			$parse_builder_css .= zakra_parse_typography_css(
				$header_widget_1_title_typography_default,
				$header_widget_1_title_typography,
				'.zak-header-builder .widget.widget-top-bar-col-1-sidebar .widget-title',
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			// Header builder widget content typography.
			$header_widget_1_content_typography_default = array(
				'font-family'    => 'Default',
				'font-weight'    => '400',
				'font-size'      => array(
					'desktop' => array(
						'size' => '2',
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
			);

			$header_widget_1_content_typography = get_theme_mod( 'zakra_widget_1_content_typography', $header_widget_1_content_typography_default );

			$parse_builder_css .= zakra_parse_typography_css(
				$header_widget_1_content_typography_default,
				$header_widget_1_content_typography,
				'.zak-header-builder .widget.widget-top-bar-col-1-sidebar',
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			// Header builder widget 2 title color.
			$header_widget_2_title_color     = get_theme_mod( 'zakra_widget_2_title_color', '' );
			$header_widget_2_title_color_css = array(
				'.zak-header-builder .widget.widget-top-bar-col-2-sidebar .widget-title' => array(
					'color' => esc_html( $header_widget_2_title_color ),
				),
			);
			$parse_builder_css                               .= zakra_parse_css( '', $header_widget_2_title_color, $header_widget_2_title_color_css );

			// Header builder widget 2 content color.
			$header_widget_2_content_color     = get_theme_mod( 'zakra_widget_2_content_color', '' );
			$header_widget_2_content_color_css = array(
				'.zak-header-builder .widget.widget-top-bar-col-2-sidebar' => array(
					'color' => esc_html( $header_widget_2_content_color ),
				),
			);
			$parse_builder_css                               .= zakra_parse_css( '', $header_widget_2_content_color, $header_widget_2_content_color_css );

			// Header builder widget 2 link color.
			$header_widget_2_link_color     = get_theme_mod( 'zakra_widget_2_link_color', '' );
			$header_widget_2_link_color_css = array(
				'.zak-header-builder .widget.widget-top-bar-col-2-sidebar a' => array(
					'color' => esc_html( $header_widget_2_link_color ),
				),
			);
			$parse_builder_css                               .= zakra_parse_css( '', $header_widget_2_link_color, $header_widget_2_link_color_css );

			// Header builder widget title typography.
			$header_widget_2_title_typography_default = array(
				'font-family'    => 'Default',
				'font-weight'    => '400',
				'font-size'      => array(
					'desktop' => array(
						'size' => '2',
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
			);

			$header_widget_2_title_typography = get_theme_mod( 'zakra_widget_2_title_typography', $header_widget_2_title_typography_default );

			$parse_builder_css .= zakra_parse_typography_css(
				$header_widget_2_title_typography_default,
				$header_widget_2_title_typography,
				'.zak-header-builder .widget.widget-top-bar-col-2-sidebar .widget-title',
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			// Header builder widget content typography.
			$header_widget_2_content_typography_default = array(
				'font-family'    => 'Default',
				'font-weight'    => '400',
				'font-size'      => array(
					'desktop' => array(
						'size' => '2',
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
			);

			$header_widget_2_content_typography = get_theme_mod( 'zakra_widget_2_content_typography', $header_widget_2_content_typography_default );

			$parse_builder_css .= zakra_parse_typography_css(
				$header_widget_2_content_typography_default,
				$header_widget_2_content_typography,
				'.zak-header-builder .widget.widget-top-bar-col-2-sidebar',
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			// Footer builder html 1 color.
			$footer_html_1_text_color     = get_theme_mod( 'zakra_footer_html_1_text_color', '' );
			$footer_html_1_text_color_css = array(
				'.zak-footer-builder .zak-html-1 *' => array(
					'color' => esc_html( $footer_html_1_text_color ),
				),
			);
			$parse_builder_css                          .= zakra_parse_css( '', $footer_html_1_text_color, $footer_html_1_text_color_css );

			// Footer builder html 1 link color.
			$footer_html_1_link_color     = get_theme_mod( 'zakra_footer_html_1_link_color', '' );
			$footer_html_1_link_color_css = array(
				'.zak-footer-builder .zak-html-1 a' => array(
					'color' => esc_html( $footer_html_1_link_color ),
				),
			);
			$parse_builder_css                          .= zakra_parse_css( '', $footer_html_1_link_color, $footer_html_1_link_color_css );

			// Footer builder html 1 link hover color.
			$footer_html_1_link_hover_color     = get_theme_mod( 'zakra_footer_html_1_link_hover_color', '' );
			$footer_html_1_link_hover_color_css = array(
				'.zak-footer-builder .zak-html-1 a:hover' => array(
					'color' => esc_html( $footer_html_1_link_hover_color ),
				),
			);
			$parse_builder_css                          .= zakra_parse_css( '', $footer_html_1_link_hover_color, $footer_html_1_link_hover_color_css );

			// Footer builder html 1 font size.
			$footer_html_1_font_size_default = array(
				'size' => '',
				'unit' => 'px',
			);

			$footer_html_1_font_size = get_theme_mod( 'zakra_footer_html_1_font_size', $footer_html_1_font_size_default );

			$parse_builder_css .= zakra_parse_slider_css(
				$footer_html_1_font_size_default,
				$footer_html_1_font_size,
				'.zak-footer-builder .zak-html-1 *',
				'font-size'
			);

			// Footer builder html 1 margin.
			$footer_html_1_margin_default = array(
				'top'    => '',
				'right'  => '',
				'bottom' => '',
				'left'   => '',
				'unit'   => 'px',
			);

			$footer_html_1_margin = get_theme_mod( 'zakra_footer_html_1_margin', $footer_html_1_margin_default );

			$parse_builder_css .= zakra_parse_dimension_css(
				$footer_html_1_margin_default,
				$footer_html_1_margin,
				'.zak-footer-builder .zak-html-1',
				'margin'
			);

			// Footer builder html 1 color.
			$footer_html_2_text_color     = get_theme_mod( 'zakra_footer_html_2_text_color', '' );
			$footer_html_2_text_color_css = array(
				'.zak-footer-builder .zak-html-2 *' => array(
					'color' => esc_html( $footer_html_2_text_color ),
				),
			);
			$parse_builder_css                          .= zakra_parse_css( '', $footer_html_2_text_color, $footer_html_2_text_color_css );

			// Footer builder html 1 link color.
			$footer_html_2_link_color     = get_theme_mod( 'zakra_footer_html_2_link_color', '' );
			$footer_html_2_link_color_css = array(
				'.zak-footer-builder .zak-html-2 a' => array(
					'color' => esc_html( $footer_html_2_link_color ),
				),
			);
			$parse_builder_css                          .= zakra_parse_css( '', $footer_html_2_link_color, $footer_html_2_link_color_css );

			// Footer builder html 1 link hover color.
			$footer_html_2_link_hover_color     = get_theme_mod( 'zakra_footer_html_2_link_hover_color', '' );
			$footer_html_2_link_hover_color_css = array(
				'.zak-footer-builder .zak-html-2 a:hover' => array(
					'color' => esc_html( $footer_html_2_link_hover_color ),
				),
			);
			$parse_builder_css                          .= zakra_parse_css( '', $footer_html_2_link_hover_color, $footer_html_2_link_hover_color_css );

			// Footer builder html 1 font size.
			$footer_html_2_font_size_default = array(
				'size' => '',
				'unit' => 'px',
			);

			$footer_html_2_font_size = get_theme_mod( 'zakra_footer_html_2_font_size', $footer_html_2_font_size_default );

			$parse_builder_css .= zakra_parse_slider_css(
				$footer_html_2_font_size_default,
				$footer_html_2_font_size,
				'.zak-footer-builder .zak-html-2 *',
				'font-size'
			);

			// Footer builder html 1 margin.
			$footer_html_2_margin_default = array(
				'top'    => '',
				'right'  => '',
				'bottom' => '',
				'left'   => '',
				'unit'   => 'px',
			);

			$footer_html_2_margin = get_theme_mod( 'zakra_footer_html_2_margin', $footer_html_2_margin_default );

			$parse_builder_css .= zakra_parse_dimension_css(
				$footer_html_2_margin_default,
				$footer_html_2_margin,
				'.zak-footer-builder .zak-html-2',
				'margin'
			);

			// Footer builder widget title color.
			$footer_widget_title_color     = get_theme_mod( 'zakra_footer_widget_1_title_color', '' );
			$footer_widget_title_color_css = array(
				'.zak-footer-builder .widget.widget-footer-sidebar-1 .widget-title' => array(
					'color' => esc_html( $footer_widget_title_color ),
				),
			);
			$parse_builder_css                               .= zakra_parse_css( '', $footer_widget_title_color, $footer_widget_title_color_css );

			// Footer builder widget content color.
			$footer_widget_content_color     = get_theme_mod( 'zakra_footer_widget_1_content_color', '' );
			$footer_widget_content_color_css = array(
				'.zak-footer-builder .widget.widget-footer-sidebar-1' => array(
					'color' => esc_html( $footer_widget_content_color ),
				),
			);
			$parse_builder_css                               .= zakra_parse_css( '', $footer_widget_content_color, $footer_widget_content_color_css );

			// Footer builder widget link color.
			$footer_widget_link_color     = get_theme_mod( 'zakra_footer_widget_1_link_color', '' );
			$footer_widget_link_color_css = array(
				'.zak-footer-builder .widget.widget-footer-sidebar-1 a' => array(
					'color' => esc_html( $footer_widget_link_color ),
				),
			);
			$parse_builder_css                               .= zakra_parse_css( '', $footer_widget_link_color, $footer_widget_link_color_css );

			// Footer builder widget title typography.
			$footer_widget_1_title_typography_default = array(
				'font-family'    => 'Default',
				'font-weight'    => '400',
				'font-size'      => array(
					'desktop' => array(
						'size' => '2',
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
			);
			$footer_widget_1_title_typography = get_theme_mod( 'zakra_footer_widget_1_title_typography', $footer_widget_1_title_typography_default );
			$parse_builder_css .= zakra_parse_typography_css(
				$footer_widget_1_title_typography_default,
				$footer_widget_1_title_typography,
				'.zak-footer-builder .widget.widget-footer-sidebar-1 .widget-title',
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			// Footer builder widget content typography.
			$footer_widget_1_content_typography_default = array(
				'font-family'    => 'Default',
				'font-weight'    => '400',
				'font-size'      => array(
					'desktop' => array(
						'size' => '2',
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
			);
			$footer_widget_1_content_typography = get_theme_mod( 'zakra_footer_widget_1_content_typography', $footer_widget_1_content_typography_default );
			$parse_builder_css .= zakra_parse_typography_css(
				$footer_widget_1_content_typography_default,
				$footer_widget_1_content_typography,
				'.zak-footer-builder .widget.widget-footer-sidebar-1',
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			// Footer builder widget 2 title color.
			$footer_widget_2_title_color     = get_theme_mod( 'zakra_footer_widget_2_title_color', '' );
			$footer_widget_2_title_color_css = array(
				'.zak-footer-builder .widget.widget-footer-sidebar-2 .widget-title' => array(
					'color' => esc_html( $footer_widget_2_title_color ),
				),
			);
			$parse_builder_css                               .= zakra_parse_css( '', $footer_widget_2_title_color, $footer_widget_2_title_color_css );

			// Footer builder widget 2 content color.
			$footer_widget_2_content_color     = get_theme_mod( 'zakra_footer_widget_2_content_color', '' );
			$footer_widget_2_content_color_css = array(
				'.zak-footer-builder .widget.widget-footer-sidebar-2' => array(
					'color' => esc_html( $footer_widget_2_content_color ),
				),
			);
			$parse_builder_css                               .= zakra_parse_css( '', $footer_widget_2_content_color, $footer_widget_2_content_color_css );

			// Footer builder widget 2 link color.
			$footer_widget_2_link_color     = get_theme_mod( 'zakra_footer_widget_2_link_color', '' );
			$footer_widget_2_link_color_css = array(
				'.zak-footer-builder .widget.widget-footer-sidebar-2 a' => array(
					'color' => esc_html( $footer_widget_2_link_color ),
				),
			);
			$parse_builder_css                               .= zakra_parse_css( '', $footer_widget_2_link_color, $footer_widget_2_link_color_css );

			// Footer builder widget 2 title typography.
			$footer_widget_2_title_typography_default = array(
				'font-family'    => 'Default',
				'font-weight'    => '400',
				'font-size'      => array(
					'desktop' => array(
						'size' => '2',
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
			);
			$footer_widget_2_title_typography = get_theme_mod( 'zakra_footer_widget_2_title_typography', $footer_widget_2_title_typography_default );
			$parse_builder_css .= zakra_parse_typography_css(
				$footer_widget_2_title_typography_default,
				$footer_widget_2_title_typography,
				'.zak-footer-builder .widget.widget-footer-sidebar-2 .widget-title',
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			// Footer builder widget 2 content typography.
			$footer_widget_2_content_typography_default = array(
				'font-family'    => 'Default',
				'font-weight'    => '400',
				'font-size'      => array(
					'desktop' => array(
						'size' => '2',
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
			);
			$footer_widget_2_content_typography = get_theme_mod( 'zakra_footer_widget_2_content_typography', $footer_widget_2_content_typography_default );
			$parse_builder_css .= zakra_parse_typography_css(
				$footer_widget_2_content_typography_default,
				$footer_widget_2_content_typography,
				'.zak-footer-builder .widget.widget-footer-sidebar-2',
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			// Footer builder widget 3 title color.
			$footer_widget_3_title_color     = get_theme_mod( 'zakra_footer_widget_3_title_color', '' );
			$footer_widget_3_title_color_css = array(
				'.zak-footer-builder .widget.widget-footer-sidebar-3 .widget-title' => array(
					'color' => esc_html( $footer_widget_3_title_color ),
				),
			);
			$parse_builder_css                               .= zakra_parse_css( '', $footer_widget_3_title_color, $footer_widget_3_title_color_css );

			// Footer builder widget 3 content color.
			$footer_widget_3_content_color     = get_theme_mod( 'zakra_footer_widget_3_content_color', '' );
			$footer_widget_3_content_color_css = array(
				'.zak-footer-builder .widget.widget-footer-sidebar-3' => array(
					'color' => esc_html( $footer_widget_3_content_color ),
				),
			);
			$parse_builder_css                               .= zakra_parse_css( '', $footer_widget_3_content_color, $footer_widget_3_content_color_css );

			// Footer builder widget 3 link color.
			$footer_widget_3_link_color     = get_theme_mod( 'zakra_footer_widget_3_link_color', '' );
			$footer_widget_3_link_color_css = array(
				'.zak-footer-builder .widget.widget-footer-sidebar-3 a' => array(
					'color' => esc_html( $footer_widget_3_link_color ),
				),
			);
			$parse_builder_css                               .= zakra_parse_css( '', $footer_widget_3_link_color, $footer_widget_3_link_color_css );

			// Footer builder widget 3 title typography.
			$footer_widget_3_title_typography_default = array(
				'font-family'    => 'Default',
				'font-weight'    => '400',
				'font-size'      => array(
					'desktop' => array(
						'size' => '3',
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
			);

			$footer_widget_3_title_typography = get_theme_mod( 'zakra_footer_widget_3_title_typography', $footer_widget_3_title_typography_default );

			$parse_builder_css .= zakra_parse_typography_css(
				$footer_widget_3_title_typography_default,
				$footer_widget_3_title_typography,
				'.zak-footer-builder .widget.widget-footer-sidebar-3 .widget-title',
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			// Footer builder widget 3 content typography.
			$footer_widget_3_content_typography_default = array(
				'font-family'    => 'Default',
				'font-weight'    => '400',
				'font-size'      => array(
					'desktop' => array(
						'size' => '3',
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
			);

			$footer_widget_3_content_typography = get_theme_mod( 'zakra_footer_widget_3_content_typography', $footer_widget_3_content_typography_default );

			$parse_builder_css .= zakra_parse_typography_css(
				$footer_widget_3_content_typography_default,
				$footer_widget_3_content_typography,
				'.zak-footer-builder .widget.widget-footer-sidebar-3',
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			// Footer builder widget 4 title color.
			$footer_widget_4_title_color     = get_theme_mod( 'zakra_footer_widget_4_title_color', '' );
			$footer_widget_4_title_color_css = array(
				'.zak-footer-builder .widget.widget-footer-sidebar-4 .widget-title' => array(
					'color' => esc_html( $footer_widget_4_title_color ),
				),
			);
			$parse_builder_css                               .= zakra_parse_css( '', $footer_widget_4_title_color, $footer_widget_4_title_color_css );

			// Footer builder widget 4 content color.
			$footer_widget_4_content_color     = get_theme_mod( 'zakra_footer_widget_4_content_color', '' );
			$footer_widget_4_content_color_css = array(
				'.zak-footer-builder .widget.widget-footer-sidebar-4' => array(
					'color' => esc_html( $footer_widget_4_content_color ),
				),
			);
			$parse_builder_css                               .= zakra_parse_css( '', $footer_widget_4_content_color, $footer_widget_4_content_color_css );

			// Footer builder widget 4 link color.
			$footer_widget_4_link_color     = get_theme_mod( 'zakra_footer_widget_4_link_color', '' );
			$footer_widget_4_link_color_css = array(
				'.zak-footer-builder .widget.widget-footer-sidebar-4 a' => array(
					'color' => esc_html( $footer_widget_4_link_color ),
				),
			);
			$parse_builder_css                               .= zakra_parse_css( '', $footer_widget_4_link_color, $footer_widget_4_link_color_css );

			// Footer builder widget 4 title typography.
			$footer_widget_4_title_typography_default = array(
				'font-family'    => 'Default',
				'font-weight'    => '400',
				'font-size'      => array(
					'desktop' => array(
						'size' => '4',
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
			);

			$footer_widget_4_title_typography = get_theme_mod( 'zakra_footer_widget_4_title_typography', $footer_widget_4_title_typography_default );

			$parse_builder_css .= zakra_parse_typography_css(
				$footer_widget_4_title_typography_default,
				$footer_widget_4_title_typography,
				'.zak-footer-builder .widget.widget-footer-sidebar-4 .widget-title',
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			// Footer builder widget 4 content typography.
			$footer_widget_4_content_typography_default = array(
				'font-family'    => 'Default',
				'font-weight'    => '400',
				'font-size'      => array(
					'desktop' => array(
						'size' => '4',
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
			);

			$footer_widget_4_content_typography = get_theme_mod( 'zakra_footer_widget_4_content_typography', $footer_widget_4_content_typography_default );

			$parse_builder_css .= zakra_parse_typography_css(
				$footer_widget_4_content_typography_default,
				$footer_widget_4_content_typography,
				'.zak-footer-builder .widget.widget-footer-sidebar-4',
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			// Footer builder footer menu color.
			$footer_menu_color     = get_theme_mod( 'zakra_footer_menu_color', '' );
			$footer_menu_color_css = array(
				'.zak-footer-builder .zak-footer-nav ul li a' => array(
					'color' => esc_html( $footer_menu_color ),
				),
			);
			$parse_builder_css                               .= zakra_parse_css( '', $footer_menu_color, $footer_menu_color_css );

			// Footer builder footer menu hover color.
			$footer_menu_hover_color     = get_theme_mod( 'zakra_footer_menu_hover_color', '' );
			$footer_menu_hover_color_css = array(
				'.zak-footer-builder .zak-footer-nav ul li a:hover' => array(
					'color' => esc_html( $footer_menu_hover_color ),
				),
			);
			$parse_builder_css                               .= zakra_parse_css( '', $footer_menu_hover_color, $footer_menu_hover_color_css );

			// Footer builder footer menu typography.
			$footer_menu_typography_default = array(
				'font-family'    => 'Default',
				'font-weight'    => '400',
				'font-size'      => array(
					'desktop' => array(
						'size' => '4',
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
			);

			$footer_menu_1_typography = get_theme_mod( 'zakra_footer_menu_1_typography', $footer_menu_typography_default );

			$parse_builder_css .= zakra_parse_typography_css(
				$footer_menu_typography_default,
				$footer_menu_1_typography,
				'.zak-footer-builder .zak-footer-nav-1 ul li a',
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			// Footer builder footer menu 2 color.
			$footer_menu_2_color     = get_theme_mod( 'zakra_footer_menu_2_color', '' );
			$footer_menu_2_color_css = array(
				'.zak-footer-builder .zak-footer-nav-2 ul li a' => array(
					'color' => esc_html( $footer_menu_2_color ),
				),
			);
			$parse_builder_css                               .= zakra_parse_css( '', $footer_menu_2_color, $footer_menu_2_color_css );

			// Footer builder footer menu 2 hover color.
			$footer_menu_2_hover_color     = get_theme_mod( 'zakra_footer_menu_2_hover_color', '' );
			$footer_menu_2_hover_color_css = array(
				'.zak-footer-builder .zak-footer-nav-2 ul li a:hover' => array(
					'color' => esc_html( $footer_menu_2_hover_color ),
				),
			);
			$parse_builder_css                               .= zakra_parse_css( '', $footer_menu_2_hover_color, $footer_menu_2_hover_color_css );

			// Footer builder footer menu 2 typography.
			$footer_menu_2_typography_default = array(
				'font-family'    => 'Default',
				'font-weight'    => '400',
				'font-size'      => array(
					'desktop' => array(
						'size' => '4',
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
			);

			$footer_menu_2_typography = get_theme_mod( 'zakra_footer_menu_2_typography', $footer_menu_2_typography_default );

			$parse_builder_css .= zakra_parse_typography_css(
				$footer_menu_2_typography_default,
				$footer_menu_2_typography,
				'.zak-footer-builder .zak-footer-nav-2 ul li a',
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			// Footer builder copyright color.
			$footer_copyright_color     = get_theme_mod( 'zakra_footer_copyright_text_color', '' );
			$footer_copyright_color_css = array(
				'.zak-footer-builder .zak-copyright' => array(
					'color' => esc_html( $footer_copyright_color ),
				),
			);
			$parse_builder_css                               .= zakra_parse_css( '', $footer_copyright_color, $footer_copyright_color_css );

			// Footer builder copyright color.
			$footer_copyright_link_color     = get_theme_mod( 'zakra_footer_copyright_link_color', '' );
			$footer_copyright_link_color_css = array(
				'.zak-footer-builder .zak-copyright a' => array(
					'color' => esc_html( $footer_copyright_link_color ),
				),
			);
			$parse_builder_css                               .= zakra_parse_css( '', $footer_copyright_link_color, $footer_copyright_link_color_css );

			// Footer builder copyright hover color.
			$footer_copyright_link_hover_color     = get_theme_mod( 'zakra_footer_copyright_link_hover_color', '' );
			$footer_copyright_link_hover_color_css = array(
				'.zak-footer-builder .zak-copyright a:hover' => array(
					'color' => esc_html( $footer_copyright_link_hover_color ),
				),
			);
			$parse_builder_css                               .= zakra_parse_css( '', $footer_copyright_link_hover_color, $footer_copyright_link_hover_color_css );

			// Footer builder copyright typography.
			$footer_copyright_typography_default = array(
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
			);

			$footer_copyright_typography = get_theme_mod( 'zakra_footer_copyright_typography', $footer_copyright_typography_default );

			$parse_builder_css .= zakra_parse_typography_css(
				$footer_copyright_typography_default,
				$footer_copyright_typography,
				'.zak-footer-builder .zak-copyright',
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			// Footer builder copyright margin.
			$footer_copyright_margin_default = array(
				'top'    => '',
				'right'  => '',
				'bottom' => '',
				'left'   => '',
				'unit'   => 'px',
			);

			$footer_copyright_margin = get_theme_mod( 'zakra_footer_copyright_margin', $footer_copyright_margin_default );

			$parse_builder_css .= zakra_parse_dimension_css(
				$footer_copyright_margin_default,
				$footer_copyright_margin,
				'.zak-footer-builder .zak-copyright',
				'margin'
			);

			// Header builder site logo width.
			$header_site_logo_width_default = array(
				'size' => '',
				'unit' => 'px',
			);

			$header_site_logo_width = get_theme_mod( 'zakra_header_site_logo_height', $header_site_logo_width_default );

			$parse_builder_css .= zakra_parse_slider_css(
				$header_site_logo_width_default,
				$header_site_logo_width,
				'.zak-header-builder .site-branding .custom-logo-link img',
				'width'
			);

			// Header builder site title color.
			$header_site_title_color     = get_theme_mod( 'zakra_header_site_identity_color', '#16181a' );
			$header_site_title_color_css = array(
				'.zak-header-builder .site-title' => array(
					'color' => esc_html( $header_site_title_color ),
				),
			);
			$parse_builder_css            .= zakra_parse_css( '#16181a', $header_site_title_color, $header_site_title_color_css );

			// Header builder site title typography.
			$header_site_title_typography_default = array(
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
			);

			$header_site_title_typography = get_theme_mod( 'zakra_header_site_title_typography', $header_site_title_typography_default );

			$parse_builder_css .= zakra_parse_typography_css(
				$header_site_title_typography_default,
				$header_site_title_typography,
				'.zak-header-builder .site-title',
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			// Header builder site tagline color.
			$header_site_tagline_color     = get_theme_mod( 'zakra_header_site_tagline_color', '' );
			$header_site_tagline_color_css = array(
				'.zak-header-builder .site-description' => array(
					'color' => esc_html( $header_site_tagline_color ),
				),
			);
			$parse_builder_css            .= zakra_parse_css( '', $header_site_tagline_color, $header_site_tagline_color_css );

			// Header builder site tagline typography.
			$header_site_tagline_typography_default = array(
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
			);

			$header_site_tagline_typography = get_theme_mod( 'zakra_header_site_tagline_typography', $header_site_tagline_typography_default );

			$parse_builder_css .= zakra_parse_typography_css(
				$header_site_tagline_typography_default,
				$header_site_tagline_typography,
				'.zak-header-builder .site-description',
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			// Footer builder bottom area color.
			$footer_bottom_area_color     = get_theme_mod( 'zakra_footer_bottom_area_color', '' );
			$footer_bottom_area_color_css = array(
				'.zak-footer-builder .zak-footer-bottom-row' => array(
					'color' => esc_html( $footer_bottom_area_color ),
				),
			);
			$parse_builder_css            .= zakra_parse_css( '', $footer_bottom_area_color, $footer_bottom_area_color_css );

			// Footer builder top area color.
			$footer_top_area_color     = get_theme_mod( 'zakra_footer_top_area_color', '#FF0000' );
			$footer_top_area_color_css = array(
				'.zak-footer-builder .zak-footer-top-row' => array(
					'color' => esc_html( $footer_top_area_color ),
				),
			);
			$parse_builder_css            .= zakra_parse_css( '#FF0000', $footer_top_area_color, $footer_top_area_color_css );

			// Footer builder main area color.
			$footer_main_area_color     = get_theme_mod( 'zakra_footer_main_area_color', '' );
			$footer_main_area_color_css = array(
				'.zak-footer-builder .zak-footer-main-row' => array(
					'color' => esc_html( $footer_main_area_color ),
				),
			);
			$parse_builder_css            .= zakra_parse_css( '', $footer_main_area_color, $footer_main_area_color_css );

			// Footer builder main area link color.
			$footer_main_area_link_color     = get_theme_mod( 'zakra_footer_main_area_link_color', '' );
			$footer_main_area_link_color_css = array(
				'.zak-footer-builder .zak-footer-main-row a, .zak-footer-builder .zak-footer-main-row ul li a, .zak-footer-builder .zak-footer-main-row .widget ul li a' => array(
					'color' => esc_html( $footer_main_area_link_color ),
				),
			);
			$parse_builder_css            .= zakra_parse_css( '', $footer_main_area_link_color, $footer_main_area_link_color_css );

			// Footer builder main area link hover color.
			$footer_main_area_link_hover_color     = get_theme_mod( 'zakra_footer_main_area_link_hover_color', '' );
			$footer_main_area_link_hover_color_css = array(
				'.zak-footer-builder .zak-footer-main-row a, .zak-footer-builder .zak-footer-main-row ul li a:hover, .zak-footer-builder .zak-footer-main-row .widget ul li a:hover' => array(
					'color' => esc_html( $footer_main_area_link_hover_color ),
				),
			);
			$parse_builder_css            .= zakra_parse_css( '', $footer_main_area_link_hover_color, $footer_main_area_link_hover_color_css );

			// Header builder main border bottom.
			$is_header_transparent                  = zakra_is_header_transparent_enabled();
			$header_builder_main_border_bottom_css_selector = $is_header_transparent ? '.zak-header-builder.zak-layout-1-transparent .zak-header-transparent-wrapper' : '.zak-header-builder, .zak-header-sticky-wrapper .sticky-header';


			// Header builder border color.
			$header_builder_border_color     = get_theme_mod( 'zakra_header_builder_border_color', '' );
			$header_builder_border_color_css = array(
				$header_builder_main_border_bottom_css_selector => array(
					'border-color' => esc_html( $header_builder_border_color ),
				),
			);
			$parse_builder_css            .= zakra_parse_css( '', $header_builder_border_color, $header_builder_border_color_css );

			// Header builder border width.
			$header_builder_border_width_default = array(
				'top'    => '0',
				'right'  => '0',
				'bottom' => '1',
				'left'   => '0',
				'unit'   => 'px',
			);

			$header_builder_border_width = get_theme_mod( 'zakra_header_builder_border_width', $header_builder_border_width_default );

			$parse_builder_css .= zakra_parse_dimension_css(
				$header_builder_border_width_default,
				$header_builder_border_width,
				$header_builder_main_border_bottom_css_selector,
				'border-width'
			);

			// Header builder background.
			$header_builder_background_default = array(
				'background-color'      => '',
				'background-image'      => '',
				'background-position'   => 'center center',
				'background-size'       => 'auto',
				'background-attachment' => 'scroll',
				'background-repeat'     => 'repeat',
			);
			$header_builder_background         = get_theme_mod( 'zakra_header_builder_background', $header_builder_background_default );
			$parse_builder_css                           .= zakra_parse_background_css( $header_builder_background_default, $header_builder_background, '.zak-header-builder' );

			// Footer builder widget title content color.
			$footer_widget_main_area_title_color     = get_theme_mod( 'zakra_footer_main_area_widget_title_color', '' );
			$footer_widget_main_area_title_color_css = array(
				'.zak-footer-builder .zak-footer-main-row .widget-title, .zak-footer-builder .zak-footer-main-row h1, .zak-footer-builder .zak-footer-main-row h2, .zak-footer-builder .zak-footer-main-row h3, .zak-footer-builder .zak-footer-main-row h4, .zak-footer-builder .zak-footer-main-row h5, .zak-footer-builder .zak-footer-main-row h6' => array(
					'color' => esc_html( $footer_widget_main_area_title_color ),
				),
			);
			$parse_builder_css                               .= zakra_parse_css( '', $footer_widget_main_area_title_color, $footer_widget_main_area_title_color_css );

			// Footer builder widget item border.
			$footer_widgets_item_border_bottom_width_default = array(
				'size' => '',
				'unit' => 'px',
			);

			$footer_widgets_item_border_bottom_width = get_theme_mod( 'zakra_footer_widgets_item_border_bottom_width', $footer_widgets_item_border_bottom_width_default );

			$parse_builder_css .= zakra_parse_slider_css(
				$footer_widgets_item_border_bottom_width_default,
				$footer_widgets_item_border_bottom_width,
				'.zak-footer-builder .zak-footer-main-row ul li',
				'border-bottom-width'
			);

			// Footer builder widgets item border bottom color.
			$footer_widgets_item_border_bottom__color     = get_theme_mod( 'zakra_footer_widgets_item_border_bottom_color', '#e9ecef' );
			$footer_widgets_item_border_bottom__color_css = array(
				'.zak-footer-builder .zak-footer-main-row ul li' => array(
					'border-bottom-color' => esc_html( $footer_widgets_item_border_bottom__color ),
				),
			);
			$parse_builder_css                                    .= zakra_parse_css( '#e9ecef', $footer_widgets_item_border_bottom__color, $footer_widgets_item_border_bottom__color_css );

			// Footer builder widget 5 title color.
			$footer_widget_5_title_color     = get_theme_mod( 'zakra_footer_widget_5_title_color', '' );
			$footer_widget_5_title_color_css = array(
				'.zak-footer-builder .widget.widget-footer-bar-col-1-sidebar .widget-title' => array(
					'color' => esc_html( $footer_widget_5_title_color ),
				),
			);
			$parse_builder_css                               .= zakra_parse_css( '', $footer_widget_5_title_color, $footer_widget_5_title_color_css );

			// Footer builder widget 5 content color.
			$footer_widget_5_content_color     = get_theme_mod( 'zakra_footer_widget_5_content_color', '' );
			$footer_widget_5_content_color_css = array(
				'.zak-footer-builder .widget.widget-footer-bar-col-1-sidebar' => array(
					'color' => esc_html( $footer_widget_5_content_color ),
				),
			);
			$parse_builder_css                               .= zakra_parse_css( '', $footer_widget_5_content_color, $footer_widget_5_content_color_css );

			// Footer builder widget 5 link color.
			$footer_widget_5_link_color     = get_theme_mod( 'zakra_footer_widget_5_link_color', '' );
			$footer_widget_5_link_color_css = array(
				'.zak-footer-builder .widget.widget-footer-bar-col-1-sidebar a' => array(
					'color' => esc_html( $footer_widget_5_link_color ),
				),
			);
			$parse_builder_css                               .= zakra_parse_css( '', $footer_widget_5_link_color, $footer_widget_5_link_color_css );

			// Footer builder widget 5 title typography.
			$footer_widget_5_title_typography_default = array(
				'font-family'    => 'Default',
				'font-weight'    => '400',
				'font-size'      => array(
					'desktop' => array(
						'size' => '5',
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
			);
			$footer_widget_5_title_typography = get_theme_mod( 'zakra_footer_widget_5_title_typography', $footer_widget_5_title_typography_default );
			$parse_builder_css .= zakra_parse_typography_css(
				$footer_widget_5_title_typography_default,
				$footer_widget_5_title_typography,
				'.zak-footer-builder .widget.widget-footer-bar-col-1-sidebar .widget-title',
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			// Footer builder widget 5 content typography.
			$footer_widget_5_content_typography_default = array(
				'font-family'    => 'Default',
				'font-weight'    => '400',
				'font-size'      => array(
					'desktop' => array(
						'size' => '5',
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
			);
			$footer_widget_5_content_typography = get_theme_mod( 'zakra_footer_widget_5_content_typography', $footer_widget_5_content_typography_default );
			$parse_builder_css .= zakra_parse_typography_css(
				$footer_widget_5_content_typography_default,
				$footer_widget_5_content_typography,
				'.zak-footer-builder .widget.widget-footer-bar-col-1-sidebar',
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			// Footer builder widget 6 title color.
			$footer_widget_6_title_color     = get_theme_mod( 'zakra_footer_widget_6_title_color', '' );
			$footer_widget_6_title_color_css = array(
				'.zak-footer-builder .widget.widget-footer-bar-col-2-sidebar .widget-title' => array(
					'color' => esc_html( $footer_widget_6_title_color ),
				),
			);
			$parse_builder_css                               .= zakra_parse_css( '', $footer_widget_6_title_color, $footer_widget_6_title_color_css );

			// Footer builder widget 6 content color.
			$footer_widget_6_content_color     = get_theme_mod( 'zakra_footer_widget_6_content_color', '' );
			$footer_widget_6_content_color_css = array(
				'.zak-footer-builder .widget.widget-footer-bar-col-2-sidebar' => array(
					'color' => esc_html( $footer_widget_6_content_color ),
				),
			);
			$parse_builder_css                               .= zakra_parse_css( '', $footer_widget_6_content_color, $footer_widget_6_content_color_css );

			// Footer builder widget 6 link color.
			$footer_widget_6_link_color     = get_theme_mod( 'zakra_footer_widget_6_link_color', '' );
			$footer_widget_6_link_color_css = array(
				'.zak-footer-builder .widget.widget-footer-bar-col-2-sidebar a' => array(
					'color' => esc_html( $footer_widget_6_link_color ),
				),
			);
			$parse_builder_css                               .= zakra_parse_css( '', $footer_widget_6_link_color, $footer_widget_6_link_color_css );

			// Footer builder widget 6 title typography.
			$footer_widget_6_title_typography_default = array(
				'font-family'    => 'Default',
				'font-weight'    => '400',
				'font-size'      => array(
					'desktop' => array(
						'size' => '6',
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
			);
			$footer_widget_6_title_typography = get_theme_mod( 'zakra_footer_widget_6_title_typography', $footer_widget_6_title_typography_default );
			$parse_builder_css .= zakra_parse_typography_css(
				$footer_widget_6_title_typography_default,
				$footer_widget_6_title_typography,
				'.zak-footer-builder .widget.widget-footer-bar-col-2-sidebar .widget-title',
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			// Footer builder widget 6 content typography.
			$footer_widget_6_content_typography_default = array(
				'font-family'    => 'Default',
				'font-weight'    => '400',
				'font-size'      => array(
					'desktop' => array(
						'size' => '6',
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
			);
			$footer_widget_6_content_typography = get_theme_mod( 'zakra_footer_widget_6_content_typography', $footer_widget_6_content_typography_default );
			$parse_builder_css .= zakra_parse_typography_css(
				$footer_widget_6_content_typography_default,
				$footer_widget_6_content_typography,
				'.zak-footer-builder .widget.widget-footer-bar-col-2-sidebar',
				array(
					'tablet' => 768,
					'mobile' => 600,
				)
			);

			// Header builder mobile menu typography.
			$header_builder_mobile_menu_typography_default = array(
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
			);

			$header_builder_mobile_menu_typography = get_theme_mod( 'zakra_header_mobile_menu_typography', $header_builder_mobile_menu_typography_default );

			$parse_builder_css .= zakra_parse_typography_css(
				$header_builder_mobile_menu_typography_default,
				$header_builder_mobile_menu_typography,
				'.zak-header-builder .zak-mobile-menu a',
				array(
					'mobile' => 600,
					'tablet' => 768,
				)
			);

			// Footer builder area cols.
			$footer_builder_top_col = get_theme_mod('zakra_footer_top_area_cols', 4);
			$footer_builder_main_col = get_theme_mod('zakra_footer_main_area_cols', 4);
			$footer_builder_bottom_col = get_theme_mod('zakra_footer_bottom_area_cols', 1);
			$parse_builder_css .= ":root{--top-grid-columns: {$footer_builder_top_col};
			--main-grid-columns: {$footer_builder_main_col};
			--bottom-grid-columns: {$footer_builder_bottom_col};
			}";

			if ( 1 == $footer_builder_top_col ){
				$parse_builder_css .= " .zak-footer-builder .zak-top-row{justify-items: center;} ";
				$parse_builder_css .= " .zak-footer-builder .zak-top-row .zak-footer-top-1-col{ display: block;} ";
			} elseif ( 1 === $footer_builder_main_col ) {
				$parse_builder_css .= " .zak-footer-builder .zak-main-row{justify-items: center;} ";
			} elseif ( 1 === $footer_builder_bottom_col ) {
				$parse_builder_css .= " .zak-footer-builder .zak-bottom-row{justify-items: center;} ";
			}

			// Header cart color.
			$header_cart_color     = get_theme_mod( 'zakra_cart_color', '' );
			$header_cart_color_css = array(
				'.zak-header-builder .zakra-icon--cart,.zak-mini-cart .zak-icon' => array(
					'fill' => esc_html( $header_cart_color ),
				),
			);
			$parse_builder_css                               .= zakra_parse_css( '', $header_cart_color, $header_cart_color_css );

			// Footer widgets title color.
			$footer_widgets_title_color     = get_theme_mod( 'zakra_footer_widgets_title_color', '' );
			$footer_widgets_title_color_css = array(
				'.zak-footer-builder .zak-footer-main-row .widget-title, .zak-footer-builder .zak-footer-main-row h1, .zak-footer-builder .zak-footer-main-row h2, .zak-footer-builder .zak-footer-main-row h3, .zak-footer-builder .zak-footer-main-row h4, .zak-footer-builder .zak-footer-main-row h5, .zak-footer-builder .zak-footer-main-row h6' => array(
					'color' => esc_html( $footer_widgets_title_color ),
				),
			);
			$parse_builder_css                      .= zakra_parse_css( '', $footer_widgets_title_color, $footer_widgets_title_color_css );

			// Footer bottom layout alignment.
			$footer_builder_bottom_layout = get_theme_mod('zakra_footer_bottom_inner_element_layout', 'column');

			if ( ! empty( $footer_builder_bottom_layout ) ) {
				$parse_builder_css .= ".zak-footer-builder .zak-footer-bottom-row .zak-footer-col{flex-direction: $footer_builder_bottom_layout;}";
			}

			// Footer main layout alignment.
			$footer_builder_main_layout = get_theme_mod('zakra_footer_main_inner_element_layout', 'column');

			if ( ! empty( $footer_builder_main_layout ) ) {
				$parse_builder_css .= ".zak-footer-builder .zak-footer-main-row .zak-footer-col{flex-direction: $footer_builder_main_layout;}";
			}

			// Footer top layout alignment.
			$footer_builder_top_layout = get_theme_mod('zakra_footer_top_inner_element_layout', 'column');

			if ( ! empty( $footer_builder_top_layout ) ) {
				$parse_builder_css .= ".zak-footer-builder .zak-footer-top-row .zak-footer-col{flex-direction: $footer_builder_top_layout;}";
			}

			// Copyright alignment.
			$copyright_alignment = get_theme_mod('zakra_copyright_alignment', 'center');

			$parse_builder_css .= ".zak-footer-builder .zak-copyright{text-align: $copyright_alignment;}";

			// Footer menu alignment.
			$footer_menu_alignment = get_theme_mod('zakra_footer_menu_alignment', 'center');

			$parse_builder_css .= ".zak-footer-builder .zak-footer-nav{display: flex; justify-content: $footer_menu_alignment;}";

			// Footer menu 2 alignment.
			$footer_menu_alignment_2 = get_theme_mod('zakra_footer_menu_2_alignment', 'center');

			$parse_builder_css .= ".zak-footer-builder .zak-footer-nav-2{display: flex; justify-content: $footer_menu_alignment_2;}";

			// Html alignment.
			$html_alignment = get_theme_mod('zakra_html_1_alignment', 'center');

			$parse_builder_css .= ".zak-footer-builder .zak-html-1{text-align: $html_alignment;}";

			// Html 2 alignment.
			$html_alignment_2 = get_theme_mod('zakra_html_2_alignment', 'center');

			$parse_builder_css .= ".zak-footer-builder .zak-html-2{text-align: $html_alignment_2;}";

			// Social alignment.
			$social_alignment = get_theme_mod('zakra_socials_alignment', '');

			$parse_builder_css .= ".zak-footer-builder .footer-social-icons{text-align: $social_alignment;}";

			// Widget alignment.
			$widget_1_alignment = get_theme_mod('zakra_footer_widget_1_alignment', '');

			$parse_builder_css .= ".zak-footer-builder .widget-footer-sidebar-1{text-align: $widget_1_alignment;}";

			// Widget 2 alignment.
			$widget_2_alignment = get_theme_mod('zakra_footer_widget_2_alignment', '');

			$parse_builder_css .= ".zak-footer-builder .widget-footer-sidebar-2{text-align: $widget_2_alignment;}";

			// Widget 3 alignment.
			$widget_3_alignment = get_theme_mod('zakra_footer_widget_3_alignment', '');

			$parse_builder_css .= ".zak-footer-builder .widget-footer-sidebar-3{text-align: $widget_3_alignment;}";

			// Widget 4 alignment.
			$widget_4_alignment = get_theme_mod('zakra_footer_widget_4_alignment', '');

			$parse_builder_css .= ".zak-footer-builder .widget-footer-sidebar-4{text-align: $widget_4_alignment;}";

			// Widget 5 alignment.
			$widget_5_alignment = get_theme_mod('zakra_footer_widget_5_alignment', '');

			$parse_builder_css .= ".zak-footer-builder .widget-footer-bar-col-1-sidebar{text-align: $widget_5_alignment;}";

			// Widget 6 alignment.
			$widget_6_alignment = get_theme_mod('zakra_footer_widget_6_alignment', '');

			$parse_builder_css .= ".zak-footer-builder .widget-footer-bar-col-2-sidebar{text-align: $widget_6_alignment;}";

			$parse_builder_css .= $dynamic_css;

			return apply_filters( 'zakra_theme_builder_dynamic_css', $parse_builder_css );
		}

	}
}
