<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Get Options
$bs_newsletter_title = newsx_get_option('bs_newsletter_title');
$bs_newsletter_description = newsx_get_option('bs_newsletter_description');
$bs_newsletter_shortcode = newsx_get_option('bs_newsletter_shortcode');
$bs_newsletter_policy = newsx_get_option('bs_newsletter_policy');

echo '<div class="newsx-newsletter-wrap">';

    // Edit Button
    echo newsx_customizer_edit_button_markup('bs_newsletter');
    
    echo '<div class="newsx-newsletter-title newsx-flex">';
        echo newsx_get_svg_icon('paper-plane');
        echo '<div>';
            echo '<h3>'. esc_html($bs_newsletter_title) .'</h3>';
            echo '<p>'. esc_html($bs_newsletter_description) .'</p>';
        echo '</div>';
    echo '</div>';

    echo '<div class="newsx-newsletter-form">';
        echo do_shortcode( $bs_newsletter_shortcode );
    echo '</div>';
    
    echo '' !== $bs_newsletter_policy ? '<p class="newsx-newsletter-policy">'. wp_kses_post($bs_newsletter_policy) .'</p>' : '';
echo '</div>';