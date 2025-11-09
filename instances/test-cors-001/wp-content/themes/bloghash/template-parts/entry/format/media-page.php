<?php
/**
 * Template part for displaying page featured image.
 *
 * @link https://codex.wordpress.org/Template_Hierarchy
 *
 * @package BlogHash
 * @author Peregrine Themes
 * @since   1.0.0
 */

/**
 * Do not allow direct script access.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Get default post media.
$bloghash_media = bloghash_get_post_media( '' );

if ( ! $bloghash_media || post_password_required() ) {
	return;
}

$bloghash_media = apply_filters( 'bloghash_post_thumbnail', $bloghash_media, get_the_ID() );

$bloghash_classes = array( 'post-thumb', 'entry-media', 'thumbnail' );

$bloghash_classes = apply_filters( 'bloghash_post_thumbnail_wrapper_classes', $bloghash_classes, get_the_ID() );
$bloghash_classes = trim( implode( ' ', array_unique( $bloghash_classes ) ) );

// Print the post thumbnail.
echo wp_kses_post(
	sprintf(
		'<div class="%2$s">%1$s</div>',
		$bloghash_media,
		esc_attr( $bloghash_classes )
	)
);
