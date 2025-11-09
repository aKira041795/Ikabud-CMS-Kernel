<?php
/**
 * Adds classes to appropriate places.
 *
 * @package zakra
 *
 * TODO: @since.
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Zakra_Css_Classes' ) ) :

	/**
	 * Zakra_Css_Classes class.
	 */
	class Zakra_Css_Classes {

		/**
		 * Constructor.
		 */
		public function __construct() {

			add_filter( 'body_class', array( $this, 'zakra_add_body_classes' ) );

			add_filter( 'post_class', array( $this, 'zakra_add_post_classes' ) );

			add_filter( 'zakra_header_class', array( $this, 'zakra_add_header_classes' ) );

			add_filter( 'zakra_header_top_class', array( $this, 'zakra_add_top_bar_classes' ) );

			add_filter( 'zakra_read_more_wrapper_class', array( $this, 'zakra_add_read_more_classes' ) );

			add_filter(
				'zakra_footer_widget_container_class',
				array(
					$this,
					'zakra_add_footer_widget_container_classes',
				)
			);

			add_filter( 'zakra_footer_bar_class', array( $this, 'zakra_add_footer_bar_classes' ) );

			add_filter( 'zakra_primary_menu_class', array( $this, 'zakra_add_primary_menu_classes' ) );

			add_action( 'wp_enqueue_scripts', array( $this, 'zakra_add_metabox_styles' ), 12 );
		}

		/**
		 * Adds css classes on body
		 *
		 * @param array $classes list of old classes.
		 *
		 * @return array
		 */
		public function zakra_add_body_classes( $classes ) {

			if ( ! is_home() ) {

				$content_margin = get_post_meta( zakra_get_post_id(), 'zakra_remove_content_margin' );

				if ( isset( $content_margin[0] ) && $content_margin[0] ) {

					$classes[] = 'zak-no-content-margin';
				}
			}

			// Adds a class of hfeed to non-singular pages.
			if ( ! is_singular() ) {

				$classes[] = 'hfeed';
			}

			/**
			 * Layout.
			 */

			// Layout.
			$classes[] = zakra_get_current_layout();

			// Container style.
			$container_layout = get_theme_mod( 'zakra_container_layout', 'wide' );
			$classes[]        = 'zak-container--' . $container_layout;

			// Content area style.
			$content_layout = get_theme_mod( 'zakra_content_area_layout', 'bordered' );
			$classes[]      = 'zak-content-area--' . $content_layout;

			// Add transparent header class.
			if ( zakra_is_header_transparent_enabled() ) {

				$classes[] = 'has-transparent-header';
			}

			// Add if page header is enabled.
			if ( 'page-header' === zakra_page_title_position() ) {

				$classes[] = 'has-page-header';
			}

			// Add class if breadcrumbs is enabled.
			if ( zakra_is_breadcrumbs_enabled() ) {

				$classes[] = 'has-breadcrumbs';
			}

			return $classes;
		}

		/**
		 * Adds css classes into the post.
		 *
		 * @param array $classes old classes.
		 *
		 * @return array new classes
		 */
		public function zakra_add_post_classes( $classes ) {

			if ( ( is_archive() || is_home() || is_search() ) ) {

				// If not WC pages.
				if ( ! ( function_exists( 'is_woocommerce' ) && is_woocommerce() ) ) {

					$classes[] = 'zak-post';
				}
			}

			// TODO: Refactor this, these classes are not necessary.
			if ( is_single() ) {

				$classes[] = 'zakra-single-article';
			}

			if ( is_singular( 'post' ) ) {

				$classes[] = 'zakra-article-post';
			}

			if ( is_singular( 'page' ) ) {

				$classes[] = 'zakra-article-page';
			}

			return $classes;
		}

		/**
		 * Adds css classes into top bar.
		 *
		 * @param array $classes list of old classes.
		 *
		 * @return array
		 */
		public function zakra_add_top_bar_classes( $classes ) {

				$classes[] = '';

			return $classes;
		}

		/**
		 * Adds css classes into header
		 *
		 * @param array $classes list of old classes.
		 *
		 * @return array
		 */
		public function zakra_add_header_classes( $classes ) {

			// TODO: check zakra pro header_top_class method.
			if ( ! is_home() ) {

				$header_style = get_post_meta( zakra_get_post_id(), 'zakra_main_header_style', true );
			}

			if ( ! empty( $header_style ) && 'default' !== $header_style ) {

				if ( 'zak-layout-1-menu-style-2' === $header_style ) {
					$classes[]    = 'zak-layout-1';
					$classes[]    = 'zak-menu--center';
					$header_style = 'zak-layout-1-style-1';
				}

				$classes[] = $header_style;
			} else {

				$layout = get_theme_mod( 'zakra_main_header_layout', 'layout-1' );

				if ( $layout == 'layout-1' ) {

					$style     = get_theme_mod( 'zakra_main_header_layout_1_style', 'style-1' );
					$classes[] = 'zak-' . $layout;
					$classes[] = 'zak-' . $layout . '-' . $style;
				} elseif ( $layout == 'layout-2' ) {

					$style     = get_theme_mod( 'zakra_main_header_layout_2_style', 'style-1' );
					$classes[] = 'zak-' . $layout;
					$classes[] = 'zak-' . $layout . '-' . $style;
				}
			}

			// Add transparent header class.
			if ( zakra_is_header_transparent_enabled() ) {

				$classes[] = 'zak-layout-1-transparent';
			}

			return $classes;
		}

		/**
		 * Adds css classes into primary menu
		 *
		 * @param array $classes list of old classes.
		 *
		 * @return array
		 */
		public function zakra_add_primary_menu_classes( $classes ) {

			$zakra_menu_active_style = get_post_meta( zakra_get_post_id(), 'zakra_menu_active_style', true );
			$zakra_menu_extra        = get_theme_mod( 'zakra_primary_menu_extra', false );

			if ( ! empty( $zakra_menu_active_style ) ) {

				$classes[] = 'zak-layout-1-' . $zakra_menu_active_style;
			} elseif ( 'layout-2' !== get_theme_mod( 'zakra_main_menu_layout', 'layout-1' ) ) {

				$classes[] = 'zak-layout-1';

				$style = get_theme_mod( 'zakra_main_menu_layout_1_style', 'style-1' );

				$classes[] = 'zak-layout-1-' . $style;

			}

			if ( ! empty( $zakra_menu_extra ) ) {

				$classes[] = 'zak-extra-menus';
			}

			return $classes;
		}

		/**
		 * Adds css classes into the footer widget area
		 *
		 * @param array $classes list of old classes.
		 *
		 * @return array
		 */
		public function zakra_add_footer_widget_container_classes( $classes ) {

			$footer_layout             = get_theme_mod( 'zakra_footer_column_layout', 'layout-1' );
			$footer_classes            = get_theme_mod( 'zakra_footer_column_layout_1_style', 'style-4' );
			$footer_widgets_style_meta = get_post_meta( zakra_get_post_id(), 'zakra_footer_column_layout_1_style', true );

			if ( 'layout-1' === $footer_layout ) {

				$classes[] = 'zak-' . $footer_layout;

				if ( 'customizer' === $footer_widgets_style_meta || empty( $footer_widgets_style_meta ) ) {
					$classes[] = 'zak-' . $footer_layout . '-' . $footer_classes;
				}
			}

			// Add hide class if the widget title is disabled.
			if ( empty( get_theme_mod( 'zakra_enable_footer_widgets_title', true ) ) ) {

				$classes[] = 'zak-footer-widget--title-hidden';
			}

			return $classes;
		}

		/**
		 * Adds css classes into read more.
		 *
		 * @param array $classes list of old classes.
		 *
		 * @return array
		 */
		public function zakra_add_read_more_classes( $classes ) {

			$layout    = get_theme_mod( 'zakra_blog_button_layout', 'layout-1' );
			$classes[] = 'zak-' . $layout;

			return $classes;
		}

		/**
		 * Adds css classes into the footer bar area
		 *
		 * @param array $classes list of old classes.
		 *
		 * @return array
		 */
		public function zakra_add_footer_bar_classes( $classes ) {

			$footer_style = get_post_meta( zakra_get_post_id(), 'zakra_footer_bar_style', true );
			$footer_style = empty( $footer_style ) || 'customizer' === $footer_style ? get_theme_mod( 'zakra_footer_bar_style', 'style-2' ) : $footer_style;
			$classes[]    = 'zak-' . $footer_style;

			return $classes;
		}

		/**
		 * Adds styles from metabox.
		 *
		 * @TODO Move to Zakra_Dynamic_CSS class, only CSS classes related work here.
		 *
		 * @return void
		 */
		public function zakra_add_metabox_styles() {

			// Customizer.
			$customize_zakra_menu_item_color        = get_theme_mod( 'zakra_main_menu_color', '#16181a' );
			$customize_zakra_menu_item_hover_color  = get_theme_mod( 'zakra_main_menu_hover_color', '#027abb' );
			$customize_zakra_menu_item_active_color = get_theme_mod( 'zakra_main_menu_active_color', '#027abb' );

			// Meta.
			$zakra_menu_item_color        = get_post_meta( zakra_get_post_id(), 'zakra_menu_item_color', true );
			$zakra_menu_item_hover_color  = get_post_meta( zakra_get_post_id(), 'zakra_menu_item_hover_color', true );
			$zakra_menu_item_active_color = get_post_meta( zakra_get_post_id(), 'zakra_menu_item_active_color', true );

			$meta_css = '';

			if ( $customize_zakra_menu_item_color !== $zakra_menu_item_color && ! empty( $zakra_menu_item_color ) ) {

				$meta_css .= '
				.main-navigation.zak-primary-nav > ul > li > a {
					color: ' . $zakra_menu_item_color . '
				}
				';
			}

			if ( $customize_zakra_menu_item_hover_color !== $zakra_menu_item_hover_color && ! empty( $zakra_menu_item_hover_color ) ) {

				$meta_css .= '
				.main-navigation.zak-primary-nav > ul > li:hover > a {
					color: ' . $zakra_menu_item_hover_color . '
				}
				';
			}

			if ( $customize_zakra_menu_item_active_color !== $zakra_menu_item_active_color && ! empty( $zakra_menu_item_active_color ) ) {

				$meta_css .= '
				.main-navigation.zak-primary-nav > ul li:active > a,
				.main-navigation.zak-primary-nav > ul > li.current_page_item > a,
				.main-navigation.zak-primary-nav > ul > li.current-menu-item > a {
					color: ' . $zakra_menu_item_active_color . ';
				}
				';

				$meta_css .= '
				.main-navigation.zak-primary-nav.zak-layout-1-style-2 > ul > li.current_page_item > a::before,
				.main-navigation.zak-primary-nav.zak-layout-1-style-2 > ul > li.current-menu-item > a::before,
				.main-navigation.zak-primary-nav.zak-layout-1-style-3 > ul > li.current_page_item > a::before,
				.main-navigation.zak-primary-nav.zak-layout-1-style-3 > ul > li.current-menu-item > a::before,
				.main-navigation.zak-primary-nav.zak-layout-1-style-4 > ul > li.current_page_item > a::before,
				.main-navigation.zak-primary-nav.zak-layout-1-style-4 > ul > li.current-menu-item > a::before {
					background-color: ' . $zakra_menu_item_active_color . ';
				}
				';
			}

			$meta_css .= apply_filters( 'zakra_meta_box_style', false );

			if ( zakra_is_zakra_pro_active() ) {

				wp_add_inline_style( 'zakra-pro', $meta_css );
			} else {

				wp_add_inline_style( 'zakra-style', $meta_css );
			}
		}
	}
endif;

new Zakra_Css_Classes();
