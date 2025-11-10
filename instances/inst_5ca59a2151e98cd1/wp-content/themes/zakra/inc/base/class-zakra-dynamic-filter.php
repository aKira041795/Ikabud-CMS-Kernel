<?php
/**
 * Filter array values.
 *
 * @package    ThemeGrill
 * @subpackage Zakra
 * @since      Zakra 1.1.7
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/*========================================== HEADER > HEADER TOP BAR ==========================================*/
if ( ! class_exists( 'Zakra_Dynamic_Filter' ) ) :

	/**
	 * Filter array values.
	 */
	class Zakra_Dynamic_Filter {

		/**
		 * Array of filter name and css classes.
		 *
		 * @since    1.1.7
		 * @access   private
		 * @var      array $css_class_arr Filter tag and class list.
		 */
		private static $css_class_arr = array();

		/**
		 * Get filter tag and class list in Array.
		 *
		 * @since    1.1.7
		 * @access   public
		 *
		 * @return array Filter tag and class list.
		 */
		public static function css_class_list() {

			self::$css_class_arr = array(
				'zakra_header_class'                      => array(
					'zak-header',
				),
				'zakra_header_main_container_class'       => array(
					'zak-container',
				),
				'zakra_header_top_class'                  => array(
					'zak-top-bar',
				),
				'zakra_header_top_container_class'        => array(
					'zak-container',
					'zak-top-bar-container',
				),
				'zakra_header_search_class'               => array(
					'zak-header-search',
				),
				'zakra_page_header_container_class'       => array(
					'zak-container',
				),
				'zakra_nav_class'                         => array(
					'zak-main-nav',
					'main-navigation',
					'zak-primary-nav',
				),
				'zakra_header_action_class'               => array(
					'zak-header-actions',
				),
				'zakra_read_more_wrapper_class'           => array(
					'zak-entry-footer',
				),
				'zakra_footer_widgets_container_class'    => array(
					'zak-container',
				),
				'zakra_footer_bottom_bar_container_class' => array(
					'zak-container',
				),
				'zakra_scroll_to_top_class'               => array(
					'zak-scroll-to-top',
				),
				'zakra_mobile_nav_class'                  => array(
					'zak-main-nav',
					'zak-mobile-nav',
				),
			);

			return apply_filters( 'zakra_css_class_list', self::$css_class_arr );

		}

		/**
		 * Filter the array according to key.
		 *
		 * @since    1.1.7
		 * @access   public
		 *
		 * @param string $tag Filter tag.
		 *
		 * @return array Filter tag and class list.
		 */
		public static function filter_via_tag( $tag ) {

			$css_class = self::css_class_list();

			$filtered = array();

			if ( isset( $css_class[ $tag ] ) ) {
				$filtered = $css_class[ $tag ];
			}

			return $filtered;

		}

	}

endif;
