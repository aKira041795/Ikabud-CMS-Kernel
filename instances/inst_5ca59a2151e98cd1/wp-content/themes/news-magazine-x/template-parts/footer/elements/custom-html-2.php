<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$custom_html = newsx_get_option( 'footer_html2_editor' );

if ( !isset( $custom_html ) || empty( $custom_html ) ) {
	return;
}

echo '<div class="newsx-custom-html newsx-footer-custom-html-2">';
	echo newsx_customizer_edit_button_markup('ft_custom_html_2');
	echo wp_kses_post( $custom_html );
echo '</div>';
