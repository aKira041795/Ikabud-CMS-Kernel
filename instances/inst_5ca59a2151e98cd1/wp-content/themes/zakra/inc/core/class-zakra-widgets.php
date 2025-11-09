<?php
/**
 * Register widget areas.
 *
 * @package zakra
 *
 * TODO: @since.
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Zakra_Widgets' ) ) {

	/**
	 * Widgets.
	 */
	class Zakra_Widgets {

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

			add_action( 'widgets_init', array( $this, 'widgets_init' ) );
		}

		/**
		 * Register sidebars.
		 *
		 * @return void
		 */
		public function widgets_init() {

			$sidebars = $this->get_sidebars_list();

			foreach ( $sidebars as $id => $name ) {

				register_sidebar(
					apply_filters(
						'zakra_sidebars_widget_args',
						array(
							'id'            => $id,
							'name'          => $name,
							'description'   => esc_html__( 'Add widgets here.', 'zakra' ),
							'before_widget' => '<section id="%1$s" class="widget widget-' . $id . ' %2$s">',
							'after_widget'  => '</section>',
							'before_title'  => '<h2 class="widget-title">',
							'after_title'   => '</h2>',
						)
					)
				);
			}
		}

		/**
		 * Get information of sidebars to be registered.
		 *
		 * @return array
		 */
		public function get_sidebars_list() {

			$sidebars = array(
				'sidebar-right'            => esc_html__( 'Sidebar Right', 'zakra' ),
				'sidebar-left'             => esc_html__( 'Sidebar Left', 'zakra' ),
				'top-bar-col-1-sidebar'    => esc_html__( 'Column 1', 'zakra' ),
				'top-bar-col-2-sidebar'    => esc_html__( 'Column 2', 'zakra' ),
				'footer-sidebar-1'         => esc_html__( 'Footer One', 'zakra' ),
				'footer-sidebar-2'         => esc_html__( 'Footer Two', 'zakra' ),
				'footer-sidebar-3'         => esc_html__( 'Footer Three', 'zakra' ),
				'footer-sidebar-4'         => esc_html__( 'Footer Four', 'zakra' ),
				'footer-bar-col-1-sidebar' => esc_html__( 'Footer Five', 'zakra' ),
				'footer-bar-col-2-sidebar' => esc_html__( 'Footer Six', 'zakra' ),
			);

			if ( zakra_is_woocommerce_active() ) {

				$sidebars['wc-left-sidebar']  = esc_html__( 'WooCommerce Left Sidebar', 'zakra' );
				$sidebars['wc-right-sidebar'] = esc_html__( 'WooCommerce Right Sidebar', 'zakra' );
			}

			return apply_filters( 'zakra_sidebars_args', $sidebars );
		}
	}

}

Zakra_Widgets::get_instance();
