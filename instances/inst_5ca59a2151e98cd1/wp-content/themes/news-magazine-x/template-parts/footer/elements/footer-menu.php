<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$menu_classes = ['newsx-nav-menu', 'newsx-footer-menu', 'newsx-flex'];

echo '<div class="newsx-menu-wrapper">';

	wp_nav_menu( [
		'theme_location' 	=> 'footer',
		'menu_id'        	=> 'newsx-footer-menu',
		'menu_class'        => esc_attr( implode( ' ', $menu_classes ) ),
		'container' 	 	=> 'nav',
		'container_class'	=> 'site-navigation newsx-footer-menu',
		'fallback_cb' 	    => 'newsx_menu_fallback',
		'items_wrap'        => newsx_customizer_edit_button_markup('ft_footer_menu') . '<ul id="%1$s" class="%2$s">%3$s</ul>'
	] );

echo '</div>';
