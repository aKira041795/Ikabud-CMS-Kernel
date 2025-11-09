<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

global $paged;
global $wp_query;
$pages = $wp_query->max_num_pages;
$range = 2;
$showitems = ( $range * 2 ) + 1;

if ( empty( $paged ) ) {
	$paged = 1;
}

if ( ! $pages ) {
	$pages = 1;
}

if ( $pages == 1 ) {
	return;
}

echo '<div class="newsx-blog-pagination">';

	//  Previous Page
	if ( $paged > 1 ) {
		echo '<a href="'. esc_url( get_pagenum_link( $paged - 1 ) ) .'" class="newsx-prev">'. newsx_get_svg_icon('angle-left') .'</a>';
	}

    // First page is always displayed
    echo '<a href="' . esc_url( get_pagenum_link( 1 ) ) . '" class="'. ( $paged == 1 ? 'current' : '' ) .'">1</a>';

    // Ellipsis if there are pages between the first page and pages around the current page
    if ($paged > $range + 2) {
        echo '<span class="extend">...</span>';
    }

    // Pages around the current page
    for ( $i = max($paged - $range, 2); $i <= min($paged + $range, $pages - 1); $i++ ) {
        if ( $paged < 3 ) {
            $range = 2;
        }

        if ($paged == $i) {
            echo '<span class="current">'. esc_html($i) .'</span>'; // current page
        } else {
            echo '<a href="'. esc_url( get_pagenum_link( $i ) ) .'">'. esc_html($i) .'</a>';
        }
    }

    // Ellipsis if there are pages between pages around the current page and the last page
    if ($paged < $pages - $range - 1) {
        echo '<span class="extend">...</span>';
    }

    // Last page is always displayed
    echo '<a href="' . esc_url( get_pagenum_link( $pages ) ) . '" class="'. ( $paged == $pages ? 'current' : '' ) .'">' . esc_html($pages) . '</a>';

	// Next Page
	if ( $paged < $pages ) {
		echo '<a href="'. esc_url( get_pagenum_link( $paged + 1 ) ).'" class="newsx-next" >'. newsx_get_svg_icon('angle-right') .'</a>';
	}

echo '</div>';
