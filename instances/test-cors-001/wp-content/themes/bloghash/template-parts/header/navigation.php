<?php
/**
 * The template for displaying header navigation.
 *
 * @package     Bloghash
 * @author      Peregrine Themes
 * @since       1.0.0
 */

?>

<nav class="site-navigation main-navigation bloghash-primary-nav bloghash-nav bloghash-header-element" role="navigation"<?php bloghash_schema_markup( 'site_navigation' ); ?> aria-label="<?php esc_attr_e( 'Site Navigation', 'bloghash' ); ?>">

<?php

if ( has_nav_menu( 'bloghash-primary' ) ) {
	wp_nav_menu(
		array(
			'theme_location' => 'bloghash-primary',
			'menu_id'        => 'bloghash-primary-nav',
			'container'      => '',
			'link_before'    => '<span>',
			'link_after'     => '</span>',
		)
	);
} else {
	wp_page_menu(
		array(
			'menu_class'  => 'bloghash-primary-nav',
			'show_home'   => true,
			'container'   => 'ul',
			'before'      => '',
			'after'       => '',
			'link_before' => '<span>',
			'link_after'  => '</span>',
		)
	);
}

?>
</nav><!-- END .bloghash-nav -->
