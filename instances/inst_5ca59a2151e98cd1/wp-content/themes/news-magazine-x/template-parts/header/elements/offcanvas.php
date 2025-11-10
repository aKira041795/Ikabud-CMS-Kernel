<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$offcanvas_icon = newsx_get_option('header_ofc_icon');

// Get <Args></Args>
$is_duplicate = isset($args['is_duplicate']) && $args['is_duplicate'];
$class = $is_duplicate ? ' newsx-duplicate-element' : '';

// Button
echo '<div tabindex="0" class="newsx-offcanvas-btn'. esc_attr($class) .'">';
    echo newsx_customizer_edit_button_markup('hd_offcanvas');
    echo newsx_get_svg_icon($offcanvas_icon);
echo '</div>';

// Widgets Area
echo '<div class="newsx-offcanvas-wrap">';
    echo '<div class="newsx-offcanvas-overlay"></div>';
    echo '<aside class="newsx-offcanvas-widgets-area">';
        echo newsx_customizer_edit_button_markup('sidebar-widgets-header-offcanvas');

        echo '<div tabindex="0" class="newsx-offcanvas-close-btn">';
            echo newsx_get_svg_icon('close');
        echo '</div>';

        newsx_dynamic_sidebar('header-offcanvas');
    echo '</aside>';
echo '</div>';