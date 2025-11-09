<?php
/**
 * Load CSS & Javascript files.
 *
 * @package zakra
 *
 * TODO: @since.
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Zakra_Enqueue_Scripts' ) ) {

	/**
	 * Enqueue Scripts.
	 */
	class Zakra_Enqueue_Scripts {

		/**
		 * Instance.
		 *
		 * @access private
		 * @var object
		 */
		private static $instance;

		/**
		 * Initiator.
		 */
		public static function get_instance() {

			if ( ! isset( self::$instance ) ) {

				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Constructor.
		 */
		private function __construct() {

			$this->setup_hooks();
		}

		/**
		 * Define hooks.
		 *
		 * @return void
		 */
		public function setup_hooks() {

			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

			add_action( 'enqueue_block_editor_assets', array( $this, 'block_editor_styles' ), 1 );

			add_action( 'customize_controls_enqueue_scripts', array( $this, 'zakra_inline_customizer_css' ) );

			if ( is_child_theme() ) {
				add_action(
					'customize_controls_enqueue_scripts',
					[
						$this,
						'zakra_child_theme_inline_customizer_css',
					]
				);
			}
		}

		/**
		 * Enqueue scripts and styles.
		 *
		 * @return void
		 * TODO: Refactor this, split code inside method.
		 */
		public function enqueue_scripts() {

			$suffix = zakra_get_script_suffix();

			// FontAwesome.
			global $customind;

			$fontawesome_path = $customind->get_asset_url( 'all.min.css', 'assets/fontawesome/v6/css', false );

			wp_enqueue_style( 'font-awesome-all', $fontawesome_path, array(), '6.2.4' );

			// Local Google fonts locally.
			$host_fonts_locally = get_theme_mod( 'zakra_load_google_fonts_locally', false );

			$typography_ids = apply_filters(
				'zakra_enqueue_scripts_typography_ids',
				array(
					'zakra_body_typography',
					'zakra_heading_typography',
					'zakra_site_title_typography',
					'zakra_site_tagline_typography',
					'zakra_main_menu_typography',
					'zakra_sub_menu_typography',
					'zakra_mobile_menu_typography',
					'zakra_breadcrumb_typography',
					'zakra_shop_product_button_typography',
					'zakra_shop_product_price_typography',
					'zakra_shop_product_title_typography',
					'zakra_shop_product_view_cart_typography',
					'zakra_post_page_title_typography',
					'zakra_blog_post_title_typography',
					'zakra_h1_typography',
					'zakra_h2_typography',
					'zakra_h3_typography',
					'zakra_h4_typography',
					'zakra_h5_typography',
					'zakra_h6_typography',
					'zakra_widget_title_typography',
					'zakra_widget_content_typography',
					'zakra_header_drawer_menu_typography',
					'zakra_header_main_menu_typography',
					'zakra_header_sub_menu_typography',
					'zakra_header_site_title_typography',
					'zakra_header_site_tagline_typography',
					'zakra_header_secondary_menu_typography',
					'zakra_header_secondary_sub_menu_typography',
					'zakra_header_tertiary_menu_typography',
					'zakra_header_tertiary_sub_menu_typography',
					'zakra_header_quaternary_menu_typography',
					'zakra_button_typography',
					'zakra_header_button_typography',
				)
			);

			$google_fonts_url = \Customind\Core\get_google_fonts_url_by_ids( $typography_ids, $host_fonts_locally );

			if ( $google_fonts_url ) {
				wp_enqueue_style( 'zakra_google_fonts', $google_fonts_url, array(), ZAKRA_THEME_VERSION, 'all' );
			}

			// Theme style.
			wp_register_style(
				'zakra-style',
				get_stylesheet_uri(),
				array(),
				ZAKRA_THEME_VERSION
			);

			wp_enqueue_style( 'zakra-style' );

			// Support RTL.
			wp_style_add_data( 'zakra-style', 'rtl', 'replace' );

			/**
			 * Dynamic CSS.
			 */
			// Dynamically generated styles from options.
			add_filter( 'zakra_dynamic_theme_css', array( 'Zakra_Dynamic_CSS', 'render_output' ) );
			add_filter( 'zakra_dynamic_theme_css', array( 'Zakra_Dynamic_CSS', 'render_builder_output' ) );

			// Generate dynamic CSS to add inline styles for the theme.
			$theme_dynamic_css = apply_filters( 'zakra_dynamic_theme_css', '' );

			// Load dynamic CSS.
			if ( zakra_is_zakra_pro_active() ) {

				wp_add_inline_style( 'zakra-pro', $theme_dynamic_css );
			} else {

				wp_add_inline_style( 'zakra-style', $theme_dynamic_css );
			}

			/**
			 * Scripts.
			 */
			// Do not load scripts if AMP.
			if ( zakra_is_amp() ) {

				return;
			}

			// Script for menus.
			wp_enqueue_script(
				'zakra-navigation',
				ZAKRA_PARENT_ASSETS_URI . '/js/navigation' . $suffix . '.js',
				array(),
				ZAKRA_THEME_VERSION,
				true
			);

			// Accessiblity JS for keyboard only users.
			wp_enqueue_script(
				'zakra-skip-link-focus-fix',
				ZAKRA_PARENT_ASSETS_URI . '/js/skip-link-focus-fix' . $suffix . '.js',
				array(),
				ZAKRA_THEME_VERSION,
				true
			);

			// Zakra main JavaScript file.
			wp_enqueue_script(
				'zakra-custom',
				ZAKRA_PARENT_ASSETS_URI . '/js/zakra-custom' . $suffix . '.js',
				array(),
				ZAKRA_THEME_VERSION,
				true
			);

			// JS file for comment form.
			if ( is_singular() && comments_open() && get_option( 'thread_comments' ) ) {

				wp_enqueue_script( 'comment-reply' );
			}
		}

		/**
		 * Enqueue block editor styles.
		 *
		 * TODO: @since.
		 */
		public function block_editor_styles() {

			wp_enqueue_style( 'zakra-block-editor-styles', ZAKRA_PARENT_URI . '/style-editor-block.css', array(), ZAKRA_THEME_VERSION );
		}

		public function zakra_inline_customizer_css() {
			wp_add_inline_style(
				'customize-controls',
				'
		        #customize-control-zakra_site_identity_general_heading .customind-control .font-normal{
		        font-weight: 600;
		        }

		        #customize-control-zakra_header_media_heading .customind-control .font-normal{
		        font-weight: 600;
		        }

		        #customize-control-zakra_header_media_heading .customind-control {
		        border-bottom: 1px solid #e5e5e5;
		        }

		        #customize-control-zakra_site_identity_general_heading .customind-control {
		        border-bottom: 1px solid #e5e5e5;
		        }

		        #customize-control-blogname #_customize-input-blogname {
		        height: 40px;
		        }

		        #customize-control-blogdescription #_customize-input-blogdescription {
		        height: 40px;
		        }

		        [data-zakra-header-panel="active"]{
			    #sub-accordion-section-zakra_builder{
			    top: 65px !important;
			        left:2px !important;
			    visibility: visible !important;
			    height: auto !important;
			    transform: none !important;
			    z-index: 99999999;

			    .section-meta{
			        display:none !important;
			    }
			}

			#accordion-section-zakra_builder {
			    height:155px !important;
			    visibility: hidden;
			}
			    [data-control-id="zakra_builder_heading"]{
			        max-width: 310px !important;
			    }
			}

			.section-open[data-zakra-header-panel="active"]{
			    #sub-accordion-section-zakra_builder{
			        visibility: hidden !important;
			        height: auto !important;
			        transform: none !important;
			    }
			    }

			    #customize-control-zakra_header_builder_style_heading {
			    margin-top: 20px;
			    }

			    .zak-hidden{
			       height: 0;
				    visibility: hidden;
				    padding: 0 !important;
				    margin: 0;
				}

				.customize-control-customind-upsell a:focus{
				color:white;
				box-shadow: none;
				outline: none;
				}

				#customize-control-zakra_demo_migrated_heading {
				display: none;
				}

				#customize-theme-controls .control-section-customind-upsell-section .accordion-section-title {
				    padding: 16px 8px;
				}
		    '
			);
		}

		public function zakra_child_theme_inline_customizer_css() {
			wp_add_inline_style(
				'customize-controls',
				'
		        .zak-hidden.child-theme-notice {
					height: auto;
				    visibility: visible;
				        padding-top: 16px !important;
                         padding-bottom: 16px !important;
				    margin: 0;
				}

		    '
			);
		}
	}

}

Zakra_Enqueue_Scripts::get_instance();
