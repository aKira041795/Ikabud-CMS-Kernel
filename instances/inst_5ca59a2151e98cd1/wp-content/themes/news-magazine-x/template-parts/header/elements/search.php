<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$class = 'header-search-'. newsx_get_option('header_search_style');
$placeholder = newsx_get_option('header_search_placeholder');

// Get Args
$is_duplicate = isset($args['is_duplicate']) && $args['is_duplicate'];
$class .= $is_duplicate ? ' newsx-duplicate-element' : '';

echo '<div class="newsx-header-search '. esc_attr($class) .'">';

	// Edit Button
	echo newsx_customizer_edit_button_markup('hd_search');

	// Search Form
	get_search_form( ['placeholder' => $placeholder] );

	// Search Icon
	echo '<div tabindex="0" class="newsx-search-icon">';
		newsx_default_icon_markup('search', true);
	echo '</div>';

	// AJAX Search
	if ( newsx_get_option('header_search_ajax_enable') && defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
		echo '<div class="newsx-ajax-search-results"><ul></ul></div>';
	}

echo '</div>';
