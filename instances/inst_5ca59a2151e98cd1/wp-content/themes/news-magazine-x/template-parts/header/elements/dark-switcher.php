<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$show_tooltip = newsx_get_option('dark_switcher_show_tooltip');
$tooltip_light = newsx_get_option('dark_switcher_tooltip_light');
$tooltip_dark = newsx_get_option('dark_switcher_tooltip_dark');
$tooltip_light_attrs = $show_tooltip ? ' data-tooltip="'. esc_attr($tooltip_dark) .'" data-gravity="s"' : ' title="'. esc_attr__( 'Switch to Dark', 'news-magazine-x' ) .'"';
$tooltip_dark_attrs = $show_tooltip ? ' data-tooltip="'. esc_attr($tooltip_light) .'" data-gravity="s"' : ' title="'. esc_attr__( 'Switch to Light', 'news-magazine-x' ) .'"';

$active_class = ('dark' === newsx_get_option('dark_switcher_default') && defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code()) ? 'active' : '';

// Get Args
$is_duplicate = isset($args['is_duplicate']) && $args['is_duplicate'];
$class = $is_duplicate ? ' newsx-duplicate-element' : '';

$html = '<div tabindex="0" class="newsx-dark-mode-switcher newsx-flex '. esc_attr($active_class . $class) .'">';
	// Edit Button
	$html .= newsx_customizer_edit_button_markup('hd_dark_switcher');

	$html .= '<div class="newsx-switch-to-dark"'. $tooltip_dark_attrs .'>';
		$html .= newsx_get_svg_icon('sun');
	$html .= '</div>';

	$html .= ' <div class="newsx-switch-to-light"'. $tooltip_light_attrs .'>';
		$html .= newsx_get_svg_icon('moon');
	$html .= '</div>';
$html .= '</div>';

echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
