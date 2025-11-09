<?php
/**
 * Primary menu hooks.
 *
 * @package zakra
 *
 * @since 3.0.0
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/*========================================= Hooks > Primary Menu ==========================================*/

if ( ! function_exists( 'zakra_mobile_navigation' ) ) :

	/**
	 * Adds mobile navigation.
	 */
	function zakra_mobile_navigation() {

		if ( empty( get_theme_mod( 'zakra_enable_primary_menu', true ) ) ) {

			return;
		}

		get_template_part( 'template-parts/header/primary-menu/mobile', 'toggle' );
	}
endif;

add_action( 'zakra_header_block_two', 'zakra_mobile_navigation', 20 );


if ( ! function_exists( 'zakra_primary_menu' ) ) :

	/**
	 * Primary menu.
	 */
	function zakra_primary_menu() {

		// Bail out if the menu is disabled from customizer.
		if ( empty( get_theme_mod( 'zakra_enable_primary_menu', true ) ) ) {

			return;
		}

		get_template_part( 'template-parts/header/primary-menu/site', 'navigation' );
	}
endif;


if ( ! function_exists( 'zakra_header_main_site_navigation' ) ) :

	/**
	 * Site navigation.
	 */
	function zakra_header_main_site_navigation() {

		/**
		 * Add header elements before primary navigation.
		 *
		 * @since 1.4.7
		 */
		do_action( 'zakra_primary_menu_before' );

		zakra_primary_menu();

		/**
		 * Hook - zakra_header_actions
		 *
		 * @hooked zakra_header_actions - 10
		 */
		do_action( 'zakra_header_actions', true );

		/**
		 * Hook - zakra_header_buttons
		 *
		 * @hooked zakra_header_buttons - 10
		 */
		do_action( 'zakra_header_buttons', true );

		/**
		 * Add header elements after primary navigation.
		 *
		 * @since 1.4.7
		 */
		do_action( 'zakra_primary_menu_after' );
	}
endif;

add_action( 'zakra_header_block_two', 'zakra_header_main_site_navigation', 10 );


if ( ! function_exists( 'zakra_add_submenu_icon' ) ) :

	/**
	 * Add submenu toggle icon after the menu items with submenus.
	 *
	 * @param string $item_output The menu item's starting HTML output.
	 * @param WP_Post $item Menu item data object.
	 * @param int $depth Depth of menu item. Used for padding.
	 * @param stdClass $args An object of wp_nav_menu() arguments.
	 *
	 * @return array|mixed|string|string[]
	 *
	 * @since 3.0.0
	 *
	 */
	function zakra_add_submenu_icon( $item_output, $item, $depth, $args ) {

		$theme_location = array( 'menu-primary', 'menu-mobile', 'menu-header-top-1', 'menu-header-top-2', 'menu-right', 'menu-left', 'secondary-menu', 'menu-secondary' );

		if ( in_array( $args->theme_location, $theme_location, true ) ) {

			if (
					in_array( 'menu-item-has-children', $item->classes, true ) ||
					in_array( 'page_item_has_children', $item->classes, true )
				) {

				$submenu_toggle_markup = '<span role="button" tabindex="0" class="zak-submenu-toggle" onkeypress="">' .
										'<svg class="zak-icon zak-dropdown-icon" xmlns="http://www.w3.org/2000/svg" xml:space="preserve" viewBox="0 0 24 24"><path d="M12 17.5c-.3 0-.5-.1-.7-.3l-9-9c-.4-.4-.4-1 0-1.4s1-.4 1.4 0l8.3 8.3 8.3-8.3c.4-.4 1-.4 1.4 0s.4 1 0 1.4l-9 9c-.2.2-.4.3-.7.3z"/></svg>' .
										'</span>';

				$item_output = str_replace(
					$args->link_after . '</a>',
					'zak-mobile-menu' === $args->menu_id || 'secondary-menu' === $args->menu_id ? $args->link_after . '</a>' . $submenu_toggle_markup : $args->link_after . $submenu_toggle_markup . '</a>',
					$item_output
				);
			}
		}

		return $item_output;
	}

endif;

add_filter( 'walker_nav_menu_start_el', 'zakra_add_submenu_icon', 10, 4 );


if ( ! function_exists( 'zakra_shift_extra_menu' ) ) :

	/**
	 * Keep menu items on one line.
	 *
	 * @param string   $items The HTML list content for the menu items.
	 * @param stdClass $args  An object containing wp_nav_menu() arguments.
	 *
	 * @return string HTML for more button.
	 */
	function zakra_shift_extra_menu( $items, $args ) {

		if ( 'menu-primary' === $args->theme_location && get_theme_mod( 'zakra_primary_menu_extra', false ) ) :

			$items .= '<li class="menu-item menu-item-has-children zak-menu-extras-wrap">';
			$items .= '<span class="submenu-expand">';
			$items .= '<i class="fa fa-ellipsis-v"></i>';
			$items .= '</span>';
			$items .= '<ul class="sub-menu" id="zak-menu-extras">';
			$items .= '</ul>';
			$items .= '</li>';
		endif;

		return $items;
	}
endif;

add_filter( 'wp_nav_menu_items', 'zakra_shift_extra_menu', 9, 2 );


if ( ! function_exists( 'zakra_menu_fallback' ) ) :

	/**
	 * Menu fallback for primary menu.
	 *
	 * Contains wp_list_pages to display pages created,
	 * search icons and WooCommerce cart icon.
	 */
	function zakra_menu_fallback() {

		require get_template_directory() . '/inc/class-zakra-walker-page.php';
		$output = '<ul id="zak-primary-menu" class="zak-primary-menu">';

		$output .= wp_list_pages(
			array(
				'echo'               => false,
				'title_li'           => false,
				'walker'             => new Zakra_Walker_Page(),
				'has_children_class' => 'menu-item-has-children',
				'current_class'      => 'current-menu-item',
			)
		);

		$output .= '</ul>';

		// @codingStandardsIgnoreStart
		echo $output;
		// @codingStandardsIgnoreEnd
	}

endif;
