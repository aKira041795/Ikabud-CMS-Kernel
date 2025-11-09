<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

echo '<aside class="footer-widgets-area footer-widgets-area-4">';
    if ( is_active_sidebar( 'footer-widgets-4' ) ) {
        echo newsx_customizer_edit_button_markup('sidebar-widgets-footer-widgets-4');
    }
    
    newsx_dynamic_sidebar( 'footer-widgets-4' );
echo '</aside>';

