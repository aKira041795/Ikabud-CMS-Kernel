<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$images = get_field('newsx_post_gallery_images');

if ( empty( $images ) ) {
	return;
}

// Main Gallery
echo '<div class="format-gallery-wrapper">';

	echo '<div class="format-gallery swiper">';
		echo '<div class="swiper-wrapper">';

		foreach ( $images as $image ) {
			echo '<div class="swiper-slide">';
				echo wp_get_attachment_image( $image['ID'], 'full' );	
				
				$caption = wp_get_attachment_caption($image['ID']);
				echo $caption ? '<span class="image-caption">' . esc_html($caption) . '</span>' : '';
			echo '</div>';
		}

		echo '</div>';
	echo '</div>';

echo '</div>';

// Thumbs Gallery
echo '<div class="thumbs-gallery-wrapper">';
	echo '<div class="thumbs-gallery swiper">';
		echo '<div class="swiper-wrapper">';

		foreach ( $images as $image ) {
			echo '<div class="swiper-slide">';
				echo wp_get_attachment_image( $image['ID'], 'thumbnail' );	
			echo '</div>';
		}

		echo '</div>';
	echo '</div>';
echo '</div>';
