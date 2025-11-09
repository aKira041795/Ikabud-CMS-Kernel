<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Get Options
$header_pm_submenu_animation = newsx_get_option('header_pm_submenu_animation');

if ( !in_array( $header_pm_submenu_animation, ['none', 'fade'] ) && (!defined('NEWSX_CORE_PRO_VERSION') || !newsx_core_pro_fs()->can_use_premium_code()) ) {
    $header_pm_submenu_animation = 'fade';
}

// Menu Classes
$menu_classes = ['newsx-nav-menu', 'newsx-desktop-menu', 'newsx-flex'];
$menu_classes[] = 'newsx-submenu-anim-'. $header_pm_submenu_animation;
$toggle_label = newsx_get_option('header_pm_toggle_label');

echo '<div class="newsx-menu-wrapper newsx-header-menu-primary-wrapper">';

	// Desktop Menu
	wp_nav_menu( [
		'theme_location' 	=> 'primary',
		'menu_id'        	=> 'newsx-header-menu-primary',
		'menu_class'        => esc_attr( implode( ' ', $menu_classes ) ),
		'container' 	 	=> 'nav',
		'container_class'	=> 'site-navigation newsx-desktop-menu-container newsx-header-menu-primary',
		'fallback_cb' 		=> 'newsx_menu_fallback',
		'items_wrap'        => newsx_customizer_edit_button_markup('hd_primary_menu') . '<ul id="%1$s" class="%2$s">%3$s</ul>'
	] );

	// Mobile Menu Button
	$header_pm_toggle_style = defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ? newsx_get_option('header_pm_toggle_style') : 'minimal';

	echo '<div tabindex="0" class="newsx-mobile-menu-toggle style-'. esc_attr($header_pm_toggle_style) .'">';
	$toggle_icon = newsx_get_option('header_pm_toggle_icon');
	if ('none' !== $toggle_icon) {
		if ( !defined('NEWSX_CORE_PRO_VERSION') || !newsx_core_pro_fs()->can_use_premium_code() ) {
			$toggle_icon = 'chevron-down';
		}
		echo newsx_get_svg_icon($toggle_icon) . newsx_get_svg_icon('close');
	}
	
	echo '' !== $toggle_label ? '<span>'. esc_html($toggle_label) .'</span>' : '';

	// Edit Button
	echo newsx_customizer_edit_button_markup('hd_primary_menu');
	echo '</div>';

	// Mobile Menu
	wp_nav_menu( [
		'theme_location' 	=> 'primary',
		'menu_id'        	=> 'newsx-mobile-menu-primary',
		'menu_class'        => 'newsx-nav-menu newsx-mobile-menu',
		'container' 	 	=> 'nav',
		'container_class'	=> 'site-navigation newsx-mobile-menu-container newsx-header-menu-primary',
		'items_wrap'        => '<ul id="%1$s" class="%2$s">%3$s</ul>',
		'fallback_cb' 		=> '__return_false',
	] );

echo '</div>';
