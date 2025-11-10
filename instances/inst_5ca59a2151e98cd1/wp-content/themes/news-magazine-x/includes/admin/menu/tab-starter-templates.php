<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( newsx_is_plugin_installed( 'news-magazine-x-core' ) && is_plugin_active( 'news-magazine-x-core/news-magazine-x-core.php' ) ) {
	require_once NEWSX_CORE_PATH . 'admin/partials/newsx-core-admin-display.php';
} else {
	echo '<h3>'. esc_html__( 'Unlock endless possibilities by installing the Starter Templates plugin!', 'news-magazine-x' ) .'</h3>';
	echo '<p>'. esc_html__( 'Click the button below to begin your journey with ease.', 'news-magazine-x' ) .'</p>';
	echo '<br>';
	
	echo '<button class="newsx-btn-get-started button button-primary button-hero">';
		echo '<span>'. esc_html__( 'Install Starter Templates', 'news-magazine-x' ) .'</span>';
	echo '</button>';
}

?>