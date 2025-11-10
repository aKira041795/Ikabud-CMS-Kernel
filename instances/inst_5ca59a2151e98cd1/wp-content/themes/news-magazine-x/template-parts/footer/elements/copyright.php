<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$copyright = newsx_get_option( 'copyright_editor' );

if ( '' === $copyright && defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
	return;
}

// Get theme author details.
$theme_author = newsx_get_theme_author_details();

// Replace shortcodes.
$copyright = str_replace( '[copyright]', '&copy;', $copyright );
$copyright = str_replace( '[current_year]', gmdate( 'Y' ), $copyright );
$copyright = str_replace( '[site_title]', get_bloginfo( 'name' ), $copyright );
$copyright = str_replace( '[theme_author]', $theme_author['theme_name'], $copyright );

// Powered by
if ( !defined('NEWSX_CORE_PRO_VERSION') || !newsx_core_pro_fs()->can_use_premium_code() ) {
	$powered_by = sprintf( ' | ' . esc_html__( 'Powered by %s', 'news-magazine-x' ), '<a href="' . esc_url( $theme_author['theme_author_url'] ) . '" rel="nofollow noopener" target="_blank">' . $theme_author['theme_name'] . '</a>' );
	$powered_by = '' === $copyright ? str_replace( ' | ', '', $powered_by ) : $powered_by;
	$copyright .= $powered_by;
}

// Copyirght.
echo '<div class="newsx-copyright">';
	echo newsx_customizer_edit_button_markup('ft_copyright');

	if ( defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
		echo wp_kses_post( $copyright );
	} else {
		echo do_shortcode( $copyright );
	}
	
echo '</div>';
