<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$site_title_vis = newsx_get_option('site_title_visibility');
$display_site_title = (in_array('desktop', $site_title_vis) || in_array('tablet', $site_title_vis) || in_array('mobile', $site_title_vis)) ? true : false;
$site_tagline_vis = newsx_get_option('site_tagline_visibility');
$display_site_tagline = (in_array('desktop', $site_tagline_vis) || in_array('tablet', $site_tagline_vis) || in_array('mobile', $site_tagline_vis)) ? true : false;
$dark_logo = newsx_get_option('dark_logo');
// $newsx_custom_logo_id = get_theme_mod( 'custom_logo' );

// Get Args
$is_duplicate = isset($args['is_duplicate']) && $args['is_duplicate'];
$class  = $is_duplicate ? ' newsx-duplicate-element' : '';
$class .= '' !== $dark_logo ? ' newsx-has-dark-logo' : '';

$html = '';

if ( has_custom_logo() || $display_site_title || $display_site_tagline ) :

	$html .= '<div class="newsx-site-identity'. esc_attr($class) .'">';

	// Edit Button
	$html .= newsx_customizer_edit_button_markup('title_tagline');

	if ( has_custom_logo() ) {
		$html .= '<div class="site-logo">';
			$html .= get_custom_logo();
			
			if (defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code()) {
				if ($dark_logo) {
					$html .= '<a href="' . esc_url( home_url( '/' ) ) . '" title="' . esc_attr__( 'Home', 'news-magazine-x' ) . '" rel="home" class="newsx-dark-logo">';
						$html .= '<img src="' . esc_url($dark_logo) . '" alt="' . esc_attr(get_bloginfo('name')) . '">';
					$html .= '</a>';
				}
			}

		$html .= '</div>';
	}

	if ( $display_site_title || $display_site_tagline ) {
		$site_title_tag = 'h1' === newsx_get_option('site_title_tag') && (is_home() || is_front_page());

		$html .= '<div class="newsx-site-title-tagline">';
			if ( $display_site_title ) {
				$html .= $site_title_tag ? '<h1 class="site-title">' : '<span class="site-title">';
				$html .= '<a href="' . esc_url( home_url( '/' ) ) . '" title="' . esc_attr__( 'Home', 'news-magazine-x' ) . '" rel="home">';
				$html .= esc_html( get_bloginfo( 'name' ) );
				$html .= '</a>';
				$html .= $site_title_tag ? '<h1>' : '</span>';
			}

			if ( $display_site_tagline && '' !== get_bloginfo( 'description' ) ) {
				$html .= '<p class="site-description">';
				$html .= esc_html( get_bloginfo( 'description', 'display' ) );
				$html .= '</p>';
			}
		$html .= '</div>';
	}

	$html .= '</div>';

endif;

echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
