<?php
/**
 * BlogHash Theme Setup Class.
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

if ( ! class_exists( 'Bloghash_Theme_Setup' ) ) :

	/**
	 * Bloghash Options Class.
	 */
	class Bloghash_Theme_Setup {

		/**
		 * Singleton instance of the class.
		 *
		 * @since 1.0.0
		 * @var object
		 */
		private static $instance;

		/**
		 * Main Bloghash_Theme_Setup Instance.
		 *
		 * @since 1.0.0
		 * @return Bloghash_Theme_Setup
		 */
		public static function instance() {

			if ( ! isset( self::$instance ) && ! ( self::$instance instanceof Bloghash_Theme_Setup ) ) {
				self::$instance = new Bloghash_Theme_Setup();
			}
			return self::$instance;
		}

		/**
		 * Primary class constructor.
		 *
		 * @since 1.0.0
		 */
		public function __construct() {

			// Add theme supports.
			add_action( 'after_setup_theme', array( $this, 'setup' ), 10 );

			// Content width.
			add_action( 'wp', array( $this, 'content_width' ) );
		}

		/**
		 * Sets up theme defaults and registers support for various WordPress features.
		 *
		 * Note that this function is hooked into the after_setup_theme hook, which
		 * runs before the init hook. The init hook is too late for some features, such
		 * as indicating support for post thumbnails.
		 *
		 * @since 1.0.0
		 */
		public function setup() {

			// Make the theme available for translation.
			load_theme_textdomain( 'bloghash', BLOGHASH_THEME_PATH . '/languages' );

			// Add default posts and comments RSS feed links to head.
			add_theme_support( 'automatic-feed-links' );

			// Add theme support for Post Thumbnails and image sizes.
			add_theme_support( 'post-thumbnails' );

			// Add theme support for various Post Formats.
			add_theme_support(
				'post-formats',
				array(
					'gallery',
					'image',
					'link',
					'quote',
					'video',
					'audio',
					'status',
					'aside',
				)
			);

			// Add title output.
			add_theme_support( 'title-tag' );

			// Add wide image support.
			add_theme_support( 'align-wide' );

			// Responsive embeds support.
			add_theme_support( 'responsive-embeds' );

			// Add support for core block visual styles.
			add_theme_support( 'wp-block-styles' );

			// add custom spacing support.
			add_theme_support( 'custom-spacing' );

			// Selective Refresh for Customizer.
			add_theme_support( 'customize-selective-refresh-widgets' );

			// Excerpt support for pages.
			add_post_type_support( 'page', 'excerpt' );

			// Register Navigation menu.
			register_nav_menus(
				array(
					'bloghash-primary' => esc_html__( 'Primary Navigation', 'bloghash' ),
				)
			);

			// Add theme support for Custom Logo.
			add_theme_support(
				'custom-logo',
				apply_filters(
					'bloghash_custom_logo_args',
					array(
						'width'       => 200,
						'height'      => 40,
						'flex-height' => true,
						'flex-width'  => true,
					)
				)
			);

			// Add theme support for Custom Background.
			add_theme_support(
				'custom-background',
				apply_filters(
					'bloghash_custom_background_args',
					array(
						'default-color' => '#F9F9FF',
						'default-size'  => 'fit',
					)
				)
			);

			// Enable HTML5 markup.
			add_theme_support(
				'html5',
				array(
					'search-form',
					'gallery',
					'caption',
					'script',
					'style',
				)
			);

			add_theme_support(
				'custom-header',
				apply_filters(
					'bloghash_custom_header_args',
					array(
						'default-image' => '',
						'width'         => 1920,
						'height'        => 250,
						'flex-height'   => true,
						'header-text'   => false,
					)
				)
			);

			add_theme_support(
				'starter-content',
				array(
					'widgets' => array(
						'bloghash-footer-1' => array(
							'search',
						),
						'bloghash-footer-2' => array(
							'categories',
						),
						'bloghash-footer-3' => array(
							'archives',
						),
						'bloghash-footer-4' => array(
							'meta',
						),
					),
				)
			);

			// Add editor style.
			$bloghash_suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

			add_editor_style( 'assets/css/editor-style' . $bloghash_suffix . '.css' );

			do_action( 'bloghash_after_setup_theme' );
		}

		/**
		 * Set the content width in pixels, based on the theme's design and stylesheet.
		 *
		 * @global int $content_width
		 * @since 1.0.0
		 */
		public function content_width() {
			global $content_width;

			if ( ! isset( $content_width ) ) {
				$content_width = apply_filters( 'bloghash_content_width', intval( bloghash_option( 'container_width' ) ) - 100 ); // phpcs:ignore
			}
		}
	}

endif;

/**
 * The function which returns the one Bloghash_Options instance.
 *
 * @since 1.0.0
 * @return object
 */
function bloghash_theme_setup() {
	return Bloghash_Theme_Setup::instance();
}

bloghash_theme_setup();
