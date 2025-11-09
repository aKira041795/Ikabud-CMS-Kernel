<?php
/**
 * Template part for displaying entry thumbnail (featured image).
 *
 * @link https://codex.wordpress.org/Template_Hierarchy
 *
 * @package     Bloghash
 * @author      Peregrine Themes
 * @since       1.0.0
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

$bloghash_post_format = get_post_format();

// Wrap with link for non-singular pages.
if ( 'link' === $bloghash_post_format || ! is_single( get_the_ID() ) ) {

	$bloghash_icon = '';

	if ( is_sticky() ) {
		$bloghash_icon = sprintf(
			'<span class="entry-media-icon is_sticky" title="%1$s" aria-hidden="true"><span class="entry-media-icon-wrapper">%2$s%3$s</span></span>',
			esc_attr__( 'Featured', 'bloghash' ),
			bloghash()->icons->get_svg(
				'pin',
				array(
					'class'       => 'top-icon',
					'aria-hidden' => 'true',
				)
			),
			bloghash()->icons->get_svg( 'pin', array( 'aria-hidden' => 'true' ) )
		);
	} elseif ( 'video' === $bloghash_post_format ) {

		$bloghash_icon = sprintf(
			'<span class="entry-media-icon" aria-hidden="true"><span class="entry-media-icon-wrapper">%1$s%2$s</span></span>',
			bloghash()->icons->get_svg(
				'play-2',
				array(
					'class'       => 'top-icon',
					'aria-hidden' => 'true',
				)
			),
			bloghash()->icons->get_svg( 'play-2', array( 'aria-hidden' => 'true' ) )
		);
	} elseif ( 'link' === $bloghash_post_format ) {
		$bloghash_icon = sprintf(
			'<span class="entry-media-icon" title="%1$s" aria-hidden="true"><span class="entry-media-icon-wrapper">%2$s%3$s</span></span>',
			esc_url( bloghash_entry_get_permalink() ),
			bloghash()->icons->get_svg(
				'external-link',
				array(
					'class'       => 'top-icon',
					'aria-hidden' => 'true',
				)
			),
			bloghash()->icons->get_svg( 'external-link', array( 'aria-hidden' => 'true' ) )
		);
	}

	$bloghash_icon = apply_filters( 'bloghash_post_format_media_icon', $bloghash_icon, $bloghash_post_format );

	$bloghash_media = sprintf(
		'<a href="%1$s" class="entry-image-link">%2$s%3$s</a>',
		esc_url( bloghash_entry_get_permalink() ),
		$bloghash_media,
		$bloghash_icon
	);
}

$bloghash_media = apply_filters( 'bloghash_post_thumbnail', $bloghash_media );

// Print the post thumbnail.
echo wp_kses(
	sprintf(
		'<div class="post-thumb entry-media thumbnail">%1$s</div>',
		$bloghash_media
	),
	bloghash_get_allowed_html_tags()
);
