<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$date_format = newsx_get_option('date_format');
$date_show_time = newsx_get_option('date_show_time');
$date_show_icons = newsx_get_option('date_show_icons');

$date_string = '';
$time_string = '';

if ( 'default' == $date_format ) {
	$date_string = date_i18n( 'l, F j, Y' );
} elseif ( 'wordpress' == $date_format ) {
	$date_string = date_i18n( get_option( 'date_format' ) );
}

// Time
if ( $date_show_time ) {
	$time_string = date_i18n( 'H:i:s' );
}

// Get Args
$is_duplicate = isset($args['is_duplicate']) && $args['is_duplicate'];
$class = $is_duplicate ? ' newsx-duplicate-element' : '';

echo '<div class="newsx-date-and-time'. esc_attr($class) .'">';
	echo newsx_customizer_edit_button_markup('hd_date_and_time');

	echo '<span>';
		if ( $date_show_icons ) {
			echo newsx_get_svg_icon('calendar');
		}
		echo esc_html( $date_string );
	echo '</span>';
	
	if ( $date_show_time ) {
		echo '<span>';
			if ( $date_show_icons ) {
				echo newsx_get_svg_icon('clock');
			}
			echo '<span>'. esc_html( $time_string ) .'</span>';
		echo '</span>';
	}
echo '</div>';
