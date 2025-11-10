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

<nav id="zak-primary-nav" class="<?php zakra_css_class( 'zakra_nav_class' ); ?> <?php zakra_primary_menu_class(); ?>">
	<?php
	wp_nav_menu(
		array(
			'theme_location' => 'menu-primary',
			'menu_id'        => 'zak-primary-menu',
			'menu_class'     => 'zak-primary-menu',
			'container'      => '',
			'fallback_cb'    => 'zakra_menu_fallback',
		)
	);
	?>
</nav><!-- #zak-primary-nav -->

