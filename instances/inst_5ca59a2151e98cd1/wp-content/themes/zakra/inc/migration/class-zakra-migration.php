<?php
/**
 * Migrate options on theme updates.
 *
 * @package zakra
 *
 * @since 3.0.0
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Zakra_Migration' ) ) {

	/**
	 * Zakra_Migration class.
	 *
	 */
	class Zakra_Migration {
		/**
		 * @var array|false
		 */
		private $old_theme_mods;

		public function __construct() {

			if ( self::maybe_run_migration() || self::demo_import_migration() ) {
				/**
				 * Zakra revamp migrations.
				 */
				$this->old_theme_mods = get_theme_mods();

				add_action( 'after_setup_theme', [ $this, 'customizer_migration_v3' ], 20 );
			}

			add_action( 'themegrill_ajax_demo_imported', [ $this, 'zakra_builder_migration' ], 25 );

			$enable_builder = get_theme_mod( 'zakra_enable_builder', '' );

			add_action( 'zakra_theme_review_notice_set_time', [ $this, 'zakra_sidebar_full_width' ] );

			if ( $enable_builder ) {
				add_action( 'after_setup_theme', [ $this, 'zakra_builder_migration' ], 25 );
			}

			add_action( 'after_setup_theme', [ $this, 'zakra_outside_background_migration' ], 25 );
			// add_action( 'after_setup_theme', [ $this, 'zakra_builder_rollback' ], 25 );
		}

		public function zakra_sidebar_full_width() {

			if ( get_option( 'zakra_sidebar_layout_migration' ) ) {
				return;
			}

				set_theme_mod( 'zakra_page_sidebar_layout', 'contained' );
				set_theme_mod( 'zakra_archive_sidebar_layout', 'contained' );
				update_option( 'zakra_sidebar_layout_migration', true );

		}

		/**
		 * Migrate customizer options.
		 *
		 * @package Zakra
		 *
		 * @since 3.0.0
		 */
		public function customizer_migration_v3() {

			/**
			 * Revamp migration.
			 */
			// Site identity and tagline display.
			if ( 'blank' === get_theme_mod( 'header_textcolor' ) ) {

				set_theme_mod( 'zakra_enable_site_identity', false );
				set_theme_mod( 'zakra_enable_site_tagline', false );
			}

			// Site identity and tagline color.
			$header_text_color = get_theme_mod( 'header_textcolor' );

			if ( $header_text_color ) {

				set_theme_mod( 'zakra_site_identity_color', '#' . $header_text_color );
				remove_theme_mod( 'header_textcolor' );
			}

			$normal_options = [
				[
					'old_key' => 'zakra_base_color_primary',
					'new_key' => 'zakra_primary_color',
				],
				[
					'old_key' => 'zakra_base_color_text',
					'new_key' => 'zakra_base_color',
				],
				[
					'old_key' => 'zakra_base_color_border',
					'new_key' => 'zakra_border_color',
				],
				[
					'old_key' => 'zakra_button_text_color',
					'new_key' => 'zakra_button_color',
				],
				[
					'old_key' => 'zakra_button_text_hover_color',
					'new_key' => 'zakra_button_hover_color',
				],
				[
					'old_key' => 'zakra_button_bg_color',
					'new_key' => 'zakra_button_background_color',
				],
				[
					'old_key' => 'zakra_button_bg_hover_color',
					'new_key' => 'zakra_button_background_hover_color',
				],
				[
					'old_key' => 'zakra_header_top_text_color',
					'new_key' => 'zakra_top_bar_color',
				],
				[
					'old_key' => 'zakra_header_button_bg_color',
					'new_key' => 'zakra_header_button_background_color',
				],
				[
					'old_key' => 'zakra_header_button_bg_hover_color',
					'new_key' => 'zakra_header_button_background_hover_color',
				],
				[
					'old_key' => 'zakra_header_main_border_bottom_color',
					'new_key' => 'zakra_main_header_border_bottom_color',
				],
				[
					'old_key' => 'zakra_primary_menu_text_color',
					'new_key' => 'zakra_main_menu_color',
				],
				[
					'old_key' => 'zakra_primary_menu_text_hover_color',
					'new_key' => 'zakra_main_menu_hover_color',
				],
				[
					'old_key' => 'zakra_primary_menu_text_active_color',
					'new_key' => 'zakra_main_menu_active_color',
				],
				[
					'old_key' => 'zakra_page_title_enabled',
					'new_key' => 'zakra_page_title_position',
				],
				[
					'old_key' => 'zakra_breadcrumbs_seperator_color',
					'new_key' => 'zakra_breadcrumb_separator_color',
				],
				[
					'old_key' => 'zakra_post_content_archive_blog',
					'new_key' => 'zakra_blog_excerpt_type',
				],
				[
					'old_key' => 'zakra_footer_widgets_border_top_color',
					'new_key' => 'zakra_footer_column_border_top_color',
				],
				[
					'old_key' => 'zakra_footer_widgets_text_color',
					'new_key' => 'zakra_footer_column_widget_text_color',
				],
				[
					'old_key' => 'zakra_footer_widgets_link_color',
					'new_key' => 'zakra_footer_column_widget_link_color',
				],
				[
					'old_key' => 'zakra_footer_widgets_link_hover_color',
					'new_key' => 'zakra_footer_column_widget_link_hover_color',
				],
				[
					'old_key' => 'zakra_scroll_to_top_bg_color',
					'new_key' => 'zakra_scroll_to_top_background',
				],
				[
					'old_key' => 'zakra_scroll_to_top_bg_hover_color',
					'new_key' => 'zakra_scroll_to_top_hover_background',
				],
				[
					'old_key' => 'zakra_scroll_to_top_color',
					'new_key' => 'zakra_scroll_to_top_icon_color',
				],
				[
					'old_key' => 'zakra_header_top_left_content',
					'new_key' => 'zakra_top_bar_column_1_content_type',
				],
				[
					'old_key' => 'zakra_header_top_left_content_html',
					'new_key' => 'zakra_top_bar_column_1_html',
				],
				[
					'old_key' => 'zakra_header_top_left_content_menu',
					'new_key' => 'zakra_top_bar_column_1_menu',
				],
				[
					'old_key' => 'zakra_header_top_right_content',
					'new_key' => 'zakra_top_bar_column_2_content_type',
				],
				[
					'old_key' => 'zakra_header_top_right_content_html',
					'new_key' => 'zakra_top_bar_column_2_html',
				],
				[
					'old_key' => 'zakra_header_top_right_content_menu',
					'new_key' => 'zakra_top_bar_column_2_menu',
				],
				[
					'old_key' => 'zakra_footer_bar_section_one',
					'new_key' => 'zakra_footer_bar_column_1_content_type',
				],
				[
					'old_key' => 'zakra_footer_bar_section_one_html',
					'new_key' => 'zakra_footer_bar_column_1_html',
				],
				[
					'old_key' => 'zakra_footer_bar_section_one_menu',
					'new_key' => 'zakra_footer_bar_column_1_menu',
				],
				[
					'old_key' => 'zakra_footer_bar_section_two',
					'new_key' => 'zakra_footer_bar_column_2_content_type',
				],
				[
					'old_key' => 'zakra_footer_bar_section_two_html',
					'new_key' => 'zakra_footer_bar_column_2_html',
				],
				[
					'old_key' => 'zakra_footer_bar_section_two_menu',
					'new_key' => 'zakra_footer_bar_column_2_menu',
				],
				[
					'old_key' => 'zakra_header_button_text_color',
					'new_key' => 'zakra_header_button_color',
				],
				[
					'old_key' => 'zakra_header_button_text_hover_color',
					'new_key' => 'zakra_header_button_hover_color',
				],
				[
					'old_key' => 'zakra_scroll_to_top_hover_color',
					'new_key' => 'zakra_scroll_to_top_icon_hover_color',
				],

			];

			foreach ( $normal_options as $option ) {

				$old_value = get_theme_mod( $option['old_key'] );

				if ( $old_value ) {

					set_theme_mod( $option['new_key'], $old_value );
					remove_theme_mod( $option['old_key'] );
				}
			}

			// Enable migration.
			$enable_options = [
				[
					'old_key' => 'zakra_header_top_enabled',
					'new_key' => 'zakra_enable_top_bar',
					'default' => false,
				],
				[
					'old_key' => 'zakra_scroll_to_top_enabled',
					'new_key' => 'zakra_enable_scroll_to_top',
					'default' => true,
				],
				[
					'old_key' => 'zakra_enable_read_more_archive_blog',
					'new_key' => 'zakra_enable_blog_button',
					'default' => true,
				],
				[
					'old_key' => 'zakra_breadcrumbs_enabled',
					'new_key' => 'zakra_enable_breadcrumb',
					'default' => true,
				],
				[
					'old_key' => 'tg_header_menu_search_enabled',
					'new_key' => 'zakra_enable_header_search',
					'default' => true,
				],
			];

			foreach ( $enable_options as $option ) {

				if ( ! array_key_exists( $option['old_key'], $this->old_theme_mods ) ) {
					continue;
				}

				$old_value = get_theme_mod( $option['old_key'], $option['default'] );

				set_theme_mod( $option['new_key'], $old_value );

				remove_theme_mod( $option['old_key'] );
			}

			// Footer widgets.
			$footer_widgets = get_theme_mod( 'zakra_footer_widgets_enabled', true );

			if ( $footer_widgets ) {
				set_theme_mod( 'zakra_enable_footer_column', true );
				remove_theme_mod( 'zakra_footer_widgets_enabled' );
			} else {
				set_theme_mod( 'zakra_enable_footer_column', false );
				remove_theme_mod( 'zakra_footer_widgets_enabled' );
			}

			// Primary menu enable
			$primary_menu = get_theme_mod( 'zakra_primary_menu_disabled' );

			if ( ! empty( $primary_menu ) ) {
				set_theme_mod( 'zakra_enable_primary_menu', false );
			} else {
				set_theme_mod( 'zakra_enable_primary_menu', true );
			}

			// Widget title enable
			$widget_title_enable = get_theme_mod( 'zakra_footer_widgets_hide_title' );

			if ( ! empty( $widget_title_enable ) ) {

				set_theme_mod( 'zakra_enable_footer_widgets_title', false );
				remove_theme_mod( 'zakra_footer_widgets_hide_title' );
			} else {

				set_theme_mod( 'zakra_enable_footer_widgets_title', true );
				remove_theme_mod( 'zakra_footer_widgets_hide_title' );
			}

			// Header button.
			$header_button_text = get_theme_mod( 'zakra_header_button_text' );

			if ( $header_button_text ) {

				set_theme_mod( 'zakra_enable_header_button', true );
			}

			// Container layout.
			$container_layout = get_theme_mod( 'zakra_general_container_style', 'wide' );

			if ( $container_layout ) {

				switch ( $container_layout ) {
					case 'tg-container--wide':
						$container_layout_new = 'wide';
						break;
					case 'tg-container--boxed':
						$container_layout_new = 'boxed';
						break;
					case 'tg-container--separate':
						$container_layout_new = 'wide';
						set_theme_mod( 'zakra_content_area_layout', 'boxed' );
						break;
					default:
						$container_layout_new = 'wide';
				}

				set_theme_mod( 'zakra_container_layout', $container_layout_new );
				remove_theme_mod( 'zakra_general_container_style' );
			}

			// Slider control migration.
			$slider_options = [
				[
					'old_key' => 'zakra_general_container_width',
					'new_key' => 'zakra_container_width',
					'default' => [
						'size' => 1170,
						'unit' => 'px',
					],
				],
				[
					'old_key' => 'zakra_general_sidebar_width',
					'new_key' => 'zakra_sidebar_width',
					'default' => [
						'size' => 30,
						'unit' => '%',
					],
				],
				[
					'old_key' => 'zakra_header_button_roundness',
					'new_key' => 'zakra_header_button_border_radius',
					'default' => [
						'size' => '',
						'unit' => 'px',
					],
				],
				[
					'old_key' => 'zakra_header_main_border_bottom_width',
					'new_key' => 'zakra_main_header_border_bottom_width',
					'default' => [
						'size' => 1,
						'unit' => 'px',
					],
				],
				[
					'old_key' => 'zakra_footer_widgets_border_top_width',
					'new_key' => 'zakra_footer_column_border_top_width',
					'default' => [
						'size' => '',
						'unit' => 'px',
					],
				],
				[
					'old_key' => 'zakra_button_roundness',
					'new_key' => 'zakra_button_border_radius',
					'default' => [
						'size' => '',
						'unit' => 'px',
					],
				],
				[
					'old_key' => 'zakra_primary_menu_border_bottom_width',
					'new_key' => 'zakra_primary_menu_border_bottom_width',
					'default' => [
						'size' => '',
						'unit' => 'px',
					],
				],
				[
					'old_key' => 'zakra_footer_widgets_item_border_bottom_width',
					'new_key' => 'zakra_footer_widgets_item_border_bottom_width',
					'default' => [
						'size' => '',
						'unit' => 'px',
					],
				],
				[
					'old_key' => 'zakra_footer_bar_border_top_width',
					'new_key' => 'zakra_footer_bar_border_top_width',
					'default' => [
						'size' => 1,
						'unit' => 'px',
					],
				],
				[
					'old_key' => 'zakra_mobile_menu_breakpoint',
					'new_key' => 'zakra_mobile_menu_breakpoint',
					'default' => [
						'size' => 768,
						'unit' => 'px',
					],
				],
			];

			// Loop through the options and migrate their values.
			foreach ( $slider_options as $option ) {

				// Check if id exist in database or not.
				if ( ! array_key_exists( $option['old_key'], $this->old_theme_mods ) ) {
					continue;
				}

				$old_value = get_theme_mod( $option['old_key'] );

				// Check if the value is scalar.
				if ( ! is_scalar( $old_value ) ) {
					continue;
				}

				if ( isset( $old_value ) ) {

					set_theme_mod(
						$option['new_key'],
						[
							'size' => $old_value,
							'unit' => $option['default']['unit'],
						]
					);

					if ( $option['old_key'] !== $option['new_key'] ) {

						remove_theme_mod( $option['old_key'] );
					}
				}
			}

			// Extract size and unit from the value.
			$typography_converted_value = function ( $value ) {
				$unit_list = [ 'px', 'em', 'rem', '%', '-', 'vw', 'vh', 'pt', 'pc', '' ];

				if ( ! $value ) {
					return [
						'size' => '',
						'unit' => '',
					];
				}

				preg_match( '/^(\d+(?:\.\d+)?)(.*)$/', $value, $matches );

				$size = isset( $matches[1] ) ? (float) $matches[1] : '';
				$unit = isset( $matches[2] ) ? $matches[2] : '';

				if ( 'rem' === $unit ) {

					$size = $size * ( 14.4 / 10 );
				}

				if ( ! in_array( $unit, $unit_list ) ) {

					$unit = 'px';
				}

				$validUnits = [ 'px', 'em', 'rem' ];

				if ( ! in_array( $unit, $validUnits ) ) {

					switch ( $unit ) {
						case 'pc':
							$size *= 16;
							$unit  = 'px';
							break;
						case 'vw':
							$size *= 19.2;
							$unit  = 'px';
							break;
						case 'vh':
							$size *= 10.8;
							$unit  = 'px';
							break;
						case '%':
							$size *= 1.6;
							$unit  = 'px';
							break;
						case 'pt':
							$size *= 1.333;
							$unit  = 'px';
							break;
						default:
							break;
					}
				}

				return [
					'size' => $size,
					'unit' => $unit,
				];
			};

			$dimension_converted_value = function ( $value ) {
				$unit_list = [ 'px', 'em', 'rem', '%', '-', 'vw', 'vh', 'pt', 'pc' ];

				if ( ! $value ) {
					return [
						'size' => '',
						'unit' => 'px',
					];
				}

				preg_match( '/^(\d+(?:\.\d+)?)(.*)$/', $value, $matches );

				$size = isset( $matches[1] ) ? (float) $matches[1] : 0;
				$unit = isset( $matches[2] ) ? $matches[2] : '';

				if ( ! in_array( $unit, $unit_list ) ) {

					$unit = 'px';
				}

				if ( 'px' !== $unit ) {

					switch ( $unit ) {
						case 'em':
						case 'pc':
						case 'rem':
							$size *= 14.4;
							$unit  = 'px';
							break;
						case 'vw':
							$size *= 19.2;
							$unit  = 'px';
							break;
						case 'vh':
							$size *= 10.8;
							$unit  = 'px';
							break;
						case '%':
							$size *= 1.6;
							$unit  = 'px';
							break;
						case 'pt':
							$size *= 1.333;
							$unit  = 'px';
							break;
						default:
							break;
					}
				}

				return [
					'size' => $size,
					'unit' => $unit,
				];
			};

			// Migrate the typography options.
			$typography_options = [
				[
					'old_key' => 'zakra_base_typography_body',
					'new_key' => 'zakra_body_typography',
				],
				[
					'old_key' => 'zakra_base_typography_heading',
					'new_key' => 'zakra_heading_typography',
				],
				[
					'old_key' => 'zakra_typography_h1',
					'new_key' => 'zakra_h1_typography',
				],
				[
					'old_key' => 'zakra_typography_h2',
					'new_key' => 'zakra_h2_typography',
				],
				[
					'old_key' => 'zakra_typography_h3',
					'new_key' => 'zakra_h3_typography',
				],
				[
					'old_key' => 'zakra_typography_h4',
					'new_key' => 'zakra_h4_typography',
				],
				[
					'old_key' => 'zakra_typography_h5',
					'new_key' => 'zakra_h5_typography',
				],
				[
					'old_key' => 'zakra_typography_h6',
					'new_key' => 'zakra_h6_typography',
				],
				[
					'old_key' => 'zakra_typography_site_title',
					'new_key' => 'zakra_site_title_typography',
				],
				[
					'old_key' => 'zakra_typography_site_description',
					'new_key' => 'zakra_site_tagline_typography',
				],
				[
					'old_key' => 'zakra_typography_primary_menu',
					'new_key' => 'zakra_main_menu_typography',
				],
				[
					'old_key' => 'zakra_typography_primary_menu_dropdown_item',
					'new_key' => 'zakra_sub_menu_typography',
				],
				[
					'old_key' => 'zakra_typography_mobile_menu',
					'new_key' => 'zakra_mobile_menu_typography',
				],
				[
					'old_key' => 'zakra_typography_post_page_title',
					'new_key' => 'zakra_post_page_title_typography',
				],
				[
					'old_key' => 'zakra_typography_blog_post_title',
					'new_key' => 'zakra_blog_post_title_typography',
				],
				[
					'old_key' => 'zakra_typography_widget_heading',
					'new_key' => 'zakra_widget_title_typography',
				],
				[
					'old_key' => 'zakra_typography_widget_content',
					'new_key' => 'zakra_widget_content_typography',
				],
			];

			foreach ( $typography_options as $option ) {

				$old_value = get_theme_mod( $option['old_key'] );

				if ( $old_value ) {

					$new_desktop_font = isset( $old_value['font-size']['desktop'] ) ? $typography_converted_value( $old_value['font-size']['desktop'] ) : [
						'size' => '',
						'unit' => 'px',
					];

					$new_tablet_font = isset( $old_value['font-size']['tablet'] ) ? $typography_converted_value( $old_value['font-size']['tablet'] ) : [
						'size' => '',
						'unit' => 'px',
					];

					$new_mobile_font = isset( $old_value['font-size']['mobile'] ) ? $typography_converted_value( $old_value['font-size']['mobile'] ) : [
						'size' => '',
						'unit' => 'px',
					];

					$new_desktop_line_height = isset( $old_value['line-height']['desktop'] ) ? $typography_converted_value( $old_value['line-height']['desktop'] ) : [
						'size' => '',
						'unit' => '-',
					];

					if ( empty( $new_desktop_line_height['unit'] ) ) {

						$new_desktop_line_height['unit'] = '-';
					}

					$new_tablet_line_height = isset( $old_value['line-height']['tablet'] ) ? $typography_converted_value( $old_value['line-height']['tablet'] ) : [
						'size' => '',
						'unit' => '-',
					];

					if ( empty( $new_tablet_line_height['unit'] ) ) {

						$new_tablet_line_height['unit'] = '-';
					}

					$new_mobile_line_height = isset( $old_value['line-height']['mobile'] ) ? $typography_converted_value( $old_value['line-height']['mobile'] ) : [
						'size' => '',
						'unit' => '-',
					];

					if ( empty( $new_mobile_line_height['unit'] ) ) {

						$new_mobile_line_height['unit'] = '-';
					}

					$new_desktop_letter_spacing = isset( $old_value['letter-spacing']['desktop'] ) ? $typography_converted_value( $old_value['letter-spacing']['desktop'] ) : [
						'size' => '',
						'unit' => 'px',
					];

					$new_tablet_letter_spacing = isset( $old_value['letter-spacing']['tablet'] ) ? $typography_converted_value( $old_value['letter-spacing']['tablet'] ) : [
						'size' => '',
						'unit' => 'px',
					];

					$new_mobile_letter_spacing = isset( $old_value['letter-spacing']['mobile'] ) ? $typography_converted_value( $old_value['letter-spacing']['mobile'] ) : [
						'size' => '',
						'unit' => 'px',
					];

					$new_value = [
						'font-family'    => isset( $old_value['font-family'] ) ? $old_value['font-family'] : '',
						'font-weight'    => isset( $old_value['font-weight'] ) ? $old_value['font-weight'] : '',
						'subsets'        => isset( $old_value['subsets'] ) ? $old_value['subsets'] : '',
						'font-size'      => [
							'desktop' => [
								'size' => $new_desktop_font['size'],
								'unit' => $new_desktop_font['unit'],
							],
							'tablet'  => [
								'size' => $new_tablet_font['size'],
								'unit' => $new_tablet_font['unit'],
							],
							'mobile'  => [
								'size' => $new_mobile_font['size'],
								'unit' => $new_mobile_font['unit'],
							],
						],
						'line-height'    => [
							'desktop' => [
								'size' => $new_desktop_line_height['size'],
								'unit' => $new_desktop_line_height['unit'],
							],
							'tablet'  => [
								'size' => $new_tablet_line_height['size'],
								'unit' => $new_tablet_line_height['unit'],
							],
							'mobile'  => [
								'size' => $new_mobile_line_height['size'],
								'unit' => $new_mobile_line_height['unit'],
							],
						],
						'letter-spacing' => [
							'desktop' => [
								'size' => $new_desktop_letter_spacing['size'],
								'unit' => $new_desktop_letter_spacing['unit'],
							],
							'tablet'  => [
								'size' => $new_tablet_letter_spacing['size'],
								'unit' => $new_tablet_letter_spacing['unit'],
							],
							'mobile'  => [
								'size' => $new_mobile_letter_spacing['size'],
								'unit' => $new_mobile_letter_spacing['unit'],
							],
						],
						'font-style'     => isset( $old_value['font-style'] ) ? $old_value['font-style'] : '',
						'text-transform' => isset( $old_value['text-transform'] ) ? $old_value['text-transform'] : '',
					];

					set_theme_mod( $option['new_key'], $new_value );
					remove_theme_mod( $option['old_key'] );
				}
			}

			// Breadcrumb typography.
			$breadcrumb_typography = get_theme_mod( 'zakra_breadcrumbs_font_size' );

			if ( $breadcrumb_typography ) {

				$new_value = [
					'font-family' => '',
					'font-weight' => '',
					'font-size'   => [
						'desktop' => [
							'size' => $breadcrumb_typography,
							'unit' => 'px',
						],
						'tablet'  => [
							'size' => '',
							'unit' => '',
						],
						'mobile'  => [
							'size' => '',
							'unit' => '',
						],
					],
				];

				set_theme_mod( 'zakra_breadcrumb_typography', $new_value );
				remove_theme_mod( 'zakra_breadcrumbs_font_size' );
			}

			// Background migration.
			$background_option = [
				[
					'old_key' => 'zakra_header_top_bg',
					'new_key' => 'zakra_top_bar_background',
				],
				[
					'old_key' => 'zakra_header_main_bg',
					'new_key' => 'zakra_main_header_background_color',
				],
				[
					'old_key' => 'zakra_page_title_bg',
					'new_key' => 'zakra_page_header_background',
				],
				[
					'old_key' => 'zakra_footer_widgets_bg',
					'new_key' => 'zakra_footer_column_background',
				],
				[
					'old_key' => 'zakra_footer_bar_bg',
					'new_key' => 'zakra_footer_bar_background',
				],
			];

			foreach ( $background_option as $option ) {

				$old_value = get_theme_mod( $option['old_key'] );

				if ( $old_value ) {

					$new_background_value = [
						'background-color'      => isset( $old_value['background-color'] ) ? $old_value['background-color'] : '',
						'background-image'      => isset( $old_value['background-image'] ) ? $old_value['background-image'] : '',
						'background-repeat'     => isset( $old_value['background-repeat'] ) ? $old_value['background-repeat'] : '',
						'background-position'   => isset( $old_value['background-position'] ) ? $old_value['background-position'] : '',
						'background-size'       => isset( $old_value['background-size'] ) ? $old_value['background-size'] : '',
						'background-attachment' => isset( $old_value['background-attachment'] ) ? $old_value['background-attachment'] : '',
					];

					set_theme_mod( $option['new_key'], $new_background_value );
					remove_theme_mod( $option['old_key'] );
				}
			}

			// Dimension control migration.
			$dimension_option = [
				[
					'old_key' => 'zakra_page_title_padding',
					'new_key' => 'zakra_page_header_padding',
				],
				[
					'old_key' => 'zakra_header_button_padding',
					'new_key' => 'zakra_header_button_padding',
				],
				[
					'old_key' => 'zakra_button_padding',
					'new_key' => 'zakra_button_padding',
				],
			];

			foreach ( $dimension_option as $option ) {

				// Check if id exist in database or not.
				if ( ! array_key_exists( $option['old_key'], $this->old_theme_mods ) ) {
					continue;
				}

				$old_value = get_theme_mod( $option['old_key'] );

				// Check if the old value have unit key on or not.
				if ( false !== strpos( wp_json_encode( $old_value ), 'unit' ) ) {
					continue;
				}

				if ( $old_value ) {

					$new_top = isset( $old_value['top'] ) ? $dimension_converted_value( $old_value['top'] ) : [
						'size' => '',
						'unit' => 'px',
					];

					$new_right  = isset( $old_value['right'] ) ? $dimension_converted_value( $old_value['right'] ) : [
						'size' => '',
						'unit' => 'px',
					];
					$new_bottom = isset( $old_value['bottom'] ) ? $dimension_converted_value( $old_value['bottom'] ) : [
						'size' => '',
						'unit' => 'px',
					];
					$new_left   = isset( $old_value['left'] ) ? $dimension_converted_value( $old_value['left'] ) : [
						'size' => '',
						'unit' => 'px',
					];

					$new_value = [
						'top'    => $new_top['size'],
						'right'  => $new_right['size'],
						'bottom' => $new_bottom['size'],
						'left'   => $new_left['size'],
						'unit'   => $new_top['unit'],
					];

					set_theme_mod( $option['new_key'], $new_value );

					if ( $option['old_key'] !== $option['new_key'] ) {

						remove_theme_mod( $option['old_key'] );
					}
				}
			}

			// Sidebar layout migration.
			$sidebar_layout_option = [
				[
					'old_key' => 'zakra_structure_archive',
					'new_key' => 'zakra_archive_sidebar_layout',
				],
				[
					'old_key' => 'zakra_structure_post',
					'new_key' => 'zakra_post_sidebar_layout',
				],
				[
					'old_key' => 'zakra_structure_page',
					'new_key' => 'zakra_page_sidebar_layout',
				],
				[
					'old_key' => 'zakra_structure_default',
					'new_key' => 'zakra_others_sidebar_layout',
				],

			];

			foreach ( $sidebar_layout_option as $option ) {

				$old_value = get_theme_mod( $option['old_key'] );

				if ( $old_value ) {

					$new_value = '';

					if ( 'tg-site-layout--default' === $old_value ) {

						$new_value = 'centered';
					} elseif ( 'tg-site-layout--left' === $old_value ) {
						$new_value = 'left';
					} elseif ( 'tg-site-layout--right' === $old_value ) {

						$new_value = 'right';
					} elseif ( 'tg-site-layout--no-sidebar' === $old_value ) {

						$new_value = 'contained';
					} elseif ( 'tg-site-layout--stretched' === $old_value ) {

						$new_value = 'stretched';
					}

					if ( ! empty( $new_value ) ) {

						set_theme_mod( $option['new_key'], $new_value );
						remove_theme_mod( $option['old_key'] );
					}
				}
			}

			// Main header layout migration.
			$old_value = get_theme_mod( 'zakra_header_main_style' );

			if ( $old_value ) {

				$new_value = '';

				if ( 'tg-site-header--left' === $old_value ) {

					$new_value = 'style-1';
				} elseif ( 'tg-site-header--center' === $old_value ) {

					$new_value = 'style-2';
				} elseif ( 'tg-site-header--right' === $old_value ) {

					$new_value = 'style-3';
				}

				if ( ! empty( $new_value ) ) {

					set_theme_mod( 'zakra_main_header_layout', 'layout-1' );
					set_theme_mod( 'zakra_main_header_layout_1_style', $new_value );
					remove_theme_mod( 'zakra_header_main_style' );
				}
			}

			// Main menu active style migration.
			$old_menu_active_style = get_theme_mod( 'zakra_primary_menu_text_active_effect' );

			if ( $old_menu_active_style ) {

				if ( 'tg-primary-menu--style-none' === $old_menu_active_style ) {

					$new_menu_active_style = 'style-1';
				} elseif ( 'tg-primary-menu--style-underline' === $old_menu_active_style ) {

					$new_menu_active_style = 'style-2';
				} elseif ( 'tg-primary-menu--style-left-border' === $old_menu_active_style ) {

					$new_menu_active_style = 'style-3';
				} elseif ( 'tg-primary-menu--style-right-border' === $old_menu_active_style ) {

					$new_menu_active_style = 'style-4';
				} else {

					$new_menu_active_style = 'style-1';
				}

				set_theme_mod( 'zakra_main_menu_layout_1_style', $new_menu_active_style );
				remove_theme_mod( 'zakra_primary_menu_text_active_effect' );
			}

			// Page header layout migration.
			$old_page_header_layout = get_theme_mod( 'zakra_page_title_alignment' );

			if ( $old_page_header_layout ) {

				if ( 'tg-page-header--left-right' === $old_page_header_layout ) {

					$new_page_header_layout = 'style-1';
				} elseif ( 'tg-page-header--right-left' === $old_page_header_layout ) {

					$new_page_header_layout = 'style-2';
				} elseif ( 'tg-page-header--both-center' == $old_page_header_layout ) {

					$new_page_header_layout = 'style-3';
				} elseif ( 'tg-page-header--both-left' === $old_page_header_layout ) {

					$new_page_header_layout = 'style-4';
				} elseif ( 'tg-page-header--both-right' === $old_page_header_layout ) {

					$new_page_header_layout = 'style-5';
				} else {

					$new_page_header_layout = 'style-1';
				}

				set_theme_mod( 'zakra_page_header_layout', $new_page_header_layout );
				remove_theme_mod( 'zakra_page_title_alignment' );
			}

			// Post meta style migration.
			$old_post_meta_style = get_theme_mod( 'zakra_blog_archive_meta_style' );

			if ( $old_post_meta_style ) {

				if ( 'tg-meta-style-one' === $old_post_meta_style ) {

					$new_post_meta_style = 'style-1';
				} elseif ( 'zak-style-2' === $old_post_meta_style ) {

					$new_post_meta_style = 'style-2';
				} else {

					$new_post_meta_style = 'style-1';
				}

				set_theme_mod( 'zakra_post_meta_style', $new_post_meta_style );
				remove_theme_mod( 'zakra_blog_archive_meta_style' );
			}

			// Footer column advanced style.
			$old_footer_column_advance_style = get_theme_mod( 'zakra_footer_widgets_style' );

			if ( $old_footer_column_advance_style ) {

				$new_footer_column_advance_style = '';

				if ( 'tg-footer-widget-col--one' === $old_footer_column_advance_style ) {

					$new_footer_column_advance_style = 'style-1';
				} elseif ( 'tg-footer-widget-col--two' === $old_footer_column_advance_style ) {

					$new_footer_column_advance_style = 'style-2';
				} elseif ( 'tg-footer-widget-col--three' === $old_footer_column_advance_style ) {

					$new_footer_column_advance_style = 'style-3';
				} elseif ( 'tg-footer-widget-col--four' === $old_footer_column_advance_style ) {

					$new_footer_column_advance_style = 'style-4';
				}

				if ( ! empty( $new_footer_column_advance_style ) ) {

					set_theme_mod( 'zakra_footer_column_layout', 'layout-1' );
					set_theme_mod( 'zakra_footer_column_layout_1_style', $new_footer_column_advance_style );
					remove_theme_mod( 'zakra_footer_widgets_style' );
				}
			}

			// Blog button style.
			$old_blog_button_alignment = get_theme_mod( 'zakra_read_more_align_archive_blog' );

			if ( $old_blog_button_alignment ) {

				$new_blog_button_alignment = '';

				if ( 'left' === $old_blog_button_alignment ) {

					$new_blog_button_alignment = 'style-1';
				} elseif ( 'right' === $old_blog_button_alignment ) {

					$new_blog_button_alignment = 'style-2';
				}

				if ( ! empty( $new_blog_button_alignment ) ) {

					set_theme_mod( 'zakra_blog_button_alignment', $new_blog_button_alignment );
					remove_theme_mod( 'zakra_read_more_align_archive_blog' );
				}
			}

			// Blog post elements.
			$old_blog_post_elements = get_theme_mod( 'zakra_structure_archive_blog' );

			if ( $old_blog_post_elements ) {

				$new_blog_post_elements = [];
				$blog_post_elements     = [ 'featured_image', 'title', 'meta', 'content' ];

				foreach ( $blog_post_elements as $element ) {

					if ( in_array( $element, $old_blog_post_elements, true ) ) {

						$new_blog_post_elements[] = $element;
					}
				}

				set_theme_mod( 'zakra_blog_post_elements', $new_blog_post_elements );
				remove_theme_mod( 'zakra_structure_archive_blog' );
			}

			// Blog meta elements.
			$old_meta_elements = get_theme_mod( 'zakra_meta_structure_archive_blog' );

			if ( $old_meta_elements ) {

				$new_meta_elements = [];
				$meta_elements     = [ 'author', 'date', 'categories', 'tags', 'comments' ];

				foreach ( $meta_elements as $element ) {

					if ( in_array( $element, $old_meta_elements, true ) ) {

						$new_meta_elements[] = $element;
					}
				}

				set_theme_mod( 'zakra_blog_meta_elements', $new_meta_elements );
				remove_theme_mod( 'zakra_meta_structure_archive_blog' );
			}

			// Single post elements.
			$old_single_post_elements = get_theme_mod( 'zakra_single_post_content_structure' );

			if ( $old_single_post_elements ) {

				$new_single_post_elements = [];
				$single_post_elements     = [ 'featured_image', 'title', 'meta', 'content' ];

				foreach ( $single_post_elements as $element ) {

					if ( in_array( $element, $old_single_post_elements, true ) ) {

						$new_single_post_elements[] = $element;
					}
				}

				set_theme_mod( 'zakra_single_post_elements', $new_single_post_elements );
				remove_theme_mod( 'zakra_single_post_content_structure' );
			}

			// Single post meta elements.
			$old_single_meta_elements = get_theme_mod( 'zakra_single_blog_post_meta_structure' );

			if ( $old_single_meta_elements ) {

				$new_single_meta_elements = [];
				$single_meta_elements     = [ 'author', 'date', 'categories', 'tags', 'comments' ];

				foreach ( $single_meta_elements as $element ) {

					if ( in_array( $element, $old_single_meta_elements, true ) ) {

						$new_single_meta_elements[] = $element;
					}
				}

				set_theme_mod( 'zakra_single_meta_elements', $new_single_meta_elements );
				remove_theme_mod( 'zakra_single_blog_post_meta_structure' );
			}

			// Footer bar style
			$footer_bar_style = get_theme_mod( 'zakra_footer_bar_style', 'tg-site-footer-bar--center' );

			if ( $footer_bar_style ) {

				if ( 'tg-site-footer-bar--left' === $footer_bar_style ) {

					$new_style = 'style-1';
				} else {

					$new_style = 'style-2';
				}

				set_theme_mod( 'zakra_footer_bar_style', $new_style );

			}

			// Sidebar widgets.
			$map = [
				'header-top-left-sidebar'  => 'top-bar-col-1-sidebar',
				'header-top-right-sidebar' => 'top-bar-col-2-sidebar',
				'footer-bar-left-sidebar'  => 'footer-bar-col-1-sidebar',
				'footer-bar-right-sidebar' => 'footer-bar-col-2-sidebar',
			];

			$sidebarwidgets = get_option( 'sidebars_widgets' );

			foreach ( $map as $old => $new ) {

				if ( isset( $sidebarwidgets[ $old ] ) ) {

					$sidebarwidgets[ $new ] = $sidebarwidgets[ $old ];
					unset( $sidebarwidgets[ $old ] );
				}
			}

			// Post meta migration.
			$arg       = [
				'post_type'      => 'any',
				'posts_per_page' => - 1,
			];
			$the_query = new WP_Query( $arg );

			// The loop.
			while ( $the_query->have_posts() ) :
				$the_query->the_post();

				// Layout.
				$post_id                   = get_the_ID();
				$post_meta_style_old_value = get_post_meta( $post_id, 'zakra_layout', true );

				$post_meta_style_new_value = '';

				if ( 'tg-site-layout--default' === $post_meta_style_old_value ) {

					$post_meta_style_new_value = 'centered';
				} elseif ( 'tg-site-layout--left' === $post_meta_style_old_value ) {

					$post_meta_style_new_value = 'left';
				} elseif ( 'tg-site-layout--right' === $post_meta_style_old_value ) {

					$post_meta_style_new_value = 'right';
				} elseif ( 'tg-site-layout--no-sidebar' === $post_meta_style_old_value ) {

					$post_meta_style_new_value = 'contained';
				} elseif ( 'tg-site-layout--stretched' === $post_meta_style_old_value ) {

					$post_meta_style_new_value = 'stretched';
				} elseif ( 'tg-site-layout--customizer' === $post_meta_style_old_value ) {

					$post_meta_style_new_value = 'customizer';
				}

				if ( ! empty( $post_meta_style_new_value ) ) {

					add_post_meta( $post_id, 'zakra_sidebar_layout', $post_meta_style_new_value );
					delete_post_meta( $post_id, 'zakra_layout' );
				}

				// Header style.
				$post_meta_header_style = get_post_meta( get_the_ID(), 'zakra_header_style', true );

				$new_post_meta_header_style = '';
				if ( 'tg-site-header--left' === $post_meta_header_style ) {

					$new_post_meta_header_style = 'zak-layout-1-style-1';
				} elseif ( 'tg-site-header--center' === $post_meta_header_style ) {

					$new_post_meta_header_style = 'zak-layout-1-style-2';
				} elseif ( 'tg-site-header--right' === $post_meta_header_style ) {

					$new_post_meta_header_style = 'zak-layout-1-style-3';
				}

				if ( ! empty( $new_post_meta_header_style ) ) {

					add_post_meta( $post_id, 'zakra_main_header_style', $new_post_meta_header_style );
					delete_post_meta( $post_id, 'zakra_header_style' );
				}

				// Active menu item style.
				$post_meta_active_menu_item_style = get_post_meta( get_the_ID(), 'zakra_menu_item_active_style', true );

				if ( $post_meta_active_menu_item_style ) {

					if ( 'tg-primary-menu--style-none' === $post_meta_active_menu_item_style ) {

						$new_post_meta_active_menu_item_style = 'style-1';
					} elseif ( 'tg-primary-menu--style-underline' === $post_meta_active_menu_item_style ) {

						$new_post_meta_active_menu_item_style = 'style-2';
					} elseif ( 'tg-primary-menu--style-left-border' === $post_meta_active_menu_item_style ) {

						$new_post_meta_active_menu_item_style = 'style-3';
					} elseif ( 'tg-primary-menu--style-right-border' === $post_meta_active_menu_item_style ) {

						$new_post_meta_active_menu_item_style = 'style-4';
					} else {
						$new_post_meta_active_menu_item_style = 'style-1';
					}

					add_post_meta( $post_id, 'zakra_menu_active_style', $new_post_meta_active_menu_item_style );
					delete_post_meta( $post_id, 'zakra_menu_item_active_style' );
				}

				// Sidebar layout.
				$post_meta_sidebar_layout = get_post_meta( get_the_ID(), 'zakra_sidebar', true );

				if ( $post_meta_sidebar_layout ) {

					$new_slider_layout = '';

					if ( 'footer-sidebar-1' === $post_meta_sidebar_layout ) {

						$new_slider_layout = '1';
					} elseif ( 'footer-sidebar-2' === $post_meta_sidebar_layout ) {

						$new_slider_layout = '2';
					} elseif ( 'footer-sidebar-3' === $post_meta_sidebar_layout ) {

						$new_slider_layout = '3';
					} elseif ( 'footer-sidebar-4' === $post_meta_sidebar_layout ) {

						$new_slider_layout = '4';
					}

					if ( ! empty( $new_slider_layout ) ) {

						update_post_meta( $post_id, 'zakra_sidebar', $new_slider_layout );
					}
				}

			endwhile;

			// WooCommerce Sidebar layout migration.
			$wc_sidebar_layout_option = [
				[
					'old_key' => 'zakra_structure_wc',
					'new_key' => 'zakra_woocommerce_sidebar_layout',
				],
				[
					'old_key' => 'zakra_structure_wc_product',
					'new_key' => 'zakra_woocommerce_single_product_sidebar_layout',
				],

			];

			foreach ( $wc_sidebar_layout_option as $option ) {

				$old_value = get_theme_mod( $option['old_key'] );

				if ( $old_value ) {

					$new_value = '';

					if ( 'tg-site-layout--default' === $old_value ) {

						$new_value = 'centered';
					} elseif ( 'tg-site-layout--left' === $old_value ) {
						$new_value = 'left';
					} elseif ( 'tg-site-layout--right' === $old_value ) {

						$new_value = 'right';
					} elseif ( 'tg-site-layout--no-sidebar' === $old_value ) {

						$new_value = 'contained';
					} elseif ( 'tg-site-layout--stretched' === $old_value ) {

						$new_value = 'stretched';
					}

					if ( ! empty( $new_value ) ) {

						set_theme_mod( $option['new_key'], $new_value );
						remove_theme_mod( $option['old_key'] );
					}
				}
			}

			// Set flag not to repeat the migration process, run it only once.
			update_option( 'zakra_customizer_migration_v3', true );
		}

		/**
		 * Migrate customizer options.
		 *
		 * @package Zakra
		 *
		 * @since 3.0.0
		 */
		public function zakra_builder_migration() {

			if ( get_option( 'zakra_builder_migration' ) && ! doing_action( 'themegrill_ajax_demo_imported' ) ) {
				return;
			}

			if ( ( doing_action( 'themegrill_ajax_demo_imported' ) && get_theme_mod( 'demo_migrated_to_builder' ) ) || get_option( 'zakra_free_demo_migrated_to_builder' ) ) {
				update_option( 'zakra_free_demo_migrated_to_builder', true );
				return;
			}

			// Save old data.
			$theme_mods = get_theme_mods();

			update_option( 'zakra_customizer_before_builder_old_data', $theme_mods );

			$header_builder_config = [
				'desktop' => [
					'top'    => [
						'left'   => [],
						'center' => [],
						'right'  => [],
					],
					'main'   => [
						'left'   => [],
						'center' => [],
						'right'  => [],
					],
					'bottom' => [
						'left'   => [],
						'center' => [],
						'right'  => [],
					],
				],
				'mobile'  => [
					'top'    => [
						'left'   => [],
						'center' => [],
						'right'  => [],
					],
					'main'   => [
						'left'   => [ 'logo' ],
						'center' => [],
						'right'  => [ 'toggle-button' ],
					],
					'bottom' => [
						'left'   => [],
						'center' => [],
						'right'  => [],
					],
				],
				'offset'  => [],
			];

			// Top bar.
			$top_bar_enable = get_theme_mod( 'zakra_enable_top_bar', false );
			if ( $top_bar_enable ) {
				$column_layout_1 = get_theme_mod( 'zakra_top_bar_column_1_content_type', 'text_html' );
				$column_layout_2 = get_theme_mod( 'zakra_top_bar_column_2_content_type', 'text_html' );

				switch ( $column_layout_1 ) {
					case 'text_html':
						$column_layout_1_html = get_theme_mod( 'zakra_top_bar_column_1_html', '' );
						if ( $column_layout_1_html ) {
							set_theme_mod( 'zakra_header_html_1', $column_layout_1_html );
							remove_theme_mod( 'zakra_top_bar_column_1_html' );
						}
						$header_builder_config['desktop']['top']['left'] = [
							'html-1',
						];
						break;
					case 'menu':
						$column_layout_1_menu = get_theme_mod( 'zakra_top_bar_column_1_menu', '' );
						if ( $column_layout_1_menu ) {
							set_theme_mod( 'zakra_header_tertiary_menu', $column_layout_1_menu );
							$header_builder_config['desktop']['top']['left'] = [
								'tertiary-menu',
							];
						}
						break;
					case 'widget':
						$header_builder_config['desktop']['top']['left'] = [
							'widget-1',
						];
						break;
				}

				switch ( $column_layout_2 ) {
					case 'text_html':
						$column_layout_2_html = get_theme_mod( 'zakra_top_bar_column_2_html', '' );
						if ( $column_layout_2_html ) {
							set_theme_mod( 'zakra_header_html_2', $column_layout_2_html );
							remove_theme_mod( 'zakra_top_bar_column_2_html' );
						}
						$header_builder_config['desktop']['top']['right'] = [
							'html-2',
						];
						break;
					case 'menu':
						$column_layout_2_menu = get_theme_mod( 'zakra_top_bar_column_2_menu', '' );
						if ( $column_layout_2_menu ) {
							set_theme_mod( 'zakra_header_quaternary_menu', $column_layout_2_menu );
							$header_builder_config['desktop']['top']['right'] = [
								'quaternary-menu',
							];
						}
						break;
					case 'widget':
						$header_builder_config['desktop']['top']['right'] = [
							'widget-2',
						];
						break;
				}
			}

			// Main header.
			$main_header_layout   = get_theme_mod( 'zakra_main_header_layout', 'layout-1' );
			$primary_menu_enable  = get_theme_mod( 'zakra_enable_primary_menu', true );
			$header_search_enable = get_theme_mod( 'zakra_enable_header_search', true );
			$header_button_enable = get_theme_mod( 'zakra_enable_header_button', false );

			if ( 'layout-1' === $main_header_layout ) {
				$main_header_style = get_theme_mod( 'zakra_main_header_layout_1_style', 'style-1' );

				switch ( $main_header_style ) {
					case 'style-1':
						$header_builder_config['desktop']['main']['left'] = [
							'logo',
						];
						if ( $primary_menu_enable ) {
							$header_builder_config['desktop']['main']['right'][] = 'primary-menu';
						}
						if ( $header_search_enable ) {
							$header_builder_config['desktop']['main']['right'][] = 'search';
						}
						if ( class_exists( 'WooCommerce' ) ) {
							$header_builder_config['desktop']['main']['right'][] = 'cart';
						}
						if ( $header_button_enable ) {
							$header_builder_config['desktop']['main']['right'][] = 'button';
						}
						break;
					case 'style-2':
						$header_builder_config['desktop']['main']['center'] = [
							'logo',
						];
						if ( $primary_menu_enable ) {
							$header_builder_config['desktop']['bottom']['center'][] = 'primary-menu';
						}
						if ( $header_search_enable ) {
							$header_builder_config['desktop']['bottom']['center'][] = 'search';
						}
						if ( class_exists( 'WooCommerce' ) ) {
							$header_builder_config['desktop']['bottom']['center'][] = 'cart';
						}
						if ( $header_button_enable ) {
							$header_builder_config['desktop']['bottom']['center'][] = 'button';
						}
						break;
					case 'style-3':
						if ( $primary_menu_enable ) {
							$header_builder_config['desktop']['main']['left'][] = 'primary-menu';
						}
						if ( $header_search_enable ) {
							$header_builder_config['desktop']['main']['left'][] = 'search';
						}
						if ( class_exists( 'WooCommerce' ) ) {
							$header_builder_config['desktop']['main']['left'][] = 'cart';
						}
						if ( $header_button_enable ) {
							$header_builder_config['desktop']['main']['left'][] = 'button';
						}
						$header_builder_config['desktop']['main']['right'] = [
							'logo',
						];
						break;
				}
			} elseif ( 'layout-2' === $main_header_layout ) {
				$main_header_layout_2_style     = get_theme_mod( 'zakra_main_header_layout_2_style', 'style-1' );
				$menu_bg_default                = [
					'background-color'      => '#F4F4F5',
					'background-image'      => '',
					'background-repeat'     => 'repeat',
					'background-position'   => 'center center',
					'background-size'       => 'contain',
					'background-attachment' => 'scroll',
				];
				$bottom_area_border_width_value = [
					'top'    => '1',
					'right'  => '0',
					'bottom' => '0',
					'left'   => '0',
					'unit'   => 'px',
				];
				$menu_background                = get_theme_mod( 'zakra_main_header_menu_background_color', $menu_bg_default );
				set_theme_mod( 'zakra_header_bottom_area_background', $menu_background );
				set_theme_mod( 'zakra_header_bottom_area_border_width', $bottom_area_border_width_value );
				set_theme_mod( 'zakra_header_bottom_area_border_color', '#D4D4D8' );

				switch ( $main_header_layout_2_style ) {
					case 'style-1':
						$header_builder_config['desktop']['main']['left'] = [
							'logo',
						];
						if ( $primary_menu_enable ) {
							$header_builder_config['desktop']['bottom']['left'][] = 'primary-menu';
						}
						$header_builder_config['desktop']['bottom']['center'] = [];
						if ( $header_search_enable ) {
							$header_builder_config['desktop']['bottom']['right'][] = 'search';
						}
						if ( class_exists( 'WooCommerce' ) ) {
							$header_builder_config['desktop']['bottom']['right'][] = 'cart';
						}
						if ( $header_button_enable ) {
							$header_builder_config['desktop']['bottom']['right'][] = 'button';
						}
						break;
				}
			}

			// Get the current menu locations
			$menu_locations = get_theme_mod( 'nav_menu_locations' );

				// Check if 'menu-mobile' is set
			if ( ! isset( $menu_locations['menu-mobile'] ) && isset( $menu_locations['menu-primary'] ) ) {
				// Set 'menu-mobile' to the value of 'primary' menu location
				$menu_locations['menu-mobile'] = $menu_locations['menu-primary'];

				// Update the theme mod with the new menu locations
				set_theme_mod( 'nav_menu_locations', $menu_locations );
			}

			$header_builder_config['offset'] = [ 'mobile-menu' ];

			self::fix_components_indices( $header_builder_config );

			set_theme_mod( 'zakra_header_builder', $header_builder_config );

			// Footer builder migration.
			$footer_builder_config = [
				'desktop' => [
					'top'    => [
						'top-1' => [],
						'top-2' => [],
						'top-3' => [],
						'top-4' => [],
						'top-5' => [],
					],
					'main'   => [
						'main-1' => [],
						'main-2' => [],
						'main-3' => [],
						'main-4' => [],
						'main-5' => [],
					],
					'bottom' => [
						'bottom-1' => [],
						'bottom-2' => [],
						'bottom-3' => [],
						'bottom-4' => [],
						'bottom-5' => [],
					],
				],
				'offset'  => [],
			];
			$footer_bar_style      = get_theme_mod( 'zakra_footer_bar_style', 'style-2' );
			$footer_bar_content_1  = get_theme_mod( 'zakra_footer_bar_column_1_content_type', 'text_html' );
			$footer_bar_content_2  = get_theme_mod( 'zakra_footer_bar_column_2_content_type', 'none' );

			switch ( $footer_bar_content_1 ) {
				case 'text_html':
					$footer_bar_html = get_theme_mod( 'zakra_footer_bar_column_1_html', '' );
					if ( $footer_bar_html ) {
						set_theme_mod( 'zakra_footer_copyright', $footer_bar_html );
						remove_theme_mod( 'zakra_footer_bar_column_1_html' );
					}

					if ( 'style-2' === $footer_bar_style ) {
						set_theme_mod( 'zakra_footer_bottom_area_cols', 1 );
						self::remove_component( 'copyright', $footer_builder_config );
						$footer_builder_config['desktop']['bottom']['bottom-1'][] = 'copyright';
					} elseif ( 'style-1' === $footer_bar_style ) {
						set_theme_mod( 'zakra_footer_bottom_area_cols', 2 );
						self::remove_component( 'copyright', $footer_builder_config );
						$footer_builder_config['desktop']['bottom']['bottom-1'][] = 'copyright';
					}
					break;
				case 'menu':
					$footer_bar_menu = get_theme_mod( 'zakra_footer_bar_column_1_menu', 'none' );
					if ( $footer_bar_menu ) {
						set_theme_mod( 'zakra_footer_menu', $footer_bar_menu );
						remove_theme_mod( 'zakra_footer_bar_column_1_menu' );

						if ( 'style-2' === $footer_bar_style ) {
							set_theme_mod( 'zakra_footer_bottom_area_cols', 1 );
							$footer_builder_config['desktop']['bottom']['bottom-1'][] = 'footer-menu';
						} elseif ( 'style-1' === $footer_bar_style ) {
							set_theme_mod( 'zakra_footer_bottom_area_cols', 2 );
							$footer_builder_config['desktop']['bottom']['bottom-1'][] = 'footer-menu';
						}
					}
					break;
				case 'widget':
					if ( 'style-2' === $footer_bar_style ) {
						set_theme_mod( 'zakra_footer_bottom_area_cols', 1 );
						$footer_builder_config['desktop']['bottom']['bottom-1'][] = 'widget-5';
					} elseif ( 'style-1' === $footer_bar_style ) {
						set_theme_mod( 'zakra_footer_bottom_area_cols', 2 );
						$footer_builder_config['desktop']['bottom']['bottom-1'][] = 'widget-5';
					}
					break;
			}

			switch ( $footer_bar_content_2 ) {
				case 'text_html':
					$footer_bar_html_2 = get_theme_mod( 'zakra_footer_bar_column_2_html', '' );
					if ( $footer_bar_html_2 ) {
						set_theme_mod( 'zakra_footer_html_1', $footer_bar_html_2 );
						remove_theme_mod( 'zakra_footer_bar_column_2_html' );
					}

					if ( 'style-2' === $footer_bar_style ) {
						set_theme_mod( 'zakra_footer_bottom_area_cols', 1 );
						$footer_builder_config['desktop']['bottom']['bottom-1'][] = 'html-1';
					} elseif ( 'style-1' === $footer_bar_style ) {
						set_theme_mod( 'zakra_footer_bottom_area_cols', 2 );
						$footer_builder_config['desktop']['bottom']['bottom-2'][] = 'html-1';
					}
					break;
				case 'menu':
					$footer_bar_menu_2 = get_theme_mod( 'zakra_footer_bar_column_2_menu', '' );
					if ( $footer_bar_menu_2 ) {
						set_theme_mod( 'zakra_footer_menu_2', $footer_bar_menu_2 );
						remove_theme_mod( 'zakra_footer_bar_column_2_menu' );

						if ( 'style-2' === $footer_bar_style ) {
							set_theme_mod( 'zakra_footer_bottom_area_cols', 1 );
							$footer_builder_config['desktop']['bottom']['bottom-1'][] = 'footer-menu-2';
						} elseif ( 'style-1' === $footer_bar_style ) {
							set_theme_mod( 'zakra_footer_bottom_area_cols', 2 );
							$footer_builder_config['desktop']['bottom']['bottom-2'][] = 'footer-menu-2';
						}
					}
					break;
				case 'widget':
					if ( 'style-2' === $footer_bar_style ) {
						set_theme_mod( 'zakra_footer_bottom_area_cols', 1 );
						$footer_builder_config['desktop']['bottom']['bottom-1'][] = 'widget-6';
					} elseif ( 'style-1' === $footer_bar_style ) {
						set_theme_mod( 'zakra_footer_bottom_area_cols', 2 );
						$footer_builder_config['desktop']['bottom']['bottom-2'][] = 'widget-6';
					}
					break;
			}

			// Footer column.
			$footer_column_enable = get_theme_mod( 'zakra_enable_footer_column', false );
			if ( $footer_column_enable ) {
				$footer_column_layout = get_theme_mod( 'zakra_footer_column_layout', 'layout-1' );
				$footer_column_style  = get_theme_mod( 'zakra_footer_column_layout_1_style', 'style-4' );

				if ( 'layout-2' !== $footer_column_layout ) {
					switch ( $footer_column_style ) {
						case 'style-1':
							set_theme_mod( 'zakra_footer_main_area_cols', 1 );
							if ( is_active_sidebar( 'footer-sidebar-1' ) ) {
								$footer_builder_config['desktop']['main']['main-1'][] = 'widget-1';
							}
							break;
						case 'style-2':
							set_theme_mod( 'zakra_footer_main_area_cols', 2 );
							if ( is_active_sidebar( 'footer-sidebar-1' ) ) {
								$footer_builder_config['desktop']['main']['main-1'][] = 'widget-1';
							}
							if ( is_active_sidebar( 'footer-sidebar-2' ) ) {
								$footer_builder_config['desktop']['main']['main-2'][] = 'widget-2';
							}
							break;
						case 'style-3':
							set_theme_mod( 'zakra_footer_main_area_cols', 3 );
							if ( is_active_sidebar( 'footer-sidebar-1' ) ) {
								$footer_builder_config['desktop']['main']['main-1'][] = 'widget-1';
							}
							if ( is_active_sidebar( 'footer-sidebar-2' ) ) {
								$footer_builder_config['desktop']['main']['main-2'][] = 'widget-2';
							}
							if ( is_active_sidebar( 'footer-sidebar-3' ) ) {
								$footer_builder_config['desktop']['main']['main-3'][] = 'widget-3';
							}
							break;
						case 'style-4':
							set_theme_mod( 'zakra_footer_main_area_cols', 4 );
							if ( is_active_sidebar( 'footer-sidebar-1' ) ) {
								$footer_builder_config['desktop']['main']['main-1'][] = 'widget-1';
							}
							if ( is_active_sidebar( 'footer-sidebar-2' ) ) {
								$footer_builder_config['desktop']['main']['main-2'][] = 'widget-2';
							}
							if ( is_active_sidebar( 'footer-sidebar-3' ) ) {
								$footer_builder_config['desktop']['main']['main-3'][] = 'widget-3';
							}
							if ( is_active_sidebar( 'footer-sidebar-4' ) ) {
								$footer_builder_config['desktop']['main']['main-4'][] = 'widget-4';
							}
							break;
					}
				}
			}

			self::fix_components_indices( $footer_builder_config );
			set_theme_mod( 'zakra_footer_builder', $footer_builder_config );

			// Normal options to builder options.
			function normal_to_builder_option( $old_mod, $new_mod, $_default = '' ) {
				$value = get_theme_mod( $old_mod, '' );
				if ( $value ) {
					set_theme_mod( $new_mod, $value );
					if ( 'zakra_footer_column_widget_text_color' !== $old_mod ) {
						remove_theme_mod( $old_mod );
					}
				} elseif ( $_default ) {
					set_theme_mod( $new_mod, $_default );
				}
			}

			// Migrate top bar options
			normal_to_builder_option( 'zakra_top_bar_color', 'zakra_header_top_area_color', '#FAFAFA' );
			normal_to_builder_option(
				'zakra_top_bar_background',
				'zakra_header_top_area_background',
				array(
					'background-color'      => '#18181B',
					'background-image'      => '',
					'background-repeat'     => 'repeat',
					'background-position'   => 'center center',
					'background-size'       => 'contain',
					'background-attachment' => 'scroll',
				)
			);

			// Migrate main header options
			$main_header_layout_1_style = get_theme_mod( 'zakra_main_header_layout_1_style', 'style-1' );
			$main_layout_bg             = get_theme_mod( 'zakra_main_header_background_color' );
			if ( 'layout-2' === $main_header_layout || 'style-2' === $main_header_layout_1_style ) {
				set_theme_mod( 'zakra_header_main_area_background', $main_layout_bg );
				set_theme_mod( 'zakra_header_bottom_area_background', $main_layout_bg );
			} else {
				normal_to_builder_option( 'zakra_main_header_background_color', 'zakra_header_main_area_background' );
			}

			$footer_bar_width = get_theme_mod(
				'zakra_footer_bar_border_top_width',
				array(
					'size' => 1,
					'unit' => 'px',
				)
			);
			if ( $footer_bar_width ) {
				$value = [
					'top'    => $footer_bar_width['size'],
					'right'  => '0',
					'bottom' => '0',
					'left'   => '0',
					'units'  => 'px',
				];
				set_theme_mod( 'zakra_footer_bottom_area_border_width', $value );
				remove_theme_mod( 'zakra_footer_bar_border_top_width' );
			}

			$main_header_border_width = get_theme_mod(
				'zakra_main_header_border_bottom_width',
				array(
					'size'  => 1,
					'units' => 'px',
				)
			);
			if ( $main_header_border_width ) {
				$value = [
					'top'    => '0',
					'right'  => '0',
					'bottom' => $main_header_border_width['size'],
					'left'   => '0',
					'units'  => 'px',
				];
				set_theme_mod( 'zakra_header_builder_border_width', $value );
				remove_theme_mod( 'zakra_main_header_border_bottom_width' );
			}
			normal_to_builder_option( 'zakra_main_header_border_bottom_color', 'zakra_header_main_area_border_color', '#E4E4E7' );
			normal_to_builder_option( 'zakra_footer_bar_border_top_color', 'zakra_footer_bottom_area_border_color', '#3f3f46' );
			normal_to_builder_option( 'zakra_primary_menu_border_bottom_width', 'zakra_header_menu_border_bottom_width' );
			normal_to_builder_option( 'zakra_primary_menu_border_bottom_color', 'zakra_header_menu_border_bottom_color', '#e9ecef' );
			normal_to_builder_option( 'zakra_main_menu_typography', 'zakra_header_main_menu_typography' );
			normal_to_builder_option( 'zakra_sub_menu_typography', 'zakra_header_sub_menu_typography' );
			normal_to_builder_option( 'zakra_mobile_menu_typography', 'zakra_header_mobile_menu_typography' );
			normal_to_builder_option( 'zakra_site_identity_color', 'zakra_header_site_identity_color' );
			normal_to_builder_option( 'zakra_site_title_typography', 'zakra_header_site_title_typography' );
			normal_to_builder_option( 'zakra_site_tagline_color', 'zakra_header_site_tagline_color' );
			normal_to_builder_option( 'zakra_site_tagline_typography', 'zakra_header_site_tagline_typography' );

			// Migrate main menu options.
			$main_menu_color        = get_theme_mod( 'zakra_main_menu_color', '' );
			$main_menu_hover_color  = get_theme_mod( 'zakra_main_menu_hover_color', '' );
			$main_menu_active_color = get_theme_mod( 'zakra_main_menu_active_color', '' );
			if ( $main_menu_color ) {
				set_theme_mod( 'zakra_header_main_menu_color', $main_menu_color );
				set_theme_mod( 'zakra_header_secondary_menu_color', $main_menu_color );
				remove_theme_mod( 'zakra_main_menu_color' );
			}

			if ( $main_menu_hover_color ) {
				set_theme_mod( 'zakra_header_main_menu_hover_color', $main_menu_hover_color );
				set_theme_mod( 'zakra_header_secondary_menu_hover_color', $main_menu_hover_color );
				remove_theme_mod( 'zakra_main_menu_hover_color' );
			}

			if ( $main_menu_active_color ) {
				set_theme_mod( 'zakra_header_main_menu_active_color', $main_menu_active_color );
				set_theme_mod( 'zakra_header_secondary_menu_active_color', $main_menu_active_color );
				remove_theme_mod( 'zakra_main_menu_active_color' );
			}

			// Migrate footer options
			$footer_column_border_width = get_theme_mod( 'zakra_footer_column_border_top_width', '' );
			if ( $footer_column_border_width ) {
				$value = [
					'top'    => '0',
					'right'  => '0',
					'bottom' => $footer_column_border_width['size'],
					'left'   => '0',
					'units'  => 'px',
				];
				set_theme_mod( 'zakra_footer_main_area_border_width', $value );
				remove_theme_mod( 'zakra_footer_column_border_top_width' );
			}

			normal_to_builder_option( 'zakra_footer_column_border_top_color', 'zakra_footer_main_area_border_color', '#e9ecef' );
			normal_to_builder_option( 'zakra_footer_column_widget_text_color', 'zakra_footer_top_area_color', '#D4D4D8' );
			normal_to_builder_option( 'zakra_footer_column_widget_text_color', 'zakra_footer_main_area_color', '#D4D4D8' );
			normal_to_builder_option( 'zakra_footer_column_widget_link_color', 'zakra_footer_main_area_link_color' );
			normal_to_builder_option( 'zakra_footer_column_widget_link_hover_color', 'zakra_footer_main_area_link_hover_color' );
			normal_to_builder_option(
				'zakra_footer_bar_background',
				'zakra_footer_bottom_area_background',
				array(
					'background-color'      => '#18181B',
					'background-image'      => '',
					'background-repeat'     => 'repeat',
					'background-position'   => 'center center',
					'background-size'       => 'contain',
					'background-attachment' => 'scroll',
				)
			);
			normal_to_builder_option( 'zakra_footer_bar_link_color', 'zakra_footer_copyright_link_color' );
			normal_to_builder_option( 'zakra_footer_bar_link_hover_color', 'zakra_footer_copyright_link_hover_color' );

			$footer_bg = get_theme_mod(
				'zakra_footer_column_background',
				array(
					'background-color'      => '#18181B',
					'background-image'      => '',
					'background-repeat'     => 'repeat',
					'background-position'   => 'center center',
					'background-size'       => 'contain',
					'background-attachment' => 'scroll',
				)
			);
			if ( $footer_bg ) {
				set_theme_mod( 'zakra_footer_main_area_background', $footer_bg );
				set_theme_mod( 'zakra_footer_top_area_background', $footer_bg );
				remove_theme_mod( 'zakra_footer_column_background' );
			}

			$footer_bar_content_color = get_theme_mod( 'zakra_footer_bar_text_color', '#fafafa' );
			if ( $footer_bar_content_color ) {
				set_theme_mod( 'zakra_footer_copyright_text_color', $footer_bar_content_color );
			}

			update_option( 'zakra_builder_migration', true );
		}

		/**
		 * Migrates outside background settings to a new theme mod.
		 *
		 * This function handles the migration of various background-related theme mods
		 * to a single, consolidated theme mod. It performs the following operations:
		 *
		 * 1. Checks if the migration has already been performed to avoid duplicate migrations.
		 * 2. Retrieves individual background-related theme mods (color, image, preset, position, size, repeat, attachment).
		 * 3. If any of these theme mods exist, it consolidates them into a single array.
		 * 4. Sets the new consolidated theme mod 'elearning_outside_container_background'.
		 * 5. Removes the old individual theme mods.
		 * 6. Updates an option to mark the migration as complete.
		 *
		 * This migration is necessary to update the theme's handling of background settings,
		 * moving from individual settings to a more flexible, consolidated approach.
		 *
		 * @return void
		 *
		 * @since 4.0.0
		 */
		public function zakra_outside_background_migration() {

			if ( get_option( 'zakra_outside_background_migration' ) ) {
				return;
			}

			$background_color      = get_theme_mod( 'background_color' );
			$background_image      = get_theme_mod( 'background_image' );
			$background_preset     = get_theme_mod( 'background_preset' );
			$background_position   = get_theme_mod( 'background_position' );
			$background_size       = get_theme_mod( 'background_size' );
			$background_repeat     = get_theme_mod( 'background_repeat' );
			$background_attachment = get_theme_mod( 'background_attachment' );

			if ( $background_color || $background_image || $background_preset || $background_position || $background_size || $background_repeat || $background_attachment ) {
				$background_value = array(
					'background-color'      => $background_color,
					'background-image'      => $background_image,
					'background-repeat'     => $background_repeat,
					'background-position'   => $background_position,
					'background-size'       => $background_size,
					'background-attachment' => $background_attachment,
				);

				set_theme_mod( 'zakra_outside_container_background', $background_value );
				remove_theme_mod( 'background_color' );
				remove_theme_mod( 'background_image' );
				remove_theme_mod( 'background_preset' );
				remove_theme_mod( 'background_position' );
				remove_theme_mod( 'background_size' );
				remove_theme_mod( 'background_attachment' );
				remove_theme_mod( 'background_repeat' );
			}

			update_option( 'zakra_outside_background_migration', true );
		}

		/**
		 * Recursively removes a specified component from an array.
		 *
		 * This static function traverses through a multidimensional array and removes
		 * all occurrences of a specified component. It performs the following operations:
		 *
		 * 1. Iterates through each element of the input array.
		 * 2. If an element is an array, it recursively calls itself on that sub-array.
		 * 3. If an element matches the component to remove, it unsets that element.
		 * 4. After processing, if the array keys are sequential integers, it reindexes the array.
		 *
		 * @param mixed $component_to_remove The component to be removed from the array.
		 * @param array &$_array             The array to remove the component from (passed by reference).
		 *
		 * @return void The function modifies the input array directly.
		 *
		 * @since 4.0.0
		 */
		public static function remove_component( $component_to_remove, &$_array ) {
			foreach ( $_array as $key => &$value ) {
				if ( is_array( $value ) ) {
					self::remove_component( $component_to_remove, $value );
				} else { // phpcs:ignore
					if ( $value === $component_to_remove ) {
						unset( $_array[ $key ] );
					}
				}
			}
			if ( array_values( $_array ) === $_array ) {
				$_array = array_values( $_array );
			}
		}

		/**
		 * Recursively fixes the indices of components in a multidimensional array.
		 *
		 * This static function traverses through a multidimensional array and ensures
		 * that any sub-arrays with numeric keys are reindexed to have sequential integer keys.
		 * It performs the following operations:
		 *
		 * 1. Iterates through each element of the input array.
		 * 2. If an element is an array, it recursively calls itself on that sub-array.
		 * 3. Checks if the current sub-array has all numeric keys.
		 * 4. If all keys are numeric, it reindexes the array using array_values().
		 *
		 * @param array &$_array The array to fix indices for (passed by reference).
		 *
		 * @return bool Returns true if any changes were made, false otherwise.
		 *
		 * @since 4.0.0
		 */
		public static function fix_components_indices( &$_array ) {
			$fixed = false;

			foreach ( $_array as &$value ) {
				if ( is_array( $value ) ) {
					if ( ! self::fix_components_indices( $value ) ) {
						$numeric_keys = false;
						$all_numeric  = true;
						foreach ( array_keys( $value ) as $key ) {
							if ( is_numeric( $key ) ) {
								$numeric_keys = true;
							} else {
								$all_numeric = false;
								break;
							}
						}
						if ( $numeric_keys && $all_numeric ) {
							$value = array_values( $value );
							$fixed = true;
						}
					}
				}
			}

			return $fixed;
		}

		public static function zakra_builder_rollback() {

			update_option( 'theme_mods_zakra', '' );
				$theme_mods_old = get_option( 'zakra_customizer_before_builder_old_data' );
			if ( $theme_mods_old ) {
				foreach ( $theme_mods_old as $key => $value ) {
					set_theme_mod( $key, $value );
				}
			}
				delete_option( 'zakra_customizer_before_builder_old_data' );
				delete_option( 'zakra_builder_migration' );
				delete_option( 'zakra_pro_builder_migration' );
		}

		/**
		 * Return the value for customize migration on demo import.
		 *
		 * @return bool
		 */
		public static function demo_import_migration() {

			if ( isset( $_GET['zakra_notice_dismiss'] ) && isset( $_GET['_zakra_demo_import_migration_notice_dismiss_nonce'] ) ) {

				if ( ! wp_verify_nonce( wp_unslash( $_GET['_zakra_demo_import_migration_notice_dismiss_nonce'] ), 'zakra_demo_import_migration_notice_dismiss_nonce' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

					wp_die( esc_html__( 'Action failed. Please refresh the page and retry.', 'zakra' ) );
				}

				return true;
			}

			return false;
		}

		/**
		 * Determines whether to run the customizer migration.
		 *
		 * This static function checks if the customizer migration needs to be executed.
		 * It performs the following checks:
		 *
		 * 1. Verifies if the migration has already been run by checking a specific option.
		 * 2. If the migration has been run before, it returns false.
		 * 3. If not previously migrated, it checks for the presence of old theme mods.
		 * 4. Specifically looks for theme mods with the 'elearning_' prefix.
		 *
		 * The function is designed to prevent unnecessary migrations and ensure
		 * that the migration only runs when old theme data is present.
		 *
		 * @return bool Returns true if migration should be run, false otherwise.
		 *
		 * @since 3.0.0
		 */
		public static function zakra_maybe_enable_builder() {

			/**
			 * If the option with keys zakra_stretched_style_transfer ( introduced in V1.0.8 )
			 * or zakra_migrations ( introduced V1.5.3 ) is available in the option table.
			 * It is not a fresh installation of the theme.
			 *
			 * @TODO Better way to check if it is a fresh installation of theme.
			 */
			if ( get_option( 'zakra_stretched_style_transfer' ) || get_option( 'zakra_migrations' ) ) {

				return false;
			}

			return true;
		}

		/**
		 * @return bool
		 */
		public static function maybe_run_migration() {

			/**
			 * Check migration is already run or not.
			 * If migration is already run then return false.
			 *
			 */
			$migrated = get_option( 'zakra_customizer_migration_v3' ) || get_theme_mod( 'zakra_enable_builder' );

			if ( $migrated || wp_doing_ajax() ) {

				return false;
			}

			/**
			 * If user don't import the demo and upgrade the theme.
			 * Then we need to run the migration.
			 *
			 */
			$result     = false;
			$theme_mods = get_theme_mods();

			update_option( 'zakra_customizer_old_data', $theme_mods );

			foreach ( $theme_mods as $key => $_ ) {

				if ( strpos( $key, 'zakra_' ) !== false ) {

					$result = true;
					break;
				}
			}

			return $result;
		}
	}

	new Zakra_Migration();

}
