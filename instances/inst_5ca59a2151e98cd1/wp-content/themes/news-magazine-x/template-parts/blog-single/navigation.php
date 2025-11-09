<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Get Previous and Next Posts
$prev_post = get_adjacent_post( false, '', true );
$next_post = get_adjacent_post( false, '', false );

if ( empty( $prev_post ) && empty( $next_post ) ) {
    return false;
}

// Get Options
$nav_style = newsx_get_option('bs_nav_style');
$nav_arrow = newsx_get_option('bs_nav_arrow');
$prev_text = newsx_get_option('bs_nav_prev_text');
$next_text = newsx_get_option('bs_nav_next_text');
$dividers = newsx_get_option('bs_nav_dividers');

if ( !defined('NEWSX_CORE_PRO_VERSION') || !newsx_core_pro_fs()->can_use_premium_code() ) {
    $nav_style = in_array($nav_style, ['s5', 's0-nr']) ? $nav_style : 's0-nr';
    $nav_arrow = 'chevron';
}

// Background Image
if ( 's2' === $nav_style && defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ) {
    $prev_bg = !empty( $prev_post ) ? ' style="background-image: url('. esc_url(get_the_post_thumbnail_url($prev_post->ID, 'full')) .')"' : '';
    $next_bg = !empty( $next_post ) ? ' style="background-image: url('. esc_url(get_the_post_thumbnail_url($next_post->ID, 'full')) .')"' : '';
} else {
    $prev_bg = '';
    $next_bg = '';
}

// Wrapper
echo '<div class="newsx-post-navigation newsx-flex-nowrap '. esc_attr('newsx-'. $nav_style) .'">';

    // Edit Button
    echo newsx_customizer_edit_button_markup('bs_navigation');

    // Previous Post
    if ( !empty( $prev_post ) ) {
        echo '<a href="'. get_permalink( $prev_post ) .'" class="prev-post newsx-flex-nowrap" '. $prev_bg .'>';
            if ( 's0' == $nav_style || 's0-nr' == $nav_style ) {
                echo '<span class="newsx-flex">'. newsx_get_svg_icon($nav_arrow .'-left') . $prev_text .'</span>';
                echo '<div class="newsx-flex-nowrap">';
                    echo get_the_post_thumbnail($prev_post->ID, 'thumbnail');
                    echo '<h4>'. get_the_title( $prev_post ) .'</h4>';
                echo '</div>';
            } elseif ( 's1' == $nav_style ) {
                echo get_the_post_thumbnail($prev_post->ID, 'thumbnail');
                echo '<div class="newsx-flex-nowrap">';
                    echo '<span class="newsx-flex">'. newsx_get_svg_icon($nav_arrow .'-left') . $prev_text .'</span>';
                    echo '<h4>'. get_the_title( $prev_post ) .'</h4>';
                echo '</div>';
            } elseif ( 's5' == $nav_style ) {
                echo '<h5 class="newsx-flex">'. newsx_get_svg_icon($nav_arrow .'-left') . $prev_text .'</h5>';
            } else {
                echo '<span class="newsx-flex">'. newsx_get_svg_icon($nav_arrow .'-left') . $prev_text .'</span>';
                echo '<h4>'. get_the_title( $prev_post ) .'</h4>';
            }
        echo '</a>';
    } else {
        echo '<a href="#" class="prev-post newsx-flex-nowrap">';
        echo '</a>';
    }

    echo ($dividers) ? '<span class="newsx-divider"></span>' : '';

    // Next Post
    if ( !empty( $next_post ) ) {
        echo '<a href="'. get_permalink( $next_post ) .'" class="next-post newsx-flex-nowrap" '. $next_bg .'>';    
            if ( 's0' == $nav_style || 's0-nr' == $nav_style ) {
                echo '<span class="newsx-flex">'. $next_text . newsx_get_svg_icon($nav_arrow .'-right') .'</span>';
                echo '<div class="newsx-flex-nowrap">';
                echo '<h4>'. get_the_title( $next_post ) .'</h4>';
                echo get_the_post_thumbnail($next_post->ID, 'thumbnail');
                echo '</div>';
            } elseif ( 's1' == $nav_style ) {
                echo '<div class="newsx-flex-nowrap">';
                    echo '<span class="newsx-flex">'. $next_text . newsx_get_svg_icon($nav_arrow .'-right') .'</span>';
                    echo '<h4>'. get_the_title( $next_post ) .'</h4>';
                echo '</div>';
                echo get_the_post_thumbnail($next_post->ID, 'thumbnail');
            } elseif ( 's5' == $nav_style ) {
                echo '<h5 class="newsx-flex">'. $next_text . newsx_get_svg_icon($nav_arrow .'-right') .'</h5>';
            } else {
                echo '<span class="newsx-flex">'. $next_text . newsx_get_svg_icon($nav_arrow .'-right') .'</span>';
                echo '<h4>'. get_the_title( $next_post ) .'</h4>';
            }
        echo '</a>';
    } else {
        echo '<a href="#" class="next-post newsx-flex-nowrap">';
        echo '</a>';
    }

echo '</div>';