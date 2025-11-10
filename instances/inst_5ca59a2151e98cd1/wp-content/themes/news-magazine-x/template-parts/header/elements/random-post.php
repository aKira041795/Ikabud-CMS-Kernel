<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$html = '';
$icon = newsx_get_option('random_post_icon');
$show_tooltip = newsx_get_option('random_post_show_tooltip');
$tooltip = newsx_get_option('random_post_tooltip');

// Get Args
$is_duplicate = isset($args['is_duplicate']) && $args['is_duplicate'];
$class = $is_duplicate ? ' newsx-duplicate-element' : '';

if ( '' !== $icon || '' !== $tooltip ) {
	// Get a random post
	$random_post = get_posts(array(
		'posts_per_page' => 1,
		'orderby' => 'rand',
		'ignore_sticky_posts' => true,
		'post_type' => 'post',
		'post_status' => 'publish',
	));

	// Check if a random post was found
	if ( $random_post ) {
		$id = $random_post[0]->ID;
		$link = get_permalink($id);
		$tooltip_attrs = $show_tooltip ? ' data-tooltip="'. esc_attr($tooltip) .'" data-gravity="s"' : ' title="'. esc_attr__( 'Random Post', 'news-magazine-x' ) .'"';
		
		$html .= '<div class="newsx-random-post newsx-flex'. esc_attr($class) .'">';
			$html .= newsx_customizer_edit_button_markup('hd_random_post');
			$html .= '<a href="'. esc_url( $link ) .'" class="newsx-flex-center-vr"'. $tooltip_attrs .'>';
				$html .= '' !== $icon ? newsx_get_svg_icon($icon) : '';
			$html .= '</a>';
		$html .= '</div>';
	}
}

echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
