<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$footer_logo = newsx_get_option('footer_logo');
$ft_dark_logo = newsx_get_option('ft_dark_logo');
$class = '' !== $ft_dark_logo ? ' newsx-has-dark-logo' : '';

echo '<div class="newsx-footer-logo ' . esc_attr($class) . '">';
    echo newsx_customizer_edit_button_markup('ft_logo');
    if ('' !== $footer_logo) {
        $srcset = '';

        if (defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code()) {
            $ft_retina_logo_sw = newsx_get_option('ft_retina_logo_sw');
            $ft_retina_logo = newsx_get_option('ft_retina_logo');
            
            if ($ft_retina_logo_sw && $ft_retina_logo) {
                $srcset = ' srcset="' . esc_url($footer_logo) . ' 1x, ' . esc_url($ft_retina_logo) . ' 2x"';
            }
        }

        echo '<img src="' . esc_url($footer_logo) . '"' . $srcset . ' alt="' . esc_attr(get_bloginfo('name')) . '">';

        if (defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code()) {
            if ($ft_dark_logo) {
                echo '<img src="' . esc_url($ft_dark_logo) . '" alt="' . esc_attr(get_bloginfo('name')) . '" class="newsx-dark-logo">';
            }
        }
    }
echo '</div>';
