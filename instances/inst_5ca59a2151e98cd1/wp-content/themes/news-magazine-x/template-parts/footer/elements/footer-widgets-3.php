<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

echo '<aside class="footer-widgets-area footer-widgets-area-3">';
    if ( is_active_sidebar( 'footer-widgets-3' ) ) {
        echo newsx_customizer_edit_button_markup('sidebar-widgets-footer-widgets-3');
    }
    
    newsx_dynamic_sidebar( 'footer-widgets-3' );
echo '</aside>';

