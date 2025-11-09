<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$separator = ',&nbsp;';
$sources = function_exists('get_field') ? get_field('newsx_post_sources') : [];
$vias = function_exists('get_field') ? get_field('newsx_post_vias') : [];

echo '<div class="newsx-post-sources">';

    if ( get_the_tag_list() ) {
        echo '<div class="post-source source-tag">';
            echo '<span>'. newsx_get_svg_icon('tags') . esc_html__('Tagged:', 'news-magazine-x') .'</span>';
            echo get_the_tag_list();
        echo '</div>';
    }

    // Sources
    if ( !empty($sources)) {
        echo '<div class="post-source">';
            echo '<span>'. newsx_get_svg_icon('link') . esc_html__('Sources:', 'news-magazine-x') .'</span>';
            foreach ($sources as $key => $source) {
                $sources_separator = $key !== array_key_last($sources) ? $separator : '';

                // Source
                echo '<a href="'. esc_url($source['newsx_post_source_url']) .'" target="_blank">'. esc_html($source['newsx_post_source_name']) .'</a>'. $sources_separator;
            }
        echo '</div>';
    }

    // Vias
    if ( !empty($vias)) {
        echo '<div class="post-source">';
            echo '<span>'. newsx_get_svg_icon('external-link-alt') . esc_html__('Via:', 'news-magazine-x') .'</span>';
            foreach ($vias as $key => $via) {
                $vias_separator = $key !== array_key_last($vias) ? $separator : '';

                // Via
                echo '<a href="'. esc_url($via['newsx_post_via_url']) .'" target="_blank">'. esc_html($via['newsx_post_via_name']) .'</a>'. $vias_separator;
            }
        echo '</div>';
    }

echo '</div>';
