<?php
require __DIR__ . '/global/colors.php';
require __DIR__ . '/global/container.php';
require __DIR__ . '/global/content-area.php';
require __DIR__ . '/global/sidebar.php';
require __DIR__ . '/global/typography.php';
require __DIR__ . '/global/button.php';
require __DIR__ . '/content/blog.php';
require __DIR__ . '/content/blog-meta.php';
require __DIR__ . '/content/single-post.php';
require __DIR__ . '/content/blog-sidebar.php';
require __DIR__ . '/additional/additional.php';
require __DIR__ . '/woocommerce/woocommerce.php';
require __DIR__ . '/footer/scroll-to-top.php';

$enable_builder = get_theme_mod( 'zakra_enable_builder', false );

if ( $enable_builder || zakra_maybe_enable_builder() ) {
	require __DIR__ . '/header-builder/header-builder.php';
	require __DIR__ . '/header-builder/logo.php';
	require __DIR__ . '/header-builder/widget-1.php';
	require __DIR__ . '/header-builder/widget-2.php';
	require __DIR__ . '/header-builder/secondary-menu.php';
	require __DIR__ . '/header-builder/tertiary-menu.php';
	require __DIR__ . '/header-builder/quaternary-menu.php';
	require __DIR__ . '/header-builder/primary-menu.php';
	require __DIR__ . '/header-builder/mobile-menu.php';
	require __DIR__ . '/header-builder/offset-area.php';
	require __DIR__ . '/header-builder/header-button.php';
	require __DIR__ . '/header-builder/search.php';
	require __DIR__ . '/header-builder/top-area.php';
	require __DIR__ . '/header-builder/main-area.php';
	require __DIR__ . '/header-builder/bottom-area.php';
	require __DIR__ . '/header-builder/html-1.php';
	require __DIR__ . '/header-builder/html-2.php';
	require __DIR__ . '/header-builder/cart.php';
	require __DIR__ . '/header-builder/socials.php';
	require __DIR__ . '/header-builder/toggle-button.php';
	require __DIR__ . '/footer-builder/footer-builder.php';
	require __DIR__ . '/footer-builder/html-1.php';
	require __DIR__ . '/footer-builder/html-2.php';
	require __DIR__ . '/footer-builder/widget-1.php';
	require __DIR__ . '/footer-builder/widget-2.php';
	require __DIR__ . '/footer-builder/widget-3.php';
	require __DIR__ . '/footer-builder/widget-4.php';
	require __DIR__ . '/footer-builder/widget-5.php';
	require __DIR__ . '/footer-builder/widget-6.php';
	require __DIR__ . '/footer-builder/top-area.php';
	require __DIR__ . '/footer-builder/main-area.php';
	require __DIR__ . '/footer-builder/bottom-area.php';
	require __DIR__ . '/footer-builder/footer-menu.php';
	require __DIR__ . '/footer-builder/footer-menu-2.php';
	require __DIR__ . '/footer-builder/socials.php';
	require __DIR__ . '/footer-builder/copyright.php';
} else {
	require __DIR__ . '/header/site-identity.php';
	require __DIR__ . '/header/main-header.php';
	require __DIR__ . '/header/primary-menu.php';
	require __DIR__ . '/header/top-bar.php';
	require __DIR__ . '/header/header-button.php';
	require __DIR__ . '/header/header-search.php';
	require __DIR__ . '/footer/footer-column.php';
	require __DIR__ . '/footer/footer-bar.php';
}
if ( ! zakra_maybe_enable_builder() ) {
	require __DIR__ . '/global/builder.php';
}
require __DIR__ . '/header/header-media.php';
require __DIR__ . '/header/page-header.php';
require __DIR__ . '/header/breadcrumb.php';
