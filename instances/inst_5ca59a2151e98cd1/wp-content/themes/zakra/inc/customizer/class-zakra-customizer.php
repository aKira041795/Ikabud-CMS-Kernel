<?php
/**
 * Zakra Customizer Class
 *
 * @package zakra
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/functions.php';

if ( ! class_exists( 'Zakra_Customizer' ) ) :

	/**
	 * Zakra Customizer class
	 */
	class Zakra_Customizer {
		/**
		 * Constructor - Setup customizer
		 */
		public function __construct() {
			add_action( 'customize_register', array( $this, 'zakra_customize_register' ) );
			add_action( 'customize_register', array( $this, 'on_customizer_register' ) );
			add_filter( 'zakra_default_variants', array( $this, 'add_font_variants' ) );
			add_filter( 'zakra_fontawesome_src', array( $this, 'fontawesome_src' ) );
			add_action( 'customize_preview_init', array( $this, 'customize_preview_js' ), 11 );
			add_action( 'customize_controls_enqueue_scripts', array( $this, 'customize_js' ), 11 );

			$enable_builder = get_theme_mod( 'zakra_enable_builder', false );
			if ( $enable_builder || zakra_maybe_enable_builder() ) {
				add_filter( 'customizer_widgets_section_args', [ $this, 'modify_widgets_panel' ], 10, 3 );
				add_filter( 'customize_section_active', [ $this, 'modify_widgets_section_state' ], 100, 2 );
			}
		}

		public function on_customizer_register( $wp_customize ) {
			$this->includes();
			do_action( 'zakra_customize_register', $wp_customize );
		}

		protected function includes() {
			require_once __DIR__ . '/panels-sections/panels-sections.php';
			require_once __DIR__ . '/options/options.php';
		}

		public function customize_js() {

			wp_enqueue_script(
				'zakra-customizer',
				ZAKRA_PARENT_CUSTOMIZER_URI . '/assets/js/zakra-customize.js',
				array( 'jquery', 'customize-controls' ),
				ZAKRA_THEME_VERSION,
				true
			);
		}

		/**
		 * Filters response of WP_Customize_Section::active().
		 *
		 * @param bool  $active Whether the Customizer section is active.
		 * @param mixed $section WP_Customize_Section instance.
		 * @return bool
		 */
		public function modify_widgets_section_state( bool $active, $section ): bool {
			if (
				str_contains( $section->id, 'footer-sidebar-' ) ||
				str_contains( $section->id, 'footer-bar-col-' ) ||
				str_contains( $section->id, 'top-bar-col-' )
			) {
				$active = true;
			}
			return $active;
		}

		/**
		 * Modify widgets panel.
		 *
		 * @param array      $section_args Array of Customizer widget section arguments.
		 * @param string     $section_id   Customizer section ID.
		 * @param int|string $sidebar_id   Sidebar ID.
		 */
		public function modify_widgets_panel( array $section_args, string $section_id, $sidebar_id ): array {
			$footer_widgets = [
				'footer-sidebar-1',
				'footer-sidebar-2',
				'footer-sidebar-3',
				'footer-sidebar-4',
				'footer-bar-col-1-sidebar',
				'footer-bar-col-2-sidebar',
			];
			$header_widgets = [
				'top-bar-col-1-sidebar',
				'top-bar-col-2-sidebar',
			];

			if ( in_array( $sidebar_id, $footer_widgets, true ) ) {
				$section_args['panel'] = 'zakra_footer_builder';
			}

			if ( in_array( $sidebar_id, $header_widgets, true ) ) {
				$section_args['panel'] = 'zakra_header_builder';
			}

			return $section_args;
		}

		/**
		 * Include the required files for extending the custom Customize controls.
		 *
		 * @param WP_Customize_Manager $wp_customize Theme Customizer object.
		 */
		public function zakra_customize_register( $wp_customize ) {

			// Override default.
			require ZAKRA_PARENT_CUSTOMIZER_DIR . '/override-defaults.php';
			require ZAKRA_PARENT_CUSTOMIZER_DIR . '/class-zakra-customizer-partials.php';
		}

		/**
		 * Binds JS handlers to make Theme Customizer preview reload changes asynchronously.
		 *
		 * @since 3.0.0
		 */
		public function customize_preview_js() {
			if ( zakra_maybe_enable_builder() ) {
				set_theme_mod( 'zakra_enable_builder', true );
				update_option( 'zakra_builder_migration', true );
			}
			$suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';

			wp_enqueue_script(
				'zakra-customizer-preview',
				ZAKRA_PARENT_CUSTOMIZER_URI . '/assets/js/zakra-customize-preview' . $suffix . '.js',
				array(
					'customize-preview',
					'wp-hooks',
				),
				ZAKRA_THEME_VERSION,
				true
			);
		}

		/**
		 * @param $array
		 *
		 * @return mixed
		 */
		public function add_font_variants( $array ) {
			$array[] = '500';
			$array[] = '500italic';
			$array[] = '700italic';

			return $array;
		}

		/**
		 * @param $path
		 *
		 * @return string
		 */
		public function fontawesome_src( $path ) {
			$path = '/assets/lib/font-awesome/css/font-awesome';

			return $path;
		}
	}
endif;

new Zakra_Customizer();
