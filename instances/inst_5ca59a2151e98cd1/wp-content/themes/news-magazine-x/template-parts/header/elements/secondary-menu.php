<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Get Options
$header_sm_submenu_animation = newsx_get_option('header_sm_submenu_animation');

if ( !in_array( $header_sm_submenu_animation, ['none', 'fade'] ) && (!defined('NEWSX_CORE_PRO_VERSION') || !newsx_core_pro_fs()->can_use_premium_code()) ) {
    $header_sm_submenu_animation = 'fade';
}

// Menu Classes
$menu_classes = ['newsx-nav-menu', 'newsx-desktop-menu', 'newsx-flex'];
$menu_classes[] = 'newsx-submenu-anim-'. $header_sm_submenu_animation;

// Get Args
$is_duplicate = isset($args['is_duplicate']) && $args['is_duplicate'];
$class = $is_duplicate ? ' newsx-duplicate-element' : '';

echo '<div class="newsx-menu-wrapper newsx-header-menu-secondary-wrapper'. esc_attr($class) .'">';

	// Desktop Menu
	wp_nav_menu( [
		'theme_location' 	=> 'secondary',
		'menu_id'        	=> 'newsx-header-menu-secondary',
		'menu_class'        => esc_attr( implode( ' ', $menu_classes ) ),
		'container' 	 	=> 'nav',
		'container_class'	=> 'site-navigation newsx-header-menu-secondary',
		'fallback_cb' 		=> 'newsx_menu_fallback',
		'items_wrap'        => newsx_customizer_edit_button_markup('hd_secondary_menu') . '<ul id="%1$s" class="%2$s">%3$s</ul>'
	] );

echo '</div>';
