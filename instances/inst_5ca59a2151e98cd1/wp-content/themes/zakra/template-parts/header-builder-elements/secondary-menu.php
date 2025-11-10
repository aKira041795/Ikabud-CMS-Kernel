<?php
/**
 * Site navigation template file.
 *
 * @package zakra
 *
 * @since 3.0.0
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;
?>

<nav id="zak-secondary-nav" class="zak-secondary-nav menu">
	<?php
	wp_nav_menu(
		array(
			'theme_location' => 'menu-secondary',
			'menu_id'        => 'zak-secondary-menu',
			'menu_class'     => 'zak-secondary-menu',
			'container'      => '',
			'fallback_cb'    => 'zakra_menu_fallback',
		)
	);
	?>
</nav><!-- #zak-secondary-nav -->

