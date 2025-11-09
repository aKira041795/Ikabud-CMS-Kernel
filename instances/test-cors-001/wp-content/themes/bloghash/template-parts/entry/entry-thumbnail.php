<?php
/**
 * Template part for displaying media of the entry.
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

$bloghash_post_format = get_post_format();

if ( is_single() ) {
	$bloghash_post_format = '';
}

do_action( 'bloghash_before_entry_thumbnail' );

get_template_part( 'template-parts/entry/format/media', $bloghash_post_format );

do_action( 'bloghash_after_entry_thumbnail' );
